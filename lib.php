<?php
// Jetset Johnsons — shared library (plain PHP, no framework, no Composer).
// Works on any standard shared-hosting PHP install with curl + json enabled
// (both are on by default on virtually every cPanel PHP build).

// ---------------------------------------------------------------------------
// Paths & config
// ---------------------------------------------------------------------------
define('APP_ROOT', __DIR__);
define('DATA_DIR', APP_ROOT . '/data');
define('DB_PATH', DATA_DIR . '/db.json');
define('DB_LOCK_PATH', DATA_DIR . '/db.lock');

function load_env() {
    $path = APP_ROOT . '/.env';
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        $len = strlen($val);
        if ($len >= 2 && (($val[0] === '"' && $val[$len-1] === '"') || ($val[0] === "'" && $val[$len-1] === "'"))) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$val");
        }
    }
}
load_env();

function env($key, $default = '') {
    $v = getenv($key);
    return $v === false || $v === '' ? $default : $v;
}

define('FAMILY_CODE', env('FAMILY_CODE'));
define('ANTHROPIC_API_KEY', env('ANTHROPIC_API_KEY'));
define('ANTHROPIC_MODEL', env('ANTHROPIC_MODEL', 'claude-sonnet-5'));
define('GOOGLE_MAPS_API_KEY', env('GOOGLE_MAPS_API_KEY'));

// ---------------------------------------------------------------------------
// JSON response helpers
// ---------------------------------------------------------------------------
function json_out($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function json_error($msg, $status = 400) {
    json_out(['error' => $msg], $status);
}
function body_json() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
function require_auth() {
    if (!FAMILY_CODE) json_error('Server is not configured yet (FAMILY_CODE is missing from .env).', 500);
    $supplied = isset($_SERVER['HTTP_X_FAMILY_CODE']) ? $_SERVER['HTTP_X_FAMILY_CODE'] : (isset($_GET['c']) ? $_GET['c'] : '');
    if (!hash_equals(FAMILY_CODE, (string)$supplied)) json_error('Wrong family code.', 401);
}

// ---------------------------------------------------------------------------
// Data layer — single JSON file, exclusive-locked for the whole read+write.
// ---------------------------------------------------------------------------
function db_lock_open() {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    $fp = fopen(DB_LOCK_PATH, 'c');
    flock($fp, LOCK_EX);
    return $fp;
}
function db_lock_close($fp) {
    flock($fp, LOCK_UN);
    fclose($fp);
}
function read_db() {
    if (!file_exists(DB_PATH)) return ['cities' => new stdClass()];
    $raw = file_get_contents(DB_PATH);
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];
    if (!isset($data['cities']) || !is_array($data['cities'])) $data['cities'] = [];
    return $data;
}
function write_db($db) {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    $tmp = DB_PATH . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($tmp, DB_PATH);
}
// Run $fn($db) with the DB locked; $fn mutates $db by reference and returns
// whatever the route handler should send back to the client.
function with_db($fn) {
    $lock = db_lock_open();
    $db = read_db();
    $result = $fn($db);
    write_db($db);
    db_lock_close($lock);
    return $result;
}

function gen_id($prefix = '') {
    $ms = (string) round(microtime(true) * 1000);
    return $prefix . base_convert($ms, 10, 36) . bin2hex(random_bytes(4));
}

// ---------------------------------------------------------------------------
// HTTP helpers (curl)
// ---------------------------------------------------------------------------
function http_json($method, $url, $headers = [], $bodyArray = null, $timeout = 45) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($bodyArray !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($bodyArray));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return [0, null, $err];
    $data = json_decode($raw, true);
    return [$code, $data, $err];
}
function http_get_binary($url, $timeout = 30) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($raw === false) return [0, '', 'application/octet-stream'];
    $bodyStr = substr($raw, $headerSize);
    return [$code, $bodyStr, $contentType ?: 'application/octet-stream'];
}

// ---------------------------------------------------------------------------
// Claude helper
// ---------------------------------------------------------------------------
function claude_json($prompt, $system = null, $useWebSearch = false) {
    if (!ANTHROPIC_API_KEY) return ['error' => 'ANTHROPIC_API_KEY is not set on the server.'];
    $body = [
        'model' => ANTHROPIC_MODEL,
        'max_tokens' => 2000,
        'system' => $system ?: 'Respond with ONLY valid JSON. No prose, no markdown code fences.',
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ];
    if ($useWebSearch) $body['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search']];

    list($code, $data, $err) = http_json('POST', 'https://api.anthropic.com/v1/messages', [
        'content-type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
    ], $body, 60);

    if ($code < 200 || $code >= 300 || !$data) {
        $msg = ($data['error']['message'] ?? null) ?: ($err ?: 'Claude API error');
        return ['error' => $msg];
    }
    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $text = trim($text);
    $cleaned = preg_replace('/^```json\s*/i', '', $text);
    $cleaned = preg_replace('/^```\s*/', '', $cleaned);
    $cleaned = preg_replace('/```\s*$/', '', $cleaned);
    $json = json_decode(trim($cleaned), true);
    return ['raw' => $text, 'json' => $json];
}

// ---------------------------------------------------------------------------
// Google Places (New)
// ---------------------------------------------------------------------------
function places_text_search($query) {
    if (!GOOGLE_MAPS_API_KEY) return null;
    list($code, $data, $err) = http_json('POST', 'https://places.googleapis.com/v1/places:searchText', [
        'content-type: application/json',
        'x-goog-api-key: ' . GOOGLE_MAPS_API_KEY,
        'x-goog-fieldmask: places.id,places.displayName,places.rating,places.websiteUri,places.location,places.photos',
    ], ['textQuery' => $query]);

    if ($code < 200 || $code >= 300 || !$data) {
        error_log('Places search failed: ' . (($data['error']['message'] ?? null) ?: $err));
        return null;
    }
    if (empty($data['places'])) return null;
    $p = $data['places'][0];
    return [
        'placeId' => $p['id'] ?? null,
        'rating' => $p['rating'] ?? null,
        'website' => $p['websiteUri'] ?? null,
        'lat' => $p['location']['latitude'] ?? null,
        'lng' => $p['location']['longitude'] ?? null,
        'photoRef' => $p['photos'][0]['name'] ?? null,
    ];
}

function enrich_pin(&$pin, $city) {
    $changed = false;
    if (in_array($pin['category'], ['eat', 'play', 'see']) && empty($pin['placeId']) && GOOGLE_MAPS_API_KEY) {
        $result = places_text_search($pin['title'] . ', ' . $city['name'] . ' ' . $city['state']);
        if ($result) { $pin = array_merge($pin, $result); $changed = true; }
    }
    if ($pin['category'] === 'eat' && empty($pin['website']) && empty($pin['menuUrl']) && ANTHROPIC_API_KEY) {
        $r = claude_json(
            'Find the official online menu URL for the restaurant "' . $pin['title'] . '" in ' . $city['name'] . ', ' . $city['state'] . '.',
            'Respond with ONLY a JSON object like {"menuUrl":"https://..."} or {"menuUrl":null} if none is found.',
            true
        );
        if (!empty($r['json']['menuUrl'])) { $pin['menuUrl'] = $r['json']['menuUrl']; $changed = true; }
    }
    return $changed;
}

// ---------------------------------------------------------------------------
// ICS export
// ---------------------------------------------------------------------------
function ics_escape($s) {
    $s = (string)($s ?? '');
    $s = str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $s);
    return $s;
}
function to_utc_from_zoned($dateStr, $timeStr, $tz) {
    try {
        $dt = new DateTime($dateStr . ' ' . ($timeStr ?: '00:00'), new DateTimeZone($tz ?: 'UTC'));
    } catch (Exception $e) {
        $dt = new DateTime($dateStr . ' ' . ($timeStr ?: '00:00'), new DateTimeZone('UTC'));
    }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt;
}
function format_ics_date(DateTime $dt) {
    return $dt->format('Ymd\THis\Z');
}

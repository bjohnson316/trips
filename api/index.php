<?php
require_once __DIR__ . '/../lib.php';

require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$path = isset($_SERVER['PATH_INFO']) ? trim($_SERVER['PATH_INFO'], '/') : '';
$seg = $path === '' ? [] : explode('/', $path);
$n = count($seg);

$PIN_DEFAULTS = [
    'category' => 'see', 'title' => '', 'note' => '', 'url' => '', 'when' => '', 'status' => 'want', 'byName' => '',
    'lat' => null, 'lng' => null, 'placeId' => null, 'rating' => null, 'website' => null, 'photoRef' => null, 'menuUrl' => null,
    'tickets' => false, 'tDate' => '', 'tTime' => '', 'tTz' => '', 'tNote' => '', 'remind' => '1440,180',
    'venue' => '', 'evDate' => '', 'evTime' => '',
];

// ---------------------------------------------------------------------------
// GET /api/config
// ---------------------------------------------------------------------------
if ($n === 1 && $seg[0] === 'config' && $method === 'GET') {
    json_out([
        'hasAI' => (bool) ANTHROPIC_API_KEY,
        'hasMaps' => (bool) GOOGLE_MAPS_API_KEY,
        'hasUnsplash' => (bool) env('UNSPLASH_ACCESS_KEY'),
        'model' => ANTHROPIC_MODEL,
    ]);
}

// ---------------------------------------------------------------------------
// GET /api/cities
// ---------------------------------------------------------------------------
if ($n === 1 && $seg[0] === 'cities' && $method === 'GET') {
    $db = read_db();
    json_out(array_values($db['cities']));
}

// ---------------------------------------------------------------------------
// GET/PUT/PATCH/DELETE /api/cities/:id
// ---------------------------------------------------------------------------
if ($n === 2 && $seg[0] === 'cities') {
    $id = $seg[1];

    if ($method === 'GET') {
        $db = read_db();
        if (!isset($db['cities'][$id])) json_error('City not found', 404);
        json_out($db['cities'][$id]);
    }

    if ($method === 'PUT') {
        $body = body_json();
        $result = with_db(function (&$db) use ($id, $body) {
            $realId = $id === 'new' ? gen_id('c') : $id;
            $existing = $db['cities'][$realId] ?? [];
            $city = [
                'id' => $realId,
                'name' => $body['name'] ?? ($existing['name'] ?? ''),
                'state' => $body['state'] ?? ($existing['state'] ?? ''),
                'startDate' => $body['startDate'] ?? ($existing['startDate'] ?? ''),
                'endDate' => $body['endDate'] ?? ($existing['endDate'] ?? ''),
                'photoUrl' => $existing['photoUrl'] ?? null,
                'photoIndex' => $existing['photoIndex'] ?? 0,
                'pins' => $existing['pins'] ?? new stdClass(),
                'live' => $existing['live'] ?? ['see' => new stdClass(), 'events' => new stdClass(), 'games' => new stdClass(), 'traditions' => new stdClass()],
            ];
            $db['cities'][$realId] = $city;
            return $city;
        });
        json_out($result);
    }

    if ($method === 'PATCH') {
        $body = body_json();
        $result = with_db(function (&$db) use ($id, $body) {
            if (!isset($db['cities'][$id])) json_error('City not found', 404);
            $city = array_merge($db['cities'][$id], $body);
            $city['id'] = $id;
            $db['cities'][$id] = $city;
            return $city;
        });
        json_out($result);
    }

    if ($method === 'DELETE') {
        with_db(function (&$db) use ($id) { unset($db['cities'][$id]); return null; });
        json_out(['ok' => true]);
    }
}

// ---------------------------------------------------------------------------
// PUT/PATCH/DELETE /api/cities/:id/pins/:pid
// ---------------------------------------------------------------------------
if ($n === 4 && $seg[0] === 'cities' && $seg[2] === 'pins') {
    $cid = $seg[1]; $pid = $seg[3];

    if ($method === 'PUT') {
        $body = body_json();
        $result = with_db(function (&$db) use ($cid, $pid, $body, $PIN_DEFAULTS) {
            if (!isset($db['cities'][$cid])) json_error('City not found', 404);
            if (!isset($db['cities'][$cid]['pins']) || !is_array($db['cities'][$cid]['pins'])) $db['cities'][$cid]['pins'] = [];
            $realPid = $pid === 'new' ? gen_id('p') : $pid;
            $existing = $db['cities'][$cid]['pins'][$realPid] ?? [];
            $pin = ['id' => $realPid, 'createdAt' => $existing['createdAt'] ?? gmdate('Y-m-d\TH:i:s.000\Z')];
            foreach ($PIN_DEFAULTS as $key => $def) {
                $pin[$key] = array_key_exists($key, $body) ? $body[$key] : (array_key_exists($key, $existing) ? $existing[$key] : $def);
            }
            $city = $db['cities'][$cid];
            enrich_pin($pin, $city); // synchronous: no background job queue on shared hosting
            $db['cities'][$cid]['pins'][$realPid] = $pin;
            return $pin;
        });
        json_out($result);
    }

    if ($method === 'PATCH') {
        $body = body_json();
        $result = with_db(function (&$db) use ($cid, $pid, $body) {
            if (!isset($db['cities'][$cid]['pins'][$pid])) json_error('Pin not found', 404);
            $pin = array_merge($db['cities'][$cid]['pins'][$pid], $body);
            $pin['id'] = $pid;
            $db['cities'][$cid]['pins'][$pid] = $pin;
            return $pin;
        });
        json_out($result);
    }

    if ($method === 'DELETE') {
        with_db(function (&$db) use ($cid, $pid) { unset($db['cities'][$cid]['pins'][$pid]); return null; });
        json_out(['ok' => true]);
    }
}

// ---------------------------------------------------------------------------
// PUT /api/cities/:id/live/:kind
// ---------------------------------------------------------------------------
if ($n === 4 && $seg[0] === 'cities' && $seg[2] === 'live' && $method === 'PUT') {
    $cid = $seg[1]; $kind = $seg[3];
    $body = body_json();
    $result = with_db(function (&$db) use ($cid, $kind, $body) {
        if (!isset($db['cities'][$cid])) json_error('City not found', 404);
        $entry = ['items' => $body['items'] ?? [], 'at' => gmdate('Y-m-d\TH:i:s.000\Z'), 'note' => $body['note'] ?? ''];
        $db['cities'][$cid]['live'][$kind] = $entry;
        return $entry;
    });
    json_out($result);
}

// ---------------------------------------------------------------------------
// POST /api/ai — one-off JSON-only Claude call
// ---------------------------------------------------------------------------
if ($n === 1 && $seg[0] === 'ai' && $method === 'POST') {
    $body = body_json();
    $result = claude_json($body['prompt'] ?? '', $body['system'] ?? null, false);
    if (isset($result['error'])) json_error($result['error'], 500);
    json_out($result);
}

// ---------------------------------------------------------------------------
// POST /api/cities/:id/refresh — events/games via Claude + web search
// ---------------------------------------------------------------------------
if ($n === 3 && $seg[0] === 'cities' && $seg[2] === 'refresh' && $method === 'POST') {
    $cid = $seg[1];
    $body = body_json();
    $kind = $body['kind'] ?? '';
    if (!in_array($kind, ['events', 'games'])) json_error('kind must be "events" or "games"', 400);

    $db = read_db();
    if (!isset($db['cities'][$cid])) json_error('City not found', 404);
    $city = $db['cities'][$cid];
    if (!ANTHROPIC_API_KEY) json_error('ANTHROPIC_API_KEY is not set on the server.', 400);

    $prompt = $kind === 'events'
        ? "Search the web for real, currently-scheduled public events (concerts, festivals, exhibitions, shows) in {$city['name']}, {$city['state']} happening between {$city['startDate']} and {$city['endDate']}. Respond with ONLY this JSON shape: {\"items\":[{\"name\":\"\",\"date\":\"YYYY-MM-DD\",\"time\":\"HH:MM\",\"venue\":\"\",\"url\":\"\"}]}. Only include events backed by a real listing you found. Use an empty items array if you find none."
        : "Search the web for real, scheduled professional or major college sports games in {$city['name']}, {$city['state']} happening between {$city['startDate']} and {$city['endDate']}. Respond with ONLY this JSON shape: {\"items\":[{\"league\":\"\",\"matchup\":\"\",\"date\":\"YYYY-MM-DD\",\"time\":\"HH:MM\",\"venue\":\"\",\"url\":\"\"}]}. Only include games backed by a real schedule you found. Use an empty items array if you find none.";

    $r = claude_json($prompt, 'You verify information with web search before answering. After searching, respond with ONLY the final JSON object — no prose, no markdown fences.', true);
    if (isset($r['error'])) json_error($r['error'], 500);
    $items = $r['json']['items'] ?? [];
    $items = array_values(array_filter($items, function ($it) use ($city) {
        return isset($it['date']) && $it['date'] >= $city['startDate'] && $it['date'] <= $city['endDate'];
    }));

    $result = with_db(function (&$db) use ($cid, $kind, $items) {
        $entry = ['items' => $items, 'at' => gmdate('Y-m-d\TH:i:s.000\Z')];
        $db['cities'][$cid]['live'][$kind] = $entry;
        return $entry;
    });
    json_out($result);
}

// ---------------------------------------------------------------------------
// POST /api/cities/:id/photo — cycle city cover photo via Places
// ---------------------------------------------------------------------------
if ($n === 3 && $seg[0] === 'cities' && $seg[2] === 'photo' && $method === 'POST') {
    $cid = $seg[1];
    $db = read_db();
    if (!isset($db['cities'][$cid])) json_error('City not found', 404);
    if (!GOOGLE_MAPS_API_KEY) json_error('GOOGLE_MAPS_API_KEY is not set on the server.', 400);
    $city = $db['cities'][$cid];

    $result = places_text_search($city['name'] . ', ' . $city['state']);
    if (!$result || empty($result['photoRef'])) json_error('No photo found for this city.', 404);

    $photoUrl = '/api/photo?ref=' . rawurlencode($result['photoRef']) . '&c=' . rawurlencode(FAMILY_CODE);
    $out = with_db(function (&$db) use ($cid, $photoUrl) {
        $db['cities'][$cid]['photoIndex'] = ($db['cities'][$cid]['photoIndex'] ?? 0) + 1;
        $db['cities'][$cid]['photoUrl'] = $photoUrl;
        return ['photoUrl' => $photoUrl];
    });
    json_out($out);
}

// ---------------------------------------------------------------------------
// GET /api/photo?ref=... — Google photo proxy (keeps the key server-side)
// Uses a query param rather than a path segment because Google's photo
// "name" contains literal slashes (places/XXX/photos/YYY).
// ---------------------------------------------------------------------------
if ($n === 1 && $seg[0] === 'photo' && $method === 'GET') {
    if (!GOOGLE_MAPS_API_KEY) { http_response_code(400); echo 'Maps key not configured'; exit; }
    $ref = $_GET['ref'] ?? '';
    if ($ref === '') { http_response_code(400); echo 'Missing ref'; exit; }
    $url = 'https://places.googleapis.com/v1/' . $ref . '/media?maxWidthPx=1200&key=' . GOOGLE_MAPS_API_KEY;
    list($code, $bin, $contentType) = http_get_binary($url);
    if ($code < 200 || $code >= 300) { http_response_code(502); echo 'Photo fetch failed'; exit; }
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=86400');
    echo $bin;
    exit;
}

// ---------------------------------------------------------------------------
// GET /api/cities/:id/pins/:pid/ics
// ---------------------------------------------------------------------------
if ($n === 5 && $seg[0] === 'cities' && $seg[2] === 'pins' && $seg[4] === 'ics' && $method === 'GET') {
    $cid = $seg[1]; $pid = $seg[3];
    $db = read_db();
    if (!isset($db['cities'][$cid])) { http_response_code(404); echo 'City not found'; exit; }
    $pin = $db['cities'][$cid]['pins'][$pid] ?? null;
    if (!$pin) { http_response_code(404); echo 'Pin not found'; exit; }
    $city = $db['cities'][$cid];

    $dateStr = $pin['tDate'] ?: $pin['evDate'];
    $timeStr = $pin['tTime'] ?: ($pin['evTime'] ?: '00:00');
    $tz = $pin['tTz'] ?: 'America/Chicago';
    if (!$dateStr) { http_response_code(400); echo 'This pin has no date set yet.'; exit; }

    $start = to_utc_from_zoned($dateStr, $timeStr, $tz);
    $end = (clone $start)->modify('+2 hours');
    $reminders = array_filter(array_map('intval', explode(',', $pin['remind'] ?: '1440,180')));

    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Jetset Johnsons//EN', 'CALSCALE:GREGORIAN', 'BEGIN:VEVENT'];
    $lines[] = 'UID:' . $pin['id'] . '@jetsetjohnsons';
    $lines[] = 'DTSTAMP:' . format_ics_date(new DateTime('now', new DateTimeZone('UTC')));
    $lines[] = 'DTSTART:' . format_ics_date($start);
    $lines[] = 'DTEND:' . format_ics_date($end);
    $lines[] = 'SUMMARY:' . ics_escape($pin['title']);
    $lines[] = 'LOCATION:' . ics_escape($pin['venue'] ?: $city['name']);
    $lines[] = 'DESCRIPTION:' . ics_escape($pin['tNote'] ?: $pin['note']);
    foreach ($reminders as $mins) {
        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = 'DESCRIPTION:' . ics_escape($pin['title']);
        $lines[] = 'TRIGGER:-PT' . $mins . 'M';
        $lines[] = 'END:VALARM';
    }
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    header('Content-Type: text/calendar; charset=utf-8');
    $safeName = preg_replace('/[^a-z0-9]+/i', '-', $pin['title'] ?: 'event');
    header('Content-Disposition: attachment; filename="' . $safeName . '.ics"');
    echo implode("\r\n", $lines);
    exit;
}

// ---------------------------------------------------------------------------
// No route matched
// ---------------------------------------------------------------------------
json_error('Not found', 404);

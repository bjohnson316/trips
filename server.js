// Jetset Johnsons — self-hosted server
// Node 18+, Express only. See README.md for setup on Hostinger cPanel.

const path = require('path');
const fs = require('fs');
const fsp = fs.promises;
const crypto = require('crypto');
const express = require('express');

// ---------------------------------------------------------------------------
// Minimal .env loader (no extra dependency). Most cPanel Node.js apps inject
// env vars automatically, but this makes `npm start` work from a plain SSH
// shell too, as long as a .env file (copied from .env.example) sits next to
// this file.
// ---------------------------------------------------------------------------
(function loadEnv() {
  const envPath = path.join(__dirname, '.env');
  if (!fs.existsSync(envPath)) return;
  const text = fs.readFileSync(envPath, 'utf8');
  for (const raw of text.split('\n')) {
    const line = raw.trim();
    if (!line || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq === -1) continue;
    const key = line.slice(0, eq).trim();
    let val = line.slice(eq + 1).trim();
    if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
      val = val.slice(1, -1);
    }
    if (process.env[key] === undefined) process.env[key] = val;
  }
})();

const PORT = process.env.PORT || 3000;
const FAMILY_CODE = process.env.FAMILY_CODE || '';
const ANTHROPIC_API_KEY = process.env.ANTHROPIC_API_KEY || '';
const ANTHROPIC_MODEL = process.env.ANTHROPIC_MODEL || 'claude-sonnet-5';
const WEB_SEARCH_TOOL = process.env.WEB_SEARCH_TOOL || 'web_search_20250305';
const GOOGLE_MAPS_API_KEY = process.env.GOOGLE_MAPS_API_KEY || '';
const UNSPLASH_ACCESS_KEY = process.env.UNSPLASH_ACCESS_KEY || '';
const DATA_DIR = path.resolve(__dirname, process.env.DATA_DIR || './data');
const DB_PATH = path.join(DATA_DIR, 'db.json');

// ---------------------------------------------------------------------------
// Data layer — single JSON file, written atomically, one write at a time.
// ---------------------------------------------------------------------------
let db = { cities: {} };
let writeQueue = Promise.resolve();

function loadDB() {
  try {
    const raw = fs.readFileSync(DB_PATH, 'utf8');
    db = JSON.parse(raw);
  } catch (e) {
    console.error('Could not read data/db.json, starting from an empty database:', e.message);
    db = { cities: {} };
  }
  if (!db.cities) db.cities = {};
}

function saveDB() {
  writeQueue = writeQueue.then(async () => {
    await fsp.mkdir(DATA_DIR, { recursive: true });
    const tmp = DB_PATH + '.tmp';
    await fsp.writeFile(tmp, JSON.stringify(db, null, 2), 'utf8');
    await fsp.rename(tmp, DB_PATH);
  }).catch(e => console.error('DB write failed:', e));
  return writeQueue;
}

loadDB();

function genId(prefix) {
  return (prefix || '') + Date.now().toString(36) + crypto.randomBytes(4).toString('hex');
}

// ---------------------------------------------------------------------------
// App + auth
// ---------------------------------------------------------------------------
const app = express();
app.use(express.json({ limit: '2mb' }));

function requireAuth(req, res, next) {
  if (!FAMILY_CODE) {
    return res.status(500).json({ error: 'Server is not configured yet (FAMILY_CODE is missing from .env).' });
  }
  const supplied = req.get('x-family-code') || req.query.c || '';
  if (supplied !== FAMILY_CODE) return res.status(401).json({ error: 'Wrong family code.' });
  next();
}

app.use('/api', requireAuth);

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
app.get('/api/config', (req, res) => {
  res.json({
    hasAI: !!ANTHROPIC_API_KEY,
    hasMaps: !!GOOGLE_MAPS_API_KEY,
    hasUnsplash: !!UNSPLASH_ACCESS_KEY,
    model: ANTHROPIC_MODEL,
  });
});

// ---------------------------------------------------------------------------
// Cities
// ---------------------------------------------------------------------------
app.get('/api/cities', (req, res) => {
  res.json(Object.values(db.cities));
});

app.get('/api/cities/:id', (req, res) => {
  const city = db.cities[req.params.id];
  if (!city) return res.status(404).json({ error: 'City not found' });
  res.json(city);
});

app.put('/api/cities/:id', async (req, res) => {
  const rawId = req.params.id;
  const id = rawId === 'new' ? genId('c') : rawId;
  const existing = db.cities[id] || {};
  const city = {
    id,
    name: req.body.name ?? existing.name ?? '',
    state: req.body.state ?? existing.state ?? '',
    startDate: req.body.startDate ?? existing.startDate ?? '',
    endDate: req.body.endDate ?? existing.endDate ?? '',
    photoUrl: existing.photoUrl ?? null,
    photoIndex: existing.photoIndex ?? 0,
    pins: existing.pins ?? {},
    live: existing.live ?? { see: {}, events: {}, games: {}, traditions: {} },
  };
  db.cities[id] = city;
  await saveDB();
  res.json(city);
});

app.patch('/api/cities/:id', async (req, res) => {
  const city = db.cities[req.params.id];
  if (!city) return res.status(404).json({ error: 'City not found' });
  Object.assign(city, req.body, { id: city.id });
  await saveDB();
  res.json(city);
});

app.delete('/api/cities/:id', async (req, res) => {
  delete db.cities[req.params.id];
  await saveDB();
  res.json({ ok: true });
});

// ---------------------------------------------------------------------------
// Pins
// ---------------------------------------------------------------------------
function getCityOr404(req, res) {
  const city = db.cities[req.params.id];
  if (!city) { res.status(404).json({ error: 'City not found' }); return null; }
  if (!city.pins) city.pins = {};
  return city;
}

const PIN_DEFAULTS = {
  category: 'see', title: '', note: '', url: '', when: '', status: 'want', byName: '',
  lat: null, lng: null, placeId: null, rating: null, website: null, photoRef: null, menuUrl: null,
  tickets: false, tDate: '', tTime: '', tTz: '', tNote: '', remind: '1440,180',
  venue: '', evDate: '', evTime: '',
};

app.put('/api/cities/:id/pins/:pid', async (req, res) => {
  const city = getCityOr404(req, res); if (!city) return;
  const pid = req.params.pid === 'new' ? genId('p') : req.params.pid;
  const existing = city.pins[pid] || {};
  const pin = { id: pid, createdAt: existing.createdAt || new Date().toISOString() };
  for (const key of Object.keys(PIN_DEFAULTS)) {
    pin[key] = req.body[key] !== undefined ? req.body[key] : (existing[key] !== undefined ? existing[key] : PIN_DEFAULTS[key]);
  }
  city.pins[pid] = pin;
  await saveDB();
  res.json(pin);
  enrichPinInBackground(city, pin);
});

app.patch('/api/cities/:id/pins/:pid', async (req, res) => {
  const city = getCityOr404(req, res); if (!city) return;
  const pin = city.pins[req.params.pid];
  if (!pin) return res.status(404).json({ error: 'Pin not found' });
  Object.assign(pin, req.body, { id: pin.id });
  await saveDB();
  res.json(pin);
});

app.delete('/api/cities/:id/pins/:pid', async (req, res) => {
  const city = getCityOr404(req, res); if (!city) return;
  delete city.pins[req.params.pid];
  await saveDB();
  res.json({ ok: true });
});

// ---------------------------------------------------------------------------
// Live lists: see / events / games / traditions
// ---------------------------------------------------------------------------
app.put('/api/cities/:id/live/:kind', async (req, res) => {
  const city = getCityOr404(req, res); if (!city) return;
  if (!city.live) city.live = {};
  city.live[req.params.kind] = { items: req.body.items || [], at: new Date().toISOString(), note: req.body.note || '' };
  await saveDB();
  res.json(city.live[req.params.kind]);
});

// ---------------------------------------------------------------------------
// Claude helpers
// ---------------------------------------------------------------------------
async function claudeJSON({ system, prompt, useWebSearch }) {
  const body = {
    model: ANTHROPIC_MODEL,
    max_tokens: 2000,
    system: system || 'Respond with ONLY valid JSON. No prose, no markdown code fences.',
    messages: [{ role: 'user', content: prompt }],
  };
  if (useWebSearch) body.tools = [{ type: WEB_SEARCH_TOOL, name: 'web_search' }];

  const r = await fetch('https://api.anthropic.com/v1/messages', {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      'x-api-key': ANTHROPIC_API_KEY,
      'anthropic-version': '2023-06-01',
    },
    body: JSON.stringify(body),
  });
  const data = await r.json();
  if (!r.ok) throw new Error(data.error?.message || 'Claude API error');
  const text = (data.content || []).filter(b => b.type === 'text').map(b => b.text).join('\n').trim();
  const cleaned = text.replace(/^```json\s*/i, '').replace(/^```\s*/i, '').replace(/```\s*$/i, '').trim();
  let json = null;
  try { json = JSON.parse(cleaned); } catch { /* leave null, caller decides */ }
  return { raw: text, json };
}

// Generic JSON-only AI call, used by the frontend's finders (restaurants,
// entertainment, "popular things to do", etc.)
app.post('/api/ai', async (req, res) => {
  if (!ANTHROPIC_API_KEY) return res.status(400).json({ error: 'ANTHROPIC_API_KEY is not set on the server.' });
  try {
    const { system, prompt } = req.body;
    const result = await claudeJSON({ system, prompt });
    res.json(result);
  } catch (e) {
    res.status(500).json({ error: e.message });
  }
});

// Events / games refresh — uses web search, only keeps items inside the trip dates.
app.post('/api/cities/:id/refresh', async (req, res) => {
  const city = getCityOr404(req, res); if (!city) return;
  if (!ANTHROPIC_API_KEY) return res.status(400).json({ error: 'ANTHROPIC_API_KEY is not set on the server.' });
  const kind = req.body.kind;
  if (kind !== 'events' && kind !== 'games') return res.status(400).json({ error: 'kind must be "events" or "games"' });

  const prompt = kind === 'events'
    ? `Search the web for real, currently-scheduled public events (concerts, festivals, exhibitions, shows) in ${city.name}, ${city.state} happening between ${city.startDate} and ${city.endDate}. Respond with ONLY this JSON shape: {"items":[{"name":"","date":"YYYY-MM-DD","time":"HH:MM","venue":"","url":""}]}. Only include events backed by a real listing you found. Use an empty items array if you find none.`
    : `Search the web for real, scheduled professional or major college sports games in ${city.name}, ${city.state} happening between ${city.startDate} and ${city.endDate}. Respond with ONLY this JSON shape: {"items":[{"league":"","matchup":"","date":"YYYY-MM-DD","time":"HH:MM","venue":"","url":""}]}. Only include games backed by a real schedule you found. Use an empty items array if you find none.`;

  try {
    const { json } = await claudeJSON({
      system: 'You verify information with web search before answering. After searching, respond with ONLY the final JSON object — no prose, no markdown fences.',
      prompt,
      useWebSearch: true,
    });
    const parsed = json || { items: [] };
    const items = (parsed.items || []).filter(it => it.date >= city.startDate && it.date <= city.endDate);
    if (!city.live) city.live = {};
    city.live[kind] = { items, at: new Date().toISOString() };
    await saveDB();
    res.json(city.live[kind]);
  } catch (e) {
    res.status(500).json({ error: e.message });
  }
});

// ---------------------------------------------------------------------------
// Google Places (New) — enrichment + photo proxy
// ---------------------------------------------------------------------------
async function placesTextSearch(query) {
  if (!GOOGLE_MAPS_API_KEY) return null;
  const r = await fetch('https://places.googleapis.com/v1/places:searchText', {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      'x-goog-api-key': GOOGLE_MAPS_API_KEY,
      'x-goog-fieldmask': 'places.id,places.displayName,places.rating,places.websiteUri,places.location,places.photos',
    },
    body: JSON.stringify({ textQuery: query }),
  });
  const data = await r.json();
  if (!r.ok) { console.error('Places search failed:', data.error?.message || r.status); return null; }
  if (!data.places || !data.places.length) return null;
  const p = data.places[0];
  return {
    placeId: p.id || null,
    rating: p.rating ?? null,
    website: p.websiteUri || null,
    lat: p.location?.latitude ?? null,
    lng: p.location?.longitude ?? null,
    photoRef: p.photos?.[0]?.name || null,
  };
}

async function enrichPinInBackground(city, pin) {
  try {
    let changed = false;
    if (['eat', 'play', 'see'].includes(pin.category) && !pin.placeId && GOOGLE_MAPS_API_KEY) {
      const result = await placesTextSearch(`${pin.title}, ${city.name} ${city.state}`);
      if (result) { Object.assign(pin, result); changed = true; }
    }
    if (pin.category === 'eat' && !pin.website && !pin.menuUrl && ANTHROPIC_API_KEY) {
      const { json } = await claudeJSON({
        system: 'Respond with ONLY a JSON object like {"menuUrl":"https://..."} or {"menuUrl":null} if none is found.',
        prompt: `Find the official online menu URL for the restaurant "${pin.title}" in ${city.name}, ${city.state}.`,
        useWebSearch: true,
      });
      if (json && json.menuUrl) { pin.menuUrl = json.menuUrl; changed = true; }
    }
    if (changed) await saveDB();
  } catch (e) {
    console.error('Background enrichment failed for pin', pin.id, e.message);
  }
}

// Cycle a city's cover photo using Google Places
app.post('/api/cities/:id/photo', async (req, res) => {
  const city = getCityOr404(req, res); if (!city) return;
  if (!GOOGLE_MAPS_API_KEY) return res.status(400).json({ error: 'GOOGLE_MAPS_API_KEY is not set on the server.' });
  try {
    const result = await placesTextSearch(`${city.name}, ${city.state}`);
    if (!result || !result.photoRef) return res.status(404).json({ error: 'No photo found for this city.' });
    city.photoIndex = (city.photoIndex || 0) + 1;
    city.photoUrl = `/api/photo/${encodeURIComponent(result.photoRef)}?c=${encodeURIComponent(FAMILY_CODE)}`;
    await saveDB();
    res.json({ photoUrl: city.photoUrl });
  } catch (e) {
    res.status(500).json({ error: e.message });
  }
});

// Photo proxy — keeps the Google key server-side
app.get('/api/photo/:placeId', async (req, res) => {
  if (!GOOGLE_MAPS_API_KEY) return res.status(400).send('Maps key not configured');
  try {
    const name = decodeURIComponent(req.params.placeId);
    const r = await fetch(`https://places.googleapis.com/v1/${name}/media?maxWidthPx=1200&key=${GOOGLE_MAPS_API_KEY}`, { redirect: 'follow' });
    if (!r.ok) return res.status(502).send('Photo fetch failed');
    res.set('Content-Type', r.headers.get('content-type') || 'image/jpeg');
    res.set('Cache-Control', 'public, max-age=86400');
    res.send(Buffer.from(await r.arrayBuffer()));
  } catch (e) {
    res.status(500).send(e.message);
  }
});

// ---------------------------------------------------------------------------
// ICS export — converts the plan's local time in its time zone to UTC.
// ---------------------------------------------------------------------------
function icsEscape(s) {
  return String(s || '').replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\n/g, '\\n');
}

function toUTCFromZoned(dateStr, timeStr, tz) {
  const [y, mo, d] = dateStr.split('-').map(Number);
  const [h, mi] = (timeStr || '00:00').split(':').map(Number);
  let guess = Date.UTC(y, mo - 1, d, h, mi);
  for (let i = 0; i < 3; i++) {
    const fmt = new Intl.DateTimeFormat('en-US', {
      timeZone: tz || 'UTC', hour12: false,
      year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
    });
    const parts = Object.fromEntries(fmt.formatToParts(new Date(guess)).map(p => [p.type, p.value]));
    const seenAsUTC = Date.UTC(+parts.year, +parts.month - 1, +parts.day, parts.hour === '24' ? 0 : +parts.hour, +parts.minute);
    const targetAsUTC = Date.UTC(y, mo - 1, d, h, mi);
    const diff = targetAsUTC - seenAsUTC;
    if (diff === 0) break;
    guess += diff;
  }
  return new Date(guess);
}

function formatICSDate(date) {
  return date.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
}

app.get('/api/cities/:id/pins/:pid/ics', (req, res) => {
  const city = db.cities[req.params.id];
  if (!city) return res.status(404).send('City not found');
  const pin = city.pins?.[req.params.pid];
  if (!pin) return res.status(404).send('Pin not found');

  const dateStr = pin.tDate || pin.evDate;
  const timeStr = pin.tTime || pin.evTime || '00:00';
  const tz = pin.tTz || 'America/Chicago';
  if (!dateStr) return res.status(400).send('This pin has no date set yet.');

  const startUTC = toUTCFromZoned(dateStr, timeStr, tz);
  const endUTC = new Date(startUTC.getTime() + 2 * 60 * 60 * 1000);
  const reminders = String(pin.remind || '1440,180').split(',').map(s => parseInt(s.trim(), 10)).filter(n => !Number.isNaN(n));

  const lines = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Jetset Johnsons//EN', 'CALSCALE:GREGORIAN',
    'BEGIN:VEVENT',
    `UID:${pin.id}@jetsetjohnsons`,
    `DTSTAMP:${formatICSDate(new Date())}`,
    `DTSTART:${formatICSDate(startUTC)}`,
    `DTEND:${formatICSDate(endUTC)}`,
    `SUMMARY:${icsEscape(pin.title)}`,
    `LOCATION:${icsEscape(pin.venue || city.name)}`,
    `DESCRIPTION:${icsEscape(pin.tNote || pin.note || '')}`,
  ];
  for (const mins of reminders) {
    lines.push('BEGIN:VALARM', 'ACTION:DISPLAY', `DESCRIPTION:${icsEscape(pin.title)}`, `TRIGGER:-PT${mins}M`, 'END:VALARM');
  }
  lines.push('END:VEVENT', 'END:VCALENDAR');

  res.set('Content-Type', 'text/calendar; charset=utf-8');
  res.set('Content-Disposition', `attachment; filename="${(pin.title || 'event').replace(/[^a-z0-9]+/gi, '-')}.ics"`);
  res.send(lines.join('\r\n'));
});

// ---------------------------------------------------------------------------
// Static frontend
// ---------------------------------------------------------------------------
app.use(express.static(path.join(__dirname, 'public'), {
  maxAge: '1h',
  setHeaders: (res, filePath) => {
    // Keep the service worker itself out of long-lived caching so an update
    // to it (and therefore to CACHE_NAME) is picked up promptly.
    if (filePath.endsWith(path.sep + 'sw.js')) res.setHeader('Cache-Control', 'no-cache');
  },
}));
app.get('*', (req, res) => {
  if (req.path.startsWith('/api/')) return res.status(404).json({ error: 'Not found' });
  res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

app.listen(PORT, () => {
  console.log(`Jetset Johnsons listening on port ${PORT}`);
  if (!FAMILY_CODE) console.warn('WARNING: FAMILY_CODE is not set in .env — the API will reject every request.');
});

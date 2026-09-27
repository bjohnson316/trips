# Jetset Johnsons — self-hosted server

A one-file-frontend, one-dependency (Express) travel app. Everything the
family needs — cities, pins, plans, restaurant/activity finders, events,
sports, and a map — lives in this folder and stores its data in
`data/db.json`.

## What's in here

```
server.js                    the whole backend
public/index.html            the whole frontend (no build step)
public/manifest.webmanifest  PWA manifest ("Add to Home Screen")
public/icon-192.png
public/icon-512.png
public/apple-touch-icon.png  gradient "J" logo, dotted flight path
data/db.json                 seeded with Boston + Salem + the Bostonia pin
package.json                 the one dependency: express
.env.example                 copy to .env and fill in
.gitignore
```

## Deploying on Hostinger's cPanel (Setup Node.js App)

1. **Log in to cPanel → Software → Setup Node.js App → Create Application.**
2. **Node.js version:** pick 18.x or newer.
3. **Application mode:** Production.
4. **Application root:** a folder name, e.g. `jetset-johnsons` (cPanel creates
   it under your home directory).
5. **Application URL:** pick the domain/subdomain you want this on (e.g.
   `trip.yourdomain.com`).
6. **Application startup file:** `server.js`
7. Click **Create**. cPanel will show you a command like
   `source /home/yourusername/nodevenv/jetset-johnsons/18/bin/activate` —
   ignore it unless you're doing this over SSH.
8. **Upload the files:** in cPanel → **File Manager**, navigate into the
   application root folder cPanel just created, upload `jetset-johnsons.zip`,
   and extract it there (so `server.js` sits directly inside the app root,
   not inside a nested subfolder — if extracting creates an extra folder,
   move everything up one level).
9. Back on the **Setup Node.js App** page, open your app and scroll to
   **Environment Variables**. Add these one at a time:
   - `FAMILY_CODE` — pick a passcode your family will type once.
   - `ANTHROPIC_API_KEY` — from [console.anthropic.com](https://console.anthropic.com).
   - `GOOGLE_MAPS_API_KEY` — see the Google setup note below.
   - `ANTHROPIC_MODEL` — leave as `claude-sonnet-5` unless you want a different model.

   (You can also skip this step and instead rename `.env.example` to `.env`
   in File Manager and fill in the values there — the server reads either.)
10. Click **Run NPM Install** on the app page. This installs Express using
    cPanel's own npm, which matters — a `node_modules` folder installed on
    your laptop may not be binary-compatible with the server.
11. Click **Restart** (or **Start**).
12. Visit your Application URL. You should see the family-code gate. Enter
    the code you set and a name.

### If you'd rather do it over SSH
```
cd ~/jetset-johnsons        # your application root
cp .env.example .env        # then edit .env with your real values
npm install
```
Then restart the app from the cPanel UI so Passenger picks up the change.

## Setting up the Google Maps key (optional but recommended)

Real photos, ratings, websites, and exact pin positions all come from
**Places API (New)**. Without this key the app still works — it just uses
illustrated skylines/covers instead of photos, and map pins are
approximate.

1. In [Google Cloud Console](https://console.cloud.google.com), create (or
   pick) a project.
2. **APIs & Services → Library** → enable **Places API (New)**.
3. **Billing** must be enabled on that project (Places API isn't free, but
   Google's monthly free credit covers normal family use).
4. **APIs & Services → Credentials** → create an API key, then restrict it
   to Places API (New) for safety.
5. Paste it into `GOOGLE_MAPS_API_KEY`.

If photos don't show up after deploying, check the server's error log
(cPanel → Setup Node.js App → your app → **Errors log**, or
`stderr.log` in the application root) for a line starting with
`Places search failed:` — it prints Google's exact rejection reason
(usually "API not enabled" or "billing not enabled").

## Setting up the Anthropic key (optional but recommended)

Needed for the restaurant/activity finders, the "Popular things to do"
generator, event/game search, and menu-link lookups. Without it, those
buttons show a plain note instead of failing.

## Keeping your family's data safe on redeploy

`data/db.json` is where every pin, plan, and cached list lives once your
family starts using the app. **If you ever re-upload a new zip to update
the app, don't overwrite `data/db.json`** — re-upload just `server.js` and
the `public/` folder, or download a fresh copy of your live `data/db.json`
first and put it back after extracting.

## API routes (for reference)

- `GET /api/config`
- `GET/PUT/PATCH/DELETE /api/cities[/:id]`
- `PUT/PATCH/DELETE /api/cities/:id/pins/:pid`
- `PUT /api/cities/:id/live/:kind` (`kind` = `see` | `events` | `games` | `traditions`)
- `POST /api/ai` — one-off JSON-only Claude call
- `POST /api/cities/:id/refresh` — `{kind: "events"|"games"}`, uses Claude + web search
- `POST /api/cities/:id/photo` — cycles the city's cover photo
- `GET /api/photo/:placeId` — Google photo proxy (keeps the key server-side)
- `GET /api/cities/:id/pins/:pid/ics` — calendar file with reminders

All `/api/*` routes require either an `x-family-code` header or a `?c=`
query parameter matching `FAMILY_CODE`.

## Known simplifications in this MVP

- The sports tab's team database covers ~40 major US metros with common
  aliases — ask for more to be added if your destination isn't listed.
- Background enrichment (Places lookups, menu links) runs after a pin is
  saved and finishes within a few seconds; the app picks it up on its next
  20-second poll.
- The map's "scale bar" is only meaningfully accurate once at least two
  pins have real Google coordinates; otherwise pin positions are
  deterministic-but-approximate placeholders.

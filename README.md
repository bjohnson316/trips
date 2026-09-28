# Jetset Johnsons — GitHub Pages + Firebase

Your own `index.html`, unchanged in look and layout (tiles, hero, bottom nav,
SVG icons, sheets, map, dark mode), running as a static site. The only thing
swapped is where the data lives: Cloud Firestore instead of the old server.

## Set it up

### 1. Publish the security rules (once)
Firebase console → project `jetset-d5503` → **Firestore Database → Rules** →
paste the contents of `firestore.rules` → **Publish**. If you already
published these rules from the earlier build, they're identical. Nothing to redo.

### 2. Put these files at the top level of the `trips` repo
Replace what's there. Delete any leftovers from older builds: `server.js`,
`app.js`, `package.json`, `package-lock.json`, `api/`, `lib.php`, `data/`,
`.env`, `.env.example`. GitHub Pages can't run them.

> If you ever uploaded a `.env` file to GitHub, delete it and treat anything
> in it as public. If it held an Anthropic or Google API key, revoke that key.
> The repo history keeps old copies even after you delete the file.

Repo **Settings → Pages** → Deploy from a branch → `main` → `/ (root)`.

### 3. Open `https://bjohnson316.github.io/trips/`
Enter a family code and your name. The first time a code is used, the app asks
whether to create a new family space with it. Say yes and Boston (with the
Bostonia pin and 12 things to do) and Salem MA are loaded in. Everyone else
enters the same code to join.

## The family code

There's no server to check a password, so the code works like a secret link:
your browser turns it into a long hash and uses that as the address of your
family's data. The code itself is never sent to Firebase or stored in the repo.

- Anyone with the code and the site URL can read and change your trips.
- Use a long, unguessable code (8 characters is the minimum, a few random words is better).
- There's no "forgot my code". If it's lost, the data is unreachable, and a different code opens a different, empty space.
- Keep anything sensitive out of pins.
- Optional: in Google Cloud Console → APIs & Services → Credentials, restrict
  the browser API key to the referrer `https://bjohnson316.github.io/*`.
  The key in `index.html` is a public web key by design.

## What works

Everything that doesn't need a paid or private key: trips, a **Dashboard** that
lists every pin, pins on Eat / Play / Explore / Events / Sports, the loved-it
toggle, the map, team lists with season
badges, dark mode, live updates between phones, and offline use (changes made
offline sync when you're back online).

### Free, with no key and no account
- **City photos.** Each trip gets a cover photo from Wikipedia / Wikimedia Commons the first time
  it's opened, saved so every phone shows the same one. The credit is shown
  on the photo. "New photo" cycles through others. Renaming a trip fetches a
  new one. Tip: "Austin TX" finds the right city more reliably than "Austin".
- **Map pin positions.** Restaurants, places and sights you add are looked up on
  OpenStreetMap (limited to about 25 miles around the trip city, and no more
  than one lookup a second). If nothing matches, the pin simply isn't
  placed, and Directions still works. "Place N more on the map" retries.

### The Dashboard tab
The first tab, and where a trip opens. It lists **every pin for the trip in one
place**, grouped as Restaurants, Entertainment, Sights, Events and Games (newest
first), with the same buttons as everywhere else: Directions, the heart, Remove and
the links below. The buttons at the top filter by **Want to go** or **Loved it**
and show the counts. It updates live when anyone in the family pins something.

### Links on every listing
Each restaurant, place and sight links to its own information page where one can be found:
- **Website**: the official site, when OpenStreetMap knows it.
- **Wikipedia**: a matching article, but only if it has the same name **and** is located
  near the trip city, so a same-named place in another country is never linked.
- **Menu** (restaurants), **Link** (a pin that came with its own page, like a live event
  lookup), and **Info** (a Google search for the listing) whenever nothing better is known.

These are found automatically, in the background, the first time you open the Eat, Play,
Explore or Dashboard tab (a list of ten or twelve takes around fifteen seconds, at one
request a second), then **saved for the whole family**, so each listing is only looked up once.
Pins made from a suggestion keep its links. Events and games use the page that came with them.

### The Map tab
A real, zoomable map (no Leaflet logo or flag; only the required map credits). **Satellite** (default) shows aerial imagery with street and
place names on top; **Streets** switches to OpenStreetMap. Your choice is
remembered per phone. With pins, the map fits them all; **with no pins it shows
the trip's city** (its outline is looked up once and saved for everyone), and
"Whole city" always takes you back. Tap a pin for its card and Directions. The
map keeps your zoom and position while you use the rest of the app.

- Satellite imagery and labels come from Esri's free public tile service, which
  needs no key but which Esri lists as a legacy service that could change without
  notice. **If satellite tiles ever stop loading, the map switches to Streets on
  its own and says so.** Streets (OpenStreetMap) is the dependable one.
- Both are credited on the map. Please keep that credit visible.
- The mapping library is Leaflet 1.9.4 (BSD-2 licence, `leaflet-LICENSE.txt`),
  bundled in the repo so nothing loads from a CDN.

### Optional: Claude lists (one shared key)
Turns on "Find restaurants / entertainment / things to do / yearly events" and
the live event and game lookups. **You add the key once and every phone in your
family space uses it automatically. Nobody else types anything.**

1. console.anthropic.com → **Billing** → buy credit (the API is prepaid; $5 is the minimum).
2. **Limits** → set a monthly spend cap.
3. **Settings → Privacy** → turn on **web search** (needed for the live event and game lookups only).
4. **API Keys → Create Key**, copy it (starts with `sk-ant-`).
5. In the app, tap your name at the top of the home screen → **AI suggestions** → paste the key → Continue.

Phones that were signed in before this update show "Unlock AI suggestions"
once. Tap it, enter the family code, and Continue. New phones just type the
code as usual. To replace or remove the key, use the same place. Removing it
turns AI off for everyone.

**How the key is protected.** Before it's saved, the key is encrypted (AES-GCM)
with a secret derived from your family code (PBKDF2), and only the scrambled
text goes into the database. Each phone derives that secret once when the code
is typed, and keeps only the derived secret, never the code or the API key.
That means a screenshot of the Firebase console, an export, or a slip in the
security rules doesn't expose the key.

**What it doesn't protect against.** A phone has to decrypt the key to use it, so
anyone who has the family code can use the key, and could read it with the
browser's developer tools. So:
- Keep the spend cap on, and keep the family code long and private.
- If the code ever gets out, remove the key in the app, delete it in the Anthropic
  console, and make a new one.
- If you'd rather the key never reach phones at all, that takes a small
  server-side proxy, which is what the Firebase upgrade you passed on would have provided.

Lists are AI-generated, so check details before booking; the live lookups link to their sources.

### Still off (needs a private server-side key)
Real photos, ratings and exact menu links for individual restaurants. Directions,
Menu and Info open Google Maps and Google search instead.

## Privacy

Looking up a photo, city, website or map position sends the trip's city name or the pin's
name to Wikipedia or OpenStreetMap from your phone, and viewing the map loads tiles
from OpenStreetMap or Esri (so they see your IP address and the area you're viewing). Claude lists send the
prompt, using the shared key, to Anthropic. Nothing is sent to any of them until you add a trip, add
a pin, or press a "Find…" button. Map positions are © OpenStreetMap
contributors and photos are credited to their authors on Wikimedia Commons.

## Changing things later

- `firebase-bundle.js` is the Firebase SDK in one file. To rebuild it:
  `cd tools/firebase-bundle && npm install && npm run build`, then copy the
  new `firebase-bundle.js` to the repo root.
- After editing any file, bump `CACHE_NAME` in `sw.js` (`jetset-shell-v9` →
  `v10`) so phones pick up the new version instead of the cached one.
- Firebase's free plan (50,000 reads and 20,000 writes a day, 1 GB) is far more
  than a family trip planner uses.

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

Everything that doesn't need a paid or private key: trips, pins on Eat / Play /
Explore / Events / Sports, the loved-it toggle, the map, team lists with season
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

### Optional: Claude lists (your own Anthropic key)
Turns on "Find restaurants / entertainment / things to do / yearly events",
and the live event and game lookups. Lists are saved for the whole family, so
**only one person needs a key.**

1. console.anthropic.com → **Billing** → buy credit (the API is prepaid; $5 is the minimum).
2. **Limits** → set a monthly spend cap.
3. **Settings → Privacy** → turn on **web search** (needed for the live event and game lookups only).
4. **API Keys → Create Key**, copy it (starts with `sk-ant-`).
5. In the app, tap your name at the top of the home screen → **AI suggestions** → paste the key → Continue.

The key is kept in that phone's browser storage only. It is never saved to
your family's data or to GitHub. Anyone who can open developer tools on that
phone could read it, which is why the spend cap matters. "Remove key" is in
the same place. Lists are AI-generated, so check details before booking; the
live lookups link to their sources.

### Still off (needs a private server-side key)
Real photos, ratings and menu links for individual restaurants. Each pin's
Directions and Menu buttons open Google Maps and Google search instead.

## Privacy

Looking up a photo or map position sends the trip's city name or the pin's
name to Wikipedia or OpenStreetMap from your phone. Claude lists send the
prompt to Anthropic. Nothing is sent to any of them until you add a trip, add
a pin, or press a "Find…" button. Map positions are © OpenStreetMap
contributors and photos are credited to their authors on Wikimedia Commons.

## Changing things later

- `firebase-bundle.js` is the Firebase SDK in one file. To rebuild it:
  `cd tools/firebase-bundle && npm install && npm run build`, then copy the
  new `firebase-bundle.js` to the repo root.
- After editing any file, bump `CACHE_NAME` in `sw.js` (`jetset-shell-v4` →
  `v5`) so phones pick up the new version instead of the cached one.
- Firebase's free plan (50,000 reads and 20,000 writes a day, 1 GB) is far more
  than a family trip planner uses.

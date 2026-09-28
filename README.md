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

Everything that doesn't need a secret key: trips, pins on Eat / Play / Explore /
Events / Sports, the loved-it toggle, the map, team lists with season badges,
dark mode, live updates between phones, and offline use (changes made offline
sync when you're back online).

What's off, because it needs a private API key that can't sit in a public page:

- **AI lists** (find restaurants, entertainment, things to do, yearly events),
  **live event and game lookup**, and **real photos/ratings/menus**.
  In their place, each section has a Google Maps or Google search link for that
  category. Find something, then pin it with the "Pin a…" buttons.
- Adding these back later takes a small Firebase Cloud Function to hold the key.

## Changing things later

- `firebase-bundle.js` is the Firebase SDK in one file. To rebuild it:
  `cd tools/firebase-bundle && npm install && npm run build`, then copy the
  new `firebase-bundle.js` to the repo root.
- After editing any file, bump `CACHE_NAME` in `sw.js` (`jetset-shell-v3` →
  `v4`) so phones pick up the new version instead of the cached one.
- Firebase's free plan (50,000 reads and 20,000 writes a day, 1 GB) is far more
  than a family trip planner uses.

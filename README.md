# Jetset Johnsons — GitHub Pages + Firebase version

A static site (nothing to install, no server) that stores the family's trips
in Cloud Firestore. GitHub Pages hosts the files; Firebase holds the shared data.

## Set it up (3 steps)

### 1. Publish the security rules
Firebase console → your project (`jetset-d5503`) → **Firestore Database** →
**Rules** tab → replace everything with the contents of `firestore.rules` →
**Publish**.

Without this step every save fails with "permission denied", because a new
database starts out locked.

### 2. Put these files at the top level of your `trips` repo
Replace what's there. Everything in this folder goes in the repo root, with
`index.html` at the top level, not inside a subfolder.

**Delete these old files from the repo if they're there:** `server.js`,
`app.js`, `package.json`, `package-lock.json`, `api/`, `lib.php`, `data/`,
`.env`, `.env.example`. GitHub Pages can't run them, and they do nothing here.

> **If you ever uploaded a `.env` file to GitHub, delete it now** and treat
> anything that was in it as public. If it held an Anthropic or Google API
> key, revoke that key and make a new one. The repo history keeps old copies
> even after you delete the file.

GitHub → repo **Settings → Pages** → Source: **Deploy from a branch**, branch
`main`, folder `/ (root)`.

### 3. Open the site and create your family space
Go to `https://bjohnson316.github.io/trips/`, enter a family code and your
name. The first time a code is used, the app asks whether to create a new
family space with it — say yes, and Boston and Salem are loaded in for you.
Give the same code to everyone else; they'll join the same space.

## About the family code

There is no server to check a password, so the code works like a secret
link: the browser turns it into a long hash and uses that as the address of
your family's data. The code itself is never sent to Firebase or stored in
the repo.

- **Anyone who has the code and the site URL can read and change your trips.**
- **Pick something long and unguessable** (the app requires at least 8
  characters; a few random words is better). A short or obvious code can be
  guessed.
- There's no "forgot my code". If it's lost, the data is unreachable, and a
  different code opens a different, empty space.
- Don't put anything sensitive in pins. Confirmation numbers are fine;
  passport or card numbers are not.
- Optional hardening: in Google Cloud Console → APIs & Services →
  Credentials, restrict the browser API key to the HTTP referrer
  `https://bjohnson316.github.io/*`. The key in `index.html` is a public
  web key by design, but this stops other sites from using it.

## What works in this version

Everything that doesn't need a secret key: trips, pins for eat / play / see,
plans with times, time zones and reminders, calendar files (`.ics`) and
Google Calendar links, events, sports teams, the map, light/dark mode, and
live updates between family members' phones. It also works offline once
loaded, and changes made offline sync when you're back online.

What's off:

- **AI restaurant/activity suggestions, event and game search, and menu
  lookups.** These would expose an API key in the browser. The Eat, Play and
  Explore tabs use Google Maps search links instead. Adding this back later
  means a small Firebase Cloud Function to hold the key.
- **Real place photos, ratings, and exact map pins.** Same reason. Covers are
  illustrated and map positions are approximate.

## Changing it later

- `firebase-bundle.js` is the Firebase SDK, bundled into one file. To rebuild
  it (for example, to update the SDK): `cd tools/firebase-bundle && npm install && npm run build`,
  then copy the new `firebase-bundle.js` up to the repo root.
- If you change files, bump `CACHE_NAME` in `sw.js` (`jetset-shell-v2` →
  `v3`) so phones pick up the new version instead of the cached one.

## Free-plan limits

Firebase's free plan allows 50,000 document reads and 20,000 writes per day
and 1 GB of storage. A family trip planner uses a tiny fraction of that.

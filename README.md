# Jetset Johnsons — PHP version (no Node.js required)

This is a full rebuild of the self-hosted app in plain PHP, for hosting
accounts that don't have Node.js installed (like a standard shared-hosting
cPanel account — the same one this was built for, after we found it had
zero `ea-nodejs*` packages available).

**No Application Manager, no "Enable Dependencies", no npm, no build step.**
Just PHP files that Apache runs directly — the same way any ordinary PHP
site works. If your hosting account can run WordPress, it can run this.

## Requirements

- PHP 7.4 or newer (PHP 8.x recommended) — check/set this in cPanel's
  **MultiPHP Manager** for your domain.
- The `curl` and `json` PHP extensions enabled — these are on by default on
  virtually every cPanel PHP build. If something errors out mentioning
  `curl_init` or `json_decode`, check **MultiPHP INI Editor** for your domain
  and make sure `curl` and `json` are checked under Extensions.
- `mod_rewrite` and `mod_headers` enabled on Apache — also on by default for
  cPanel/EasyApache accounts.

## What's in here

```
index.html                the whole frontend (identical to the Node version)
sw.js                      service worker (offline shell caching)
manifest.webmanifest
icon-192.png / icon-512.png / apple-touch-icon.png
lib.php                    all backend logic: DB, auth, Claude calls, Places calls, ICS
api/
  index.php                the router — dispatches /api/* requests to lib.php functions
  .htaccess                rewrites /api/anything to index.php
data/
  db.json                  seeded with Boston + Salem + the Bostonia pin
  .htaccess                blocks direct web access to this folder
.htaccess                  blocks direct access to .env and lib.php; small housekeeping
.env.example               copy to .env and fill in
.gitignore
```

## Deploying

1. Upload every file in this zip directly into your domain or subdomain's
   document root (in File Manager or via FTP) — so `index.html` sits right
   at the root, not in a nested subfolder.
2. In File Manager, enable **Show Hidden Files (dotfiles)**, then rename
   `.env.example` to `.env` and fill in at least `FAMILY_CODE`. Add
   `ANTHROPIC_API_KEY` and `GOOGLE_MAPS_API_KEY` whenever you're ready —
   the app works without them, just with fewer features (see the main
   project notes on free vs. paid features).
3. That's it — no install step. Visit your domain. You should see the
   family-code gate immediately.

### If something doesn't work

- **Blank page or 500 error:** check cPanel's **Errors** page (or
  `error_log` in File Manager) for the actual PHP error.
- **"Wrong family code" even though you typed it right:** double-check
  `.env` was saved as `.env`, not `.env.example.txt` or similar — some
  file managers silently add an extension when renaming. Confirm with
  `ls -la` in Terminal if you have it.
- **App loads but every button errors:** open your browser's dev tools →
  Network tab, click a button, and check what `/api/...` request failed
  and with what status code — that'll point at the exact cause.
- **"Failed to save" or a blank response when adding a pin:** the `data/`
  folder needs to be writable by PHP. In File Manager, right-click `data` →
  Permissions, and set it to `755` (or `775` if `755` isn't enough on your
  host) — most shared hosting already sets this correctly on upload, but
  it's the first thing to check if writes fail.
- **`.htaccess` seems to be ignored (rewrite doesn't work):** some very
  locked-down hosts disable `.htaccess` overrides entirely. If `/api/cities`
  in your browser gives a 404 instead of a 401 (wrong code) or JSON, ask
  your host to confirm `AllowOverride All` (or at least `AllowOverride
  FileInfo`) is set for your account.

## Keeping your family's data safe on redeploy

`data/db.json` is where every pin, plan, and cached list lives once your
family starts using the app. **If you re-upload files to update the app,
don't overwrite `data/db.json`** unless you mean to reset it — just
re-upload `index.html`, `sw.js`, `lib.php`, and the `api/` folder.

## Differences from the Node.js version

- **Enrichment is synchronous, not backgrounded.** When you add an eat/play/see
  pin, the Google Places lookup (and, for restaurants, the menu-link search)
  happens before the response comes back — so saving a pin can take a couple
  of extra seconds when those keys are configured, instead of finishing
  silently in the background. Functionally the same result, just a different
  wait.
- **The photo proxy uses a query parameter** (`/api/photo?ref=...`) instead
  of a path segment, because Google's photo reference contains literal
  slashes that don't survive being embedded in a URL path segment reliably
  across different Apache configurations.
- Everything else — every screen, every button, every pin field, the ICS
  export, the map, the sports database — works identically to the Node
  version, because the frontend (`index.html`) is the exact same file.

# OwnTone YouTube Streaming Dashboard

A dark-mode, single-page PHP + vanilla JS dashboard for a home server running
[OwnTone](https://github.com/owntone/owntone-server). Search or paste a
YouTube URL, and it plays through OwnTone by piping audio into a named pipe.

## How it works

- Type a search term or paste a YouTube URL into the single input box.
  - A YouTube URL resolves its title/thumbnail/channel and shows it as a
    single result card — it doesn't auto-play.
  - Plain text triggers a `yt-dlp` search (30 results, one scrollable list).
- Clicking Play kills any in-flight stream, then launches a detached
  `yt-dlp | ffmpeg -re` pipeline that writes WAV audio into OwnTone's named
  pipe at real playback speed (not a burst — OwnTone needs time to attach as
  a live reader).
- The backend resolves the pipe's current OwnTone track id and tells OwnTone
  (via its REST API) to clear the queue, add that track, and start playback.
- The UI stays in sync with OwnTone over its WebSocket API — no polling.
- A "Playlist" tab lets you save/remove search results; both the playlist
  and the last search are cached server-side so a page refresh or a
  different browser sees the same thing.

## Requirements

- PHP (via Apache/Nginx + php-fpm)
- `yt-dlp`, `ffmpeg`, `jq` on `PATH` for the PHP process user
- A running OwnTone server on the same host, reachable at `127.0.0.1:3689`
- An OwnTone library pipe source already configured, e.g.
  `/opt/docker/owntone/pipes/youtube.fifo`

## Project layout

```
public/   web root — point your vhost's DocumentRoot here
  index.php     page shell
  backend.php   search/play API (POST action=search|play)
  app.js        vanilla JS: input handling, pagination, OwnTone sync
  style.css     dark-mode layout
tests/    PHP/Node unit tests for the pure/testable logic
docs/     design spec and implementation plan
```

Only `public/` needs to be web-reachable — point your Apache/Nginx
vhost's `DocumentRoot` (or php-fpm pool root) at `public/`, not the repo
root, so `tests/`, `docs/`, and `.git` are never served over HTTP.

## Setup

1. Set your web server's document root to `public/`.
2. Confirm the constants at the top of `public/backend.php` match your
   environment:
   - `OWNTONE_BASE` — OwnTone's base URL (default `http://127.0.0.1:3689`)
   - `YOUTUBE_FIFO_PATH` — host path to the named pipe (for writing audio)
   - `OWNTONE_PIPE_DIRECTORY` — the same pipe's path as OwnTone itself sees
     it (e.g. inside its container), used to look up the pipe's track id
   - `YOUTUBE_FIFO_MATCH` — substring used to find the pipe's library track
   - `PLAYLIST_FILE` / `LAST_SEARCH_FILE` — **must be an absolute path
     outside your web server's document root.** If your document root
     covers more than this app's own directory (e.g. a shared multi-app
     root like `/var/www`), a path such as `__DIR__ . '/../data'` can land
     right back inside it and become directly downloadable over HTTP —
     verify with `curl http://<host>/<relative-path-to-file>` and confirm
     it 404s.
3. In OwnTone's config, add the pipe's directory to `directories` so it's
   indexed as a library track, and add `<pipe>.metadata` if you want real
   metadata (not implemented here — see the design doc).
4. Open the site in a browser on the same LAN as the server.

## Known host-specific gotchas

Found and fixed against a real deployment — worth re-checking if you
redeploy elsewhere:

- **OwnTone's WebSocket** listens on a *separate* port from its HTTP API
  (returned by `GET /api/config`'s `websocket_port`), at the root path, and
  requires the `notify` subprotocol — `app.js` discovers this dynamically,
  it isn't hardcoded to port 3689.
- **`yt-dlp`/`ffmpeg` must actually be installed** and on `PATH` for the
  PHP-FPM user specifically (not just your login shell) — `ffmpeg -re` is
  required so OwnTone has time to attach as a live pipe reader before the
  whole file has already been written and closed.
- **Search latency**: extracting full metadata for 30 videos can exceed a
  reverse proxy's default timeout (nginx's default is 60s). Give
  `backend.php`'s location block its own longer `fastcgi_read_timeout`.
  Search also touches the CPU hard enough on low-power hardware to
  temporarily starve other services (including SSH) — avoid triggering
  concurrent searches.
- **Directory traversal permissions**: a file can be `0777` and still be
  unreadable/unwritable by the PHP process if *any parent directory* in the
  path lacks execute/search permission for that user — check the whole
  chain with `namei -l <path>`, not just the file itself.

## Tests

```bash
php tests/backend_test.php
node tests/app_test.js
```

Both exercise the pure/testable helper functions only — the shell/network
integration paths require a live host and are covered by the manual
verification checklist above.

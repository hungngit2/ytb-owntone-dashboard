# OwnTone YouTube Streaming Dashboard

A dark-mode, single-page PHP + vanilla JS dashboard for a home server running
[OwnTone](https://github.com/owntone/owntone-server). Search or paste a
YouTube URL, and it plays through OwnTone by piping audio into a named pipe.

## How it works

- Type a search term or paste a YouTube URL into the single input box.
  - A YouTube URL plays immediately.
  - Plain text triggers a `yt-dlp` search (30 results, paginated 5 at a time).
- Playing a track kills any in-flight stream, then launches a detached
  `yt-dlp | ffmpeg` pipeline that writes WAV audio into OwnTone's named pipe.
- The backend resolves the pipe's current OwnTone track id and tells OwnTone
  (via its REST API) to clear the queue, add that track, and start playback.
- The UI stays in sync with OwnTone over its WebSocket API — no polling.

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
   - `YOUTUBE_FIFO_PATH` — path to the named pipe
   - `YOUTUBE_FIFO_MATCH` — substring used to find the pipe's library track
3. Open the site in a browser on the same LAN as the server.

## Known items to verify on your host

These couldn't be exercised in development (no live OwnTone/yt-dlp/ffmpeg
available there) — see
[`docs/superpowers/plans/2026-07-09-owntone-youtube-dashboard.md`](docs/superpowers/plans/2026-07-09-owntone-youtube-dashboard.md)
for the full checklist:

- **WebSocket endpoint** — `public/app.js` connects to
  `ws://<host>:3689/api/v6/ws`. Confirm this matches your OwnTone version's
  actual WebSocket port/path/subprotocol; if it's wrong, playback still works
  but the live UI (progress, volume, play/pause) won't update.
- **Search latency** — searching 30 YouTube results can take longer than
  PHP's default `max_execution_time`. Raise the timeout for `public/backend.php`
  if searches are timing out.
- **Pipe track resolution timing** — if OwnTone only registers the fifo as a
  library track after first write (rather than at startup), the very first
  play attempt could race against that registration.

## Tests

```bash
php tests/backend_test.php
node tests/app_test.js
```

Both exercise the pure/testable helper functions only — the shell/network
integration paths require a live host and are covered by the manual
verification checklist above.

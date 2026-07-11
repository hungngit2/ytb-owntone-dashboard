# OwnTone YouTube Streaming Dashboard

A dark-mode, single-page PHP + vanilla JS dashboard for a home server running
[OwnTone](https://github.com/owntone/owntone-server). Search or paste a
YouTube URL, save songs into named playlists, and play through OwnTone by
piping audio into a named pipe.

## How it works

- Type a search term or paste a YouTube URL into the single input box.
  - A YouTube URL resolves its title/thumbnail/channel (via YouTube's
    oEmbed endpoint) and shows it as a single result card — it doesn't
    auto-play.
  - Plain text searches YouTube's official **Data API v3 directly from the
    browser** (30 results). This runs entirely client-side — the backend is
    never involved in search, so it can't load the server.
- Clicking Play kills any in-flight stream, tells OwnTone to queue and start
  that track, then launches a detached `yt-dlp | ffmpeg -re` pipeline that
  writes WAV audio into OwnTone's named pipe at real playback speed (not a
  burst — OwnTone needs time to attach as a live reader). A second pipe
  carries real title/artist/duration/artwork metadata to OwnTone itself
  (shairport-sync's pipe metadata protocol), so OwnTone's own "now playing"
  shows more than the generic pipe filename.
- The UI stays in sync with OwnTone over its WebSocket API for play/pause/
  volume state, with a local 1-second ticker interpolating the progress bar
  between syncs (OwnTone's WebSocket only pushes on state changes, not a
  per-second heartbeat).
- A "Playlist" tab supports multiple **named** playlists: create one, add
  search results to an existing or new one, remove items, and "Play All"
  (which starts the first item; Prev/Next then step through it). The
  currently-playing row is highlighted, matched by YouTube video id (not a
  raw URL string, since the same video can appear as `watch?v=`, `youtu.be/`,
  or with extra query params depending on where it came from).
- **Auto-play-next survives the browser being closed.** Playing anything
  persists the whole list + starting index server-side
  (`queue_state.json`), and a separate always-running process,
  `bin/queue-daemon.php` (see Setup), polls OwnTone every 2s and starts the
  next item itself once the current one actually finishes — no browser tab
  needs to stay open for this to work. A shuffle toggle changes what
  "next" means (random, never repeating the current item) and can be
  flipped mid-playlist without interrupting what's currently playing. The
  next sequential track is also pre-downloaded in the background while
  the current one plays (see "Preloading the next track" below), so
  switching is close to instant instead of waiting on yt-dlp each time.
- The last search and all playlists are cached server-side (as JSON files)
  so a page refresh or a different browser sees the same thing.

## Requirements

- PHP (via Apache/Nginx + php-fpm)
- `yt-dlp` and `ffmpeg` on `PATH` for the **PHP-FPM process user**
  specifically (not just your login shell) — used only for playback
  (download + transcode + duration lookup), never for search
- A running OwnTone server on the same host, reachable at `127.0.0.1:3689`
- An OwnTone library pipe source already configured (see Setup below)
- A free **YouTube Data API v3** key (see Setup) — required for search to
  work at all, since search runs against Google's API, not `yt-dlp`

## Project layout

```
public/   web root — point your vhost's DocumentRoot here
  index.php            page shell
  backend.php          play/playlist/queue/cache API (POST action=...)
  app.js               vanilla JS: search, playback, playlists, OwnTone sync
  style.css            dark-mode layout
  config.js            YouTube API key (gitignored — you create this)
  config.example.js     ^ template for the above, committed
bin/
  queue-daemon.php      standalone process: polls OwnTone, auto-advances the queue
docs/
  queue-daemon.service  systemd unit for bin/queue-daemon.php
  (design spec and implementation plan)
tests/    PHP/Node unit tests for the pure/testable logic
```

Only `public/` needs to be web-reachable — point your Apache/Nginx vhost's
`DocumentRoot` (or php-fpm pool root) at `public/`, not the repo root, so
`tests/`, `docs/`, and `.git` are never served over HTTP.

## Setup

### 1. YouTube Data API key (required for search)

1. Go to https://console.cloud.google.com/apis/library/youtube.googleapis.com
   (create a free Google Cloud project if you don't have one) and click
   **Enable**.
2. **APIs & Services → Credentials → Create Credentials → API key**.
3. Restrict the key (HTTP referrer or IP) — it's visible to anyone who
   views the page source.
4. Copy `public/config.example.js` to `public/config.js` and paste your key
   into it. **Never commit `config.js`** — it's gitignored on purpose.

### 2. Web server

Set your document root to `public/`, and give `backend.php`'s location its
own longer `fastcgi_read_timeout` — even without the old `yt-dlp` search
path, `action=play` still does a duration lookup that can take 10-15s.

### 3. `public/backend.php` constants

| Constant | Meaning |
| --- | --- |
| `OWNTONE_BASE` | OwnTone's base URL (default `http://127.0.0.1:3689`) |
| `YOUTUBE_FIFO_PATH` | Host path to the named pipe, for writing audio. **Must be a path the PHP-FPM user can both create files in and traverse every parent directory of** — see the permissions gotcha below. Currently `/mnt/appsrv/ytb-owntone/pipes/youtube.fifo` on the deployed host, deliberately *not* under the OwnTone docker-compose directory (see gotchas) |
| `OWNTONE_PIPE_DIRECTORY` | The same pipe's path as **OwnTone itself** sees it (e.g. inside its container/docker volume mount) — used to look up the pipe's library track id via OwnTone's API. Distinct from `YOUTUBE_FIFO_PATH` since they can differ (host path vs. container path) |
| `YOUTUBE_FIFO_MATCH` | Substring used to find the pipe's library track by path |
| `PLAYLIST_FILE` / `LAST_SEARCH_FILE` | **Must be an absolute path outside your web server's document root.** If your document root covers more than this app's own directory (a shared multi-app root), a path like `__DIR__ . '/../data'` can land right back inside it and become directly downloadable over HTTP — verify with `curl http://<host>/<relative-path>` and confirm it 404s. Currently `/mnt/appsrv/ytb-owntone/data/*.json` on the deployed host |
| `QUEUE_STATE_FILE` | Persisted "what's playing" (queue items + current index + shuffle), read by `bin/queue-daemon.php` (see below) so auto-advance works with no browser open. Same outside-the-document-root rule as above. Currently `/mnt/appsrv/ytb-owntone/data/queue_state.json` |
| `AUDIO_CACHE_DIR` | Holds at most one pre-downloaded "next track" audio file, used to make Next/auto-advance skip yt-dlp's resolve+download step (see "Preloading the next track" below). Currently `/mnt/appsrv/ytb-owntone/cache` |

All three (`pipes/`, `data/`, `cache/`) live together under one parent
directory (`/mnt/appsrv/ytb-owntone/` on the deployed host) and need to exist and
be `chown`'d to the PHP-FPM/web server user *before first use* — if the
parent directory's own parent (e.g. `/mnt/appsrv`) isn't writable by that
user, PHP's own `mkdir()` fallback will silently fail and the feature it
backs (playlists, auto-advance, preload) will just quietly not work. E.g.:

```bash
mkdir -p /mnt/appsrv/ytb-owntone/pipes /mnt/appsrv/ytb-owntone/data /mnt/appsrv/ytb-owntone/cache
chown -R www-data:www-data /mnt/appsrv/ytb-owntone
```

### 4. OwnTone configuration

- Add the pipe's directory to OwnTone's `directories` config setting so
  it's indexed as a library track (a fifo sitting on disk isn't enough —
  it has to be inside a path OwnTone actually scans).
- No separate `.metadata` pipe setup needed on your end — `backend.php`
  creates `<YOUTUBE_FIFO_PATH>.metadata` itself on first play if missing.

### 5. Auto-play-next daemon (works even with the browser closed)

`bin/queue-daemon.php` polls OwnTone every 2s and starts the next queued
item itself once the current one finishes — this is a separate always-running
process, not something any browser tab drives.

1. Copy `bin/queue-daemon.php` wherever you deploy `backend.php` (it looks
   for `backend.php` either as a `bin/` + `public/` sibling, or flat
   alongside itself — whichever layout you use).
2. Copy `docs/queue-daemon.service` to `/etc/systemd/system/`, adjusting
   `ExecStart` to match your deployed path.
3. Enable and start it:
   ```bash
   systemctl daemon-reload
   systemctl enable queue-daemon.service
   systemctl start queue-daemon.service
   ```
4. `systemctl status queue-daemon.service` should show `Active: active (running)`.

### 6. Open the site

Open it in a browser on the same LAN as the server.

## Known host-specific gotchas

Found and fixed against a real deployment — worth re-checking if you
redeploy elsewhere:

- **OwnTone's WebSocket** listens on a *separate* port from its HTTP API
  (returned by `GET /api/config`'s `websocket_port`), at the root path, and
  requires the `notify` subprotocol — `app.js` discovers this dynamically,
  it isn't hardcoded to port 3689.
- **Directory traversal permissions can silently reset.** A file can be
  `0777` and still be unreadable/unwritable by the PHP process if *any
  parent directory* in the path lacks execute/search permission for that
  user — check the whole chain with `namei -l <path>`, not just the file
  itself. This was observed to happen (docker compose recreation reset a
  parent directory's permissions), which is exactly why `YOUTUBE_FIFO_PATH`
  now lives under a path owned directly by the web server user, with no
  dependency on a Docker-managed directory's permissions.
- **Named pipe writes block until a reader attaches.** The metadata pipe
  write is wrapped in `timeout 5` for this reason — an unguarded write can
  hang forever if OwnTone's reader doesn't reconnect in time, and repeated
  play attempts would each leak another stuck process.
- **`prgr` (progress) metadata**: OwnTone's parser rejects the whole
  progress item if any of its three RTP-timestamp fields parses to exactly
  zero — use `1` as a nonzero reference point for start/pos, not `0`.
- **Search must never touch `yt-dlp`.** A previous version ran
  `yt-dlp --dump-json` server-side for search (30 videos of full metadata
  extraction per query) and this was heavy enough to freeze the host under
  normal use — confirmed multiple times, requiring a physical power-cycle.
  Search now runs entirely in the browser against the YouTube Data API.
  Don't reintroduce a server-side search path without a very good reason.
- **No seeking on pipe-based playback.** OwnTone can't seek within a named
  pipe source — there's no file to seek into, just a live stream PHP is
  writing in real time — so the progress bar is display-only by design,
  not a missing feature. If real seeking is ever wanted, it would require
  a fundamentally different approach (e.g. downloading to a real file
  OwnTone can seek within, rather than piping live).
- **"Finished" detection needs slack, not an exact match.** The queue
  daemon decides a track ended when OwnTone reports paused *and* progress
  is within 4s of the reported duration — not 1s. The duration we send is
  yt-dlp's rounded-to-the-second estimate, and the actual streamed/decoded
  audio has been observed ending ~2s short of it; too tight a window means
  finished tracks never get detected and playback just stalls forever.
- **Prev/Next and the playing-item highlight are driven by server state,
  not browser memory.** `app.js` re-fetches `action=queue_state` on every
  websocket tick (piggybacking on OwnTone's own event cadence) instead of
  tracking "what's playing" locally — local-only state goes stale the
  moment the page is refreshed or the daemon advances the track with no
  browser open at all.

### Preloading the next track

While a track plays, the backend pre-downloads the *next sequential* item
(via `maybe_preload_next` in `backend.php`) into `AUDIO_CACHE_DIR`, keyed
by video id. When that track's turn comes, `play_url` skips yt-dlp
entirely and streams from the cached file instead (`cat file | ffmpeg -re`)
— removing yt-dlp's resolve+download latency from the critical path of
pressing Next or auto-advancing. Only one file is ever cached at a time; a
stray/abandoned preload is cleared out (but never the file the currently-
playing pipeline might have just started reading) the next time a new
preload kicks off. Shuffle mode has no fixed "next" to preload, so it's
skipped there — the daemon only picks the random next track once the
current one actually finishes.

## Tests

```bash
php tests/backend_test.php
node tests/app_test.js
```

Both exercise the pure/testable helper functions only — the shell/network
integration paths require a live host and are covered by manual
verification against the real OwnTone instance.

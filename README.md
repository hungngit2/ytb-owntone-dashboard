# OwnTone YouTube Streaming Dashboard

A dark-mode, single-page PHP + vanilla JS dashboard for a home server running
[OwnTone](https://github.com/owntone/owntone-server). Search or paste a
YouTube URL, save songs into named playlists, and play through OwnTone —
or entirely in the browser, without OwnTone at all, via a "Local" mode
toggle.


## How it works

- Type a search term or paste a YouTube URL into the single input box.
  - A single-video URL resolves its title/thumbnail/channel (via
    YouTube's oEmbed endpoint) and shows it as a single result card — it
    doesn't auto-play.
  - A **playlist URL** (`youtube.com/playlist?list=...`) resolves every
    video in it into the results list, same as a text search — paginated
    via the Data API (up to 250 videos, 5 pages), so Play/Save/Play All
    all work on it unchanged. A video URL that merely happens to be
    playing *within* a playlist context (`watch?v=X&list=Y`) is still
    just resolved as the one video, not auto-imported as a playlist.
  - Plain text searches YouTube's official **Data API v3 directly from the
    browser** (30 results). Both this and the playlist import run entirely
    client-side — the backend is never involved, so it can't load the server.
- **OwnTone / Local mode toggle** (top-right of the hero, next to the
  status indicators): switches between the two ways this app can actually
  play audio.
  - **OwnTone mode** (default): the backend queues the track on your
    OwnTone server, which does the actual decoding/output — playback keeps
    going through whatever real speakers/AirPlay outputs OwnTone is
    configured with, independent of this browser tab.
  - **Local mode**: the browser plays the track directly itself, via a
    plain `<audio>` element pointed at a backend-resolved direct stream
    URL — no OwnTone involved at all. Useful when OwnTone isn't reachable,
    or you just want to listen through the device you're using right now.
    Its own queue, shuffle/repeat, and auto-advance state persist to
    `localStorage` (see `LS_KEYS` in `app.js`) so they survive a reload,
    but this mode has no server-side daemon — auto-advance only happens
    while this tab stays open.
- Clicking Play resolves the video to a real playable URL server-side
  (`resolve_direct_stream_url` in `backend.php`, via `yt-dlp -g`) and hands
  that straight to OwnTone as a direct HTTP-stream queue item — no local
  transcoding pipeline needed in the common case. If that fails (a CDN
  403, or OwnTone can't open it), it falls back to the older path: a
  detached `yt-dlp | ffmpeg -re` pipeline writing WAV audio into a named
  pipe OwnTone reads as a library track, with a second pipe carrying
  shairport-sync-style metadata. The hero shows a small **FIFO badge**
  whenever a track is playing through this fallback, so it's obvious which
  path is active.
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
- **Auto-play-next survives the browser being closed** (OwnTone mode only).
  Playing anything persists the whole list + starting index server-side
  (`queue_state.json`), and a separate always-running process,
  `bin/queue-daemon.php` (see Setup), polls OwnTone every 2s and starts the
  next item itself once the current one actually finishes — no browser tab
  needs to stay open for this to work. An **auto-next toggle** (the
  play/pause-icon switch next to the transport controls) turns this off
  entirely when you don't want the queue to advance on its own; it's
  persisted the same way (`auto_advance` in `queue_state.json`, or
  `localStorage` in Local mode). A shuffle toggle changes what "next"
  means (random, never repeating the current item) and can be flipped
  mid-playlist without interrupting what's currently playing. A repeat
  toggle (off / repeat-all / repeat-one, cycled by one button) changes what
  happens once the queue runs out: stop (off), wrap back to the first item
  (all), or replay the same track indefinitely (one) — all three toggles
  are persisted server-side so `bin/queue-daemon.php` honors them with no
  browser involved. The next sequential track is also pre-downloaded in
  the background while the current one plays (see "Preloading the next
  track" below), so falling back to the fifo path is close to instant
  instead of waiting on yt-dlp each time.
- The last search and all playlists are cached server-side (as JSON files)
  so a page refresh or a different browser sees the same thing.
- **The now-playing title scrolls (marquee) instead of truncating** when
  it doesn't fit the hero card, and **the currently-playing row
  auto-scrolls into view** in the results/playlist list whenever the
  playing track changes (not on every periodic sync, so it doesn't fight
  you scrolling to browse other results while something plays).
- Clicking the **hero thumbnail** opens the resolved direct CDN audio URL
  in a new tab (bypassing OwnTone entirely); clicking the **track title**
  opens the regular YouTube watch page instead.

## Requirements

- PHP (via Apache/Nginx + php-fpm)
- `yt-dlp` and `ffmpeg` on `PATH` for the **PHP-FPM process user**
  specifically (not just your login shell) — used for resolving direct
  stream URLs, the fifo fallback pipeline, and duration lookups; never for
  search
- A running OwnTone server on the same host, reachable at `127.0.0.1:3689`
  — the fifo fallback also needs an OwnTone library pipe source configured
  (see Setup below); the direct-stream path doesn't need any OwnTone-side
  config beyond a normal working install
- A free **YouTube Data API v3** key (see Setup) — required for search to
  work at all, since search runs against Google's API, not `yt-dlp`

## Project layout

```
public/   web root — point your vhost's DocumentRoot here
  index.php               page shell (also runs enforce_dashboard_auth())
  backend.php             play/playlist/queue/cache/OwnTone-proxy API (POST action=...)
  app.js                  vanilla JS: search, playback, playlists, OwnTone sync, Local mode
  style.css               dark-mode layout
  config.js               YouTube API key (gitignored — you create this)
  config.example.js        ^ template for the above, committed
  owntone-auth.php        OwnTone basic auth creds, if enabled (gitignored — you create this)
  owntone-auth.example.php ^ template for the above, committed
  dashboard-auth.php      this app's own login creds, if enabled (gitignored — you create this)
  dashboard-auth.example.php ^ template for the above, committed
bin/
  queue-daemon.php      standalone process: polls OwnTone, auto-advances the queue
docs/
  queue-daemon.service  systemd unit for bin/queue-daemon.php
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
Also add `fastcgi_ignore_client_abort on;` to that same location block —
see the "Resilience" section below for why this matters (a page reload
can otherwise cut a play/stop/seek request short mid-lock).

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

### 6. OwnTone basic auth (optional)

If you turn on basic auth in OwnTone's own web interface (Settings > Web
Interface), `backend.php` needs those credentials to keep talking to it —
the browser never sees them; only `backend.php`'s server-side curl calls do.

1. Copy `public/owntone-auth.example.php` to `public/owntone-auth.php` and
   fill in `OWNTONE_AUTH_USERNAME`/`OWNTONE_AUTH_PASSWORD`.
2. That covers the JSON API, artwork, and `stream.mp3` — all proxied
   through `backend.php`. OwnTone's websocket (used for live play/pause/
   volume updates) is a direct browser connection that can't carry a
   custom `Authorization` header, so it needs its own way to authenticate
   if you want it reachable outside your LAN.

### 7. This app's own login (optional)

`enforce_dashboard_auth()` (called from both `index.php` and
`backend.php`) can require HTTP Basic Auth for the dashboard itself.

1. Copy `public/dashboard-auth.example.php` to `public/dashboard-auth.php`
   and fill in `DASHBOARD_AUTH_USERNAME`/`DASHBOARD_AUTH_PASSWORD`.
2. By default this only applies to clients whose IP **isn't** private/LAN
   (10/8, 172.16/12, 192.168/16, 127/8, `::1`) — so it's normally invisible
   on your own network and only kicks in for remote/public access. Set
   `DASHBOARD_FORCE_AUTH_FOR_LOCAL` to `true` to require it from everyone,
   LAN included.
3. This only covers `index.php`/`backend.php` — plain static assets
   (`app.js`, `style.css`, `config.js`) are served straight by your web
   server without going through PHP, so they aren't covered by this check.

### 8. Open the site

Open it in a browser on the same LAN as the server.

## Known host-specific gotchas

Found and fixed against a real deployment — worth re-checking if you
redeploy elsewhere:

- **OwnTone's WebSocket** listens on a *separate* port from its HTTP API
  (returned by `GET /api/config`'s `websocket_port`), at the root path, and
  requires the `notify` subprotocol — `app.js` discovers this dynamically,
  it isn't hardcoded to port 3689. It also has no built-in reconnect: if
  the connection ever drops (network blip, OwnTone restart), `app.js` now
  retries with backoff (capped at 30s) instead of silently going stale
  until the page is manually reloaded — plus a 15s polling fallback as a
  second line of defense either way.
- **Pausing a pipe-based track resets OwnTone's own progress counter to
  ~0, and it never recovers on its own.** Verified live by process ID:
  the same `ffmpeg`/`yt-dlp` pipeline keeps running unchanged across
  pause/resume (the audio itself isn't restarting), but OwnTone's reported
  `item_progress_ms` drops to ~0 the instant you pause and starts counting
  up from there again on resume — a display/tracking quirk specific to
  this pipe data source, not a real restart. `app.js` corrects for it with
  its own offset (`progressOffsetSeconds`, captured at pause time from the
  locally-interpolated position and re-applied on top of whatever OwnTone
  reports), rather than trusting OwnTone's number directly. This only
  covers pause/resume through this app's own button — pausing via some
  other OwnTone client isn't tracked and would still show the raw
  (wrong) position.
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
  The one deliberate exception is Mix/Radio playlists (`list=RD...`,
  `handle_resolve_mix_playlist`) — the Data API can't look those up at
  all, and `yt-dlp --flat-playlist` (no per-video extraction, capped via
  `--playlist-end`/`MIX_PLAYLIST_MAX_ITEMS`) is far cheaper than the old
  `--dump-json` search path, but it's still real server-side yt-dlp usage
  so keep it that narrow.
- **Wrapping a bare `yt-dlp` in `timeout` breaks under php-fpm's
  `shell_exec`, even though bare `yt-dlp` alone works fine.** Confirmed
  live: `shell_exec('yt-dlp --version')` succeeds, and the shell's own
  `$PATH` (read via `shell_exec('echo $PATH')`) does list yt-dlp's
  directory, but `shell_exec('timeout 20 yt-dlp ...')` fails with exit
  127 ("failed to run command 'yt-dlp': No such file or directory") —
  `timeout`'s own child-process exec doesn't resolve the bare name the
  same way. `YTDLP_BIN`/`TIMEOUT_BIN` (absolute paths, from `which
  yt-dlp`/`which timeout`) sidestep it. None of the other yt-dlp
  invocations in this file hit this, since none of them wrap yt-dlp in
  `timeout` — only `build_ytdlp_flat_playlist_cmd` does, since it's the
  only synchronous (not fire-and-forget/backgrounded) yt-dlp call.
- **A pasted playlist/video URL has to reach the same "save to last
  search" path as a text search, or it silently isn't restorable on
  reload.** `resolveUrlToResult` (single pasted video) was missing the
  `cacheLastSearch()` call that `runSearch`/`runPlaylistImport` both make
  — fixed by adding it there too.
- **A `watch?v=X&list=Y` URL is still a playlist**, not just a single
  video played in a playlist context — `isYoutubePlaylistUrl` used to
  only match `youtube.com/playlist?list=X` pages and treated every
  `watch?v=...&list=...` link as a lone video, silently dropping the rest
  of the playlist. It now matches on the presence of a `list=` param
  regardless of path, via `extractYoutubePlaylistId`, with Mix/Radio ids
  (`list=RD...`) carved out to the separate resolver above.
- **Seeking only works once a track is fully cached.** OwnTone can't seek
  within a live pipe stream — only a real file on disk is seekable. See
  "Preloading the next track" below for how the progress bar becomes
  draggable once the background download finishes.
- **"Finished" detection needs slack, not an exact match — and a second,
  independent signal.** The queue daemon primarily decides a track ended
  when OwnTone reports paused *and* progress is within 4s of the reported
  duration — not 1s, since the duration we send is yt-dlp's rounded-to-
  the-second estimate and the actual streamed/decoded audio routinely
  ends a couple of seconds short of it. But for *some* videos the gap is
  bigger than 4s (codec/container-dependent), and the daemon got stuck
  paused with several seconds still "remaining" by that estimate,
  never advancing. `queue_should_advance()` now also treats "our own
  ffmpeg pipeline process has already exited on its own" as an
  independent, duration-estimate-agnostic finished signal (requires
  progress > 0, so a track that never actually started isn't mistaken
  for finished) — see `is_pipeline_running()`.
- **A bare `ffmpeg`/`yt-dlp` pkill/pgrep pattern isn't safe on a shared
  host.** This box also runs Jellyfin, which spawns its own long-running
  `ffmpeg` processes (transcoding, thumbnail generation) — a plain
  `pkill -f ffmpeg` would also kill Jellyfin's unrelated processes on
  every play/stop/seek, and a plain `pgrep -f ffmpeg` would report our
  own pipeline as "still running" almost permanently regardless of its
  real state (confirmed live: `is_pipeline_running()` returned `true`
  with zero of our own processes running, purely from Jellyfin's).
  `OUR_FFMPEG_PATTERN` matches a substring unique to our own ffmpeg
  invocation (`wav -ar 44100 -ac 2 pipe:1`) instead.
- **Prev/Next and the playing-item highlight are driven by server state,
  not browser memory.** `app.js` re-fetches `action=queue_state` on every
  websocket tick (piggybacking on OwnTone's own event cadence) instead of
  tracking "what's playing" locally — local-only state goes stale the
  moment the page is refreshed or the daemon advances the track with no
  browser open at all.

### Preloading the next track

While a track plays, the backend also pre-downloads a full copy of the
*current* track and the *next sequential* item (via
`ensure_current_track_cached`/`maybe_preload_next` in `backend.php`) into
`AUDIO_CACHE_DIR`, keyed by video id. Once cached:

- Playing that track skips yt-dlp entirely and has `ffmpeg` read the file
  directly (`ffmpeg -re -i <file>`) — removing yt-dlp's resolve+download
  latency from the critical path of pressing Next or auto-advancing.
- `action=queue_state` reports `seekable: true` and the progress bar
  becomes draggable; `action=seek` restarts the pipe with `ffmpeg -ss` to
  jump to that position (only a real file can be seeked, not a live
  yt-dlp stream), reporting the seek target in the `prgr` metadata so
  OwnTone's own position display stays consistent across the restart.

Cache files persist (not deleted after one use) so repeat seeks don't
re-download — cleanup happens by only ever keeping the current + next
track's files and clearing anything else the next time a preload kicks
off. Shuffle mode has no fixed "next" to preload, so it's skipped there —
the daemon only picks the random next track once the current one actually
finishes.

## Resilience

### Auto-recovery from a frozen host

This is a resource-constrained ARM board (4 cores / ~971MB RAM) and it
has genuinely frozen before under heavy `yt-dlp`/`ffmpeg` load, requiring
a physical power-cycle. Root cause (confirmed via `dmesg`): several
concurrent `yt-dlp`+`ffmpeg` processes piled up faster than a previous
one could be signaled to exit, exhausting memory until the kernel OOM-killed
something and the host became unresponsive over SSH — while the kernel
itself stayed alive (it was already running its own thread to keep the
hardware watchdog petted, so the hardware timer never fired to help).

Three layers now guard against this:

1. **`with_playback_lock()` in `backend.php`** wraps the entire
   stop-existing-then-launch-new sequence (in `play_url`/`handle_stop`) in
   a `flock()` on `PLAYBACK_LOCK_FILE` — a real cross-process mutex.
   `stop_existing_pipeline()` alone (signal + wait up to 2s, escalating to
   `SIGKILL`, for the old process to actually exit) only serializes
   *within one PHP request*; rapid Play clicks each arrive as a *separate*
   PHP-FPM worker process with no shared state, so they could still race
   each other's stop-then-launch and stack up processes — confirmed live,
   it froze a second time even with the wait-loop in place. The lock
   itself waits up to 25s (a single play can legitimately take 15-20s on
   this host: oEmbed + yt-dlp's duration lookup over a slow network path)
   before giving up and returning a "try again" error, rather than
   blocking a PHP-FPM worker indefinitely.
2. **The `watchdog` package** (`apt install watchdog`) takes over
   `/dev/watchdog` from the kernel's own auto-pet thread, so a genuine
   full hang now triggers a real hardware reset. It's also configured
   with load-average thresholds (`max-load-1 = 24`, `max-load-5 = 20` in
   `/etc/watchdog.conf` — well above the ~6 load average seen during the
   last freeze, so ordinary heavy use never trips it) to proactively
   reboot if the host is clearly thrashing rather than just transiently
   busy. Verify it's active with `systemctl status watchdog.service` and
   `wdctl /dev/watchdog` (the latter will report "cannot read" if the
   daemon already holds the device — that's expected, not a failure;
   confirm ownership instead with `fuser /dev/watchdog`).
3. **The thumbnail shows a loading spinner while a track is "cooking"**
   (queued but not yet confirmed producing audio) — see `updateCookingIndicator`
   in `app.js`. This isn't primarily a resilience mechanism, but it exists
   because of the same discovery: plays can take much longer to actually
   start than the UI previously implied, so the app needs to show that
   honestly instead of looking broken or idle in the meantime.

The lock in (1) still wasn't the whole story: it only bounds the *live*
pipeline swap. `ensure_current_track_cached()` and `maybe_preload_next()`
both kick off *background* yt-dlp downloads via fire-and-forget
`shell_exec(... &)` — these return immediately without waiting for the
spawned process, so they aren't covered by the lock's cleanup guarantee
the way the live pipeline is. Clicking Play across many different search
results in quick succession (each a separate concurrent request) could
each kick off its own background download faster than they complete,
piling up — froze the host a third time. Two more layers close this:

4. **`MAX_CONCURRENT_YTDLP` in `backend.php`** — a hard ceiling (2) on
   concurrent yt-dlp processes, checked via `running_ytdlp_count()` before
   every fire-and-forget spawn. If already at the ceiling, the background
   cache/preload is skipped entirely rather than adding another process —
   losing seek-readiness for one track is a far smaller cost than another
   pile-up. Verified live: fired 8 concurrent play requests directly at
   the backend (bypassing the frontend entirely) and process count never
   exceeded 3-4 throughout.
5. **The frontend now disables every Play button (not just the clicked
   one)** while any play request is in flight (`playRequestInFlight` in
   `app.js`) — stopping the burst of concurrent requests at the source,
   rather than relying on the backend to absorb it after the fact.

Even with all of the above, one more gap let a **reload-then-click-Play
loop bypass the lock entirely**: reloading the page aborts the browser's
HTTP connection for whatever request was in flight, and by default both
PHP and nginx terminate the server-side request early on a client
disconnect — releasing the flock (and skipping `stop_existing_pipeline()`'s
cleanup) before the operation actually finished, letting a fresh reload
immediately fire another request into what looked like an unlocked state.
Confirmed live and fixed with two settings that need to agree:

6. **`ignore_user_abort(true)`** at the top of `backend.php` — tells PHP
   to keep running the script to completion regardless of whether the
   client is still there to receive the response (the actual side effect
   — playing/stopping/seeking — has nothing to do with that).
7. **`fastcgi_ignore_client_abort on;`** in nginx's `location = /ytb/backend.php`
   block — without this, nginx itself tears down the FastCGI request to
   PHP-FPM on client disconnect independently of PHP's own setting, so (6)
   alone isn't sufficient. Verified live: force-killed a curl client 1s
   into a play request (simulating a reload) and confirmed via `fuser
   playback.lock` that the PHP-FPM worker kept running and holding the
   lock for the full ~18s the operation actually took, with a second
   concurrent request correctly blocking on it rather than sailing through.

## Tests

```bash
php tests/backend_test.php
node tests/app_test.js
```

Both exercise the pure/testable helper functions only — the shell/network
integration paths require a live host and are covered by manual
verification against the real OwnTone instance.

# OwnTone YouTube Streaming Dashboard — Design

## Purpose

A single-page, dark-mode PHP + vanilla JS dashboard running on an Armbian home
server, alongside an OwnTone music server (Docker, same host). It lets the
user paste a YouTube URL or type a search term into one input, browse/paginate
search results, and play a chosen track through OwnTone by piping YouTube
audio into OwnTone's named pipe library source.

## Environment assumptions

- `backend.php`, `index.php` served by Apache/Nginx + php-fpm on the same
  host as OwnTone.
- OwnTone API/WebSocket reachable at `<host>:3689` with no auth (open on LAN).
- OwnTone pipe library source is `/opt/docker/owntone/pipes/youtube.fifo`,
  already configured in OwnTone as a watched library file, so OwnTone
  exposes it as a track once anything is written to it.
- `yt-dlp`, `ffmpeg`, `jq` are installed and on `PATH` for the PHP process
  user (e.g. `www-data`).
- Single concurrent user/session — no auth, no multi-tenant concerns.

## Components

### backend.php

One entry point, dispatched by `action` POST param.

**`action=search`**
1. Sanitize the query with `escapeshellarg()`.
2. Run `yt-dlp --dump-json "ytsearch30:<query>"` piped through `jq` to a
   single JSON array of `{title, webpage_url, duration_string, thumbnail}`.
3. Echo the JSON array. On failure (empty output / non-zero exit), return
   `{status:"error", message:...}` with HTTP 500.

**`action=play`**
1. Accept `url` (raw YouTube URL, whether pasted directly or taken from a
   search result's `webpage_url`). Validate it's a `youtube.com`/`youtu.be`
   URL; reject otherwise.
2. `pkill -f yt-dlp` and `pkill -f ffmpeg` (best-effort — ignore exit
   status) to stop any in-flight stream before starting a new one.
3. Launch, via `escapeshellarg()`-sanitized `nohup sh -c "... " > /dev/null
   2>&1 &`:
   `yt-dlp -f bestaudio -o - <url> | ffmpeg -i pipe:0 -f wav -ar 44100 -ac 2
   pipe:1 > /opt/docker/owntone/pipes/youtube.fifo`
   This returns to PHP immediately; the pipeline keeps running detached.
4. Call OwnTone's API server-side (`GET /api/library/tracks` filtered to the
   pipe's title/path) to resolve the current OwnTone track id for the fifo.
5. Respond `{status:"ok", track_id:N}` (or `{status:"error",...}` if the
   pipe track can't be found).

All shell inputs pass through `escapeshellarg()`; no raw interpolation.

### app.js

- **Input handling:** on submit, regex-test the input against a YouTube URL
  pattern. Match → POST `action=play` directly with that URL. No match →
  POST `action=search`, store the returned 30-item array locally, render
  page 1.
- **Pagination:** pure client-side slicing of the stored array, 5 items per
  page, Prev/Next buttons that disable at the first/last page.
- **Play button (per result row):** POST `action=play` with that row's
  `webpage_url`. On success (`track_id` returned):
  1. `POST /api/queue/items/add?uris=library:track:<track_id>`
  2. `PUT /api/player/play?item_id=<queue_item_id>` (item id returned by the
     add call) to start playback of that specific queue entry.
- **WebSocket sync:** connect to `ws://<host>:3689/api/v6/ws` on load using
  `window.location.hostname` (OwnTone is same-host). Subscribe to
  `player`, `queue`, and `volume` notification types. On each push, update:
  play/pause icon, progress bar position, current track title, volume
  slider position. No polling fallback — if the socket fails to connect,
  controls are disabled and a small "disconnected" indicator is shown.
- **Volume slider:** on drag-end, `PUT /api/player/volume?volume=N`.

### index.php / style.css

- `index.php`: static markup (kept as `.php` for future server-rendered
  bits, no server logic needed today).
- Layout: a `position: sticky` top section (disc/art preview, track title,
  progress bar, play/pause + volume controls), followed by a
  `flex:1; overflow-y:auto` results container holding the paginated list
  and its Prev/Next bar (the bar scrolls with the list, always directly
  below the last visible row — it is not pinned to the viewport).
- Dark theme via CSS custom properties (background/surface/accent/text).
  Result rows: grid of `[thumbnail | title+duration | play button]`.
  Mobile breakpoint stacks the top control bar vertically and switches
  result rows to a more compact layout.

## Error handling

- `yt-dlp`/`ffmpeg` pipeline failures are not surfaced synchronously (the
  process is detached via `nohup`); the user sees the effect only through
  OwnTone's WebSocket player state never reaching "playing". This is an
  accepted tradeoff for "low resource overhead" — no status-polling
  endpoint is added.
- `backend.php` returns explicit JSON error objects + non-200 status for
  input validation failures (bad URL, yt-dlp/jq producing no output) so
  the frontend can show an inline message immediately for those cases.

## Out of scope

- Authentication/authorization (single trusted LAN user).
- Persisting play history, queue management beyond "replace current pipe
  stream", or multi-track queueing of YouTube results.
- Retrying failed WebSocket connections beyond the initial attempt.

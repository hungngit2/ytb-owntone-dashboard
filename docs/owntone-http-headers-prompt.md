# OwnTone fork prompt: per-stream custom HTTP request headers

## Context

This is a prompt to hand to an AI coding session working in the OwnTone fork
at `owntone-server` (the same fork that already added the per-output
`channels` "left"/"right" field — see git history for that PR's shape and
conventions).

A companion app (`ytb-owntone-dashboard`) plays YouTube audio by resolving a
video to its direct CDN URL via `yt-dlp -g -f bestaudio`, then queuing that
URL directly with the existing `POST /api/queue/items/add?uris=<url>` API —
no local transcoding, no named pipe, OwnTone just fetches and decodes the
URL itself like any other internet radio stream (`DATA_KIND_HTTP`).

This already works for most videos. The one known gap: OwnTone's HTTP input
(`src/transcode.c`, the `is_http` branch feeding `avformat_open_input`) only
ever sets a single **global** `user_agent` (read from `owntone.conf`'s
`general` section) plus `icy`/`reconnect` options — there is no way to set a
custom header per stream item. Some YouTube CDN URLs 403 without a `Referer`
header matching `https://www.youtube.com/`, which a global user_agent alone
can't provide.

## Request

Add support for optional per-queue-item HTTP request headers on
`DATA_KIND_HTTP` stream sources, so a client can supply a `Referer` (or other
header) alongside the URL when queuing a track.

Suggested shape, but defer to whatever fits OwnTone's existing conventions
better:

- Extend `POST /api/queue/items/add` to accept an optional `headers` param
  (e.g. JSON-encoded `{"Referer": "https://www.youtube.com/"}`) alongside
  `uris`, stored on the queue item (similar to how `title`/`artist`/
  `artwork_url` are already stored and settable via
  `PUT /api/queue/items/{id}` — see `jsonapi_reply_queue_tracks_update` in
  `src/httpd_jsonapi.c`).
- Thread that stored header set through to `transcode.c`'s `is_http` option
  block so it's passed to ffmpeg via `av_dict_set(&options, "headers", ...)`
  (ffmpeg/avformat already supports a `headers` AVOption for HTTP-family
  demuxers — one string, `\r\n`-joined key: value pairs).

## Acceptance criteria

- Queuing a plain `http(s)://` URL with no headers behaves exactly as today
  (no regression).
- Queuing with a `Referer` header set actually reaches ffmpeg's HTTP fetch
  for that item (verify via a URL that 403s without the header and succeeds
  with it — a YouTube CDN URL captured via `yt-dlp -g` is a real-world test
  case).
- No persistent per-item state leaks across queue clears — headers apply
  only to the item they were set on.

## Not required

- No changes to the pipe (`DATA_KIND_PIPE`) or Spotify (`INPUT_TYPE_SPOTIFY`/
  librespot-c) code paths — this is scoped to the generic HTTP input only.

<?php

// Reloading the page mid-request aborts that HTTP connection — without
// this, PHP can terminate the script early on client disconnect, which
// can release the playback flock (see PLAYBACK_LOCK_FILE) and skip its
// cleanup before stop_existing_pipeline()/the new pipeline launch actually
// finish. That let a reload-then-click-Play cycle bypass the lock
// entirely and repeat indefinitely (confirmed live). The operation's real
// side effect (playing/stopping/seeking) has nothing to do with whether
// the browser stuck around to receive the response, so it should always
// run to completion regardless.
ignore_user_abort(true);

define('OWNTONE_BASE', 'http://127.0.0.1:3689');
// Optional, untracked (like config.js) — define OWNTONE_AUTH_USERNAME /
// OWNTONE_AUTH_PASSWORD there once OwnTone's web interface has basic auth
// enabled (Settings > Web Interface). Copy owntone-auth.example.php to
// owntone-auth.php and fill it in; left blank/absent, no Authorization
// header is sent, matching OwnTone's own default (unauthenticated) behavior.
if (file_exists(__DIR__ . '/owntone-auth.php')) {
    require __DIR__ . '/owntone-auth.php';
}
if (!defined('OWNTONE_AUTH_USERNAME')) {
    define('OWNTONE_AUTH_USERNAME', '');
}
if (!defined('OWNTONE_AUTH_PASSWORD')) {
    define('OWNTONE_AUTH_PASSWORD', '');
}
// Optional, untracked (like config.js/owntone-auth.php) — define these to
// require this app's own login (HTTP Basic Auth) for any client whose IP
// isn't private/LAN, or for everyone if DASHBOARD_FORCE_AUTH_FOR_LOCAL is
// true. Copy dashboard-auth.example.php to dashboard-auth.php and fill it
// in; left blank, no login is ever required (today's default behavior).
if (file_exists(__DIR__ . '/dashboard-auth.php')) {
    require __DIR__ . '/dashboard-auth.php';
}
if (!defined('DASHBOARD_AUTH_USERNAME')) {
    define('DASHBOARD_AUTH_USERNAME', '');
}
if (!defined('DASHBOARD_AUTH_PASSWORD')) {
    define('DASHBOARD_AUTH_PASSWORD', '');
}
if (!defined('DASHBOARD_FORCE_AUTH_FOR_LOCAL')) {
    define('DASHBOARD_FORCE_AUTH_FOR_LOCAL', false);
}
// All host-side app state lives under one parent directory now (pipes/
// data/cache subfolders), outside nginx's document root and deliberately
// NOT under /opt/docker/owntone/pipes: that path traverses
// /mnt/appsrv/docker, whose permissions have been observed to reset to
// block "other" access (likely on container/compose recreation), silently
// breaking www-data's ability to write here. This path is owned by
// www-data directly with a clean, non-restrictive traversal chain.
define('YOUTUBE_FIFO_PATH', '/mnt/appsrv/ytb-owntone/pipes/youtube.fifo');
define('YOUTUBE_FIFO_MATCH', 'youtube');
// Path as OwnTone sees it inside its container/library config — distinct from
// YOUTUBE_FIFO_PATH, which is the host path used to write the audio stream.
define('OWNTONE_PIPE_DIRECTORY', '/mnt/appsrv/ytb-owntone/pipes');
// Absolute path outside nginx's document root, which on the deployed host
// (root /mnt/appsrv/www;) covers this app's whole parent directory — a
// relative "../data" would land inside /mnt/appsrv/www/data and be directly
// web-reachable. Adjust if your document root differs.
define('PLAYLIST_FILE', '/mnt/appsrv/ytb-owntone/data/playlist.json');
define('LAST_SEARCH_FILE', '/mnt/appsrv/ytb-owntone/data/last_search.json');
// Server-side "what queue/playlist and index are currently playing" state,
// read by bin/queue-daemon.php so auto-advance-to-next-track works even
// with no browser open — the daemon is what's watching, not any tab.
define('QUEUE_STATE_FILE', '/mnt/appsrv/ytb-owntone/data/queue_state.json');
// Persists whether the currently-playing item was ever actually observed
// playing — see queue_should_advance's third signal for why this matters
// specifically for direct-HTTP tracks.
define('CONFIRMED_PLAYING_FILE', '/mnt/appsrv/ytb-owntone/data/confirmed_playing.json');
// Caches the last successful direct-HTTP resolve (youtube url -> CDN url) —
// handle_stream_redirect reuses it instead of re-running yt-dlp from
// scratch when the clicked track is the one already resolved for playback.
define('RESOLVED_STREAM_CACHE_FILE', '/mnt/appsrv/ytb-owntone/data/resolved_stream.json');
// Holds at most one pre-downloaded "next track" audio file at a time (see
// maybe_preload_next) — outside the web root like the other data paths.
define('AUDIO_CACHE_DIR', '/mnt/appsrv/ytb-owntone/cache');
// Serializes pipeline start/stop across CONCURRENT PHP-FPM worker
// processes (one per in-flight HTTP request) — a plain in-PHP wait loop
// only serializes within a single request, so rapid clicks (each its own
// separate request/process) could still race each other's pkill/launch
// and pile up multiple yt-dlp+ffmpeg pairs, which is exactly what
// exhausted memory and froze the host even after that wait loop was added.
define('PLAYBACK_LOCK_FILE', '/mnt/appsrv/ytb-owntone/data/playback.lock');
// Hard ceiling on concurrent yt-dlp processes (live pipeline + at most one
// background cache-priming download), checked before every fire-and-forget
// spawn (ensure_current_track_cached, maybe_preload_next). The lock above
// only bounds the LIVE pipeline swap; those two background downloads
// return immediately without waiting for the spawned process to finish,
// so they aren't covered by the same guarantee — a burst of clicks across
// many different search results could each kick one off, piling up beyond
// what the lock alone prevents. This is the last line of defense against
// that regardless of which code path or how many concurrent requests
// triggered it.
define('MAX_CONCURRENT_YTDLP', 2);
// A bare "ffmpeg" is NOT a safe pkill/pgrep pattern on this host: it also
// runs Jellyfin, whose own ffmpeg processes (transcoding, thumbnail
// generation) run continuously and match "ffmpeg" as a plain substring
// too (e.g. its own launch command embeds "--ffmpeg=/usr/lib/jellyfin-
// ffmpeg/ffmpeg", and the jellyfin-ffmpeg binary's own argv still
// contains "ffmpeg") — confirmed live via `ps`. A bare match would kill
// Jellyfin's unrelated processes on every play/stop/seek, and would make
// is_pipeline_running() report "still running" almost permanently
// regardless of our own pipeline's actual state. This exact suffix is
// unique to our own ffmpeg invocation in build_play_pipeline_cmd (used
// for both the live-stream and cached-file branches).
define('OUR_FFMPEG_PATTERN', 'wav -ar 44100 -ac 2 pipe:1');

// Pure: is this IP within the private/LAN ranges (10/8, 172.16/12,
// 192.168/16, 127/8, ::1)? Mirrors what an nginx `geo` block would match,
// but done here since DASHBOARD_AUTH_* enforcement lives in PHP, not nginx.
function is_private_local_ip(string $ip): bool
{
    if ($ip === '::1') {
        return true;
    }

    $long = ip2long($ip);
    if ($long === false) {
        return false;
    }

    $ranges = [
        ['10.0.0.0', 8],
        ['172.16.0.0', 12],
        ['192.168.0.0', 16],
        ['127.0.0.0', 8],
    ];

    foreach ($ranges as [$base, $bits]) {
        $mask = -1 << (32 - $bits);
        if (($long & $mask) === (ip2long($base) & $mask)) {
            return true;
        }
    }

    return false;
}

// Requires this app's own HTTP Basic Auth login for any client whose IP
// isn't private/LAN, or for everyone if DASHBOARD_FORCE_AUTH_FOR_LOCAL is
// true. A no-op entirely if DASHBOARD_AUTH_USERNAME/PASSWORD aren't
// configured — matches config.js/owntone-auth.php's own blank-means-off
// convention. Sends the 401 challenge and exits on failure, so this must
// run before any other output.
//
// Only covers index.php/backend.php (both call this directly) — plain
// static assets (app.js/style.css/config.js) are served straight by nginx
// without ever going through PHP, so they aren't covered by this check.
function enforce_dashboard_auth(): void
{
    if (DASHBOARD_AUTH_USERNAME === '' && DASHBOARD_AUTH_PASSWORD === '') {
        return;
    }

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $needsAuth = DASHBOARD_FORCE_AUTH_FOR_LOCAL || !is_private_local_ip($clientIp);
    if (!$needsAuth) {
        return;
    }

    $suppliedUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $suppliedPass = $_SERVER['PHP_AUTH_PW'] ?? '';

    if (hash_equals(DASHBOARD_AUTH_USERNAME, $suppliedUser) && hash_equals(DASHBOARD_AUTH_PASSWORD, $suppliedPass)) {
        return;
    }

    header('WWW-Authenticate: Basic realm="Dashboard"');
    http_response_code(401);
    echo 'Authentication required';
    exit;
}

function is_youtube_url(string $url): bool
{
    return (bool) preg_match(
        '#^https?://(www\.)?(youtube\.com/watch\?v=|youtu\.be/|youtube\.com/shorts/)#i',
        trim($url)
    );
}

function extract_youtube_video_id(string $url): ?string
{
    $patterns = [
        '/[?&]v=([a-zA-Z0-9_-]{11})/',
        '#youtu\.be/([a-zA-Z0-9_-]{11})#',
        '#youtube\.com/shorts/([a-zA-Z0-9_-]{11})#',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
    }
    return null;
}

function audio_cache_path(string $youtubeUrl, string $cacheDir = AUDIO_CACHE_DIR): ?string
{
    $videoId = extract_youtube_video_id($youtubeUrl);
    return $videoId !== null ? $cacheDir . '/' . $videoId . '.audio' : null;
}

// Pure: given the currently-playing queue and index, which url (if any)
// should be preloaded next? Shuffle has no fixed "next" to preload — the
// daemon picks it randomly only once the current track actually finishes.
// Repeat-one has nothing new to preload either: "next" is the same track
// that's already playing (and therefore already cached). Repeat-all wraps
// back to the first item once past the end, same as playback itself will.
function next_preload_target(array $items, int $currentIndex, bool $shuffle, string $repeat = 'off'): ?string
{
    if ($shuffle || $repeat === 'one') {
        return null;
    }
    $nextIndex = $currentIndex + 1;
    if ($nextIndex >= count($items)) {
        $nextIndex = $repeat === 'all' ? 0 : $nextIndex;
    }
    if ($nextIndex < 0 || $nextIndex >= count($items)) {
        return null;
    }
    $url = $items[$nextIndex]['webpage_url'] ?? '';
    return is_youtube_url($url) ? $url : null;
}

function build_play_pipeline_cmd(
    string $youtubeUrl,
    string $fifoPath,
    string $metadataFifoPath,
    string $metadataXml,
    ?string $cachedAudioPath = null,
    int $startAtSeconds = 0
): string {
    // When a preload already downloaded this track, skip yt-dlp entirely —
    // that's the whole point (removes its resolve+download latency from
    // the critical path of pressing Play/Next). Falls back to the normal
    // live yt-dlp|ffmpeg pipeline otherwise. ffmpeg reads the cache file
    // directly (not through "cat") so -ss can seek within it — a live
    // yt-dlp stream has no random access, only a real file on disk does.
    // The cache file is intentionally NOT deleted after use here: it stays
    // available for repeat seeks while this track remains current, and is
    // garbage-collected by maybe_preload_next once a different track takes over.
    if ($cachedAudioPath !== null) {
        $seekArgs = $startAtSeconds > 0 ? sprintf('-ss %d ', $startAtSeconds) : '';
        $audioPipeline = sprintf(
            'ffmpeg -re %s-i %s -f wav -ar 44100 -ac 2 pipe:1 > %s',
            $seekArgs,
            escapeshellarg($cachedAudioPath),
            escapeshellarg($fifoPath)
        );
    } else {
        $audioPipeline = sprintf(
            'yt-dlp --no-playlist -f bestaudio -o - %s | ffmpeg -re -i pipe:0 -f wav -ar 44100 -ac 2 pipe:1 > %s',
            escapeshellarg($youtubeUrl),
            escapeshellarg($fifoPath)
        );
    }

    // timeout guards against this hanging forever: writing to a named pipe
    // blocks until a reader attaches, and if OwnTone's metadata reader
    // doesn't reconnect in time, an untimed write leaks a stuck process on
    // every play attempt (that process is never matched by the yt-dlp/
    // ffmpeg cleanup below). Worst case on timeout: this one play's
    // metadata is silently skipped — audio still plays via the other pipe.
    $metadataWrite = sprintf(
        'timeout 5 printf %s > %s',
        escapeshellarg($metadataXml),
        escapeshellarg($metadataFifoPath)
    );

    $combined = sprintf('%s & %s', $metadataWrite, $audioPipeline);

    return sprintf('nohup sh -c %s > /dev/null 2>&1 &', escapeshellarg($combined));
}

// Downloads straight to a temp path, then renames into place atomically —
// so a concurrent play never sees (and uses) a half-written cache file.
function build_preload_cmd(string $youtubeUrl, string $cachePath): string
{
    $tmpPath = $cachePath . '.part';
    $cmd = sprintf(
        'yt-dlp --no-playlist -f bestaudio -o %s %s && mv %s %s',
        escapeshellarg($tmpPath),
        escapeshellarg($youtubeUrl),
        escapeshellarg($tmpPath),
        escapeshellarg($cachePath)
    );

    return sprintf('nohup sh -c %s > /dev/null 2>&1 &', escapeshellarg($cmd));
}

function build_yt_dlp_duration_cmd(string $youtubeUrl): string
{
    return sprintf(
        'yt-dlp --no-playlist --skip-download --print duration %s 2>/dev/null',
        escapeshellarg($youtubeUrl)
    );
}

// One shairport-sync-style metadata item: <type>/<code> are the ASCII DMAP
// tag hex-encoded (e.g. "minm" -> 6d696e6d), <data> is base64. OwnTone's
// parser matches purely on <code>, but real clients also send <type>
// ("core" for regular tags, "ssnc" for shairport-specific ones like prgr).
function build_metadata_item(string $typeTag, string $codeTag, string $data): string
{
    return sprintf(
        '<item><type>%s</type><code>%s</code><length>%d</length><data encoding="base64">%s</data></item>',
        bin2hex($typeTag),
        bin2hex($codeTag),
        strlen($data),
        base64_encode($data)
    );
}

function build_pipe_metadata_xml(
    string $title,
    string $artist,
    int $durationSeconds,
    string $artworkBytes = '',
    int $startAtSeconds = 0
): string {
    $xml = build_metadata_item('core', 'minm', $title) . build_metadata_item('core', 'asar', $artist);

    if ($durationSeconds > 0) {
        // OwnTone's parser rejects the whole progress item if any of the
        // three RTP-timestamp fields parses to exactly zero, so "start"
        // uses 1 as a nonzero reference point rather than 0. "pos" reports
        // $startAtSeconds as the current position — this is what makes a
        // seek show up correctly in OwnTone's own UI even though the
        // underlying pipe always restarts fresh from byte 0 of whatever
        // was seeked to in the source file.
        $start = 1;
        $pos = $start + ($startAtSeconds * 44100);
        $end = $start + ($durationSeconds * 44100);
        $xml .= build_metadata_item('ssnc', 'prgr', "{$start}/{$pos}/{$end}");
    }

    if ($artworkBytes !== '') {
        $xml .= build_metadata_item('ssnc', 'PICT', $artworkBytes);
    }

    return $xml;
}

function extract_track_id_from_tracks_json(array $tracksResponse, string $matchBasename): ?int
{
    $items = $tracksResponse['tracks']['items'] ?? null;
    if (!is_array($items)) {
        return null;
    }

    foreach ($items as $track) {
        $path = $track['path'] ?? '';
        if (stripos($path, $matchBasename) !== false) {
            return (int) $track['id'];
        }
    }

    return null;
}

function add_to_playlist_items(array $items, array $newItem): array
{
    $filtered = array_values(array_filter($items, function ($item) use ($newItem) {
        return ($item['webpage_url'] ?? null) !== ($newItem['webpage_url'] ?? null);
    }));
    $filtered[] = $newItem;
    return $filtered;
}

function remove_from_playlist_items(array $items, string $url): array
{
    return array_values(array_filter($items, function ($item) use ($url) {
        return ($item['webpage_url'] ?? null) !== $url;
    }));
}

function find_playlist_index(array $playlists, string $name): ?int
{
    foreach ($playlists as $i => $playlist) {
        if (($playlist['name'] ?? null) === $name) {
            return $i;
        }
    }

    return null;
}

function create_playlist(array $playlists, string $name): array
{
    if (find_playlist_index($playlists, $name) !== null) {
        return $playlists;
    }

    $playlists[] = ['name' => $name, 'items' => []];
    return $playlists;
}

function add_item_to_named_playlist(array $playlists, string $name, array $item): array
{
    $index = find_playlist_index($playlists, $name);
    if ($index === null) {
        $playlists[] = ['name' => $name, 'items' => []];
        $index = count($playlists) - 1;
    }

    $playlists[$index]['items'] = add_to_playlist_items($playlists[$index]['items'], $item);
    return $playlists;
}

function remove_item_from_named_playlist(array $playlists, string $name, string $url): array
{
    $index = find_playlist_index($playlists, $name);
    if ($index === null) {
        return $playlists;
    }

    $playlists[$index]['items'] = remove_from_playlist_items($playlists[$index]['items'], $url);
    return $playlists;
}

function read_json_file(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function write_json_file(array $data, string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($path, json_encode(array_values($data)));
}

function load_playlist(string $path = PLAYLIST_FILE): array
{
    return read_json_file($path);
}

function save_playlist(array $items, string $path = PLAYLIST_FILE): void
{
    write_json_file($items, $path);
}

function load_last_search(string $path = LAST_SEARCH_FILE): array
{
    return read_json_file($path);
}

function save_last_search(array $results, string $path = LAST_SEARCH_FILE): void
{
    write_json_file($results, $path);
}

// Queue state is {items: [...], current_index: N, shuffle: bool, repeat:
// 'off'|'all'|'one'} — an associative shape, distinct from the flat lists
// read_json_file/write_json_file expect.
function load_queue_state(string $path = QUEUE_STATE_FILE): array
{
    if (!file_exists($path)) {
        return ['items' => [], 'current_index' => -1, 'shuffle' => false, 'repeat' => 'off'];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
        return ['items' => [], 'current_index' => -1, 'shuffle' => false, 'repeat' => 'off'];
    }

    $repeat = $decoded['repeat'] ?? 'off';
    if (!in_array($repeat, ['off', 'all', 'one'], true)) {
        $repeat = 'off';
    }

    return [
        'items' => $decoded['items'],
        'current_index' => (int) ($decoded['current_index'] ?? -1),
        'shuffle' => (bool) ($decoded['shuffle'] ?? false),
        'repeat' => $repeat,
    ];
}

function save_queue_state(array $items, int $currentIndex, bool $shuffle = false, string $repeat = 'off', string $path = QUEUE_STATE_FILE): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($path, json_encode(['items' => $items, 'current_index' => $currentIndex, 'shuffle' => $shuffle, 'repeat' => $repeat]));
}

function read_playback_progress_state(string $path = CONFIRMED_PLAYING_FILE): array
{
    $decoded = read_json_file($path);
    return [
        'confirmed' => (bool) ($decoded['confirmed'] ?? false),
        'is_direct' => (bool) ($decoded['is_direct'] ?? false),
    ];
}

function write_playback_progress_state(array $state, string $path = CONFIRMED_PLAYING_FILE): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, json_encode($state));
}

// Marks a fresh play attempt as "not yet confirmed playing" — called right
// as a new track starts, so a still-blank player state (still resolving/
// negotiating outputs) isn't mistaken for the *previous* track's natural
// end-of-stream by queue_should_advance's third signal.
//
// $keepIsDirect matters for seek_direct_http_playback: a seek re-buffers an
// *already-known-direct* track, so is_direct must stay true through it —
// confirmed live, defaulting this to false (as for a genuinely new/unknown
// play) clobbered is_direct back to false right as a seek entered its
// vulnerable re-buffering window, undoing the pipelineExitedAfterStarting
// guard in advance_queue_if_finished and causing an immediate false
// auto-advance the moment progress ticked past 0 again.
function reset_confirmed_playing(bool $keepIsDirect = false, string $path = CONFIRMED_PLAYING_FILE): void
{
    $isDirect = $keepIsDirect ? read_playback_progress_state($path)['is_direct'] : false;
    write_playback_progress_state(['confirmed' => false, 'is_direct' => $isDirect], $path);
}

// Whether the *currently playing* track is a direct-HTTP ("url") item —
// persisted explicitly by play_url_body rather than re-derived live from
// OwnTone's /api/player+/api/queue on every check: that live lookup goes
// blank (item_id 0) during exactly the moments this flag needs to cover —
// a track's natural end, or mid-seek re-buffering — which made a
// live-lookup version of this check useless right when it mattered most
// (confirmed live: seeking a direct track was still triggering a false
// auto-advance even after gating on it, because the live lookup itself
// went blank during the seek's brief re-buffer).
function mark_current_track_is_direct(bool $isDirect, string $path = CONFIRMED_PLAYING_FILE): void
{
    $state = read_playback_progress_state($path);
    $state['is_direct'] = $isDirect;
    write_playback_progress_state($state, $path);
}

function is_current_track_direct(string $path = CONFIRMED_PLAYING_FILE): bool
{
    return read_playback_progress_state($path)['is_direct'];
}

// Records (and returns) whether the current item has been observed actually
// playing at any point since the last reset_confirmed_playing() call.
function mark_confirmed_playing_if_active(array $player, string $path = CONFIRMED_PLAYING_FILE): bool
{
    $isActive = ($player['state'] ?? '') === 'play' || (int) ($player['item_progress_ms'] ?? 0) > 0;
    if ($isActive) {
        $state = read_playback_progress_state($path);
        $state['confirmed'] = true;
        write_playback_progress_state($state, $path);
        return true;
    }

    return read_playback_progress_state($path)['confirmed'];
}

// Pure decision: given OwnTone's raw /api/player response, has the current
// item finished? Rejects a missing/zero item_length_ms (duration lookup
// failed or hasn't landed yet) rather than guessing — better to not
// auto-advance than to advance while still mid-playback. Whether there's
// a valid *next* item is next_queue_index's concern, not this one.
//
// Three independent signals, any is sufficient once genuinely not playing:
// 1. Progress is within 4s of yt-dlp's reported duration — works for most
//    videos, where the estimate is close to the real decoded length.
// 2. Our own ffmpeg pipeline process has already exited on its own —
//    ffmpeg naturally exits once its input (the yt-dlp stream or the
//    cached file) reaches EOF, i.e. once the audio genuinely finishes,
//    regardless of what yt-dlp's duration estimate said. Needed because
//    some videos' actual audio ends more than the 4s tolerance short of
//    that estimate (codec/container-dependent) — confirmed live: paused
//    with several seconds still "remaining" and never advancing, with
//    signal 1 never triggering because progress never got that close.
//    Requires progress > 0 so a track that never actually started (pipeline
//    failed before playing anything) isn't mistaken for "finished".
// 3. A direct-HTTP ("url") track's natural end-of-stream: unlike a pipe
//    track, OwnTone doesn't pause with the item retained — it wipes the
//    player back to a blank stop/idle state (item_id 0, length 0, progress
//    0), confirmed live. That blank shape is otherwise indistinguishable
//    from "hasn't started playing yet" (still resolving/negotiating
//    outputs), so $hasConfirmedPlaying — whether this item was ever
//    actually seen playing — must be true before trusting it as "finished"
//    rather than "not started".
function queue_should_advance(array $player, int $currentIndex, int $itemCount, bool $pipelineStillRunning = true, bool $hasConfirmedPlaying = false): bool
{
    if ($currentIndex < 0 || $itemCount <= 0) {
        return false;
    }

    $isPlaying = ($player['state'] ?? '') === 'play';
    if ($isPlaying) {
        return false;
    }

    $progressMs = (int) ($player['item_progress_ms'] ?? 0);
    $lengthMs = (int) ($player['item_length_ms'] ?? 0);
    $itemId = (int) ($player['item_id'] ?? 0);

    $nearEndByDuration = $lengthMs > 0 && $progressMs >= ($lengthMs - 4000);
    $pipelineExitedAfterStarting = !$pipelineStillRunning && $progressMs > 0;
    $wentIdleAfterConfirmedPlaying = $hasConfirmedPlaying && $itemId === 0 && $lengthMs === 0 && $progressMs === 0;

    return $nearEndByDuration || $pipelineExitedAfterStarting || $wentIdleAfterConfirmedPlaying;
}

// Pure: which index plays next. Sequential mode stops at the end (returns
// null). Shuffle mode picks any other index and never runs out — $randomPicker
// is injectable so tests can verify the "never repeat the current index"
// behavior deterministically instead of asserting on real randomness.
function next_queue_index(int $currentIndex, int $itemCount, bool $shuffle, string $repeat = 'off', ?callable $randomPicker = null): ?int
{
    if ($itemCount <= 0) {
        return null;
    }

    // Repeat-one always replays the same track regardless of shuffle —
    // there's no "next" to pick, sequential or otherwise.
    if ($repeat === 'one') {
        return $currentIndex;
    }

    if (!$shuffle) {
        $next = $currentIndex + 1;
        if ($next < $itemCount) {
            return $next;
        }
        return $repeat === 'all' ? 0 : null;
    }

    if ($itemCount === 1) {
        return $repeat === 'all' ? 0 : null;
    }

    $randomPicker = $randomPicker ?? function (int $min, int $max) {
        return random_int($min, $max);
    };

    do {
        $candidate = $randomPicker(0, $itemCount - 1);
    } while ($candidate === $currentIndex);

    return $candidate;
}

function handle_playlists_list(): void
{
    echo json_encode(load_playlist());
}

function handle_playlist_create(string $name): void
{
    $name = trim($name);
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'playlist name required']);
        return;
    }

    $playlists = create_playlist(load_playlist(), $name);
    save_playlist($playlists);

    echo json_encode(['status' => 'ok', 'playlists' => $playlists]);
}

function handle_playlist_add_item(string $name, array $item): void
{
    $name = trim($name);
    $url = $item['webpage_url'] ?? '';
    if ($name === '' || !is_youtube_url($url)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'invalid playlist name or url']);
        return;
    }

    $entry = [
        'webpage_url' => $url,
        'title' => $item['title'] ?? '',
        'thumbnail' => $item['thumbnail'] ?? '',
        'duration_string' => $item['duration_string'] ?? '',
        'channel' => $item['channel'] ?? '',
    ];

    $playlists = add_item_to_named_playlist(load_playlist(), $name, $entry);
    save_playlist($playlists);

    echo json_encode(['status' => 'ok', 'playlists' => $playlists]);
}

function handle_playlist_remove_item(string $name, string $url): void
{
    $playlists = remove_item_from_named_playlist(load_playlist(), $name, $url);
    save_playlist($playlists);

    echo json_encode(['status' => 'ok', 'playlists' => $playlists]);
}

function handle_cache_search(array $results): void
{
    save_last_search($results);
    echo json_encode(['status' => 'ok']);
}

function handle_last_search(): void
{
    echo json_encode(load_last_search());
}

// Applied to every curl handle that talks to OwnTone — a no-op until
// OWNTONE_AUTH_USERNAME/PASSWORD are set (see owntone-auth.example.php).
function apply_owntone_auth($ch): void
{
    if (OWNTONE_AUTH_USERNAME !== '' || OWNTONE_AUTH_PASSWORD !== '') {
        curl_setopt($ch, CURLOPT_USERPWD, OWNTONE_AUTH_USERNAME . ':' . OWNTONE_AUTH_PASSWORD);
    }
}

function owntone_get(string $path): array
{
    $ch = curl_init(OWNTONE_BASE . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    apply_owntone_auth($ch);
    $response = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode((string) $response, true);
    return is_array($decoded) ? $decoded : [];
}

function owntone_post(string $path): void
{
    owntone_request($path, 'POST');
}

// Player transport controls (play/pause/stop/next/previous) are PUT
// endpoints in OwnTone's API, unlike queue mutation endpoints (POST).
function owntone_put(string $path): void
{
    owntone_request($path, 'PUT');
}

function owntone_request(string $path, string $method): void
{
    $ch = curl_init(OWNTONE_BASE . $path);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    apply_owntone_auth($ch);
    curl_exec($ch);
    curl_close($ch);
}

function fetch_youtube_oembed(string $url): array
{
    $ch = curl_init('https://www.youtube.com/oembed?url=' . rawurlencode($url) . '&format=json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode((string) $response, true);
    return is_array($decoded) ? $decoded : [];
}

function fetch_url_bytes(string $url): string
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $data = curl_exec($ch);
    curl_close($ch);

    return is_string($data) ? $data : '';
}

function handle_resolve_url(string $url): void
{
    if (!is_youtube_url($url)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'not a valid YouTube URL']);
        return;
    }

    $oembed = fetch_youtube_oembed($url);

    echo json_encode([
        'title' => $oembed['title'] ?? $url,
        'webpage_url' => $url,
        'duration_string' => '',
        'thumbnail' => $oembed['thumbnail_url'] ?? '',
        'channel' => $oembed['author_name'] ?? '',
    ]);
}

// Resolves a track straight to its underlying CDN URL and 302s there — lets
// the frontend open the actual audio stream directly in a new tab
// (bypassing OwnTone entirely) as a plain GET-navigable link, rather than a
// fetch()+window.open('', ...).location JS round-trip. That round-trip
// looked fine locally but confirmed live: browsers increasingly treat
// setting .location on a window opened earlier in the same click handler
// as an untrusted delayed navigation and silently block it once real
// async time (the fetch) has passed — a plain synchronous window.open() of
// a real URL, whose resolution happens server-side before the redirect,
// doesn't hit that heuristic at all.
function handle_stream_redirect(string $url): void
{
    if (!is_youtube_url($url)) {
        http_response_code(400);
        echo 'not a valid YouTube URL';
        return;
    }

    // Reuse whatever attempt_direct_http_play already resolved for this
    // exact track at play time — this is almost always a click on the
    // currently-playing track's thumbnail, so this skips a redundant
    // yt-dlp invocation (the slow part) in the common case. Falls back to
    // a fresh resolve for anything else (a fifo-fallback track, a track
    // that isn't currently playing, or nothing cached yet).
    $streamUrl = get_cached_stream_url($url) ?? resolve_direct_stream_url($url);
    if ($streamUrl === null) {
        http_response_code(502);
        echo 'could not resolve a direct stream url';
        return;
    }

    header('Location: ' . $streamUrl, true, 302);
}

// Streams an OwnTone GET response straight through, byte for byte — used
// for artwork images and the continuous stream.mp3 audio, where the
// browser needs a plain <img>/<audio> src it can hit directly (no custom
// headers possible on those tags), fetched here server-side (with auth)
// instead of by the browser itself.
function proxy_owntone_get_stream(string $path): void
{
    set_time_limit(0);

    $ch = curl_init(OWNTONE_BASE . $path);
    apply_owntone_auth($ch);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) {
        if (preg_match('/^Content-Type:\s*(.+)$/i', trim($headerLine), $matches)) {
            header('Content-Type: ' . trim($matches[1]));
        }
        return strlen($headerLine);
    });
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) {
        // stream.mp3 is a long-lived connection — if the browser tab
        // closed or the user hit stop, keep this from streaming into the
        // void forever. Returning anything other than strlen($chunk) tells
        // curl to abort the transfer.
        if (connection_aborted()) {
            return 0;
        }
        echo $chunk;
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        return strlen($chunk);
    });
    curl_exec($ch);
    curl_close($ch);
}

function handle_owntone_artwork(): void
{
    proxy_owntone_get_stream('/artwork/nowplaying');
}

function handle_owntone_stream(): void
{
    proxy_owntone_get_stream('/stream.mp3');
}

// Auto-generated Mix/Radio lists (list=RD...) are dynamically built per
// viewer and aren't retrievable via the YouTube Data API's playlistItems
// endpoint (the client-side resolver used for real playlists) — yt-dlp
// can still expand them directly from the watch url. --flat-playlist keeps
// this cheap (one metadata pass over the list, no per-video extraction),
// and --playlist-end caps a Mix's effectively endless list to something
// reasonable to display. `timeout` guards a synchronous request against a
// hung yt-dlp process.
define('MIX_PLAYLIST_MAX_ITEMS', 50);
// Confirmed live: under php-fpm's shell_exec, wrapping a bare "yt-dlp"
// with a "timeout" prefix fails to resolve yt-dlp (exit 127) even though
// a bare "yt-dlp" alone (no timeout wrapper) resolves fine, and even
// though $PATH as read by the shell itself does list yt-dlp's directory —
// timeout's own exec of its child command doesn't behave the same way.
// Absolute paths for both sidestep this entirely; adjust if yt-dlp/timeout
// live elsewhere on a different deployment (`which yt-dlp`, `which timeout`).
define('YTDLP_BIN', '/usr/local/bin/yt-dlp');
define('TIMEOUT_BIN', '/usr/bin/timeout');

function build_ytdlp_flat_playlist_cmd(string $url): string
{
    return sprintf(
        '%s 20 %s --flat-playlist --playlist-end %d -J %s 2>/dev/null',
        TIMEOUT_BIN,
        YTDLP_BIN,
        MIX_PLAYLIST_MAX_ITEMS,
        escapeshellarg($url)
    );
}

function handle_resolve_mix_playlist(string $url): void
{
    if (!is_youtube_url($url)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'not a valid YouTube URL']);
        return;
    }

    $json = (string) shell_exec(build_ytdlp_flat_playlist_cmd($url));
    $data = json_decode($json, true);
    $entries = is_array($data) ? ($data['entries'] ?? []) : [];

    $items = [];
    foreach ($entries as $entry) {
        $videoId = is_array($entry) ? ($entry['id'] ?? null) : null;
        if (!is_string($videoId) || $videoId === '') {
            continue;
        }

        $durationSeconds = (int) ($entry['duration'] ?? 0);
        $thumbnails = is_array($entry['thumbnails'] ?? null) ? $entry['thumbnails'] : [];
        $lastThumbnail = end($thumbnails);

        $items[] = [
            'title' => (string) ($entry['title'] ?? $videoId),
            'webpage_url' => 'https://www.youtube.com/watch?v=' . $videoId,
            'duration_string' => $durationSeconds > 0
                ? sprintf('%d:%02d', intdiv($durationSeconds, 60), $durationSeconds % 60)
                : '',
            'thumbnail' => is_array($lastThumbnail) ? (string) ($lastThumbnail['url'] ?? '') : '',
            'channel' => (string) ($entry['channel'] ?? $entry['uploader'] ?? ''),
        ];
    }

    if (empty($items)) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Could not resolve this mix/radio playlist']);
        return;
    }

    echo json_encode(['status' => 'ok', 'items' => $items]);
}

function ensure_metadata_pipe_exists(string $metadataFifoPath): void
{
    shell_exec(sprintf(
        'test -p %s || (mkfifo %s && chmod 777 %s)',
        escapeshellarg($metadataFifoPath),
        escapeshellarg($metadataFifoPath),
        escapeshellarg($metadataFifoPath)
    ));
}

// Signals the current pipeline to stop AND waits (briefly) for it to
// actually exit before returning, escalating to SIGKILL if it hasn't by
// the deadline. Without this wait, back-to-back play requests arriving
// faster than a signaled process can unwind (e.g. rapid Next/Prev, or
// several requests firing in quick succession) can each launch a new
// yt-dlp+ffmpeg pair on top of ones still dying — this is exactly what
// piled up enough concurrent processes to exhaust memory and freeze the
// host once already (confirmed via dmesg: 4-5 simultaneous yt-dlp
// processes plus ffmpeg at the time of that freeze).
//
// That wait loop alone wasn't enough, though: it only serializes within
// ONE request. Rapid Play clicks each arrive as a SEPARATE HTTP request,
// handled by a SEPARATE PHP-FPM worker process with no shared in-PHP
// state — so several of those waits could still run concurrently and
// race each other's stop-then-launch sequence, piling up processes again
// (confirmed: froze a second time even with the wait loop in place).
// flock() on a shared lock file is a real cross-process mutex, unlike an
// in-memory wait. Waits up to 25s for the lock (not indefinitely, so a
// genuinely stuck holder still fails fast rather than piling up blocked
// PHP-FPM workers) — a single play can legitimately take upwards of 15-20s
// on this host (oEmbed + yt-dlp duration lookup over a slow network path),
// confirmed live, so a shorter timeout made ordinary near-simultaneous
// clicks fail with "busy" far more often than intended.
function with_playback_lock(callable $fn): array
{
    $lockFile = @fopen(PLAYBACK_LOCK_FILE, 'c');
    if ($lockFile === false) {
        // Can't even open the lock file — degrade gracefully rather than
        // hard-fail every play/stop/seek over what's likely a one-off
        // permissions/disk issue unrelated to the actual playback request.
        return $fn();
    }

    $locked = false;
    $deadline = microtime(true) + 25;
    while (!($locked = flock($lockFile, LOCK_EX | LOCK_NB))) {
        if (microtime(true) >= $deadline) {
            break;
        }
        usleep(100000);
    }

    if (!$locked) {
        fclose($lockFile);
        return ['status' => 'error', 'message' => 'another play/stop/seek request is already in progress — try again'];
    }

    try {
        return $fn();
    } finally {
        flock($lockFile, LOCK_UN);
        fclose($lockFile);
    }
}

function running_ytdlp_count(): int
{
    $output = trim((string) shell_exec('pgrep -c -f yt-dlp 2>/dev/null'));
    return $output === '' ? 0 : (int) $output;
}

// ffmpeg is only ever spawned by build_play_pipeline_cmd (the live
// playback pipeline) — preloading/caching only ever runs yt-dlp, never
// ffmpeg — so this uniquely identifies "is the current track's audio
// pipeline still active", used by queue_should_advance as a more reliable
// finished-signal than yt-dlp's duration estimate alone.
function is_pipeline_running(): bool
{
    return trim((string) shell_exec('pgrep -f ' . escapeshellarg(OUR_FFMPEG_PATTERN) . ' 2>/dev/null')) !== '';
}

function stop_existing_pipeline(): void
{
    shell_exec('pkill -f yt-dlp 2>/dev/null');
    shell_exec('pkill -f ' . escapeshellarg(OUR_FFMPEG_PATTERN) . ' 2>/dev/null');
    // Catches any metadata writer stuck from a prior play attempt before
    // the timeout guard existed (or before it elapses) — matched on the
    // fifo path itself, not a generic name, so it can't catch anything
    // unrelated to this app.
    shell_exec('pkill -f ' . escapeshellarg(YOUTUBE_FIFO_PATH . '.metadata') . ' 2>/dev/null');

    for ($i = 0; $i < 10; $i++) {
        $stillRunning = trim((string) shell_exec('pgrep -f ' . escapeshellarg('yt-dlp|' . OUR_FFMPEG_PATTERN) . ' 2>/dev/null'));
        if ($stillRunning === '') {
            return;
        }
        usleep(200000);
    }

    // Still alive after 2s of grace — force it rather than let a stuck
    // process linger indefinitely and stack up further with every
    // subsequent play/stop/seek call.
    shell_exec('pkill -9 -f yt-dlp 2>/dev/null');
    shell_exec('pkill -9 -f ' . escapeshellarg(OUR_FFMPEG_PATTERN) . ' 2>/dev/null');
}

// Resolves a video straight to its underlying CDN audio URL — no download,
// just format-selection metadata. See resolve_direct_stream_url for why this
// is tried before falling back to the fifo pipeline at all.
//
// Prefers m4a over the plain "bestaudio" selector (usually opus-in-webm):
// confirmed live that OwnTone/ffmpeg can't seek an opus/webm stream over
// HTTP (player_playback_seek succeeds but progress never actually moves),
// while an m4a/mp4 stream seeks correctly — mp4's moov atom index makes it
// randomly seekable in a way a live opus/webm stream isn't. Falls back to
// bestaudio for the rare video with no m4a rendition.
function build_resolve_direct_stream_url_cmd(string $youtubeUrl): string
{
    return sprintf(
        '%s 15 %s --no-playlist -f "bestaudio[ext=m4a]/bestaudio" -g %s 2>/dev/null',
        TIMEOUT_BIN,
        YTDLP_BIN,
        escapeshellarg($youtubeUrl)
    );
}

function resolve_direct_stream_url(string $youtubeUrl): ?string
{
    $out = trim((string) shell_exec(build_resolve_direct_stream_url_cmd($youtubeUrl)));
    $firstLine = trim(strtok($out, "\n") ?: '');
    return preg_match('#^https?://#i', $firstLine) ? $firstLine : null;
}

function cache_resolved_stream_url(string $youtubeUrl, string $streamUrl, string $path = RESOLVED_STREAM_CACHE_FILE): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, json_encode(['youtube_url' => $youtubeUrl, 'stream_url' => $streamUrl]));
}

// Only returns a hit for the exact url last resolved — anything else (a
// different track, or nothing cached yet) is a miss, letting the caller
// fall back to a fresh resolve.
function get_cached_stream_url(string $youtubeUrl, string $path = RESOLVED_STREAM_CACHE_FILE): ?string
{
    $cached = read_json_file($path);
    if (($cached['youtube_url'] ?? null) !== $youtubeUrl) {
        return null;
    }
    return $cached['stream_url'] ?? null;
}

// The browser never talks to OwnTone directly — it only ever calls
// backend.php, which holds real OwnTone credentials server-side (see
// OWNTONE_AUTH_USERNAME/PASSWORD above) and proxies through. Without this,
// the browser would need those same credentials itself to authenticate its
// own direct calls, and anything shipped to the browser (config.js is a
// plain static file, gitignored or not) is readable by anyone who can load
// the page — defeating the point of OwnTone having auth at all.
function handle_owntone_player(): void
{
    echo json_encode(owntone_get('/api/player'));
}

function handle_owntone_queue(): void
{
    echo json_encode(owntone_get('/api/queue'));
}

function handle_owntone_volume(int $volume): void
{
    owntone_put('/api/player/volume?volume=' . $volume);
    echo json_encode(['status' => 'ok']);
}

function handle_owntone_pause(): void
{
    owntone_put('/api/player/pause');
    echo json_encode(['status' => 'ok']);
}

function handle_owntone_resume(): void
{
    owntone_put('/api/player/play');
    echo json_encode(['status' => 'ok']);
}

function owntone_request_status(string $path, string $method): int
{
    $ch = curl_init(OWNTONE_BASE . $path);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    apply_owntone_auth($ch);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status;
}

function owntone_post_status(string $path): int
{
    return owntone_request_status($path, 'POST');
}

// Handing OwnTone the resolved CDN URL directly (as a plain DATA_KIND_HTTP
// stream item) skips the whole fifo/ffmpeg/named-pipe pipeline entirely —
// OwnTone fetches and decodes it itself. Returns null (not an error array)
// on any failure so the caller falls back to the fifo pipeline instead of
// surfacing this as a user-facing error.
function attempt_direct_http_play(string $youtubeUrl, string $title, string $channel, string $thumbnailUrl): ?array
{
    $streamUrl = resolve_direct_stream_url($youtubeUrl);
    if ($streamUrl === null) {
        return null;
    }

    $httpStatus = owntone_post_status('/api/queue/items/add?uris=' . rawurlencode($streamUrl) . '&clear=true&playback=start');
    if ($httpStatus < 200 || $httpStatus >= 300) {
        return null;
    }

    // The add call only enqueues it — a bad/expired URL or a CDN 403 only
    // surfaces once OwnTone actually tries to open and probe the stream,
    // which takes noticeably longer than a local pipe (confirmed live: up
    // to a couple seconds before state flips from its initial "pause" to
    // "play"), so this polls for a bit rather than judging on one snapshot.
    $isPlaying = false;
    for ($i = 0; $i < 8; $i++) {
        usleep(500000);
        $player = owntone_get('/api/player');
        if (($player['state'] ?? '') === 'play') {
            $isPlaying = true;
            break;
        }
    }
    if (!$isPlaying) {
        owntone_put('/api/player/stop');
        return null;
    }

    cache_resolved_stream_url($youtubeUrl, $streamUrl);

    // OwnTone has no tags to scan from a bare CDN url, so metadata (title
    // shown in its UI/AirPlay clients) has to be set explicitly here —
    // unlike the fifo path, this needs no shairport-style metadata pipe.
    $metaQuery = array_filter([
        'title' => $title !== '' ? $title : null,
        'artist' => $channel !== '' ? $channel : null,
        'artwork_url' => $thumbnailUrl !== '' ? $thumbnailUrl : null,
    ]);
    if (!empty($metaQuery)) {
        owntone_put('/api/queue/items/now_playing?' . http_build_query($metaQuery));
    }

    return [
        'status' => 'ok',
        'title' => $title ?: null,
        'thumbnail' => $thumbnailUrl ?: null,
        'channel' => $channel ?: null,
    ];
}

// The fifo pipeline: yt-dlp|ffmpeg transcodes into a named pipe OwnTone
// reads as a library track, with a second named pipe carrying shairport-
// style metadata (title/artist/artwork/progress) alongside it. Kept as the
// fallback for whatever attempt_direct_http_play can't handle (a CDN 403,
// or any seek — direct-HTTP playback has no local seek support).
function play_via_pipe(string $url, int $startAtSeconds, ?string $cachedAudioPath, string $title, string $channel, string $thumbnailUrl): array
{
    $tracks = owntone_get('/api/library/files?directory=' . rawurlencode(OWNTONE_PIPE_DIRECTORY));
    $trackId = extract_track_id_from_tracks_json($tracks, YOUTUBE_FIFO_MATCH);

    if ($trackId === null) {
        return ['status' => 'error', 'message' => 'pipe track not found in OwnTone library'];
    }

    $durationSeconds = (int) round((float) trim((string) shell_exec(build_yt_dlp_duration_cmd($url))));
    $artworkBytes = $thumbnailUrl !== '' ? fetch_url_bytes($thumbnailUrl) : '';

    // Queue + start playback BEFORE launching the pipeline, so the new
    // queue item already exists by the time metadata arrives on the pipe.
    // OwnTone applies incoming metadata to whatever item is currently
    // active — if that call happens after the metadata write (as it did
    // when the frontend made this call separately, later), the metadata
    // lands on the stale previous item instead of this one.
    owntone_post('/api/queue/items/add?uris=library:track:' . $trackId . '&clear=true&playback=start');

    $metadataFifoPath = YOUTUBE_FIFO_PATH . '.metadata';
    ensure_metadata_pipe_exists($metadataFifoPath);
    $metadataXml = build_pipe_metadata_xml($title, $channel, $durationSeconds, $artworkBytes, $startAtSeconds);

    shell_exec(build_play_pipeline_cmd($url, YOUTUBE_FIFO_PATH, $metadataFifoPath, $metadataXml, $cachedAudioPath, $startAtSeconds));

    // Not cached yet (fresh live-streamed play): fetch a full copy in the
    // background so this track becomes seekable a little while into
    // playback, without delaying the start of playback itself.
    if ($cachedAudioPath === null) {
        ensure_current_track_cached($url);
    }

    return [
        'status' => 'ok',
        'track_id' => $trackId,
        'title' => $title ?: null,
        'thumbnail' => $thumbnailUrl ?: null,
        'channel' => $channel ?: null,
    ];
}

// The actual play — assumes the playback lock is already held by the
// caller. Split out from play_url() so callers that also need to mutate
// queue_state.json/confirmed_playing.json atomically alongside the play
// itself (handle_play_queue, advance_queue_if_finished) can do the whole
// sequence under ONE lock acquisition instead of racing the daemon's
// independent poll in the gap between two separate lock acquisitions —
// confirmed live: that gap let a stale "confirmed playing" flag from the
// *previous* track combine with a transient mid-transition idle blip to
// make the daemon think a just-started track had already finished,
// wiping queue_state right after a fresh play_queue call.
function play_url_body(string $url, int $startAtSeconds, ?string $cachedAudioPath): array
{
    stop_existing_pipeline();
    reset_confirmed_playing();

    $oembed = fetch_youtube_oembed($url);
    $title = $oembed['title'] ?? '';
    $channel = $oembed['author_name'] ?? '';
    $thumbnailUrl = $oembed['thumbnail_url'] ?? '';

    // Seeks always go through the fifo/cached-file path below. A fresh
    // play tries the simpler direct-HTTP path first, falling back to
    // the fifo pipeline only if OwnTone can't open the resolved URL.
    if ($startAtSeconds === 0) {
        $direct = attempt_direct_http_play($url, $title, $channel, $thumbnailUrl);
        if ($direct !== null) {
            mark_current_track_is_direct(true);
            if ($cachedAudioPath === null) {
                ensure_current_track_cached($url);
            }
            return $direct;
        }
    }

    mark_current_track_is_direct(false);
    return play_via_pipe($url, $startAtSeconds, $cachedAudioPath, $title, $channel, $thumbnailUrl);
}

function resolve_cached_audio_path(string $url): ?string
{
    $cachePath = audio_cache_path($url);
    return ($cachePath !== null && file_exists($cachePath)) ? $cachePath : null;
}

function play_url(string $url, int $startAtSeconds = 0): array
{
    if (!is_youtube_url($url)) {
        return ['status' => 'error', 'message' => 'not a valid YouTube URL'];
    }

    // If maybe_preload_next()/ensure_current_track_cached() already fetched
    // this exact track, skip yt-dlp's resolve+download step entirely —
    // that's the delay preloading exists to remove. Seeking additionally
    // *requires* the cache: a live yt-dlp stream has no random access.
    $cachedAudioPath = resolve_cached_audio_path($url);

    if ($startAtSeconds > 0 && $cachedAudioPath === null) {
        return ['status' => 'error', 'message' => 'seek not ready yet — track is not fully cached'];
    }

    return with_playback_lock(function () use ($url, $startAtSeconds, $cachedAudioPath) {
        return play_url_body($url, $startAtSeconds, $cachedAudioPath);
    });
}

function handle_play(string $url): void
{
    echo json_encode(play_url($url));
}

function ensure_current_track_cached(string $url): void
{
    $cachePath = audio_cache_path($url);
    if ($cachePath === null || file_exists($cachePath) || file_exists($cachePath . '.part')) {
        return;
    }
    // Circuit breaker: skip this optional background cache rather than
    // add another yt-dlp process on top of whatever's already running.
    // Losing seek-readiness/instant-switch for this one track is a far
    // smaller cost than risking another process pile-up.
    if (running_ytdlp_count() >= MAX_CONCURRENT_YTDLP) {
        return;
    }
    if (!is_dir(AUDIO_CACHE_DIR)) {
        mkdir(AUDIO_CACHE_DIR, 0755, true);
    }
    shell_exec(build_preload_cmd($url, $cachePath));
}


// A direct-HTTP item is a real seekable source as far as OwnTone/ffmpeg is
// concerned (the CDN URL supports byte-range requests) — no local cache or
// pipeline restart needed, unlike the fifo path below.
function seek_direct_http_playback(int $targetSeconds): array
{
    return with_playback_lock(function () use ($targetSeconds) {
        reset_confirmed_playing(true);
        $httpStatus = owntone_request_status('/api/player/seek?position_ms=' . ($targetSeconds * 1000), 'PUT');
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return ['status' => 'error', 'message' => 'seek failed'];
        }
        return ['status' => 'ok'];
    });
}

function handle_seek(int $targetSeconds): void
{
    if ($targetSeconds < 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'invalid seek position']);
        return;
    }

    $state = load_queue_state();
    $currentIndex = $state['current_index'];
    if ($currentIndex < 0 || !isset($state['items'][$currentIndex])) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'nothing is playing']);
        return;
    }

    if (is_current_track_direct()) {
        echo json_encode(seek_direct_http_playback($targetSeconds));
        return;
    }

    $url = $state['items'][$currentIndex]['webpage_url'] ?? '';
    echo json_encode(play_url($url, $targetSeconds));
}

// Kicks off a background download of the next sequential track so the
// following Play/Next/auto-advance can skip straight past yt-dlp. Clears
// any other cached file first, since only one preload is ever wanted at a
// time — otherwise an abandoned preload (user skipped past it) would sit
// on disk forever.
function maybe_preload_next(array $items, int $currentIndex, bool $shuffle, string $repeat = 'off'): void
{
    if (!is_dir(AUDIO_CACHE_DIR)) {
        mkdir(AUDIO_CACHE_DIR, 0755, true);
    }

    // The current track's cache now persists indefinitely (kept alive for
    // repeat seeking, see play_url/ensure_current_track_cached) instead of
    // being deleted right after use, so cleanup has to run on every call,
    // not just when there's a next track to preload — otherwise a track
    // that becomes "no longer current" (with nothing next to replace it,
    // e.g. queue exhausted) would never get its cache cleared.
    $currentUrl = $items[$currentIndex]['webpage_url'] ?? '';
    $currentCachePath = is_youtube_url($currentUrl) ? audio_cache_path($currentUrl) : null;

    $nextUrl = next_preload_target($items, $currentIndex, $shuffle, $repeat);
    $nextCachePath = $nextUrl !== null ? audio_cache_path($nextUrl) : null;

    // Never the current or next track's cache, or their in-flight .part
    // downloads — anything else is a stray leftover from a track that's
    // moved on.
    $keep = array_filter([
        $currentCachePath,
        $currentCachePath !== null ? $currentCachePath . '.part' : null,
        $nextCachePath,
        $nextCachePath !== null ? $nextCachePath . '.part' : null,
    ]);
    foreach (glob(AUDIO_CACHE_DIR . '/*') ?: [] as $file) {
        if (!in_array($file, $keep, true)) {
            @unlink($file);
        }
    }

    if ($nextCachePath === null || file_exists($nextCachePath) || file_exists($nextCachePath . '.part')) {
        return;
    }

    // Same circuit breaker as ensure_current_track_cached: skip the
    // preload rather than add another yt-dlp process on top of whatever's
    // already running.
    if (running_ytdlp_count() >= MAX_CONCURRENT_YTDLP) {
        return;
    }

    shell_exec(build_preload_cmd($nextUrl, $nextCachePath));
}

function handle_play_queue(array $items, int $index, bool $shuffle, string $repeat = 'off'): void
{
    if (!in_array($repeat, ['off', 'all', 'one'], true)) {
        $repeat = 'off';
    }

    if ($index < 0 || $index >= count($items) || !is_youtube_url($items[$index]['webpage_url'] ?? '')) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'invalid queue or index']);
        return;
    }

    $url = $items[$index]['webpage_url'];
    $cachedAudioPath = resolve_cached_audio_path($url);

    // Saving the new queue state and actually playing it must happen under
    // ONE lock acquisition — see play_url_body's comment for the race this
    // closes (the daemon polling in the gap between two separate locks).
    $result = with_playback_lock(function () use ($items, $index, $shuffle, $repeat, $url, $cachedAudioPath) {
        save_queue_state($items, $index, $shuffle, $repeat);
        return play_url_body($url, 0, $cachedAudioPath);
    });
    maybe_preload_next($items, $index, $shuffle, $repeat);

    echo json_encode($result);
}

// Stops playback and clears the persisted queue so bin/queue-daemon.php
// has nothing left to auto-advance — a plain OwnTone pause wouldn't be
// enough, since the daemon (and a manual Next/Prev) both act on
// QUEUE_STATE_FILE independently of OwnTone's own playback state.
function handle_stop(): void
{
    $result = with_playback_lock(function () {
        stop_existing_pipeline();
        owntone_put('/api/player/stop');
        save_queue_state([], -1, false);
        reset_confirmed_playing();
        return ['status' => 'ok'];
    });
    echo json_encode($result);
}

function handle_set_shuffle(bool $shuffle): void
{
    $state = load_queue_state();
    save_queue_state($state['items'], $state['current_index'], $shuffle, $state['repeat']);
    echo json_encode(['status' => 'ok']);
}

function handle_set_repeat(string $repeat): void
{
    if (!in_array($repeat, ['off', 'all', 'one'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'invalid repeat mode']);
        return;
    }
    $state = load_queue_state();
    save_queue_state($state['items'], $state['current_index'], $state['shuffle'], $repeat);
    echo json_encode(['status' => 'ok']);
}

// Reports whether the currently-playing track is fully cached yet — the
// frontend uses this to decide whether the progress bar accepts a drag.
function handle_queue_state(): void
{
    $state = load_queue_state();
    $currentUrl = isset($state['items'][$state['current_index']])
        ? ($state['items'][$state['current_index']]['webpage_url'] ?? '')
        : '';
    $cachePath = $currentUrl !== '' ? audio_cache_path($currentUrl) : null;
    $state['seekable'] = ($cachePath !== null && file_exists($cachePath)) || is_current_track_direct();
    echo json_encode($state);
}

// Invoked by bin/queue-daemon.php on a loop — not reachable over HTTP.
function advance_queue_if_finished(): void
{
    // The read-decide-act sequence below races a concurrent user-triggered
    // play (handle_play_queue) unless both hold the SAME lock for their
    // whole operation — see play_url_body's comment. A busy lock here just
    // means "try again next daemon tick" (2s later), so failing to acquire
    // it is not an error worth surfacing anywhere.
    $result = with_playback_lock(function () {
        $state = load_queue_state();
        $items = $state['items'];
        $currentIndex = $state['current_index'];
        $shuffle = $state['shuffle'];
        $repeat = $state['repeat'];

        $player = owntone_get('/api/player');
        $hasConfirmedPlaying = mark_confirmed_playing_if_active($player);

        // is_pipeline_running() only means something for a fifo track (it
        // checks for OUR OWN ffmpeg process) — a direct-HTTP track never has
        // one, so it's always false regardless of whether that track is
        // fine, paused, or mid-seek-buffering. Treating that as "always
        // true" (i.e. disabling this signal) for direct tracks avoids
        // misreading a plain pause or a seek's brief re-buffer as finished —
        // confirmed live: seeking a direct track was triggering an
        // immediate false auto-advance via this exact path. Uses the
        // persisted is_current_track_direct flag, not a live OwnTone lookup
        // — that lookup goes blank (item_id 0) during exactly the moments
        // this needs to cover, which defeated an earlier version of this
        // same guard.
        $pipelineStillRunning = is_current_track_direct() ? true : is_pipeline_running();

        if (!queue_should_advance($player, $currentIndex, count($items), $pipelineStillRunning, $hasConfirmedPlaying)) {
            return ['advanced' => false];
        }

        $nextIndex = next_queue_index($currentIndex, count($items), $shuffle, $repeat);
        if ($nextIndex === null) {
            save_queue_state([], -1, false);
            return ['advanced' => false];
        }

        save_queue_state($items, $nextIndex, $shuffle, $repeat);
        $nextUrl = $items[$nextIndex]['webpage_url'] ?? '';
        play_url_body($nextUrl, 0, resolve_cached_audio_path($nextUrl));

        return ['advanced' => true, 'items' => $items, 'nextIndex' => $nextIndex, 'shuffle' => $shuffle, 'repeat' => $repeat];
    });

    if ($result['advanced'] ?? false) {
        maybe_preload_next($result['items'], $result['nextIndex'], $result['shuffle'], $result['repeat']);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    enforce_dashboard_auth();

    // Real browser-navigable GET routes (window.open/<img>/<audio> src
    // targets), not JSON POST actions like everything else below.
    $getAction = $_GET['action'] ?? '';
    if ($getAction === 'stream_redirect') {
        handle_stream_redirect((string) ($_GET['url'] ?? ''));
        return;
    }
    if ($getAction === 'owntone_artwork') {
        handle_owntone_artwork();
        return;
    }
    if ($getAction === 'owntone_stream') {
        handle_owntone_stream();
        return;
    }

    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'cache_search') {
        $results = json_decode((string) ($_POST['results'] ?? '[]'), true);
        handle_cache_search(is_array($results) ? $results : []);
    } elseif ($action === 'play') {
        handle_play((string) ($_POST['url'] ?? ''));
    } elseif ($action === 'play_queue') {
        $items = json_decode((string) ($_POST['items'] ?? '[]'), true);
        handle_play_queue(
            is_array($items) ? $items : [],
            (int) ($_POST['index'] ?? -1),
            (bool) ($_POST['shuffle'] ?? false),
            (string) ($_POST['repeat'] ?? 'off')
        );
    } elseif ($action === 'set_shuffle') {
        handle_set_shuffle((bool) ($_POST['shuffle'] ?? false));
    } elseif ($action === 'set_repeat') {
        handle_set_repeat((string) ($_POST['repeat'] ?? 'off'));
    } elseif ($action === 'stop') {
        handle_stop();
    } elseif ($action === 'seek') {
        handle_seek((int) ($_POST['seconds'] ?? -1));
    } elseif ($action === 'queue_state') {
        handle_queue_state();
    } elseif ($action === 'playlists_list') {
        handle_playlists_list();
    } elseif ($action === 'playlist_create') {
        handle_playlist_create((string) ($_POST['name'] ?? ''));
    } elseif ($action === 'playlist_add_item') {
        handle_playlist_add_item((string) ($_POST['name'] ?? ''), [
            'webpage_url' => (string) ($_POST['webpage_url'] ?? ''),
            'title' => (string) ($_POST['title'] ?? ''),
            'thumbnail' => (string) ($_POST['thumbnail'] ?? ''),
            'duration_string' => (string) ($_POST['duration_string'] ?? ''),
            'channel' => (string) ($_POST['channel'] ?? ''),
        ]);
    } elseif ($action === 'playlist_remove_item') {
        handle_playlist_remove_item((string) ($_POST['name'] ?? ''), (string) ($_POST['webpage_url'] ?? ''));
    } elseif ($action === 'last_search') {
        handle_last_search();
    } elseif ($action === 'resolve_url') {
        handle_resolve_url((string) ($_POST['url'] ?? ''));
    } elseif ($action === 'resolve_mix_playlist') {
        handle_resolve_mix_playlist((string) ($_POST['url'] ?? ''));
    } elseif ($action === 'owntone_player') {
        handle_owntone_player();
    } elseif ($action === 'owntone_queue') {
        handle_owntone_queue();
    } elseif ($action === 'owntone_volume') {
        handle_owntone_volume((int) ($_POST['volume'] ?? 0));
    } elseif ($action === 'owntone_pause') {
        handle_owntone_pause();
    } elseif ($action === 'owntone_resume') {
        handle_owntone_resume();
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'unknown action']);
    }
}

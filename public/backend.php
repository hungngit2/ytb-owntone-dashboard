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
define('OWNTONE_PIPE_DIRECTORY', '/srv/music/pipes');
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

// Pure decision: given OwnTone's raw /api/player response, has the current
// item finished? Rejects a missing/zero item_length_ms (duration lookup
// failed or hasn't landed yet) rather than guessing — better to not
// auto-advance than to advance while still mid-playback. Whether there's
// a valid *next* item is next_queue_index's concern, not this one.
//
// Two independent signals, either is sufficient once genuinely paused:
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
function queue_should_advance(array $player, int $currentIndex, int $itemCount, bool $pipelineStillRunning = true): bool
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

    $nearEndByDuration = $lengthMs > 0 && $progressMs >= ($lengthMs - 4000);
    $pipelineExitedAfterStarting = !$pipelineStillRunning && $progressMs > 0;

    return $nearEndByDuration || $pipelineExitedAfterStarting;
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

function owntone_get(string $path): array
{
    $ch = curl_init(OWNTONE_BASE . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
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

function play_url(string $url, int $startAtSeconds = 0): array
{
    if (!is_youtube_url($url)) {
        return ['status' => 'error', 'message' => 'not a valid YouTube URL'];
    }

    // If maybe_preload_next()/ensure_current_track_cached() already fetched
    // this exact track, skip yt-dlp's resolve+download step entirely —
    // that's the delay preloading exists to remove. Seeking additionally
    // *requires* the cache: a live yt-dlp stream has no random access.
    $cachePath = audio_cache_path($url);
    $cachedAudioPath = ($cachePath !== null && file_exists($cachePath)) ? $cachePath : null;

    if ($startAtSeconds > 0 && $cachedAudioPath === null) {
        return ['status' => 'error', 'message' => 'seek not ready yet — track is not fully cached'];
    }

    return with_playback_lock(function () use ($url, $startAtSeconds, $cachedAudioPath) {
        stop_existing_pipeline();

        $tracks = owntone_get('/api/library/files?directory=' . rawurlencode(OWNTONE_PIPE_DIRECTORY));
        $trackId = extract_track_id_from_tracks_json($tracks, YOUTUBE_FIFO_MATCH);

        if ($trackId === null) {
            return ['status' => 'error', 'message' => 'pipe track not found in OwnTone library'];
        }

        $oembed = fetch_youtube_oembed($url);
        $title = $oembed['title'] ?? '';
        $channel = $oembed['author_name'] ?? '';
        $thumbnailUrl = $oembed['thumbnail_url'] ?? '';
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

    save_queue_state($items, $index, $shuffle, $repeat);

    $result = play_url($items[$index]['webpage_url']);
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
    $state['seekable'] = $cachePath !== null && file_exists($cachePath);
    echo json_encode($state);
}

// Invoked by bin/queue-daemon.php on a loop — not reachable over HTTP.
function advance_queue_if_finished(): void
{
    $state = load_queue_state();
    $items = $state['items'];
    $currentIndex = $state['current_index'];
    $shuffle = $state['shuffle'];
    $repeat = $state['repeat'];

    if (!queue_should_advance(owntone_get('/api/player'), $currentIndex, count($items), is_pipeline_running())) {
        return;
    }

    $nextIndex = next_queue_index($currentIndex, count($items), $shuffle, $repeat);
    if ($nextIndex === null) {
        save_queue_state([], -1, false);
        return;
    }

    save_queue_state($items, $nextIndex, $shuffle, $repeat);
    play_url($items[$nextIndex]['webpage_url'] ?? '');
    maybe_preload_next($items, $nextIndex, $shuffle, $repeat);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
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
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'unknown action']);
    }
}

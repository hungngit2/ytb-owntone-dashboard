<?php

define('OWNTONE_BASE', 'http://127.0.0.1:3689');
// Deliberately NOT under /opt/docker/owntone/pipes: that path traverses
// /mnt/appsrv/docker, whose permissions have been observed to reset to
// block "other" access (likely on container/compose recreation), silently
// breaking www-data's ability to write here. This path is owned by
// www-data directly with a clean, non-restrictive traversal chain.
define('YOUTUBE_FIFO_PATH', '/mnt/appsrv/ytb-pipes/youtube.fifo');
define('YOUTUBE_FIFO_MATCH', 'youtube');
// Path as OwnTone sees it inside its container/library config — distinct from
// YOUTUBE_FIFO_PATH, which is the host path used to write the audio stream.
define('OWNTONE_PIPE_DIRECTORY', '/srv/music/pipes');
// Absolute path outside nginx's document root, which on the deployed host
// (root /mnt/appsrv/www;) covers this app's whole parent directory — a
// relative "../data" would land inside /mnt/appsrv/www/data and be directly
// web-reachable. Adjust if your document root differs.
define('PLAYLIST_FILE', '/mnt/appsrv/ytb-data/playlist.json');
define('LAST_SEARCH_FILE', '/mnt/appsrv/ytb-data/last_search.json');
// Server-side "what queue/playlist and index are currently playing" state,
// read by bin/queue-daemon.php so auto-advance-to-next-track works even
// with no browser open — the daemon is what's watching, not any tab.
define('QUEUE_STATE_FILE', '/mnt/appsrv/ytb-data/queue_state.json');

function is_youtube_url(string $url): bool
{
    return (bool) preg_match(
        '#^https?://(www\.)?(youtube\.com/watch\?v=|youtu\.be/|youtube\.com/shorts/)#i',
        trim($url)
    );
}

function build_play_pipeline_cmd(string $youtubeUrl, string $fifoPath, string $metadataFifoPath, string $metadataXml): string
{
    $audioPipeline = sprintf(
        'yt-dlp --no-playlist -f bestaudio -o - %s | ffmpeg -re -i pipe:0 -f wav -ar 44100 -ac 2 pipe:1 > %s',
        escapeshellarg($youtubeUrl),
        escapeshellarg($fifoPath)
    );

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

function build_pipe_metadata_xml(string $title, string $artist, int $durationSeconds, string $artworkBytes = ''): string
{
    $xml = build_metadata_item('core', 'minm', $title) . build_metadata_item('core', 'asar', $artist);

    if ($durationSeconds > 0) {
        // OwnTone's parser rejects the whole progress item if any of the
        // three RTP-timestamp fields parses to exactly zero, so "start"
        // and "pos" use 1 as a nonzero reference point (still correctly
        // yields pos_ms=0, i.e. "at the beginning") rather than 0.
        $start = 1;
        $pos = 1;
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

// Queue state is {items: [...], current_index: N, shuffle: bool} — an
// associative shape, distinct from the flat lists read_json_file/
// write_json_file expect.
function load_queue_state(string $path = QUEUE_STATE_FILE): array
{
    if (!file_exists($path)) {
        return ['items' => [], 'current_index' => -1, 'shuffle' => false];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
        return ['items' => [], 'current_index' => -1, 'shuffle' => false];
    }

    return [
        'items' => $decoded['items'],
        'current_index' => (int) ($decoded['current_index'] ?? -1),
        'shuffle' => (bool) ($decoded['shuffle'] ?? false),
    ];
}

function save_queue_state(array $items, int $currentIndex, bool $shuffle = false, string $path = QUEUE_STATE_FILE): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($path, json_encode(['items' => $items, 'current_index' => $currentIndex, 'shuffle' => $shuffle]));
}

// Pure decision: given OwnTone's raw /api/player response, has the current
// item finished? Rejects a missing/zero item_length_ms (duration lookup
// failed or hasn't landed yet) rather than guessing — better to not
// auto-advance than to advance while still mid-playback. Whether there's
// a valid *next* item is next_queue_index's concern, not this one.
function queue_should_advance(array $player, int $currentIndex, int $itemCount): bool
{
    if ($currentIndex < 0 || $itemCount <= 0) {
        return false;
    }

    $isPlaying = ($player['state'] ?? '') === 'play';
    $progressMs = (int) ($player['item_progress_ms'] ?? 0);
    $lengthMs = (int) ($player['item_length_ms'] ?? 0);

    // 4s tolerance, not 1s: our reported duration comes from yt-dlp's
    // rounded-to-the-second estimate, and the actual decoded/streamed audio
    // routinely ends a couple of seconds short of it (observed ~2.2s gap in
    // practice) — a tight window left finished tracks stuck forever, never
    // satisfying "near the end".
    return !$isPlaying && $lengthMs > 0 && $progressMs >= ($lengthMs - 4000);
}

// Pure: which index plays next. Sequential mode stops at the end (returns
// null). Shuffle mode picks any other index and never runs out — $randomPicker
// is injectable so tests can verify the "never repeat the current index"
// behavior deterministically instead of asserting on real randomness.
function next_queue_index(int $currentIndex, int $itemCount, bool $shuffle, ?callable $randomPicker = null): ?int
{
    if ($itemCount <= 0) {
        return null;
    }

    if (!$shuffle) {
        $next = $currentIndex + 1;
        return $next < $itemCount ? $next : null;
    }

    if ($itemCount === 1) {
        return null;
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
    $ch = curl_init(OWNTONE_BASE . $path);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
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

function play_url(string $url): array
{
    if (!is_youtube_url($url)) {
        return ['status' => 'error', 'message' => 'not a valid YouTube URL'];
    }

    shell_exec('pkill -f yt-dlp 2>/dev/null');
    shell_exec('pkill -f ffmpeg 2>/dev/null');
    // Catches any metadata writer stuck from a prior play attempt before
    // the timeout guard existed (or before it elapses) — matched on the
    // fifo path itself, not a generic name, so it can't catch anything
    // unrelated to this app.
    shell_exec('pkill -f ' . escapeshellarg(YOUTUBE_FIFO_PATH . '.metadata') . ' 2>/dev/null');

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
    $metadataXml = build_pipe_metadata_xml($title, $channel, $durationSeconds, $artworkBytes);

    shell_exec(build_play_pipeline_cmd($url, YOUTUBE_FIFO_PATH, $metadataFifoPath, $metadataXml));

    return [
        'status' => 'ok',
        'track_id' => $trackId,
        'title' => $title ?: null,
        'thumbnail' => $thumbnailUrl ?: null,
        'channel' => $channel ?: null,
    ];
}

function handle_play(string $url): void
{
    echo json_encode(play_url($url));
}

function handle_play_queue(array $items, int $index, bool $shuffle): void
{
    if ($index < 0 || $index >= count($items) || !is_youtube_url($items[$index]['webpage_url'] ?? '')) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'invalid queue or index']);
        return;
    }

    save_queue_state($items, $index, $shuffle);

    echo json_encode(play_url($items[$index]['webpage_url']));
}

function handle_set_shuffle(bool $shuffle): void
{
    $state = load_queue_state();
    save_queue_state($state['items'], $state['current_index'], $shuffle);
    echo json_encode(['status' => 'ok']);
}

// Invoked by bin/queue-daemon.php on a loop — not reachable over HTTP.
function advance_queue_if_finished(): void
{
    $state = load_queue_state();
    $items = $state['items'];
    $currentIndex = $state['current_index'];
    $shuffle = $state['shuffle'];

    if (!queue_should_advance(owntone_get('/api/player'), $currentIndex, count($items))) {
        return;
    }

    $nextIndex = next_queue_index($currentIndex, count($items), $shuffle);
    if ($nextIndex === null) {
        save_queue_state([], -1, false);
        return;
    }

    save_queue_state($items, $nextIndex, $shuffle);
    play_url($items[$nextIndex]['webpage_url'] ?? '');
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
            (bool) ($_POST['shuffle'] ?? false)
        );
    } elseif ($action === 'set_shuffle') {
        handle_set_shuffle((bool) ($_POST['shuffle'] ?? false));
    } elseif ($action === 'queue_state') {
        echo json_encode(load_queue_state());
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

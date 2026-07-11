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

function handle_playlist_list(): void
{
    echo json_encode(load_playlist());
}

function handle_playlist_add(array $item): void
{
    $url = $item['webpage_url'] ?? '';
    if (!is_youtube_url($url)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'not a valid YouTube URL']);
        return;
    }

    $entry = [
        'webpage_url' => $url,
        'title' => $item['title'] ?? '',
        'thumbnail' => $item['thumbnail'] ?? '',
        'duration_string' => $item['duration_string'] ?? '',
        'channel' => $item['channel'] ?? '',
    ];

    $items = add_to_playlist_items(load_playlist(), $entry);
    save_playlist($items);

    echo json_encode(['status' => 'ok', 'items' => $items]);
}

function handle_playlist_remove(string $url): void
{
    $items = remove_from_playlist_items(load_playlist(), $url);
    save_playlist($items);

    echo json_encode(['status' => 'ok', 'items' => $items]);
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

function handle_play(string $url): void
{
    if (!is_youtube_url($url)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'not a valid YouTube URL']);
        return;
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
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'pipe track not found in OwnTone library']);
        return;
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

    echo json_encode([
        'status' => 'ok',
        'track_id' => $trackId,
        'title' => $title ?: null,
        'thumbnail' => $thumbnailUrl ?: null,
        'channel' => $channel ?: null,
    ]);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'cache_search') {
        $results = json_decode((string) ($_POST['results'] ?? '[]'), true);
        handle_cache_search(is_array($results) ? $results : []);
    } elseif ($action === 'play') {
        handle_play((string) ($_POST['url'] ?? ''));
    } elseif ($action === 'playlist_list') {
        handle_playlist_list();
    } elseif ($action === 'playlist_add') {
        handle_playlist_add([
            'webpage_url' => (string) ($_POST['webpage_url'] ?? ''),
            'title' => (string) ($_POST['title'] ?? ''),
            'thumbnail' => (string) ($_POST['thumbnail'] ?? ''),
            'duration_string' => (string) ($_POST['duration_string'] ?? ''),
            'channel' => (string) ($_POST['channel'] ?? ''),
        ]);
    } elseif ($action === 'playlist_remove') {
        handle_playlist_remove((string) ($_POST['webpage_url'] ?? ''));
    } elseif ($action === 'last_search') {
        handle_last_search();
    } elseif ($action === 'resolve_url') {
        handle_resolve_url((string) ($_POST['url'] ?? ''));
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'unknown action']);
    }
}

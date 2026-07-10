<?php

define('OWNTONE_BASE', 'http://127.0.0.1:3689');
define('YOUTUBE_FIFO_PATH', '/opt/docker/owntone/pipes/youtube.fifo');
define('YOUTUBE_FIFO_MATCH', 'youtube');
// Path as OwnTone sees it inside its container/library config — distinct from
// YOUTUBE_FIFO_PATH, which is the host path used to write the audio stream.
define('OWNTONE_PIPE_DIRECTORY', '/srv/music/pipes');
// Outside the web root (public/) so the raw JSON files are never web-reachable.
define('PLAYLIST_FILE', __DIR__ . '/../data/playlist.json');
define('LAST_SEARCH_FILE', __DIR__ . '/../data/last_search.json');

function is_youtube_url(string $url): bool
{
    return (bool) preg_match(
        '#^https?://(www\.)?(youtube\.com/watch\?v=|youtu\.be/|youtube\.com/shorts/)#i',
        trim($url)
    );
}

function build_yt_dlp_search_cmd(string $query): string
{
    $searchTerm = 'ytsearch30:' . $query;
    return sprintf(
        'yt-dlp --dump-json %s 2>/dev/null | jq -s \'[.[] | {title: .title, webpage_url: .webpage_url, duration_string: .duration_string, thumbnail: .thumbnail, channel: (.channel // .uploader)}]\'',
        escapeshellarg($searchTerm)
    );
}

function build_play_pipeline_cmd(string $youtubeUrl, string $fifoPath): string
{
    $pipeline = sprintf(
        'yt-dlp --no-playlist -f bestaudio -o - %s | ffmpeg -re -i pipe:0 -f wav -ar 44100 -ac 2 pipe:1 > %s',
        escapeshellarg($youtubeUrl),
        escapeshellarg($fifoPath)
    );

    return sprintf('nohup sh -c %s > /dev/null 2>&1 &', escapeshellarg($pipeline));
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

function handle_search(string $query): void
{
    if (trim($query) === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'empty query']);
        return;
    }

    $output = shell_exec(build_yt_dlp_search_cmd($query));

    if ($output === null || trim($output) === '') {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'search returned no results']);
        return;
    }

    $results = json_decode($output, true);
    if (!is_array($results)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'search output was not valid JSON']);
        return;
    }

    save_last_search($results);

    echo json_encode($results);
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

function handle_play(string $url): void
{
    if (!is_youtube_url($url)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'not a valid YouTube URL']);
        return;
    }

    shell_exec('pkill -f yt-dlp 2>/dev/null');
    shell_exec('pkill -f ffmpeg 2>/dev/null');

    shell_exec(build_play_pipeline_cmd($url, YOUTUBE_FIFO_PATH));

    $tracks = owntone_get('/api/library/files?directory=' . rawurlencode(OWNTONE_PIPE_DIRECTORY));
    $trackId = extract_track_id_from_tracks_json($tracks, YOUTUBE_FIFO_MATCH);

    if ($trackId === null) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'pipe track not found in OwnTone library']);
        return;
    }

    $oembed = fetch_youtube_oembed($url);

    echo json_encode([
        'status' => 'ok',
        'track_id' => $trackId,
        'title' => $oembed['title'] ?? null,
        'thumbnail' => $oembed['thumbnail_url'] ?? null,
        'channel' => $oembed['author_name'] ?? null,
    ]);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'search') {
        handle_search((string) ($_POST['query'] ?? ''));
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

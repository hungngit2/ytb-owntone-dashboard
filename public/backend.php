<?php

define('OWNTONE_BASE', 'http://127.0.0.1:3689');
define('YOUTUBE_FIFO_PATH', '/opt/docker/owntone/pipes/youtube.fifo');
define('YOUTUBE_FIFO_MATCH', 'youtube');
// Path as OwnTone sees it inside its container/library config — distinct from
// YOUTUBE_FIFO_PATH, which is the host path used to write the audio stream.
define('OWNTONE_PIPE_DIRECTORY', '/srv/music/pipes');

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
        'yt-dlp --dump-json %s 2>/dev/null | jq -s \'[.[] | {title: .title, webpage_url: .webpage_url, duration_string: .duration_string, thumbnail: .thumbnail}]\'',
        escapeshellarg($searchTerm)
    );
}

function build_play_pipeline_cmd(string $youtubeUrl, string $fifoPath): string
{
    $pipeline = sprintf(
        'yt-dlp --no-playlist -f bestaudio -o - %s | ffmpeg -i pipe:0 -f wav -ar 44100 -ac 2 pipe:1 > %s',
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

    echo json_encode($results);
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
    ]);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'search') {
        handle_search((string) ($_POST['query'] ?? ''));
    } elseif ($action === 'play') {
        handle_play((string) ($_POST['url'] ?? ''));
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'unknown action']);
    }
}

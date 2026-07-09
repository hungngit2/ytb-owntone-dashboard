<?php

define('OWNTONE_BASE', 'http://127.0.0.1:3689');
define('YOUTUBE_FIFO_PATH', '/opt/docker/owntone/pipes/youtube.fifo');
define('YOUTUBE_FIFO_MATCH', 'youtube');

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
        'yt-dlp -f bestaudio -o - %s | ffmpeg -i pipe:0 -f wav -ar 44100 -ac 2 pipe:1 > %s',
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

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'not yet implemented']);
}

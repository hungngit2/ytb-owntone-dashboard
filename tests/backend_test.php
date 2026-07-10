<?php
require __DIR__ . '/../public/backend.php';

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "PASS: $msg\n";
}

assert_true(is_youtube_url('https://www.youtube.com/watch?v=abc123'), 'accepts watch url');
assert_true(is_youtube_url('https://youtu.be/abc123'), 'accepts short url');
assert_true(is_youtube_url('https://youtube.com/shorts/abc123'), 'accepts shorts url');
assert_true(!is_youtube_url('not a url'), 'rejects plain text');
assert_true(!is_youtube_url('https://vimeo.com/123'), 'rejects other domains');

$searchCmd = build_yt_dlp_search_cmd("lo-fi beats");
assert_true(str_contains($searchCmd, 'yt-dlp'), 'search cmd calls yt-dlp');
assert_true(str_contains($searchCmd, "'ytsearch30:lo-fi beats'"), 'search cmd embeds escaped query');
assert_true(str_contains($searchCmd, 'jq'), 'search cmd pipes through jq');
assert_true(!str_contains($searchCmd, '; rm'), 'search cmd has no unescaped injection for sanity');

$injected = build_yt_dlp_search_cmd("foo'; rm -rf /");
assert_true(!str_contains($injected, "'foo'; rm -rf /"), 'single quotes in query are escaped, not passed through raw');

$playCmd = build_play_pipeline_cmd('https://youtu.be/abc123', '/opt/docker/owntone/pipes/youtube.fifo');
assert_true(str_starts_with($playCmd, 'nohup sh -c'), 'play cmd is wrapped in nohup');
assert_true(str_contains($playCmd, 'yt-dlp --no-playlist -f bestaudio'), 'play cmd calls yt-dlp for bestaudio with playlist expansion disabled');
assert_true(str_contains($playCmd, 'ffmpeg -i pipe:0'), 'play cmd pipes into ffmpeg');
assert_true(str_contains($playCmd, "'/opt/docker/owntone/pipes/youtube.fifo'"), 'play cmd writes to escaped fifo path');
assert_true(str_ends_with(trim($playCmd), '&'), 'play cmd is backgrounded');

$fixture = [
    'tracks' => [
        'items' => [
            ['id' => 42, 'path' => '/music/pipes/other.fifo', 'title' => 'other'],
            ['id' => 99, 'path' => '/music/pipes/youtube.fifo', 'title' => 'youtube'],
        ],
    ],
];
assert_true(extract_track_id_from_tracks_json($fixture, 'youtube') === 99, 'finds matching pipe track id');
assert_true(extract_track_id_from_tracks_json($fixture, 'nonexistent') === null, 'returns null when no match');
assert_true(extract_track_id_from_tracks_json(['tracks' => ['items' => []]], 'youtube') === null, 'returns null for empty list');
assert_true(extract_track_id_from_tracks_json([], 'youtube') === null, 'returns null for malformed response');

echo "All backend helper tests passed.\n";

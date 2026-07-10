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

$playlistItems = [];
$playlistItems = add_to_playlist_items($playlistItems, ['webpage_url' => 'https://youtu.be/aaa', 'title' => 'Song A']);
$playlistItems = add_to_playlist_items($playlistItems, ['webpage_url' => 'https://youtu.be/bbb', 'title' => 'Song B']);
assert_true(count($playlistItems) === 2, 'add_to_playlist_items appends new entries');
assert_true($playlistItems[0]['title'] === 'Song A', 'first added entry keeps its data');

$playlistItems = add_to_playlist_items($playlistItems, ['webpage_url' => 'https://youtu.be/aaa', 'title' => 'Song A Updated']);
assert_true(count($playlistItems) === 2, 'add_to_playlist_items dedupes by webpage_url instead of appending a duplicate');
assert_true($playlistItems[1]['title'] === 'Song A Updated', 'dedupe replaces the old entry with the new one, moved to the end');

$playlistItems = remove_from_playlist_items($playlistItems, 'https://youtu.be/bbb');
assert_true(count($playlistItems) === 1, 'remove_from_playlist_items removes the matching entry');
assert_true($playlistItems[0]['webpage_url'] === 'https://youtu.be/aaa', 'remove_from_playlist_items leaves the non-matching entry');

$tmpPlaylistFile = sys_get_temp_dir() . '/playlist_test_' . uniqid() . '/playlist.json';
assert_true(load_playlist($tmpPlaylistFile) === [], 'load_playlist returns empty array when file does not exist');

save_playlist([['webpage_url' => 'https://youtu.be/ccc', 'title' => 'Song C']], $tmpPlaylistFile);
assert_true(file_exists($tmpPlaylistFile), 'save_playlist creates the file (and parent directory)');
$loaded = load_playlist($tmpPlaylistFile);
assert_true(count($loaded) === 1 && $loaded[0]['webpage_url'] === 'https://youtu.be/ccc', 'save_playlist/load_playlist round-trip preserves data');

unlink($tmpPlaylistFile);
rmdir(dirname($tmpPlaylistFile));

echo "All backend helper tests passed.\n";

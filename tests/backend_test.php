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

$playCmd = build_play_pipeline_cmd(
    'https://youtu.be/abc123',
    '/opt/docker/owntone/pipes/youtube.fifo',
    '/opt/docker/owntone/pipes/youtube.fifo.metadata',
    '<item><type>test</type></item>'
);
assert_true(str_starts_with($playCmd, 'nohup sh -c'), 'play cmd is wrapped in nohup');
assert_true(str_contains($playCmd, 'yt-dlp --no-playlist -f bestaudio'), 'play cmd calls yt-dlp for bestaudio with playlist expansion disabled');
assert_true(str_contains($playCmd, 'ffmpeg -re -i pipe:0'), 'play cmd pipes into ffmpeg at real-time rate so OwnTone can attach as a live reader');
assert_true(str_contains($playCmd, 'youtube.fifo'), 'play cmd writes to the fifo path');
assert_true(str_contains($playCmd, 'printf'), 'play cmd writes metadata via printf');
assert_true(str_contains($playCmd, '<item><type>test</type></item>'), 'metadata content is embedded (survives escaping since it has no quotes)');
assert_true(str_contains($playCmd, 'youtube.fifo.metadata'), 'play cmd writes metadata to the metadata fifo path');
assert_true(strpos($playCmd, 'printf') < strpos($playCmd, 'yt-dlp --no-playlist'), 'metadata write is backgrounded ahead of the audio pipeline, not after it');
assert_true(str_ends_with(trim($playCmd), '&'), 'play cmd is backgrounded');

$durationCmd = build_yt_dlp_duration_cmd('https://youtu.be/abc123');
assert_true(str_contains($durationCmd, "yt-dlp --no-playlist --skip-download --print duration"), 'duration cmd asks yt-dlp for duration only, no download');
assert_true(str_contains($durationCmd, "'https://youtu.be/abc123'"), 'duration cmd embeds the escaped url');

$item = build_metadata_item('core', 'minm', 'Test Title');
assert_true(str_contains($item, '<type>' . bin2hex('core') . '</type>'), 'metadata item hex-encodes the type tag');
assert_true(str_contains($item, '<code>' . bin2hex('minm') . '</code>'), 'metadata item hex-encodes the code tag');
assert_true(str_contains($item, '<length>10</length>'), 'metadata item reports the raw (non-base64) byte length');
assert_true(str_contains($item, base64_encode('Test Title')), 'metadata item base64-encodes the data');

$metadataXml = build_pipe_metadata_xml('My Title', 'My Artist', 125);
assert_true(str_contains($metadataXml, base64_encode('My Title')), 'pipe metadata includes the base64 title');
assert_true(str_contains($metadataXml, base64_encode('My Artist')), 'pipe metadata includes the base64 artist');
assert_true(str_contains($metadataXml, base64_encode('1/1/' . (1 + 125 * 44100))), 'pipe metadata includes progress with nonzero start/pos and duration converted to samples at 44100Hz');

$metadataXmlNoDuration = build_pipe_metadata_xml('My Title', 'My Artist', 0);
assert_true(!str_contains($metadataXmlNoDuration, bin2hex('prgr')), 'pipe metadata omits progress entirely when duration is unknown (0)');
assert_true(!str_contains($metadataXmlNoDuration, bin2hex('PICT')), 'pipe metadata omits artwork entirely when no bytes are given');

$fakeJpegBytes = "\xFF\xD8\xFFfake-jpeg-bytes";
$metadataXmlWithArt = build_pipe_metadata_xml('My Title', 'My Artist', 125, $fakeJpegBytes);
assert_true(str_contains($metadataXmlWithArt, '<code>' . bin2hex('PICT') . '</code>'), 'pipe metadata includes a PICT item when artwork bytes are given');
assert_true(str_contains($metadataXmlWithArt, base64_encode($fakeJpegBytes)), 'pipe metadata base64-encodes the raw artwork bytes');

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

$tmpSearchFile = sys_get_temp_dir() . '/last_search_test_' . uniqid() . '/last_search.json';
assert_true(load_last_search($tmpSearchFile) === [], 'load_last_search returns empty array when file does not exist');

save_last_search([['webpage_url' => 'https://youtu.be/ddd', 'title' => 'Song D']], $tmpSearchFile);
$loadedSearch = load_last_search($tmpSearchFile);
assert_true(
    count($loadedSearch) === 1 && $loadedSearch[0]['webpage_url'] === 'https://youtu.be/ddd',
    'save_last_search/load_last_search round-trip preserves data'
);

unlink($tmpSearchFile);
rmdir(dirname($tmpSearchFile));

echo "All backend helper tests passed.\n";

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

assert_true(is_private_local_ip('127.0.0.1'), '127.0.0.1 is private');
assert_true(is_private_local_ip('::1'), '::1 (IPv6 loopback) is private');
assert_true(is_private_local_ip('10.0.0.100'), "10.x is private (chainedbox's own LAN address)");
assert_true(is_private_local_ip('10.255.255.255'), '10.x is private up to the top of the /8 range');
assert_true(is_private_local_ip('192.168.1.50'), '192.168.x is private');
assert_true(is_private_local_ip('172.16.0.5'), '172.16.x is private');
assert_true(is_private_local_ip('172.31.255.255'), '172.31.x is private (top of the 172.16-31 range)');
assert_true(!is_private_local_ip('172.32.0.1'), '172.32.x is public (just outside the 172.16-31 private range)');
assert_true(!is_private_local_ip('8.8.8.8'), 'a public IP is not private');
assert_true(!is_private_local_ip('not-an-ip'), 'a non-IP string is not private (treated as untrusted, not a crash)');

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

$cachedPlayCmd = build_play_pipeline_cmd(
    'https://youtu.be/abc123',
    '/opt/docker/owntone/pipes/youtube.fifo',
    '/opt/docker/owntone/pipes/youtube.fifo.metadata',
    '<item><type>test</type></item>',
    '/mnt/appsrv/ytb-owntone/cache/abc12345678.audio'
);
assert_true(str_contains($cachedPlayCmd, 'ffmpeg -re -i') && str_contains($cachedPlayCmd, 'abc12345678.audio'), 'cached play cmd reads the cache file directly via ffmpeg -i (not yt-dlp)');
assert_true(!str_contains($cachedPlayCmd, 'yt-dlp'), 'cached play cmd skips yt-dlp entirely');
assert_true(!str_contains($cachedPlayCmd, '-ss'), 'cached play cmd has no seek offset when startAtSeconds is not given');
assert_true(!str_contains($cachedPlayCmd, 'rm -f'), 'cached play cmd does not delete the cache file — it stays available for repeat seeking');

$seekPlayCmd = build_play_pipeline_cmd(
    'https://youtu.be/abc123',
    '/opt/docker/owntone/pipes/youtube.fifo',
    '/opt/docker/owntone/pipes/youtube.fifo.metadata',
    '<item><type>test</type></item>',
    '/mnt/appsrv/ytb-owntone/cache/abc12345678.audio',
    30
);
assert_true(str_contains($seekPlayCmd, 'ffmpeg -re -ss 30 -i'), 'seek play cmd passes -ss before -i for input seeking on the cached file');

assert_true(extract_youtube_video_id('https://www.youtube.com/watch?v=dQw4w9WgXcQ') === 'dQw4w9WgXcQ', 'extracts id from a watch url');
assert_true(extract_youtube_video_id('https://youtu.be/dQw4w9WgXcQ') === 'dQw4w9WgXcQ', 'extracts id from a short url');
assert_true(extract_youtube_video_id('https://youtube.com/shorts/dQw4w9WgXcQ') === 'dQw4w9WgXcQ', 'extracts id from a shorts url');
assert_true(extract_youtube_video_id('not a youtube url') === null, 'returns null when no id pattern matches');

assert_true(audio_cache_path('https://youtu.be/dQw4w9WgXcQ', '/tmp/cache') === '/tmp/cache/dQw4w9WgXcQ.audio', 'audio_cache_path builds a path keyed by video id');
assert_true(audio_cache_path('not a url', '/tmp/cache') === null, 'audio_cache_path returns null when no video id can be extracted');

$resolveCmd = build_resolve_direct_stream_url_cmd('https://youtu.be/dQw4w9WgXcQ');
assert_true(str_contains($resolveCmd, YTDLP_BIN . ' --no-playlist -f "bestaudio[ext=m4a]/bestaudio" -g'), 'resolve cmd asks yt-dlp for the direct url via -g, preferring a seekable m4a rendition');
assert_true(str_starts_with($resolveCmd, TIMEOUT_BIN), 'resolve cmd is guarded by an absolute-path timeout');
assert_true(str_contains($resolveCmd, "'https://youtu.be/dQw4w9WgXcQ'"), 'resolve cmd embeds the target url');

$queueItems = [
    ['webpage_url' => 'https://youtu.be/aaaaaaaaaaa'],
    ['webpage_url' => 'https://youtu.be/bbbbbbbbbbb'],
    ['webpage_url' => 'https://youtu.be/ccccccccccc'],
];
assert_true(next_preload_target($queueItems, 0, false) === 'https://youtu.be/bbbbbbbbbbb', 'next_preload_target picks the following sequential item');
assert_true(next_preload_target($queueItems, 2, false) === null, 'next_preload_target is null at the end of the queue');
assert_true(next_preload_target($queueItems, 0, true) === null, 'next_preload_target is null in shuffle mode (no fixed next to preload)');
assert_true(next_preload_target($queueItems, 2, false, 'all') === 'https://youtu.be/aaaaaaaaaaa', 'next_preload_target (repeat-all) wraps to the first item at the end');
assert_true(next_preload_target($queueItems, 0, false, 'one') === null, 'next_preload_target (repeat-one) is null — the "next" track is already cached (it is the current one)');

$preloadCmd = build_preload_cmd('https://youtu.be/dQw4w9WgXcQ', '/tmp/cache/dQw4w9WgXcQ.audio');
assert_true(str_starts_with($preloadCmd, 'nohup sh -c'), 'preload cmd is wrapped in nohup');
assert_true(str_contains($preloadCmd, 'yt-dlp --no-playlist -f bestaudio'), 'preload cmd downloads bestaudio');
assert_true(str_contains($preloadCmd, 'dQw4w9WgXcQ.audio.part'), 'preload cmd downloads to a .part temp path first');
assert_true(str_contains($preloadCmd, 'mv ') && strpos($preloadCmd, 'mv ') < strrpos($preloadCmd, 'dQw4w9WgXcQ.audio'), 'preload cmd atomically renames into place on success');

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

$seekedMetadataXml = build_pipe_metadata_xml('My Title', 'My Artist', 125, '', 30);
assert_true(str_contains($seekedMetadataXml, base64_encode('1/' . (1 + 30 * 44100) . '/' . (1 + 125 * 44100))), 'pipe metadata reports a seek target as the current position, not the beginning');

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

$playlists = [];
assert_true(find_playlist_index($playlists, 'Yêu thích') === null, 'find_playlist_index returns null when no playlists exist');

$playlists = create_playlist($playlists, 'Yêu thích');
assert_true(count($playlists) === 1 && $playlists[0]['name'] === 'Yêu thích' && $playlists[0]['items'] === [], 'create_playlist adds a new empty playlist');

$playlists = create_playlist($playlists, 'Yêu thích');
assert_true(count($playlists) === 1, 'create_playlist does not duplicate an existing name');

$playlists = add_item_to_named_playlist($playlists, 'Yêu thích', ['webpage_url' => 'https://youtu.be/xxx', 'title' => 'Song X']);
assert_true(count($playlists[0]['items']) === 1, 'add_item_to_named_playlist adds the item to the matching playlist');

$playlists = add_item_to_named_playlist($playlists, 'Workout', ['webpage_url' => 'https://youtu.be/yyy', 'title' => 'Song Y']);
assert_true(count($playlists) === 2, 'add_item_to_named_playlist auto-creates the playlist if it does not exist yet');
assert_true(find_playlist_index($playlists, 'Workout') !== null, 'the auto-created playlist is findable by name');

$playlists = remove_item_from_named_playlist($playlists, 'Yêu thích', 'https://youtu.be/xxx');
assert_true(count($playlists[0]['items']) === 0, 'remove_item_from_named_playlist removes the item from the matching playlist only');
assert_true(count($playlists[1]['items']) === 1, 'remove_item_from_named_playlist leaves other playlists untouched');

$unchanged = remove_item_from_named_playlist($playlists, 'Nonexistent', 'https://youtu.be/xxx');
assert_true($unchanged === $playlists, 'remove_item_from_named_playlist is a no-op for a nonexistent playlist name');

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

$tmpQueueFile = sys_get_temp_dir() . '/queue_state_test_' . uniqid() . '/queue_state.json';
$emptyQueueState = load_queue_state($tmpQueueFile);
assert_true(
    $emptyQueueState === ['items' => [], 'current_index' => -1, 'shuffle' => false, 'repeat' => 'off'],
    'load_queue_state returns an empty queue when file does not exist'
);

save_queue_state([['webpage_url' => 'https://youtu.be/eee']], 0, true, 'all', $tmpQueueFile);
$loadedQueueState = load_queue_state($tmpQueueFile);
assert_true(
    count($loadedQueueState['items']) === 1
        && $loadedQueueState['current_index'] === 0
        && $loadedQueueState['shuffle'] === true
        && $loadedQueueState['repeat'] === 'all',
    'save_queue_state/load_queue_state round-trip preserves items, current_index, shuffle, and repeat'
);

file_put_contents($tmpQueueFile, json_encode(['items' => [], 'current_index' => -1, 'shuffle' => false, 'repeat' => 'bogus']));
assert_true(load_queue_state($tmpQueueFile)['repeat'] === 'off', 'load_queue_state normalizes an invalid repeat value to off');

unlink($tmpQueueFile);
rmdir(dirname($tmpQueueFile));

$playingPlayer = ['state' => 'play', 'item_progress_ms' => 5000, 'item_length_ms' => 200000];
assert_true(!queue_should_advance($playingPlayer, 0, 3), 'queue_should_advance is false while still playing');

$midPausePlayer = ['state' => 'pause', 'item_progress_ms' => 5000, 'item_length_ms' => 200000];
assert_true(!queue_should_advance($midPausePlayer, 0, 3), 'queue_should_advance is false for a genuine mid-track pause, not just finished');

$finishedPlayer = ['state' => 'pause', 'item_progress_ms' => 199500, 'item_length_ms' => 200000];
assert_true(queue_should_advance($finishedPlayer, 0, 3), 'queue_should_advance is true when paused at (near) the end');
assert_true(queue_should_advance($finishedPlayer, 2, 3), 'queue_should_advance only judges "did it finish", not queue position (that is next_queue_index\'s job)');
assert_true(!queue_should_advance($finishedPlayer, -1, 3), 'queue_should_advance is false when there is no active queue');

$unknownDurationPlayer = ['state' => 'pause', 'item_progress_ms' => 0, 'item_length_ms' => 0];
assert_true(!queue_should_advance($unknownDurationPlayer, 0, 3), 'queue_should_advance is false when duration is unknown (0) rather than guessing');

// Some videos' actual decoded audio ends more than the 4s tolerance short
// of yt-dlp's duration estimate — the daemon got stuck paused with several
// seconds still "remaining" by that estimate and never advanced. The
// ffmpeg-process-exited fallback is what catches this case.
$stuckFarFromEstimate = ['state' => 'pause', 'item_progress_ms' => 180000, 'item_length_ms' => 200000];
assert_true(
    !queue_should_advance($stuckFarFromEstimate, 0, 3),
    'queue_should_advance stays false via duration alone when nowhere near the estimated end (pipeline still running by default)'
);
assert_true(
    queue_should_advance($stuckFarFromEstimate, 0, 3, false),
    'queue_should_advance is true once the ffmpeg pipeline has exited on its own, even far from the duration estimate'
);

$neverStartedPlayer = ['state' => 'pause', 'item_progress_ms' => 0, 'item_length_ms' => 200000];
assert_true(
    !queue_should_advance($neverStartedPlayer, 0, 3, false),
    'queue_should_advance is false when the pipeline exited but progress is still 0 — never actually started, not "finished"'
);

// Direct-HTTP ("url") tracks: OwnTone wipes the player to a blank idle
// state at natural end-of-stream instead of pausing with the item
// retained, unlike a pipe track — the blank shape below is otherwise
// identical to "hasn't started playing yet".
$idlePlayer = ['state' => 'stop', 'item_progress_ms' => 0, 'item_length_ms' => 0, 'item_id' => 0];
assert_true(
    !queue_should_advance($idlePlayer, 0, 3, true, false),
    'queue_should_advance stays false on a blank idle player if this item was never confirmed playing (still starting up, not finished)'
);
assert_true(
    queue_should_advance($idlePlayer, 0, 3, true, true),
    'queue_should_advance is true on a blank idle player once this item was confirmed playing first (a url track finished)'
);

$tmpConfirmedFile = sys_get_temp_dir() . '/backend_test_confirmed_' . uniqid() . '.json';
reset_confirmed_playing(false, $tmpConfirmedFile);
assert_true(
    !mark_confirmed_playing_if_active($idlePlayer, $tmpConfirmedFile),
    'mark_confirmed_playing_if_active reports false right after a reset, while the player is still idle'
);
assert_true(
    mark_confirmed_playing_if_active(['state' => 'play', 'item_progress_ms' => 500], $tmpConfirmedFile),
    'mark_confirmed_playing_if_active reports (and records) true once the player is actually playing'
);
assert_true(
    mark_confirmed_playing_if_active($idlePlayer, $tmpConfirmedFile),
    'mark_confirmed_playing_if_active keeps reporting true afterwards, even once the player has since gone idle'
);

assert_true(
    !is_current_track_direct($tmpConfirmedFile),
    'is_current_track_direct defaults to false (fifo) for a freshly reset state'
);
mark_current_track_is_direct(true, $tmpConfirmedFile);
assert_true(is_current_track_direct($tmpConfirmedFile), 'mark_current_track_is_direct(true) is reflected by is_current_track_direct');
assert_true(
    mark_confirmed_playing_if_active($idlePlayer, $tmpConfirmedFile),
    'mark_current_track_is_direct does not clobber the previously-recorded confirmed flag'
);
mark_current_track_is_direct(false, $tmpConfirmedFile);
assert_true(!is_current_track_direct($tmpConfirmedFile), 'mark_current_track_is_direct(false) is reflected by is_current_track_direct');

// A fresh, genuinely-unknown play resets is_direct to false too.
mark_current_track_is_direct(true, $tmpConfirmedFile);
reset_confirmed_playing(false, $tmpConfirmedFile);
assert_true(!is_current_track_direct($tmpConfirmedFile), 'reset_confirmed_playing(false) clears is_direct for a fresh play attempt');

// A seek re-buffers an already-known-direct track — is_direct must survive
// the reset, or the seek's own re-buffering window gets misread as finished
// (confirmed live: this was the actual bug).
mark_current_track_is_direct(true, $tmpConfirmedFile);
reset_confirmed_playing(true, $tmpConfirmedFile);
assert_true(is_current_track_direct($tmpConfirmedFile), 'reset_confirmed_playing(true) preserves is_direct across a seek');
assert_true(
    !mark_confirmed_playing_if_active($idlePlayer, $tmpConfirmedFile),
    'reset_confirmed_playing(true) still clears confirmed, even though is_direct survives'
);

unlink($tmpConfirmedFile);

$tmpStreamCacheFile = sys_get_temp_dir() . '/backend_test_stream_cache_' . uniqid() . '.json';
assert_true(
    get_cached_stream_url('https://youtu.be/aaa', $tmpStreamCacheFile) === null,
    'get_cached_stream_url is a miss when nothing has been cached yet'
);
cache_resolved_stream_url('https://youtu.be/aaa', 'https://cdn.example/aaa.m4a', $tmpStreamCacheFile);
assert_true(
    get_cached_stream_url('https://youtu.be/aaa', $tmpStreamCacheFile) === 'https://cdn.example/aaa.m4a',
    'get_cached_stream_url hits for the exact url just cached'
);
assert_true(
    get_cached_stream_url('https://youtu.be/bbb', $tmpStreamCacheFile) === null,
    'get_cached_stream_url is a miss for a different url than the one cached'
);
unlink($tmpStreamCacheFile);

assert_true(next_queue_index(0, 3, false) === 1, 'next_queue_index (sequential) moves forward by one');
assert_true(next_queue_index(2, 3, false) === null, 'next_queue_index (sequential) stops at the end of the queue');
assert_true(next_queue_index(0, 1, true) === null, 'next_queue_index (shuffle) has nowhere to go with only one item');

$fixedPicks = [0, 0, 2]; // first two picks collide with currentIndex=0 and must be retried
$pickIndex = 0;
$picker = function () use ($fixedPicks, &$pickIndex) {
    return $fixedPicks[$pickIndex++];
};
assert_true(next_queue_index(0, 3, true, 'off', $picker) === 2, 'next_queue_index (shuffle) retries until it picks something other than the current index');

assert_true(next_queue_index(1, 3, false, 'one') === 1, 'next_queue_index (repeat-one) always replays the same index, sequential mode');
assert_true(next_queue_index(1, 3, true, 'one') === 1, 'next_queue_index (repeat-one) always replays the same index, shuffle mode');
assert_true(next_queue_index(2, 3, false, 'all') === 0, 'next_queue_index (repeat-all, sequential) wraps to the first item at the end');
assert_true(next_queue_index(1, 3, false, 'all') === 2, 'next_queue_index (repeat-all, sequential) still just moves forward before the end');
assert_true(next_queue_index(0, 1, true, 'all') === 0, 'next_queue_index (repeat-all, shuffle) wraps to the only item rather than returning null');

echo "All backend helper tests passed.\n";

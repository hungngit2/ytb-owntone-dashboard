const assert = require('assert');
const {
  isYoutubeUrl,
  isYoutubePlaylistUrl,
  isYoutubeMixPlaylistUrl,
  extractYoutubePlaylistId,
  extractYoutubeVideoId,
  mapPlayerResponse,
  mapQueueResponse,
  sanitizeVolume,
} = require('../public/app.js');

assert.strictEqual(isYoutubeUrl('https://www.youtube.com/watch?v=abc123'), true, 'accepts watch url');
assert.strictEqual(isYoutubeUrl('https://youtu.be/abc123'), true, 'accepts short url');
assert.strictEqual(isYoutubeUrl('lofi hip hop radio'), false, 'rejects plain text');
assert.strictEqual(isYoutubeUrl('https://vimeo.com/123'), false, 'rejects other domains');

assert.strictEqual(
  isYoutubePlaylistUrl('https://www.youtube.com/playlist?list=PLWz5rJ2EKKc9CBxr3BVjPTPoDPLdPIFCE'),
  true,
  'accepts a playlist page url'
);
assert.strictEqual(
  isYoutubePlaylistUrl('https://www.youtube.com/watch?v=abc12345678&list=PLWz5rJ2EKKc9CBxr3BVjPTPoDPLdPIFCE'),
  true,
  'a video played within a playlist context is also treated as a playlist import'
);
assert.strictEqual(isYoutubePlaylistUrl('lofi hip hop radio'), false, 'rejects plain text');
assert.strictEqual(
  isYoutubePlaylistUrl('https://www.youtube.com/watch?v=Bhg-Gw953b0&list=RDEMxGUZ2ZNtqwja6FDPezetCw&start_radio=1'),
  false,
  'a Mix/Radio list is excluded — it goes through isYoutubeMixPlaylistUrl instead'
);

assert.strictEqual(
  isYoutubeMixPlaylistUrl('https://www.youtube.com/watch?v=Bhg-Gw953b0&list=RDEMxGUZ2ZNtqwja6FDPezetCw&start_radio=1'),
  true,
  'accepts a Mix/Radio list (list id starts with RD)'
);
assert.strictEqual(
  isYoutubeMixPlaylistUrl('https://www.youtube.com/playlist?list=PLWz5rJ2EKKc9CBxr3BVjPTPoDPLdPIFCE'),
  false,
  'a regular playlist id is not a Mix/Radio list'
);
assert.strictEqual(isYoutubeMixPlaylistUrl('lofi hip hop radio'), false, 'rejects plain text');

assert.strictEqual(
  extractYoutubePlaylistId('https://www.youtube.com/playlist?list=PLWz5rJ2EKKc9CBxr3BVjPTPoDPLdPIFCE'),
  'PLWz5rJ2EKKc9CBxr3BVjPTPoDPLdPIFCE',
  'extracts the playlist id'
);
assert.strictEqual(extractYoutubePlaylistId('https://www.youtube.com/watch?v=abc123'), null, 'returns null when there is no list param');

assert.strictEqual(
  extractYoutubeVideoId('https://www.youtube.com/watch?v=dQw4w9WgXcQ'),
  'dQw4w9WgXcQ',
  'extracts id from a watch url'
);
assert.strictEqual(extractYoutubeVideoId('https://youtu.be/dQw4w9WgXcQ'), 'dQw4w9WgXcQ', 'extracts id from a short url');
assert.strictEqual(
  extractYoutubeVideoId('https://youtube.com/shorts/dQw4w9WgXcQ'),
  'dQw4w9WgXcQ',
  'extracts id from a shorts url'
);
assert.strictEqual(
  extractYoutubeVideoId('https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=RDdQw4w9WgXcQ&index=1'),
  'dQw4w9WgXcQ',
  'extracts id even with extra query params, so a watch url and a playlist-context url for the same video match'
);
assert.strictEqual(extractYoutubeVideoId(null), null, 'returns null for null input');
assert.strictEqual(extractYoutubeVideoId('not a youtube url'), null, 'returns null when no id pattern matches');

const player = mapPlayerResponse({ state: 'play', item_progress_ms: 15000, item_length_ms: 200000, volume: 42, item_id: 5 });
assert.deepStrictEqual(player, { isPlaying: true, progressSeconds: 15, durationSeconds: 200, volume: 42, currentItemId: 5 }, 'maps a playing state');

const paused = mapPlayerResponse({ state: 'pause', item_progress_ms: 0, item_length_ms: 0, volume: 0, item_id: null });
assert.strictEqual(paused.isPlaying, false, 'maps a paused state');

const queue = mapQueueResponse({ items: [{ id: 5, title: 'Now Playing Track' }] }, 5);
assert.deepStrictEqual(queue, { title: 'Now Playing Track', isFifo: false }, 'maps queue to current track title using currentItemId param');

const emptyQueue = mapQueueResponse({ items: [] }, 5);
assert.deepStrictEqual(emptyQueue, { title: '', isFifo: false }, 'maps empty queue to empty title');

const fifoQueue = mapQueueResponse({ items: [{ id: 5, title: 'Fifo Track', data_kind: 'pipe' }] }, 5);
assert.deepStrictEqual(fifoQueue, { title: 'Fifo Track', isFifo: true }, 'flags a pipe-sourced queue item as fifo');

const urlQueue = mapQueueResponse({ items: [{ id: 5, title: 'Direct Track', data_kind: 'url' }] }, 5);
assert.deepStrictEqual(urlQueue, { title: 'Direct Track', isFifo: false }, 'a direct url queue item is not flagged as fifo');

assert.strictEqual(sanitizeVolume(42), 42, 'accepts an in-range volume');
assert.strictEqual(sanitizeVolume(0), 0, 'accepts the 0 boundary');
assert.strictEqual(sanitizeVolume(100), 100, 'accepts the 100 boundary');
assert.strictEqual(sanitizeVolume(773094144), null, 'rejects an out-of-range value (observed from OwnTone on an unselected output)');
assert.strictEqual(sanitizeVolume(-1), null, 'rejects a negative value');
assert.strictEqual(sanitizeVolume(NaN), null, 'rejects NaN');
assert.strictEqual(sanitizeVolume('50'), null, 'rejects a non-number');

const garbageVolumePlayer = mapPlayerResponse({ state: 'stop', item_progress_ms: 0, item_length_ms: 0, volume: 773094144, item_id: null });
assert.strictEqual(garbageVolumePlayer.volume, null, 'mapPlayerResponse sanitizes an out-of-range volume to null');

console.log('All app.js helper tests passed.');

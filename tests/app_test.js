const assert = require('assert');
const { isYoutubeUrl, mapPlayerResponse, mapQueueResponse } = require('../public/app.js');

assert.strictEqual(isYoutubeUrl('https://www.youtube.com/watch?v=abc123'), true, 'accepts watch url');
assert.strictEqual(isYoutubeUrl('https://youtu.be/abc123'), true, 'accepts short url');
assert.strictEqual(isYoutubeUrl('lofi hip hop radio'), false, 'rejects plain text');
assert.strictEqual(isYoutubeUrl('https://vimeo.com/123'), false, 'rejects other domains');

const player = mapPlayerResponse({ state: 'play', item_progress_ms: 15000, item_length_ms: 200000, volume: 42, item_id: 5 });
assert.deepStrictEqual(player, { isPlaying: true, progressSeconds: 15, durationSeconds: 200, volume: 42, currentItemId: 5 }, 'maps a playing state');

const paused = mapPlayerResponse({ state: 'pause', item_progress_ms: 0, item_length_ms: 0, volume: 0, item_id: null });
assert.strictEqual(paused.isPlaying, false, 'maps a paused state');

const queue = mapQueueResponse({ items: [{ id: 5, title: 'Now Playing Track' }] }, 5);
assert.deepStrictEqual(queue, { title: 'Now Playing Track' }, 'maps queue to current track title using currentItemId param');

const emptyQueue = mapQueueResponse({ items: [] }, 5);
assert.deepStrictEqual(emptyQueue, { title: '' }, 'maps empty queue to empty title');

console.log('All app.js helper tests passed.');

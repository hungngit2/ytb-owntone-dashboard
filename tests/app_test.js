const assert = require('assert');
const { isYoutubeUrl, paginate, mapPlayerResponse, mapQueueResponse } = require('../public/app.js');

assert.strictEqual(isYoutubeUrl('https://www.youtube.com/watch?v=abc123'), true, 'accepts watch url');
assert.strictEqual(isYoutubeUrl('https://youtu.be/abc123'), true, 'accepts short url');
assert.strictEqual(isYoutubeUrl('lofi hip hop radio'), false, 'rejects plain text');
assert.strictEqual(isYoutubeUrl('https://vimeo.com/123'), false, 'rejects other domains');

const items = Array.from({ length: 30 }, (_, i) => ({ title: `Track ${i + 1}` }));

const page1 = paginate(items, 1, 5);
assert.strictEqual(page1.pageItems.length, 5, 'page 1 has 5 items');
assert.strictEqual(page1.pageItems[0].title, 'Track 1', 'page 1 starts at item 1');
assert.strictEqual(page1.hasPrev, false, 'page 1 has no prev');
assert.strictEqual(page1.hasNext, true, 'page 1 has next');
assert.strictEqual(page1.totalPages, 6, '30 items over 5-per-page is 6 pages');

const page6 = paginate(items, 6, 5);
assert.strictEqual(page6.pageItems.length, 5, 'page 6 has 5 items');
assert.strictEqual(page6.pageItems[4].title, 'Track 30', 'page 6 ends at item 30');
assert.strictEqual(page6.hasNext, false, 'page 6 has no next');

const clampedHigh = paginate(items, 99, 5);
assert.strictEqual(clampedHigh.page, 6, 'out-of-range high page clamps to last page');

const clampedLow = paginate(items, 0, 5);
assert.strictEqual(clampedLow.page, 1, 'out-of-range low page clamps to first page');

const player = mapPlayerResponse({ state: 'play', item_progress_ms: 15000, item_length_ms: 200000, volume: 42, item_id: 5 });
assert.deepStrictEqual(player, { isPlaying: true, progressSeconds: 15, durationSeconds: 200, volume: 42, currentItemId: 5 }, 'maps a playing state');

const paused = mapPlayerResponse({ state: 'pause', item_progress_ms: 0, item_length_ms: 0, volume: 0, item_id: null });
assert.strictEqual(paused.isPlaying, false, 'maps a paused state');

const queue = mapQueueResponse({ items: [{ id: 5, title: 'Now Playing Track' }] }, 5);
assert.deepStrictEqual(queue, { title: 'Now Playing Track' }, 'maps queue to current track title using currentItemId param');

const emptyQueue = mapQueueResponse({ items: [] }, 5);
assert.deepStrictEqual(emptyQueue, { title: '' }, 'maps empty queue to empty title');

console.log('All app.js helper tests passed.');

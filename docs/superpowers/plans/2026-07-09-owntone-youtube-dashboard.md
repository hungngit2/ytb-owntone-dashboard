# OwnTone YouTube Streaming Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a 4-file (`backend.php`, `app.js`, `index.php`, `style.css`) dark-mode dashboard that searches/plays YouTube audio through an OwnTone server via a named pipe, with live player sync over OwnTone's WebSocket API.

**Architecture:** `backend.php` is a single dispatch script (`action=search|play`) that shells out to `yt-dlp`/`jq`/`ffmpeg` and talks to OwnTone's REST API server-side to resolve the pipe track id. `app.js` is vanilla JS handling input routing, client-side pagination, OwnTone WebSocket subscription, and direct browser→OwnTone REST calls for queue/play/volume. `index.php`/`style.css` provide a sticky-top-controls / scrolling-results dark layout.

**Tech Stack:** PHP (php-fpm + Apache/Nginx), vanilla JS (no build step), `yt-dlp`, `ffmpeg`, `jq`, OwnTone JSON API v6 (port 3689).

## Global Constraints

- OwnTone reachable at `127.0.0.1:3689` from PHP, and `<host>:3689` from the browser (same host, no auth) — per spec `docs/superpowers/specs/2026-07-09-owntone-youtube-dashboard-design.md`.
- Named pipe path: `/opt/docker/owntone/pipes/youtube.fifo`, already registered as a library track in OwnTone (title/path contains `youtube`).
- All shell command construction must use `escapeshellarg()` — no raw interpolation of user input.
- Stale `yt-dlp`/`ffmpeg` processes are killed via `pkill -f` before starting a new stream.
- Search returns exactly the fields: `title`, `webpage_url`, `duration_string`, `thumbnail`.
- Pagination: 5 items per page, client-side, over a 30-item result set.
- No polling — WebSocket-driven sync only.
- No authentication layer (trusted single-user LAN tool).

---

### Task 0: Initialize git repository

**Files:**
- Create: `.gitignore`

- [ ] **Step 1: Init repo and add a minimal .gitignore**

```bash
git init
cat > .gitignore <<'EOF'
.DS_Store
EOF
git add .gitignore docs
git commit -m "chore: initialize repository"
```

Expected: `git status` afterward shows the spec/plan docs and `.gitignore` committed, working tree otherwise clean.

---

### Task 1: backend.php — shell command builder helpers

**Files:**
- Create: `backend.php`
- Test: `tests/backend_test.php`

**Interfaces:**
- Produces: `is_youtube_url(string $url): bool`, `build_yt_dlp_search_cmd(string $query): string`, `build_play_pipeline_cmd(string $youtubeUrl, string $fifoPath): string` — used by Task 3/4's action handlers.

- [ ] **Step 1: Write the failing test**

Create `tests/backend_test.php`:

```php
<?php
require __DIR__ . '/../backend.php';

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
assert_true(!str_contains($injected, "'; rm -rf /'"), 'single quotes in query are escaped, not passed through raw');

$playCmd = build_play_pipeline_cmd('https://youtu.be/abc123', '/opt/docker/owntone/pipes/youtube.fifo');
assert_true(str_starts_with($playCmd, 'nohup sh -c'), 'play cmd is wrapped in nohup');
assert_true(str_contains($playCmd, 'yt-dlp -f bestaudio'), 'play cmd calls yt-dlp for bestaudio');
assert_true(str_contains($playCmd, 'ffmpeg -i pipe:0'), 'play cmd pipes into ffmpeg');
assert_true(str_contains($playCmd, "'/opt/docker/owntone/pipes/youtube.fifo'"), 'play cmd writes to escaped fifo path');
assert_true(str_ends_with(trim($playCmd), '&'), 'play cmd is backgrounded');

echo "All backend helper tests passed.\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/backend_test.php`
Expected: FAIL — `backend.php` does not exist yet / functions undefined.

- [ ] **Step 3: Write minimal implementation**

Create `backend.php` with only the helpers and a direct-execution guard (dispatch logic comes in Task 3/4):

```php
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

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'not yet implemented']);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/backend_test.php`
Expected: PASS — all lines print `PASS: ...`, ends with `All backend helper tests passed.`

- [ ] **Step 5: Commit**

```bash
git add backend.php tests/backend_test.php
git commit -m "feat: add backend.php shell command builder helpers"
```

---

### Task 2: backend.php — OwnTone pipe-track lookup helper

**Files:**
- Modify: `backend.php`
- Test: `tests/backend_test.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `extract_track_id_from_tracks_json(array $tracksResponse, string $matchBasename): ?int` — used by Task 4's play handler to resolve the pipe's OwnTone track id.

- [ ] **Step 1: Write the failing test**

Append to `tests/backend_test.php` (before the final `echo "All backend helper tests passed.\n";` line):

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/backend_test.php`
Expected: FAIL — `extract_track_id_from_tracks_json` undefined.

- [ ] **Step 3: Write minimal implementation**

Add to `backend.php`, after `build_play_pipeline_cmd`:

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/backend_test.php`
Expected: PASS — all assertions pass, including the four new ones.

- [ ] **Step 5: Commit**

```bash
git add backend.php tests/backend_test.php
git commit -m "feat: add OwnTone pipe-track lookup helper"
```

---

### Task 3: backend.php — search action wiring

**Files:**
- Modify: `backend.php`

**Interfaces:**
- Consumes: `build_yt_dlp_search_cmd()` from Task 1.
- Produces: `handle_search(string $query): void` — echoes JSON, called by the dispatch block. Response shape on success: JSON array of `{title, webpage_url, duration_string, thumbnail}`. On failure: `{"status":"error","message":string}` with HTTP 500.

- [ ] **Step 1: Write the implementation**

Add to `backend.php`, after `extract_track_id_from_tracks_json`:

```php
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
```

- [ ] **Step 2: Wire it into the dispatch block**

Replace the placeholder dispatch block at the bottom of `backend.php`:

```php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'search') {
        handle_search((string) ($_POST['query'] ?? ''));
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'unknown action']);
    }
}
```

- [ ] **Step 3: Verify existing unit tests still pass**

Run: `php tests/backend_test.php`
Expected: PASS (requiring `backend.php` still only exercises the pure helpers, since the dispatch block is guarded).

- [ ] **Step 4: Manual integration verification**

Run (on the target Armbian box where `yt-dlp`/`jq` are installed):

```bash
php -S 127.0.0.1:8099 -t . &
curl -s -X POST http://127.0.0.1:8099/backend.php -d 'action=search' -d 'query=lofi hip hop' | jq '. | length, .[0]'
kill %1
```

Expected: prints `30` (result count) followed by the first result object containing `title`, `webpage_url`, `duration_string`, `thumbnail`. If `yt-dlp`/`jq` aren't installed in your current dev environment, run this step on the Armbian host instead and note the result in the task's completion notes.

- [ ] **Step 5: Commit**

```bash
git add backend.php
git commit -m "feat: wire up backend.php search action"
```

---

### Task 4: backend.php — play action wiring

**Files:**
- Modify: `backend.php`

**Interfaces:**
- Consumes: `is_youtube_url()`, `build_play_pipeline_cmd()` from Task 1; `extract_track_id_from_tracks_json()` from Task 2.
- Produces: `handle_play(string $url): void`, `owntone_get(string $path): array` — response shape on success: `{"status":"ok","track_id":int}`. On failure: `{"status":"error","message":string}` with HTTP 400/500.

- [ ] **Step 1: Write the implementation**

Add to `backend.php`, after `handle_search`:

```php
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

    $tracks = owntone_get('/api/library/tracks?limit=500');
    $trackId = extract_track_id_from_tracks_json($tracks, YOUTUBE_FIFO_MATCH);

    if ($trackId === null) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'pipe track not found in OwnTone library']);
        return;
    }

    echo json_encode(['status' => 'ok', 'track_id' => $trackId]);
}
```

- [ ] **Step 2: Wire it into the dispatch block**

Update the dispatch block:

```php
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
```

- [ ] **Step 3: Verify existing unit tests still pass**

Run: `php tests/backend_test.php`
Expected: PASS.

- [ ] **Step 4: Manual integration verification**

On the Armbian host, with OwnTone running and the pipe registered in its library:

```bash
curl -s -X POST http://127.0.0.1/backend.php -d 'action=play' -d 'url=https://youtu.be/dQw4w9WgXcQ'
```

Expected: `{"status":"ok","track_id":<some integer>}`. Confirm via `ps aux | grep -E 'yt-dlp|ffmpeg'` that a new pipeline is running, and that it replaces any prior one (run the curl command twice in a row and confirm only one pair of processes survives after the second call).

- [ ] **Step 5: Commit**

```bash
git add backend.php
git commit -m "feat: wire up backend.php play action"
```

---

### Task 5: app.js — pure helper functions

**Files:**
- Create: `app.js`
- Test: `tests/app_test.js`

**Interfaces:**
- Produces: `isYoutubeUrl(input: string): boolean`, `paginate(items: array, page: number, perPage: number): {pageItems, page, totalPages, hasPrev, hasNext}`, `mapPlayerResponse(player: object): {isPlaying, progressSeconds, durationSeconds, volume}`, `mapQueueResponse(queue: object): {title: string}` — used by Task 6/7/8's DOM-wiring code.

- [ ] **Step 1: Write the failing test**

Create `tests/app_test.js`:

```js
const assert = require('assert');
const { isYoutubeUrl, paginate, mapPlayerResponse, mapQueueResponse } = require('../app.js');

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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node tests/app_test.js`
Expected: FAIL — `app.js` does not exist / exports undefined.

- [ ] **Step 3: Write minimal implementation**

Create `app.js` starting with these pure helpers:

```js
function isYoutubeUrl(input) {
  return /^https?:\/\/(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/shorts\/)/i.test(
    input.trim()
  );
}

function paginate(items, page, perPage) {
  const totalPages = Math.max(1, Math.ceil(items.length / perPage));
  const safePage = Math.min(Math.max(1, page), totalPages);
  const start = (safePage - 1) * perPage;

  return {
    pageItems: items.slice(start, start + perPage),
    page: safePage,
    totalPages,
    hasPrev: safePage > 1,
    hasNext: safePage < totalPages,
  };
}

function mapPlayerResponse(player) {
  return {
    isPlaying: player.state === 'play',
    progressSeconds: Math.floor((player.item_progress_ms || 0) / 1000),
    durationSeconds: Math.floor((player.item_length_ms || 0) / 1000),
    volume: typeof player.volume === 'number' ? player.volume : 0,
    currentItemId: player.item_id,
  };
}

function mapQueueResponse(queue, currentItemId) {
  const items = queue.items || [];
  const current = items.find((item) => item.id === currentItemId) || items[0];
  return { title: current ? current.title : '' };
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { isYoutubeUrl, paginate, mapPlayerResponse, mapQueueResponse };
}
```

> **Note:** OwnTone's real `/api/queue` response has no `current_item_id` field — the currently-playing queue item is identified by matching `/api/player`'s `item_id` against a queue item's `id`. `mapQueueResponse` therefore takes `currentItemId` as an explicit second argument rather than reading it off `queue`; Task 8's `refreshPlayerState` passes `player.item_id` through (see `mapPlayerResponse`'s `currentItemId` passthrough, used there only to document the source — the raw `player.item_id` from the fetched JSON is what's threaded through, not the mapped value).

- [ ] **Step 4: Run test to verify it passes**

Run: `node tests/app_test.js`
Expected: PASS — prints `All app.js helper tests passed.`

- [ ] **Step 5: Commit**

```bash
git add app.js tests/app_test.js
git commit -m "feat: add app.js pure helper functions"
```

---

### Task 6: app.js — search input handling and pagination UI

**Files:**
- Modify: `app.js`

**Interfaces:**
- Consumes: `isYoutubeUrl()`, `paginate()` from Task 5.
- Produces: DOM wiring against elements defined in Task 9 (`#search-form`, `#search-input`, `#results-list`, `#prev-btn`, `#next-btn`, `#page-info`) and a global `playFromBackend(url)` function (implemented fully in Task 7, stubbed here) that result rows call on click.
- Depends on Task 9's `index.php` element ids existing — DOM-wiring code here won't execute until that markup exists, but is written now to keep pagination and rendering logic together.

- [ ] **Step 1: Write the implementation**

Add to `app.js`, after the pure helpers and their `module.exports` block:

```js
let searchResults = [];
let currentPage = 1;

function renderResults() {
  const { pageItems, page, totalPages, hasPrev, hasNext } = paginate(searchResults, currentPage, 5);
  currentPage = page;

  const list = document.getElementById('results-list');
  list.innerHTML = '';

  pageItems.forEach((item) => {
    const row = document.createElement('div');
    row.className = 'result-row';

    const thumb = document.createElement('img');
    thumb.src = item.thumbnail || '';
    thumb.alt = item.title;
    thumb.className = 'result-thumb';

    const meta = document.createElement('div');
    meta.className = 'result-meta';
    meta.innerHTML = `<div class="result-title">${item.title}</div><div class="result-duration">${item.duration_string || ''}</div>`;

    const playBtn = document.createElement('button');
    playBtn.className = 'play-btn';
    playBtn.textContent = 'Play';
    playBtn.addEventListener('click', () => playFromBackend(item.webpage_url));

    row.append(thumb, meta, playBtn);
    list.appendChild(row);
  });

  document.getElementById('page-info').textContent = `${page} / ${totalPages}`;
  document.getElementById('prev-btn').disabled = !hasPrev;
  document.getElementById('next-btn').disabled = !hasNext;
}

async function runSearch(query) {
  const res = await fetch('backend.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `action=search&query=${encodeURIComponent(query)}`,
  });
  const data = await res.json();

  if (Array.isArray(data)) {
    searchResults = data;
    currentPage = 1;
    renderResults();
  } else {
    showError((data && data.message) || 'Search failed');
  }
}

function showError(message) {
  const list = document.getElementById('results-list');
  list.innerHTML = `<div class="result-error">${message}</div>`;
}

document.getElementById('search-form').addEventListener('submit', (event) => {
  event.preventDefault();
  const input = document.getElementById('search-input');
  const value = input.value.trim();
  if (!value) return;

  if (isYoutubeUrl(value)) {
    playFromBackend(value);
  } else {
    runSearch(value);
  }
});

document.getElementById('prev-btn').addEventListener('click', () => {
  currentPage -= 1;
  renderResults();
});

document.getElementById('next-btn').addEventListener('click', () => {
  currentPage += 1;
  renderResults();
});
```

- [ ] **Step 2: Verify existing unit tests still pass**

Run: `node tests/app_test.js`
Expected: PASS (this step adds DOM code that only runs in a browser; the Node test only exercises the pure exports, which are unaffected).

- [ ] **Step 3: Commit**

```bash
git add app.js
git commit -m "feat: add app.js search input handling and pagination UI"
```

---

### Task 7: app.js — play button integration with OwnTone queue

**Files:**
- Modify: `app.js`

**Interfaces:**
- Consumes: nothing new from earlier tasks (called by Task 6's `playFromBackend` reference).
- Produces: `playFromBackend(youtubeUrl: string): Promise<void>` — POSTs to `backend.php`, then tells OwnTone to clear its queue, add the resolved track, and start playback in a single call.

- [ ] **Step 1: Write the implementation**

Add to `app.js`, after `showError`:

```js
function owntoneBase() {
  return `http://${window.location.hostname}:3689`;
}

async function playFromBackend(youtubeUrl) {
  const res = await fetch('backend.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `action=play&url=${encodeURIComponent(youtubeUrl)}`,
  });
  const data = await res.json();

  if (data.status !== 'ok') {
    showError(data.message || 'Play failed');
    return;
  }

  await fetch(
    `${owntoneBase()}/api/queue/items/add?uris=library:track:${data.track_id}&clear=true&playback=start`,
    { method: 'POST' }
  );
}
```

- [ ] **Step 2: Verify existing tests still pass**

Run: `node tests/app_test.js`
Expected: PASS.

- [ ] **Step 3: Manual integration verification**

Open the app in a browser served alongside a live OwnTone instance, paste a YouTube URL directly into the search box, and submit. Confirm in OwnTone's own web UI (or `curl http://<host>:3689/api/player`) that playback state becomes `"state":"play"` within a few seconds.

- [ ] **Step 4: Commit**

```bash
git add app.js
git commit -m "feat: wire up app.js play button to OwnTone queue"
```

---

### Task 8: app.js — WebSocket live sync and volume control

**Files:**
- Modify: `app.js`

**Interfaces:**
- Consumes: `mapPlayerResponse()`, `mapQueueResponse()` from Task 5; `owntoneBase()` from Task 7.
- Produces: DOM wiring against `#play-pause-btn`, `#volume-slider`, `#progress-fill`, `#time-current`, `#time-total`, `#now-title`, `#ws-status` (defined in Task 9). Opens the WebSocket connection on page load.

- [ ] **Step 1: Write the implementation**

Add to `app.js`, after `playFromBackend`:

```js
let lastKnownIsPlaying = false;

function applyPlayerState(player, queue) {
  lastKnownIsPlaying = player.isPlaying;

  document.getElementById('play-pause-btn').textContent = player.isPlaying ? '⏸' : '▶';
  document.getElementById('now-title').textContent = queue.title || 'No track playing';
  document.getElementById('volume-slider').value = player.volume;

  const pct = player.durationSeconds > 0 ? (player.progressSeconds / player.durationSeconds) * 100 : 0;
  document.getElementById('progress-fill').style.width = `${pct}%`;

  document.getElementById('time-current').textContent = formatTime(player.progressSeconds);
  document.getElementById('time-total').textContent = formatTime(player.durationSeconds);
}

function formatTime(totalSeconds) {
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

async function refreshPlayerState() {
  const [player, queue] = await Promise.all([
    fetch(`${owntoneBase()}/api/player`).then((r) => r.json()),
    fetch(`${owntoneBase()}/api/queue`).then((r) => r.json()),
  ]);
  applyPlayerState(mapPlayerResponse(player), mapQueueResponse(queue, player.item_id));
}

function connectWebSocket() {
  const ws = new WebSocket(`ws://${window.location.hostname}:3689/api/v6/ws`);
  const statusEl = document.getElementById('ws-status');

  ws.addEventListener('open', () => {
    statusEl.classList.add('ws-connected');
    ws.send(JSON.stringify({ notify: ['player', 'queue', 'volume'] }));
    refreshPlayerState();
  });

  ws.addEventListener('message', () => {
    refreshPlayerState();
  });

  ws.addEventListener('close', () => {
    statusEl.classList.remove('ws-connected');
  });

  ws.addEventListener('error', () => {
    statusEl.classList.remove('ws-connected');
  });
}

document.getElementById('play-pause-btn').addEventListener('click', () => {
  const endpoint = lastKnownIsPlaying ? 'pause' : 'play';
  fetch(`${owntoneBase()}/api/player/${endpoint}`, { method: 'PUT' }).then(refreshPlayerState);
});

document.getElementById('volume-slider').addEventListener('change', (event) => {
  fetch(`${owntoneBase()}/api/player/volume?volume=${event.target.value}`, { method: 'PUT' });
});

connectWebSocket();
```

- [ ] **Step 2: Verify existing tests still pass**

Run: `node tests/app_test.js`
Expected: PASS.

- [ ] **Step 3: Manual integration verification**

With OwnTone running, open the app, and from another terminal run `curl -X PUT 'http://<host>:3689/api/player/volume?volume=30'`. Confirm the volume slider in the UI updates to 30 within ~1 second without reloading the page. Then click the play/pause button in the UI and confirm OwnTone's actual playback state toggles (check via OwnTone's own UI or `curl http://<host>:3689/api/player`).

- [ ] **Step 4: Commit**

```bash
git add app.js
git commit -m "feat: add app.js WebSocket live sync and volume control"
```

---

### Task 9: index.php and style.css — dark-mode responsive layout

**Files:**
- Create: `index.php`
- Create: `style.css`

**Interfaces:**
- Consumes: element ids referenced throughout `app.js` (Tasks 6–8): `search-form`, `search-input`, `results-list`, `results-container`, `pagination`, `prev-btn`, `next-btn`, `page-info`, `player-bar`, `disc`, `now-title`, `progress-fill`, `time-current`, `time-total`, `play-pause-btn`, `volume-slider`, `ws-status`.
- Produces: the full page shell that loads `style.css` and `app.js`.

- [ ] **Step 1: Write index.php**

Create `index.php`:

```php
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>YouTube → OwnTone Dashboard</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div id="player-bar">
    <div id="disc" class="disc"></div>
    <div class="player-info">
      <div id="now-title">No track playing</div>
      <div class="progress-row">
        <span id="time-current">0:00</span>
        <div id="progress-track"><div id="progress-fill"></div></div>
        <span id="time-total">0:00</span>
      </div>
    </div>
    <div class="player-controls">
      <button id="play-pause-btn" aria-label="Play/pause">▶</button>
      <input id="volume-slider" type="range" min="0" max="100" value="50">
      <span id="ws-status" title="OwnTone connection status">●</span>
    </div>
  </div>

  <form id="search-form">
    <input id="search-input" type="text" placeholder="Tìm bài hát..." autocomplete="off">
  </form>

  <div id="results-container">
    <div id="results-list"></div>
    <div id="pagination">
      <button id="prev-btn" type="button">Prev</button>
      <span id="page-info"></span>
      <button id="next-btn" type="button">Next</button>
    </div>
  </div>

  <script src="app.js"></script>
</body>
</html>
```

- [ ] **Step 2: Write style.css**

Create `style.css`:

```css
:root {
  --bg: #121212;
  --surface: #1e1e1e;
  --surface-alt: #262626;
  --text: #e8e8e8;
  --text-dim: #9a9a9a;
  --accent: #1db954;
  --border: #333;
}

* {
  box-sizing: border-box;
}

html, body {
  margin: 0;
  height: 100%;
  background: var(--bg);
  color: var(--text);
  font-family: system-ui, -apple-system, sans-serif;
}

body {
  display: flex;
  flex-direction: column;
  height: 100vh;
  overflow: hidden;
}

#player-bar {
  position: sticky;
  top: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 12px 16px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}

.disc {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background: radial-gradient(circle at center, var(--surface-alt) 30%, var(--accent) 31%, var(--surface-alt) 33%);
  flex-shrink: 0;
}

.player-info {
  flex: 1;
  min-width: 0;
}

#now-title {
  font-size: 14px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-bottom: 4px;
}

.progress-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: var(--text-dim);
}

#progress-track {
  flex: 1;
  height: 4px;
  background: var(--surface-alt);
  border-radius: 2px;
  overflow: hidden;
}

#progress-fill {
  height: 100%;
  width: 0%;
  background: var(--accent);
}

.player-controls {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}

#play-pause-btn {
  background: var(--accent);
  border: none;
  color: #000;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  cursor: pointer;
  font-size: 14px;
}

#volume-slider {
  width: 100px;
}

#ws-status {
  color: #b04a4a;
}

#ws-status.ws-connected {
  color: var(--accent);
}

#search-form {
  padding: 12px 16px;
  flex-shrink: 0;
}

#search-input {
  width: 100%;
  padding: 10px 14px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--surface-alt);
  color: var(--text);
  font-size: 14px;
}

#results-container {
  flex: 1;
  overflow-y: auto;
  padding: 0 16px 16px;
}

.result-row {
  display: grid;
  grid-template-columns: 64px 1fr auto;
  gap: 12px;
  align-items: center;
  padding: 8px;
  border-bottom: 1px solid var(--border);
}

.result-thumb {
  width: 64px;
  height: 36px;
  object-fit: cover;
  border-radius: 4px;
  background: var(--surface-alt);
}

.result-title {
  font-size: 14px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.result-duration {
  font-size: 12px;
  color: var(--text-dim);
}

.play-btn {
  background: var(--surface-alt);
  border: 1px solid var(--border);
  color: var(--text);
  padding: 6px 12px;
  border-radius: 6px;
  cursor: pointer;
}

.result-error {
  color: #e08585;
  padding: 12px 0;
}

#pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 16px;
  padding: 12px 0;
}

#pagination button {
  background: var(--surface-alt);
  border: 1px solid var(--border);
  color: var(--text);
  padding: 6px 16px;
  border-radius: 6px;
  cursor: pointer;
}

#pagination button:disabled {
  opacity: 0.4;
  cursor: default;
}

@media (max-width: 600px) {
  #player-bar {
    flex-wrap: wrap;
  }

  .player-controls {
    width: 100%;
    justify-content: space-between;
  }

  .result-row {
    grid-template-columns: 48px 1fr auto;
  }
}
```

- [ ] **Step 3: Manual visual verification**

Serve the directory (`php -S 127.0.0.1:8099 -t .`) and open `http://127.0.0.1:8099/index.php` in a browser. Confirm:
- The top control bar stays fixed while `#results-container` scrolls independently once results overflow.
- The layout doesn't horizontally overflow at 375px, 768px, and 1440px widths (use browser dev tools device toolbar).
- Dark background, readable text contrast throughout.

- [ ] **Step 4: Commit**

```bash
git add index.php style.css
git commit -m "feat: add index.php and style.css dark-mode layout"
```

---

### Task 10: End-to-end manual verification

**Files:** none (verification only)

- [ ] **Step 1: Full flow — search and play**

On the Armbian host with OwnTone running: open `index.php`, type a plain-text query into "Tìm bài hát...", confirm 30 results arrive and page through them 5 at a time with Prev/Next. Click Play on a result and confirm OwnTone starts streaming it (check the player bar updates to show the track and starts progressing).

- [ ] **Step 2: Full flow — direct URL paste**

Paste a raw YouTube URL into the search box and submit directly (no search step). Confirm it starts playing without ever calling `action=search`.

- [ ] **Step 3: Stream replacement**

While a track is playing, search and play a second track before the first finishes. Confirm via `ps aux | grep -E 'yt-dlp|ffmpeg'` that the first pipeline's processes are gone and only the second pipeline's processes remain.

- [ ] **Step 4: Live sync**

Adjust the volume slider in the UI and confirm OwnTone's actual volume changes; separately, change OwnTone's volume from another client (or `curl -X PUT`) and confirm the UI slider updates via the WebSocket push without a page reload.

- [ ] **Step 5: Commit any fixes found during verification**

If any issues surface, fix them in the relevant task's file and commit with a message describing the fix, e.g.:

```bash
git commit -am "fix: correct OwnTone queue add param during e2e verification"
```

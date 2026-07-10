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

    const titleEl = document.createElement('div');
    titleEl.className = 'result-title';
    titleEl.textContent = item.title;

    const durationEl = document.createElement('div');
    durationEl.className = 'result-duration';
    durationEl.textContent = item.duration_string || '';

    meta.append(titleEl, durationEl);

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
  try {
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
  } catch (err) {
    showError('Search request failed');
  }
}

function showError(message) {
  const list = document.getElementById('results-list');
  list.innerHTML = '';
  const errorEl = document.createElement('div');
  errorEl.className = 'result-error';
  errorEl.textContent = message;
  list.appendChild(errorEl);
}

function owntoneBase() {
  return `http://${window.location.hostname}:3689`;
}

async function playFromBackend(youtubeUrl) {
  try {
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

    const queueRes = await fetch(
      `${owntoneBase()}/api/queue/items/add?uris=library:track:${data.track_id}&clear=true&playback=start`,
      { method: 'POST' }
    );
    if (!queueRes.ok) {
      showError('Failed to queue track in OwnTone');
    }
  } catch (err) {
    showError('Play request failed');
  }
}

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
  try {
    const [player, queue] = await Promise.all([
      fetch(`${owntoneBase()}/api/player`).then((r) => r.json()),
      fetch(`${owntoneBase()}/api/queue`).then((r) => r.json()),
    ]);
    applyPlayerState(mapPlayerResponse(player), mapQueueResponse(queue, player.item_id));
  } catch (err) {
    document.getElementById('ws-status').classList.remove('ws-connected');
  }
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

if (typeof document !== 'undefined') {
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

  document.getElementById('play-pause-btn').addEventListener('click', () => {
    const endpoint = lastKnownIsPlaying ? 'pause' : 'play';
    fetch(`${owntoneBase()}/api/player/${endpoint}`, { method: 'PUT' })
      .then(refreshPlayerState)
      .catch(() => document.getElementById('ws-status').classList.remove('ws-connected'));
  });

  document.getElementById('volume-slider').addEventListener('change', (event) => {
    fetch(`${owntoneBase()}/api/player/volume?volume=${event.target.value}`, { method: 'PUT' })
      .catch(() => document.getElementById('ws-status').classList.remove('ws-connected'));
  });

  connectWebSocket();
}

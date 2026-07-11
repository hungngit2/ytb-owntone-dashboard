function isYoutubeUrl(input) {
  return /^https?:\/\/(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/shorts\/)/i.test(
    input.trim()
  );
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
  module.exports = { isYoutubeUrl, mapPlayerResponse, mapQueueResponse };
}

let searchResults = [];
let playlistItems = [];
let currentView = 'search';

function activeItems() {
  return currentView === 'search' ? searchResults : playlistItems;
}

function renderResults() {
  const items = activeItems();

  const list = document.getElementById('results-list');
  list.innerHTML = '';

  items.forEach((item) => {
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

    const channelEl = document.createElement('div');
    channelEl.className = 'result-channel';
    channelEl.textContent = item.channel || '';

    const durationEl = document.createElement('div');
    durationEl.className = 'result-duration';
    durationEl.textContent = item.duration_string || '';

    meta.append(titleEl, channelEl, durationEl);

    const actions = document.createElement('div');
    actions.className = 'result-actions';

    const playBtn = document.createElement('button');
    playBtn.className = 'play-btn';
    playBtn.textContent = 'Play';
    playBtn.addEventListener('click', () => playFromBackend(item.webpage_url, playBtn));
    actions.appendChild(playBtn);

    if (currentView === 'search') {
      const saveBtn = document.createElement('button');
      saveBtn.className = 'save-btn';
      saveBtn.textContent = '☆';
      saveBtn.title = 'Lưu vào playlist';
      saveBtn.addEventListener('click', () => saveToPlaylist(item, saveBtn));
      actions.appendChild(saveBtn);
    } else {
      const removeBtn = document.createElement('button');
      removeBtn.className = 'save-btn';
      removeBtn.textContent = '🗑';
      removeBtn.title = 'Xóa khỏi playlist';
      removeBtn.addEventListener('click', () => removeFromPlaylist(item.webpage_url, removeBtn));
      actions.appendChild(removeBtn);
    }

    row.append(thumb, meta, actions);
    list.appendChild(row);
  });
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
      renderResults();
    } else {
      showError((data && data.message) || 'Search failed');
    }
  } catch (err) {
    showError('Search request failed');
  }
}

async function resolveUrlToResult(url) {
  try {
    const res = await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `action=resolve_url&url=${encodeURIComponent(url)}`,
    });
    const data = await res.json();

    if (data && data.webpage_url) {
      searchResults = [data];
      document.getElementById('search-input').value = '';
      if (currentView !== 'search') {
        switchView('search');
      } else {
        renderResults();
      }
    } else {
      showError((data && data.message) || 'Could not resolve URL');
    }
  } catch (err) {
    showError('Resolve request failed');
  }
}

function switchView(view) {
  currentView = view;

  document.getElementById('tab-search').classList.toggle('active', view === 'search');
  document.getElementById('tab-playlist').classList.toggle('active', view === 'playlist');

  if (view === 'playlist') {
    loadPlaylist();
  } else {
    renderResults();
  }
}

async function loadLastSearch() {
  try {
    const res = await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=last_search',
    });
    const data = await res.json();

    if (Array.isArray(data) && data.length > 0) {
      searchResults = data;
      if (currentView === 'search') {
        renderResults();
      }
    }
  } catch (err) {
    // No cached search yet, or request failed — leave the results list empty.
  }
}

async function loadPlaylist() {
  try {
    const res = await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=playlist_list',
    });
    const data = await res.json();

    if (Array.isArray(data)) {
      playlistItems = data;
      renderResults();
    } else {
      showError((data && data.message) || 'Failed to load playlist');
    }
  } catch (err) {
    showError('Playlist request failed');
  }
}

async function saveToPlaylist(item, triggerBtn) {
  if (triggerBtn) {
    triggerBtn.disabled = true;
  }

  try {
    const res = await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:
        'action=playlist_add' +
        `&webpage_url=${encodeURIComponent(item.webpage_url || '')}` +
        `&title=${encodeURIComponent(item.title || '')}` +
        `&thumbnail=${encodeURIComponent(item.thumbnail || '')}` +
        `&duration_string=${encodeURIComponent(item.duration_string || '')}` +
        `&channel=${encodeURIComponent(item.channel || '')}`,
    });
    const data = await res.json();

    if (data.status === 'ok') {
      playlistItems = data.items;
      if (triggerBtn) {
        triggerBtn.textContent = '★';
      }
    } else {
      showError(data.message || 'Save failed');
    }
  } catch (err) {
    showError('Save request failed');
  } finally {
    if (triggerBtn) {
      triggerBtn.disabled = false;
    }
  }
}

async function removeFromPlaylist(webpageUrl, triggerBtn) {
  if (triggerBtn) {
    triggerBtn.disabled = true;
  }

  try {
    const res = await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `action=playlist_remove&webpage_url=${encodeURIComponent(webpageUrl)}`,
    });
    const data = await res.json();

    if (data.status === 'ok') {
      playlistItems = data.items;
      renderResults();
    } else {
      showError(data.message || 'Remove failed');
    }
  } catch (err) {
    showError('Remove request failed');
  } finally {
    if (triggerBtn) {
      triggerBtn.disabled = false;
    }
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

let currentTrackInfo = { title: null, thumbnail: null, channel: null };

function renderNowPlaying(fallbackTitle) {
  const titleEl = document.getElementById('now-title');
  titleEl.classList.remove('loading');
  titleEl.textContent = currentTrackInfo.title || fallbackTitle || 'No track playing';

  document.getElementById('now-subtitle').textContent = currentTrackInfo.channel || '';

  const thumbEl = document.getElementById('disc-thumb');
  const heroBgImg = document.getElementById('hero-bg-img');
  if (currentTrackInfo.thumbnail) {
    thumbEl.src = currentTrackInfo.thumbnail;
    thumbEl.classList.add('visible');
    heroBgImg.src = currentTrackInfo.thumbnail;
  } else {
    thumbEl.classList.remove('visible');
    heroBgImg.removeAttribute('src');
  }
}

async function playFromBackend(youtubeUrl, triggerBtn) {
  if (triggerBtn) {
    triggerBtn.disabled = true;
    triggerBtn.textContent = '...';
  }

  const titleEl = document.getElementById('now-title');
  titleEl.classList.add('loading');
  titleEl.textContent = 'Đang tải...';

  try {
    const res = await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `action=play&url=${encodeURIComponent(youtubeUrl)}`,
    });
    const data = await res.json();

    if (data.status !== 'ok') {
      titleEl.classList.remove('loading');
      showError(data.message || 'Play failed');
      renderNowPlaying();
      return;
    }

    currentTrackInfo = { title: data.title || null, thumbnail: data.thumbnail || null, channel: data.channel || null };
    renderNowPlaying();
    document.getElementById('search-input').value = '';
  } catch (err) {
    titleEl.classList.remove('loading');
    showError('Play request failed');
    renderNowPlaying();
  } finally {
    if (triggerBtn) {
      triggerBtn.disabled = false;
      triggerBtn.textContent = 'Play';
    }
  }
}

let lastKnownIsPlaying = false;

function applyPlayerState(player, queue) {
  lastKnownIsPlaying = player.isPlaying;

  document.getElementById('play-pause-btn').textContent = player.isPlaying ? '⏸' : '▶';
  document.getElementById('disc').classList.toggle('spinning', player.isPlaying);

  const badgeEl = document.getElementById('status-badge');
  badgeEl.textContent = player.isPlaying ? 'PLAYING' : 'IDLE';
  badgeEl.classList.toggle('playing', player.isPlaying);

  renderNowPlaying(queue.title);

  document.getElementById('volume-slider').value = player.volume;
  document.getElementById('volume-value').textContent = `${player.volume}%`;

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

async function connectWebSocket() {
  const statusEl = document.getElementById('ws-status');

  let websocketPort;
  try {
    const config = await fetch(`${owntoneBase()}/api/config`).then((r) => r.json());
    websocketPort = config.websocket_port;
  } catch (err) {
    statusEl.classList.remove('ws-connected');
    return;
  }

  if (!websocketPort) {
    statusEl.classList.remove('ws-connected');
    return;
  }

  const ws = new WebSocket(`ws://${window.location.hostname}:${websocketPort}/`, 'notify');

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
      resolveUrlToResult(value);
    } else {
      runSearch(value);
    }
  });

  document.getElementById('tab-search').addEventListener('click', () => switchView('search'));
  document.getElementById('tab-playlist').addEventListener('click', () => switchView('playlist'));

  document.getElementById('play-pause-btn').addEventListener('click', () => {
    const endpoint = lastKnownIsPlaying ? 'pause' : 'play';
    fetch(`${owntoneBase()}/api/player/${endpoint}`, { method: 'PUT' })
      .then(refreshPlayerState)
      .catch(() => document.getElementById('ws-status').classList.remove('ws-connected'));
  });

  document.getElementById('volume-slider').addEventListener('input', (event) => {
    document.getElementById('volume-value').textContent = `${event.target.value}%`;
  });

  document.getElementById('volume-slider').addEventListener('change', (event) => {
    fetch(`${owntoneBase()}/api/player/volume?volume=${event.target.value}`, { method: 'PUT' })
      .catch(() => document.getElementById('ws-status').classList.remove('ws-connected'));
  });

  connectWebSocket();
  loadLastSearch();
}

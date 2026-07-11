function isYoutubeUrl(input) {
  return /^https?:\/\/(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/shorts\/)/i.test(
    input.trim()
  );
}

// The same video can appear as youtube.com/watch?v=X, youtu.be/X, or with
// extra query params depending on whether the URL came from a search
// result or a pasted link — matching by raw URL string can miss the same
// video. Extract the actual 11-char video id and match on that instead.
function extractYoutubeVideoId(url) {
  if (!url) {
    return null;
  }
  const patterns = [/[?&]v=([a-zA-Z0-9_-]{11})/, /youtu\.be\/([a-zA-Z0-9_-]{11})/, /youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/];
  for (const pattern of patterns) {
    const match = pattern.exec(url);
    if (match) {
      return match[1];
    }
  }
  return null;
}

// OwnTone has been observed returning a garbage volume (e.g. 773094144)
// when no output is actively selected (notably right after /api/player/stop)
// — a quirk in OwnTone's own state, not something we can fix from here.
// Treat anything outside 0-100 as "unknown" rather than displaying it raw.
function sanitizeVolume(rawVolume) {
  return typeof rawVolume === 'number' && Number.isFinite(rawVolume) && rawVolume >= 0 && rawVolume <= 100
    ? rawVolume
    : null;
}

function mapPlayerResponse(player) {
  return {
    isPlaying: player.state === 'play',
    progressSeconds: Math.floor((player.item_progress_ms || 0) / 1000),
    durationSeconds: Math.floor((player.item_length_ms || 0) / 1000),
    volume: sanitizeVolume(player.volume),
    currentItemId: player.item_id,
  };
}

function mapQueueResponse(queue, currentItemId) {
  const items = queue.items || [];
  const current = items.find((item) => item.id === currentItemId) || items[0];
  return { title: current ? current.title : '' };
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { isYoutubeUrl, extractYoutubeVideoId, mapPlayerResponse, mapQueueResponse, sanitizeVolume };
}

let searchResults = [];
let playlists = [];
let currentPlaylistName = null;
let currentView = 'search';

function activeItems() {
  if (currentView === 'search') {
    return searchResults;
  }
  const playlist = playlists.find((p) => p.name === currentPlaylistName);
  return playlist ? playlist.items : [];
}

function renderResults() {
  const items = activeItems();

  const list = document.getElementById('results-list');
  list.innerHTML = '';

  if (items.length === 0) {
    const emptyEl = document.createElement('div');
    emptyEl.className = 'results-empty';
    emptyEl.textContent =
      currentView === 'search' ? 'No search results yet.' : 'Playlist is empty — save songs from search results.';
    list.appendChild(emptyEl);
    return;
  }

  items.forEach((item, index) => {
    const row = document.createElement('div');
    row.className = 'result-row';
    row.dataset.url = item.webpage_url;
    row.dataset.videoId = extractYoutubeVideoId(item.webpage_url) || '';
    if (row.dataset.videoId && row.dataset.videoId === nowPlayingVideoId()) {
      row.classList.add('playing');
    }

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
    playBtn.addEventListener('click', () => playQueueItem(items, index, playBtn));
    actions.appendChild(playBtn);

    if (currentView === 'search') {
      const saveBtn = document.createElement('button');
      saveBtn.className = 'save-btn';
      saveBtn.textContent = '☆';
      saveBtn.title = 'Save to playlist';
      saveBtn.addEventListener('click', () => saveToPlaylist(item, saveBtn));
      actions.appendChild(saveBtn);
    } else {
      const removeBtn = document.createElement('button');
      removeBtn.className = 'save-btn';
      removeBtn.textContent = '🗑';
      removeBtn.title = 'Remove from playlist';
      removeBtn.addEventListener('click', () => removeFromPlaylist(item.webpage_url, removeBtn));
      actions.appendChild(removeBtn);
    }

    row.append(thumb, meta, actions);
    list.appendChild(row);
  });
}

function parseIso8601Duration(iso) {
  const match = /^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/.exec(iso || '') || [];
  const hours = parseInt(match[1] || '0', 10);
  const minutes = parseInt(match[2] || '0', 10);
  const seconds = parseInt(match[3] || '0', 10);
  const totalMinutes = hours * 60 + minutes;
  return `${totalMinutes}:${String(seconds).padStart(2, '0')}`;
}

// Runs entirely in the browser against YouTube's official Data API v3 —
// deliberately NOT routed through the backend, since the previous
// yt-dlp-based server-side search was heavy enough to freeze the host
// under normal use (30 videos of full metadata extraction per search).
async function searchYouTube(query) {
  if (typeof YOUTUBE_API_KEY === 'undefined' || !YOUTUBE_API_KEY) {
    throw new Error('YouTube API key not configured — copy config.example.js to config.js');
  }

  const searchUrl =
    'https://www.googleapis.com/youtube/v3/search' +
    `?key=${encodeURIComponent(YOUTUBE_API_KEY)}` +
    '&part=snippet&type=video&maxResults=30' +
    `&q=${encodeURIComponent(query)}`;

  const searchRes = await fetch(searchUrl);
  const searchData = await searchRes.json();

  if (searchData.error) {
    throw new Error(searchData.error.message || 'YouTube search failed');
  }

  const items = searchData.items || [];
  const videoIds = items.map((item) => item.id.videoId).filter(Boolean);

  let durationById = {};
  if (videoIds.length > 0) {
    const detailsUrl =
      'https://www.googleapis.com/youtube/v3/videos' +
      `?key=${encodeURIComponent(YOUTUBE_API_KEY)}` +
      `&part=contentDetails&id=${videoIds.join(',')}`;
    const detailsRes = await fetch(detailsUrl);
    const detailsData = await detailsRes.json();
    durationById = (detailsData.items || []).reduce((acc, v) => {
      acc[v.id] = parseIso8601Duration(v.contentDetails.duration);
      return acc;
    }, {});
  }

  return items.map((item) => ({
    title: item.snippet.title,
    webpage_url: `https://www.youtube.com/watch?v=${item.id.videoId}`,
    duration_string: durationById[item.id.videoId] || '',
    thumbnail: (item.snippet.thumbnails.medium || item.snippet.thumbnails.default || {}).url || '',
    channel: item.snippet.channelTitle,
  }));
}

function cacheLastSearch(results) {
  fetch('backend.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `action=cache_search&results=${encodeURIComponent(JSON.stringify(results))}`,
  }).catch(() => {
    // Best-effort only — losing the cross-device cache isn't worth surfacing an error for.
  });
}

async function runSearch(query) {
  setActiveTab('search');
  showLoading('Searching...');

  try {
    searchResults = await searchYouTube(query);
    renderResults();
    cacheLastSearch(searchResults);
  } catch (err) {
    showError(err.message || 'Search request failed');
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

function setActiveTab(view) {
  currentView = view;
  document.getElementById('tab-search').classList.toggle('active', view === 'search');
  document.getElementById('tab-playlist').classList.toggle('active', view === 'playlist');
}

function switchView(view) {
  setActiveTab(view);
  document.getElementById('playlist-controls').style.display = view === 'playlist' ? 'flex' : 'none';

  if (view === 'playlist') {
    loadPlaylists();
  } else {
    renderResults();
  }
}

function playAll() {
  const items = activeItems();
  if (items.length > 0) {
    playQueueItem(items, 0);
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

async function loadPlaylists() {
  try {
    const res = await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=playlists_list',
    });
    const data = await res.json();

    if (Array.isArray(data)) {
      playlists = data;
      if ((!currentPlaylistName || !playlists.some((p) => p.name === currentPlaylistName)) && playlists.length > 0) {
        currentPlaylistName = playlists[0].name;
      }
      renderPlaylistSelector();
      renderResults();
    } else {
      showError((data && data.message) || 'Failed to load playlists');
    }
  } catch (err) {
    showError('Playlist request failed');
  }
}

function renderPlaylistSelector() {
  const selector = document.getElementById('playlist-selector');
  selector.innerHTML = '';

  playlists.forEach((playlist) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'playlist-pill';
    btn.classList.toggle('active', playlist.name === currentPlaylistName);
    btn.textContent = `${playlist.name} (${playlist.items.length})`;
    btn.addEventListener('click', () => {
      currentPlaylistName = playlist.name;
      renderPlaylistSelector();
      renderResults();
    });
    selector.appendChild(btn);
  });
}

function promptForPlaylistName() {
  const existingNames = playlists.map((p) => p.name).join(', ');
  const message =
    playlists.length > 0
      ? `Save to which playlist? (existing: ${existingNames})\nEnter a different name to create a new one.`
      : 'New playlist name:';
  const suggestion = currentPlaylistName || (playlists[0] && playlists[0].name) || 'Favorites';
  return (window.prompt(message, suggestion) || '').trim();
}

async function saveToPlaylist(item, triggerBtn) {
  const name = promptForPlaylistName();
  if (!name) {
    return;
  }

  if (triggerBtn) {
    triggerBtn.disabled = true;
  }

  try {
    const res = await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:
        'action=playlist_add_item' +
        `&name=${encodeURIComponent(name)}` +
        `&webpage_url=${encodeURIComponent(item.webpage_url || '')}` +
        `&title=${encodeURIComponent(item.title || '')}` +
        `&thumbnail=${encodeURIComponent(item.thumbnail || '')}` +
        `&duration_string=${encodeURIComponent(item.duration_string || '')}` +
        `&channel=${encodeURIComponent(item.channel || '')}`,
    });
    const data = await res.json();

    if (data.status === 'ok') {
      playlists = data.playlists;
      currentPlaylistName = name;
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

async function createPlaylist(name) {
  try {
    const res = await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `action=playlist_create&name=${encodeURIComponent(name)}`,
    });
    const data = await res.json();

    if (data.status === 'ok') {
      playlists = data.playlists;
      currentPlaylistName = name;
      renderPlaylistSelector();
      renderResults();
    } else {
      showError(data.message || 'Create playlist failed');
    }
  } catch (err) {
    showError('Create playlist request failed');
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
      body:
        'action=playlist_remove_item' +
        `&name=${encodeURIComponent(currentPlaylistName || '')}` +
        `&webpage_url=${encodeURIComponent(webpageUrl)}`,
    });
    const data = await res.json();

    if (data.status === 'ok') {
      playlists = data.playlists;
      renderPlaylistSelector();
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

function showLoading(message) {
  const list = document.getElementById('results-list');
  list.innerHTML = '';
  const wrap = document.createElement('div');
  wrap.className = 'results-loading';
  const spinner = document.createElement('div');
  spinner.className = 'spinner';
  const text = document.createElement('span');
  text.textContent = message;
  wrap.append(spinner, text);
  list.appendChild(wrap);
}

function owntoneBase() {
  return `http://${window.location.hostname}:3689`;
}

let currentTrackInfo = { title: null, thumbnail: null, channel: null, webpageUrl: null };

// The authoritative "what's playing" record, mirrored from
// QUEUE_STATE_FILE on the server. Local-only state (currentTrackInfo) goes
// stale the moment the page is refreshed or bin/queue-daemon.php advances
// the track on its own (no browser involved) — this is what keeps
// highlight/prev/next correct in both cases.
let serverQueue = { items: [], current_index: -1, shuffle: false };

async function syncServerQueue() {
  try {
    const res = await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=queue_state',
    });
    serverQueue = await res.json();
    shuffleEnabled = Boolean(serverQueue.shuffle);
    document.getElementById('shuffle-btn').classList.toggle('active', shuffleEnabled);
    updatePlayingHighlight();
  } catch (err) {
    // Non-fatal: local currentTrackInfo still covers this tab's own plays.
  }
}

// OwnTone's queue title is the raw pipe filename ("youtube.fifo") whenever
// our metadata hasn't (yet, or ever) reached it — e.g. right after a fresh
// page load before any play action in this session. Not something to show
// a user, so it's treated the same as "nothing playing".
function isRawFifoFilename(title) {
  return /\.fifo$/i.test(title || '');
}

function renderNowPlaying(fallbackTitle) {
  const titleEl = document.getElementById('now-title');
  titleEl.classList.remove('loading');
  const displayFallback = isRawFifoFilename(fallbackTitle) ? '' : fallbackTitle;
  titleEl.textContent = currentTrackInfo.title || displayFallback || 'Nothing playing';

  document.getElementById('now-subtitle').textContent = currentTrackInfo.channel || '';

  const thumbEl = document.getElementById('disc-thumb');
  const heroBgImg = document.getElementById('hero-bg-img');

  // After a page refresh, currentTrackInfo is empty (it only ever gets set
  // by a play action in this session) even though OwnTone may still be
  // playing something. Fall back to OwnTone's own artwork endpoint, which
  // is populated by the PICT metadata we send on play — but only when a
  // real queue item exists (fallbackTitle set), and only if that image
  // actually loads (OwnTone returns a 404 if no artwork was ever sent).
  const thumbnailUrl = currentTrackInfo.thumbnail || (fallbackTitle ? `${owntoneBase()}/artwork/nowplaying` : '');

  if (thumbnailUrl) {
    thumbEl.onerror = () => thumbEl.classList.remove('visible');
    thumbEl.src = thumbnailUrl;
    thumbEl.classList.add('visible');
    heroBgImg.onerror = () => heroBgImg.removeAttribute('src');
    heroBgImg.src = thumbnailUrl;
  } else {
    thumbEl.classList.remove('visible');
    heroBgImg.removeAttribute('src');
  }

  updatePlayingHighlight();
}

// The video actually playing right now, per the server's queue state — not
// per this tab's own memory, so it stays correct across refreshes and
// daemon-driven auto-advances.
function nowPlayingVideoId() {
  const item = serverQueue.items[serverQueue.current_index];
  return item ? extractYoutubeVideoId(item.webpage_url) : null;
}

function updatePlayingHighlight() {
  const playingVideoId = nowPlayingVideoId();
  document.querySelectorAll('.result-row').forEach((row) => {
    row.classList.toggle('playing', Boolean(playingVideoId) && row.dataset.videoId === playingVideoId);
  });
}

function currentPlayingIndex() {
  const playingVideoId = nowPlayingVideoId();
  if (!playingVideoId) {
    return -1;
  }
  return activeItems().findIndex((item) => extractYoutubeVideoId(item.webpage_url) === playingVideoId);
}

// Prev/Next step through the server's queue directly (not the currently
// displayed search/playlist view) — that's the actual list bin/queue-daemon.php
// is advancing through, so this is what stays consistent with it.
function playRelative(offset) {
  const items = serverQueue.items;
  const index = serverQueue.current_index;
  if (index < 0 || items.length === 0) {
    return;
  }

  const targetIndex = index + offset;
  if (targetIndex < 0 || targetIndex >= items.length) {
    return;
  }

  playQueueItem(items, targetIndex);
}

let shuffleEnabled = false;

// Persists the whole list + starting index server-side (not just a single
// URL) so bin/queue-daemon.php can advance through it on its own — that's
// what makes auto-play-next survive the browser being closed, since the
// daemon (not this tab) is what decides and acts on "did it finish".
async function playQueueItem(items, index, triggerBtn) {
  if (triggerBtn) {
    triggerBtn.disabled = true;
    triggerBtn.textContent = '...';
  }

  const titleEl = document.getElementById('now-title');
  titleEl.classList.add('loading');
  titleEl.textContent = 'Loading...';
  document.getElementById('disc').classList.add('loading');

  try {
    const res = await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:
        'action=play_queue' +
        `&items=${encodeURIComponent(JSON.stringify(items))}` +
        `&index=${index}` +
        `&shuffle=${shuffleEnabled ? '1' : ''}`,
    });
    const data = await res.json();

    if (data.status !== 'ok') {
      titleEl.classList.remove('loading');
      showError(data.message || 'Play failed');
      renderNowPlaying();
      return;
    }

    currentTrackInfo = {
      title: data.title || null,
      thumbnail: data.thumbnail || null,
      channel: data.channel || null,
      webpageUrl: items[index].webpage_url,
    };
    // Set immediately rather than waiting for the next websocket sync, so
    // highlight/prev/next reflect this play right away.
    serverQueue = { items, current_index: index, shuffle: shuffleEnabled };
    renderNowPlaying();
    document.getElementById('search-input').value = '';
  } catch (err) {
    titleEl.classList.remove('loading');
    showError('Play request failed');
    renderNowPlaying();
  } finally {
    document.getElementById('disc').classList.remove('loading');
    if (triggerBtn) {
      triggerBtn.disabled = false;
      triggerBtn.textContent = 'Play';
    }
  }
}

// Stops playback and clears the server-side queue (not just an OwnTone
// pause) so bin/queue-daemon.php has nothing left to auto-advance — a
// plain pause would leave the queue state in place for the daemon to
// resume/advance the moment it next polls.
async function stopPlayback() {
  currentTrackInfo = { title: null, thumbnail: null, channel: null, webpageUrl: null };
  serverQueue = { items: [], current_index: -1, shuffle: shuffleEnabled };
  renderNowPlaying();

  try {
    await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=stop',
    });
  } catch (err) {
    showError('Stop request failed');
  }

  refreshPlayerState();
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

  // player.volume is null when OwnTone reported a garbage value (see
  // sanitizeVolume) — leave the slider/label showing the last known-good
  // reading rather than a nonsense number.
  if (player.volume !== null) {
    document.getElementById('volume-slider').value = player.volume;
    document.getElementById('volume-value').textContent = `${player.volume}%`;
  }

  startProgressTicker(player.isPlaying, player.progressSeconds, player.durationSeconds);
}

let progressTickTimer = null;
let progressSyncedAtMs = 0;
let syncedProgressSeconds = 0;
let syncedDurationSeconds = 0;

// OwnTone's WebSocket only pushes on discrete state changes (play/pause,
// track change, volume) — not a per-second heartbeat — so relying on it
// alone leaves the progress bar frozen between events. This interpolates
// locally between syncs (no extra server polling) and gets corrected back
// to the authoritative value every time a real sync arrives.
function startProgressTicker(isPlaying, progressSeconds, durationSeconds) {
  clearInterval(progressTickTimer);
  syncedProgressSeconds = progressSeconds;
  syncedDurationSeconds = durationSeconds;
  progressSyncedAtMs = Date.now();

  updateProgressDisplay(syncedProgressSeconds, syncedDurationSeconds);

  if (!isPlaying) {
    return;
  }

  progressTickTimer = setInterval(() => {
    const elapsed = (Date.now() - progressSyncedAtMs) / 1000;
    const displaySeconds =
      syncedDurationSeconds > 0
        ? Math.min(syncedProgressSeconds + elapsed, syncedDurationSeconds)
        : syncedProgressSeconds + elapsed;
    updateProgressDisplay(displaySeconds, syncedDurationSeconds);
  }, 1000);
}

// Display-only, deliberately not clickable/seekable: OwnTone can't seek
// within a pipe source (there's no file to seek in, only a live stream
// PHP is writing to it in real time) — the progress bar just reflects
// position, dragging it wouldn't do anything.
function updateProgressDisplay(progressSeconds, durationSeconds) {
  const pct = durationSeconds > 0 ? (progressSeconds / durationSeconds) * 100 : 0;
  document.getElementById('progress-fill').style.width = `${pct}%`;
  document.getElementById('time-current').textContent = formatTime(Math.floor(progressSeconds));
  document.getElementById('time-total').textContent = formatTime(durationSeconds);
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
    // Piggybacks on the websocket's own event cadence (fires on real OwnTone
    // state changes) to also catch daemon-driven auto-advances, instead of
    // polling separately.
    syncServerQueue();
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

  document.getElementById('create-playlist-btn').addEventListener('click', () => {
    const input = document.getElementById('new-playlist-name');
    const name = input.value.trim();
    if (!name) {
      return;
    }
    createPlaylist(name);
    input.value = '';
  });

  document.getElementById('play-all-btn').addEventListener('click', playAll);

  document.getElementById('shuffle-btn').addEventListener('click', () => {
    shuffleEnabled = !shuffleEnabled;
    document.getElementById('shuffle-btn').classList.toggle('active', shuffleEnabled);
    // Also updates the currently-active daemon queue immediately, not just
    // the next play action — so toggling mid-playlist takes effect right away.
    fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `action=set_shuffle&shuffle=${shuffleEnabled ? '1' : ''}`,
    }).catch(() => {});
  });

  document.getElementById('play-pause-btn').addEventListener('click', () => {
    const endpoint = lastKnownIsPlaying ? 'pause' : 'play';
    fetch(`${owntoneBase()}/api/player/${endpoint}`, { method: 'PUT' })
      .then(refreshPlayerState)
      .catch(() => document.getElementById('ws-status').classList.remove('ws-connected'));
  });

  document.getElementById('prev-btn').addEventListener('click', () => playRelative(-1));
  document.getElementById('next-btn').addEventListener('click', () => playRelative(1));
  document.getElementById('stop-btn').addEventListener('click', stopPlayback);

  document.getElementById('volume-slider').addEventListener('input', (event) => {
    document.getElementById('volume-value').textContent = `${event.target.value}%`;
  });

  document.getElementById('volume-slider').addEventListener('change', (event) => {
    fetch(`${owntoneBase()}/api/player/volume?volume=${event.target.value}`, { method: 'PUT' })
      .catch(() => document.getElementById('ws-status').classList.remove('ws-connected'));
  });

  connectWebSocket();
  loadLastSearch();
  loadPlaylists();
}

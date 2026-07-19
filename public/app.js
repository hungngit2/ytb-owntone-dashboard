// Inline SVG instead of emoji for anything set dynamically — emoji render
// as a different glyph per OS/browser (Windows vs Android vs iOS emoji
// sets); SVG paths look identical everywhere.
const ICONS = {
  play: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>',
  pause:
    '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="5" width="4" height="14"/><rect x="14" y="5" width="4" height="14"/></svg>',
  volume:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" fill="currentColor" stroke="none"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>',
  muted:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" fill="currentColor" stroke="none"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>',
  star: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
  starFilled:
    '<svg viewBox="0 0 24 24" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
  trash:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>',
};

function isYoutubeUrl(input) {
  return /^https?:\/\/(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/shorts\/)/i.test(
    input.trim()
  );
}

function extractYoutubePlaylistId(url) {
  const match = /[?&]list=([a-zA-Z0-9_-]+)/.exec(url || '');
  return match ? match[1] : null;
}

// Auto-generated "Mix"/Radio lists (list id starts with RD) aren't real
// playlists — the YouTube Data API's playlistItems endpoint can't look
// them up — so they're routed to a separate yt-dlp-backed resolver
// instead of the Data-API-based playlist import below.
function isYoutubeMixPlaylistId(id) {
  return /^RD/i.test(id || '');
}

// Any URL carrying a `list=` param is treated as "import the whole
// playlist" — whether it's a dedicated playlist page
// (youtube.com/playlist?list=X) or a video played within a playlist
// context (watch?v=X&list=Y). Mix/Radio lists are excluded here since
// they need the separate resolver above.
function isYoutubePlaylistUrl(input) {
  const id = extractYoutubePlaylistId(input);
  return !!id && !isYoutubeMixPlaylistId(id);
}

function isYoutubeMixPlaylistUrl(input) {
  return isYoutubeMixPlaylistId(extractYoutubePlaylistId(input));
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
  return { title: current ? current.title : '', isFifo: Boolean(current) && current.data_kind === 'pipe' };
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    isYoutubeUrl,
    isYoutubePlaylistUrl,
    isYoutubeMixPlaylistUrl,
    extractYoutubePlaylistId,
    extractYoutubeVideoId,
    mapPlayerResponse,
    mapQueueResponse,
    sanitizeVolume,
  };
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
    playBtn.disabled = playRequestInFlight;
    playBtn.addEventListener('click', () => playQueueItem(items, index, playBtn));
    actions.appendChild(playBtn);

    if (currentView === 'search') {
      const saveBtn = document.createElement('button');
      saveBtn.className = 'save-btn';
      saveBtn.innerHTML = ICONS.star;
      saveBtn.title = 'Save to playlist';
      saveBtn.addEventListener('click', () => saveToPlaylist(item, saveBtn));
      actions.appendChild(saveBtn);
    } else {
      const removeBtn = document.createElement('button');
      removeBtn.className = 'save-btn';
      removeBtn.innerHTML = ICONS.trash;
      removeBtn.title = 'Remove from playlist';
      removeBtn.addEventListener('click', () => removeFromPlaylist(item.webpage_url, removeBtn));
      actions.appendChild(removeBtn);
    }

    row.append(thumb, meta, actions);
    list.appendChild(row);
  });

  // Rows are brand new DOM elements (list.innerHTML was cleared above),
  // so re-run the highlight/auto-scroll against them even if the playing
  // video id itself hasn't changed — e.g. switching to the Playlist tab
  // should still scroll to the playing item there on its own first render.
  lastAutoScrolledVideoId = null;
  updatePlayingHighlight();
}

function parseIso8601Duration(iso) {
  const match = /^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/.exec(iso || '') || [];
  const hours = parseInt(match[1] || '0', 10);
  const minutes = parseInt(match[2] || '0', 10);
  const seconds = parseInt(match[3] || '0', 10);
  const totalMinutes = hours * 60 + minutes;
  return `${totalMinutes}:${String(seconds).padStart(2, '0')}`;
}

// Batches videos.list calls in groups of 50 (the API's per-request cap on
// comma-separated ids) — search results never exceed 30 so this never
// mattered there, but a playlist commonly has more than 50 items.
async function fetchDurationsById(videoIds) {
  const durationById = {};
  for (let i = 0; i < videoIds.length; i += 50) {
    const batch = videoIds.slice(i, i + 50);
    if (batch.length === 0) {
      continue;
    }
    const detailsUrl =
      'https://www.googleapis.com/youtube/v3/videos' +
      `?key=${encodeURIComponent(YOUTUBE_API_KEY)}` +
      `&part=contentDetails&id=${batch.join(',')}`;
    const detailsRes = await fetch(detailsUrl);
    const detailsData = await detailsRes.json();
    (detailsData.items || []).forEach((v) => {
      durationById[v.id] = parseIso8601Duration(v.contentDetails.duration);
    });
  }
  return durationById;
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
  const durationById = await fetchDurationsById(videoIds);

  return items.map((item) => ({
    title: item.snippet.title,
    webpage_url: `https://www.youtube.com/watch?v=${item.id.videoId}`,
    duration_string: durationById[item.id.videoId] || '',
    thumbnail: (item.snippet.thumbnails.medium || item.snippet.thumbnails.default || {}).url || '',
    channel: item.snippet.channelTitle,
  }));
}

// Same client-side approach as search (never touches the backend/yt-dlp).
// Paginates via nextPageToken, capped at 5 pages (250 videos) so a
// pathological giant playlist can't hang the tab fetching indefinitely.
async function fetchYoutubePlaylistItems(playlistId) {
  if (typeof YOUTUBE_API_KEY === 'undefined' || !YOUTUBE_API_KEY) {
    throw new Error('YouTube API key not configured — copy config.example.js to config.js');
  }

  let items = [];
  let pageToken = '';
  for (let page = 0; page < 5; page++) {
    const url =
      'https://www.googleapis.com/youtube/v3/playlistItems' +
      `?key=${encodeURIComponent(YOUTUBE_API_KEY)}` +
      '&part=snippet&maxResults=50' +
      `&playlistId=${encodeURIComponent(playlistId)}` +
      (pageToken ? `&pageToken=${encodeURIComponent(pageToken)}` : '');
    const res = await fetch(url);
    const data = await res.json();

    if (data.error) {
      throw new Error(data.error.message || 'YouTube playlist lookup failed');
    }

    items = items.concat(data.items || []);
    pageToken = data.nextPageToken || '';
    if (!pageToken) {
      break;
    }
  }

  // Deleted/private videos still occupy a slot in the playlist listing
  // but have no real video behind them — nothing to play, so skip them.
  items = items.filter(
    (item) =>
      item.snippet &&
      item.snippet.resourceId &&
      item.snippet.resourceId.videoId &&
      item.snippet.title !== 'Deleted video' &&
      item.snippet.title !== 'Private video'
  );

  const videoIds = items.map((item) => item.snippet.resourceId.videoId);
  const durationById = await fetchDurationsById(videoIds);

  return items.map((item) => {
    const videoId = item.snippet.resourceId.videoId;
    return {
      title: item.snippet.title,
      webpage_url: `https://www.youtube.com/watch?v=${videoId}`,
      duration_string: durationById[videoId] || '',
      thumbnail: (item.snippet.thumbnails.medium || item.snippet.thumbnails.default || {}).url || '',
      // videoOwnerChannelTitle is the video's own uploader; a playlistItem's
      // plain channelTitle is the PLAYLIST owner instead, wrong for videos
      // added to the playlist from other channels.
      channel: item.snippet.videoOwnerChannelTitle || item.snippet.channelTitle || '',
    };
  });
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

// Resolves every video in a pasted playlist link into the search results
// list — same list you'd get from a text search, so Play/Save/Play-all
// all work on it unchanged.
async function runPlaylistImport(url) {
  setActiveTab('search');
  showLoading('Loading playlist...');

  const playlistId = extractYoutubePlaylistId(url);
  if (!playlistId) {
    showError('Could not find a playlist in that link');
    return;
  }

  try {
    searchResults = await fetchYoutubePlaylistItems(playlistId);
    if (searchResults.length === 0) {
      showError('Playlist is empty, or none of its videos are available');
      return;
    }
    renderResults();
    cacheLastSearch(searchResults);
    document.getElementById('search-input').value = '';
  } catch (err) {
    showError(err.message || 'Playlist request failed');
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
      cacheLastSearch(searchResults);
    } else {
      showError((data && data.message) || 'Could not resolve URL');
    }
  } catch (err) {
    showError('Resolve request failed');
  }
}

// Mix/Radio lists are dynamically generated and not retrievable via the
// YouTube Data API, so this goes through the backend's yt-dlp-based
// flat-playlist resolver instead of fetchYoutubePlaylistItems.
async function resolveYoutubeMixPlaylist(url) {
  setActiveTab('search');
  showLoading('Loading mix...');

  try {
    const res = await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `action=resolve_mix_playlist&url=${encodeURIComponent(url)}`,
    });
    const data = await res.json();

    if (data && data.status === 'ok' && Array.isArray(data.items) && data.items.length > 0) {
      searchResults = data.items;
      renderResults();
      cacheLastSearch(searchResults);
      document.getElementById('search-input').value = '';
    } else {
      showError((data && data.message) || 'Could not resolve this mix/radio playlist');
    }
  } catch (err) {
    showError('Mix playlist request failed');
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
        triggerBtn.innerHTML = ICONS.starFilled;
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

// renderNowPlaying's own OwnTone-reported title, cached so syncServerQueue
// can re-render once serverQueue actually arrives (it resolves after
// applyPlayerState's own renderNowPlaying call, which ran against a still-
// empty/stale serverQueue) — without this, a page load could get stuck
// showing "Nothing playing" until the next unrelated websocket event.
let lastKnownQueueTitle = '';

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
    repeatMode = serverQueue.repeat || 'off';
    reflectRepeatUI();
    document.getElementById('progress-track').classList.toggle('seekable', Boolean(serverQueue.seekable));
    renderNowPlaying(lastKnownQueueTitle);
    updateCookingIndicator();
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

// The queue item persisted server-side (queue_state.json, mirrored into
// serverQueue) for "what's currently playing" — available immediately
// after a page reload, unlike currentTrackInfo (only set by this tab's
// own play calls) or OwnTone's own queue title (lags until its pipe
// reader actually receives our metadata write). Without this, a reload
// during that gap showed a misleading "Nothing playing" for something
// that was, in fact, already in progress.
function currentQueueItem() {
  return serverQueue.items[serverQueue.current_index] || null;
}

// Scrolls the now-playing title back and forth if it doesn't fit — plain
// truncation with an ellipsis hides information a marquee can instead
// reveal over time. Skips the reset+re-measure when the text hasn't
// actually changed, so the (frequent, every websocket sync) re-render of
// the same still-playing title doesn't keep restarting the animation.
let lastRenderedTitleText = null;

function setNowTitleText(text) {
  if (text === lastRenderedTitleText) {
    return;
  }
  lastRenderedTitleText = text;

  const titleEl = document.getElementById('now-title');
  const textEl = document.getElementById('now-title-text');
  textEl.textContent = text;
  textEl.classList.remove('marquee');
  titleEl.style.removeProperty('--marquee-distance');

  // Measured on the next frame so layout reflects the new text first.
  requestAnimationFrame(() => {
    const overflow = textEl.scrollWidth - titleEl.clientWidth;
    if (overflow > 4) {
      titleEl.style.setProperty('--marquee-distance', `-${overflow}px`);
      textEl.classList.add('marquee');
    }
  });
}

function renderNowPlaying(fallbackTitle) {
  const titleEl = document.getElementById('now-title');
  titleEl.classList.remove('loading');
  const queueItem = currentQueueItem();

  // Priority: this tab's own freshly-resolved info (set immediately after
  // this tab's own play call) > the persisted queue item (queue_state.json,
  // mirrored into serverQueue — reflects what SHOULD be current right
  // away, before OwnTone necessarily has) > OwnTone's own reported title,
  // used only as an absolute last resort. OwnTone's title is deliberately
  // ranked below queueItem: right after a new play request it can still
  // show the *previous* track (or the raw fifo filename) for a beat,
  // until its pipe reader processes our metadata write — trusting it over
  // our own queue item caused a stale/mismatched title+channel pairing to
  // flash on reload (the exact bug this function exists to avoid).
  const ownToneReportedTitle = !isRawFifoFilename(fallbackTitle) ? fallbackTitle : '';

  setNowTitleText(currentTrackInfo.title || (queueItem && queueItem.title) || ownToneReportedTitle || 'Nothing playing');

  document.getElementById('now-subtitle').textContent =
    currentTrackInfo.channel || (queueItem && queueItem.channel) || '';

  const thumbEl = document.getElementById('disc-thumb');
  const heroBgImg = document.getElementById('hero-bg-img');

  // Same priority as the title: this tab's own thumbnail, then the
  // persisted queue item's thumbnail, then OwnTone's own artwork endpoint
  // — only tried when we have no queue item at all to fall back on
  // (OwnTone 404s it if no artwork was ever sent, and it can't be trusted
  // to correspond to the current item for the same reason as the title above).
  const thumbnailUrl =
    currentTrackInfo.thumbnail ||
    (queueItem && queueItem.thumbnail) ||
    (!queueItem && ownToneReportedTitle ? `${owntoneBase()}/artwork/nowplaying` : '');

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

// Scrolls the playing row into view, but only when the playing video id
// actually CHANGES — otherwise this runs on every periodic sync (every
// few seconds) and would keep yanking the list back even while the user
// is deliberately scrolled elsewhere browsing other results.
let lastAutoScrolledVideoId = null;

function updatePlayingHighlight() {
  const playingVideoId = nowPlayingVideoId();
  document.querySelectorAll('.result-row').forEach((row) => {
    row.classList.toggle('playing', Boolean(playingVideoId) && row.dataset.videoId === playingVideoId);
  });

  if (playingVideoId && playingVideoId !== lastAutoScrolledVideoId) {
    const playingRow = document.querySelector(`.result-row[data-video-id="${playingVideoId}"]`);
    if (playingRow) {
      playingRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      lastAutoScrolledVideoId = playingVideoId;
    }
  }
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
let repeatMode = 'off'; // 'off' | 'all' | 'one' — mirrors serverQueue.repeat

function reflectRepeatUI() {
  const btn = document.getElementById('repeat-btn');
  btn.classList.toggle('active', repeatMode !== 'off');
  btn.classList.toggle('repeat-one', repeatMode === 'one');
}

// Only one play/seek/stop request in flight at a time from this tab.
// Clicking Play on one row previously only disabled THAT row's button —
// nothing stopped clicking Play on several other rows while the first
// request was still in flight, firing many concurrent backend requests.
// The backend now has its own defenses (a cross-process lock plus a hard
// cap on concurrent yt-dlp processes), but stopping the burst at the
// source is what actually prevents it, rather than relying on the
// backend to absorb it after the fact.
let playRequestInFlight = false;

// Persists the whole list + starting index server-side (not just a single
// URL) so bin/queue-daemon.php can advance through it on its own — that's
// what makes auto-play-next survive the browser being closed, since the
// daemon (not this tab) is what decides and acts on "did it finish".
async function playQueueItem(items, index, triggerBtn) {
  if (playRequestInFlight) {
    return;
  }
  playRequestInFlight = true;
  document.querySelectorAll('.play-btn').forEach((btn) => {
    btn.disabled = true;
  });
  if (triggerBtn) {
    triggerBtn.textContent = '...';
  }

  const titleEl = document.getElementById('now-title');
  titleEl.classList.add('loading');
  setNowTitleText('Loading...');

  try {
    const res = await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:
        'action=play_queue' +
        `&items=${encodeURIComponent(JSON.stringify(items))}` +
        `&index=${index}` +
        `&shuffle=${shuffleEnabled ? '1' : ''}` +
        `&repeat=${repeatMode}`,
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
    // Reflects "cooking" status from OwnTone's own confirmation rather
    // than clearing it just because this HTTP round-trip finished — the
    // pipeline can still be spinning up in the background well after the
    // play request itself returns "ok".
    updateCookingIndicator();
    playRequestInFlight = false;
    document.querySelectorAll('.play-btn').forEach((btn) => {
      btn.disabled = false;
    });
    if (triggerBtn) {
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
  updateCookingIndicator();

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

// Tracks whether OwnTone's CURRENT item (by its own numeric item id) has
// ever actually been confirmed producing audio, so the "cooking" spinner
// can be item-specific rather than trusting stale data. Duration alone
// doesn't work for this: OwnTone keeps reporting the PREVIOUS track's
// item_length_ms until the new pipe's metadata write lands, so right
// after a queue swap it can show a real-looking-but-wrong nonzero
// duration for the new item — confirmed live (a 19s video briefly showed
// the prior track's ~4min duration). isPlaying or real progress (> 0) for
// the CURRENT item id is what actually means "this item has audio flowing
// right now or has before" — reached at least once means no longer cooking,
// so a later legitimate pause of an already-loaded track doesn't re-trigger it.
let lastSeenItemId = null;
let hasConfirmedPlaybackForItemId = false;

// Corrects for a confirmed OwnTone quirk: pausing/resuming a pipe-based
// track (a live yt-dlp|ffmpeg stream, not a real seekable file) resets
// OwnTone's own item_progress_ms to ~0 on resume and it never catches
// back up — verified live by PID: the same ffmpeg/yt-dlp process kept
// running unchanged across pause/resume (so the actual audio itself isn't
// restarting), only OwnTone's reported position is wrong. Without this,
// our own progress bar/time display looked like the track had restarted
// from the beginning, matching exactly what was reported. Recalculated
// on every pause→resume cycle (see the play-pause-btn handler) and reset
// to 0 whenever the item id changes (a real new track, where OwnTone's
// own progress is correct from the start).
let progressOffsetSeconds = 0;
let pausedAtSeconds = 0;

// Shows the thumbnail spinner while a track is queued but OwnTone hasn't
// actually confirmed it's playing yet — the "cooking" window between
// launching the yt-dlp/ffmpeg pipeline into the fifo and OwnTone's own
// state catching up (which can take a while: oEmbed + yt-dlp's duration
// lookup have been observed taking 15-20s on this host). Driven by
// OwnTone's own confirmed state (not this tab's local optimism) so it's
// correct whether the play was started by this tab, another tab, or the
// daemon, and correct across a reload mid-preparation.
function updateCookingIndicator() {
  const isCooking = Boolean(currentQueueItem()) && !hasConfirmedPlaybackForItemId;
  document.getElementById('disc').classList.toggle('loading', isCooking);
}

function applyPlayerState(player, queue) {
  lastKnownIsPlaying = player.isPlaying;

  if (player.currentItemId !== lastSeenItemId) {
    lastSeenItemId = player.currentItemId;
    hasConfirmedPlaybackForItemId = false;
    progressOffsetSeconds = 0;
  }
  if (player.isPlaying || player.progressSeconds > 0) {
    hasConfirmedPlaybackForItemId = true;
  }

  document.getElementById('play-pause-btn').innerHTML = player.isPlaying ? ICONS.pause : ICONS.play;
  document.getElementById('disc').classList.toggle('spinning', player.isPlaying);

  const badgeEl = document.getElementById('status-badge');
  badgeEl.textContent = player.isPlaying ? 'PLAYING' : 'IDLE';
  badgeEl.classList.toggle('playing', player.isPlaying);

  document.getElementById('fifo-badge').hidden = !queue.isFifo;

  lastKnownQueueTitle = queue.title;
  renderNowPlaying(queue.title);
  updateCookingIndicator();

  // player.volume is null when OwnTone reported a garbage value (see
  // sanitizeVolume) — leave the slider/label showing the last known-good
  // reading rather than a nonsense number.
  if (player.volume !== null) {
    reflectVolumeUI(player.volume);
  }

  startProgressTicker(player.isPlaying, player.progressSeconds + progressOffsetSeconds, player.durationSeconds);
}

// Single place that keeps the slider, label, and mute icon in sync,
// whichever path changed the volume (websocket sync, dragging the
// slider, or the mute button) — and remembers the last nonzero level so
// unmuting restores it instead of guessing a default.
let lastNonZeroVolume = 50;

function reflectVolumeUI(volume) {
  document.getElementById('volume-slider').value = volume;
  document.getElementById('volume-value').textContent = `${volume}%`;
  document.getElementById('volume-mute-btn').innerHTML = volume > 0 ? ICONS.volume : ICONS.muted;
  if (volume > 0) {
    lastNonZeroVolume = volume;
  }
}

function setVolume(volume) {
  reflectVolumeUI(volume);
  fetch(`${owntoneBase()}/api/player/volume?volume=${volume}`, { method: 'PUT' }).catch(() =>
    document.getElementById('ws-status').classList.remove('ws-connected')
  );
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

// Only draggable once the current track is fully cached server-side (see
// serverQueue.seekable) — OwnTone can't seek a live pipe stream, only a
// real file on disk, so dragging is a no-op (and #progress-track lacks
// the .seekable class, so it isn't even clickable) until that download
// finishes in the background.
function updateProgressDisplay(progressSeconds, durationSeconds) {
  const pct = durationSeconds > 0 ? (progressSeconds / durationSeconds) * 100 : 0;
  document.getElementById('progress-fill').style.width = `${pct}%`;
  document.getElementById('time-current').textContent = formatTime(Math.floor(progressSeconds));
  document.getElementById('time-total').textContent = formatTime(durationSeconds);
}

async function seekTo(targetSeconds) {
  if (!serverQueue.seekable || syncedDurationSeconds <= 0) {
    return;
  }
  const clamped = Math.max(0, Math.min(targetSeconds, syncedDurationSeconds));

  // Reflect the drag immediately rather than waiting for the backend
  // round-trip (which restarts the pipe from the seeked offset).
  startProgressTicker(lastKnownIsPlaying, clamped, syncedDurationSeconds);

  try {
    const res = await fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `action=seek&seconds=${Math.floor(clamped)}`,
    });
    const data = await res.json();
    if (data.status !== 'ok') {
      showError(data.message || 'Seek failed');
    }
  } catch (err) {
    showError('Seek request failed');
  }

  refreshPlayerState();
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

let wsReconnectTimer = null;
let wsReconnectDelayMs = 2000;

// The websocket only pushes on OwnTone's own state changes — if the
// connection ever drops (network blip, OwnTone restart, proxy timeout)
// and is never retried, "live" updates silently stop until the page is
// manually reloaded (the exact "doesn't live-update" bug this fixes).
// Backs off up to 30s between attempts rather than hammering OwnTone.
function scheduleWebSocketReconnect() {
  clearTimeout(wsReconnectTimer);
  wsReconnectTimer = setTimeout(() => {
    wsReconnectDelayMs = Math.min(wsReconnectDelayMs * 2, 30000);
    connectWebSocket();
  }, wsReconnectDelayMs);
}

async function connectWebSocket() {
  const statusEl = document.getElementById('ws-status');
  clearTimeout(wsReconnectTimer);

  let websocketPort;
  try {
    const config = await fetch(`${owntoneBase()}/api/config`).then((r) => r.json());
    websocketPort = config.websocket_port;
  } catch (err) {
    statusEl.classList.remove('ws-connected');
    scheduleWebSocketReconnect();
    return;
  }

  if (!websocketPort) {
    statusEl.classList.remove('ws-connected');
    scheduleWebSocketReconnect();
    return;
  }

  const ws = new WebSocket(`ws://${window.location.hostname}:${websocketPort}/`, 'notify');

  ws.addEventListener('open', () => {
    statusEl.classList.add('ws-connected');
    wsReconnectDelayMs = 2000; // reset backoff after a successful connect
    ws.send(JSON.stringify({ notify: ['player', 'queue', 'volume'] }));
    refreshPlayerState();
  });

  ws.addEventListener('message', () => {
    refreshPlayerState();
  });

  ws.addEventListener('close', () => {
    statusEl.classList.remove('ws-connected');
    scheduleWebSocketReconnect();
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

    if (isYoutubeMixPlaylistUrl(value)) {
      resolveYoutubeMixPlaylist(value);
    } else if (isYoutubePlaylistUrl(value)) {
      runPlaylistImport(value);
    } else if (isYoutubeUrl(value)) {
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

  document.getElementById('repeat-btn').addEventListener('click', () => {
    repeatMode = repeatMode === 'off' ? 'all' : repeatMode === 'all' ? 'one' : 'off';
    reflectRepeatUI();
    // Also updates the currently-active daemon queue immediately, not just
    // the next play action — so toggling mid-playlist takes effect right away.
    fetch('backend.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `action=set_repeat&repeat=${repeatMode}`,
    }).catch(() => {});
  });

  document.getElementById('play-pause-btn').addEventListener('click', () => {
    if (lastKnownIsPlaying) {
      // Applied immediately, not just on resume — OwnTone resets its own
      // progress counter to ~0 the instant it pauses (confirmed live), so
      // without this the display would jump to 0:00 for the whole time
      // it's sitting paused, not just misreport after resuming.
      pausedAtSeconds = syncedProgressSeconds + (Date.now() - progressSyncedAtMs) / 1000;
      progressOffsetSeconds = pausedAtSeconds;
      fetch(`${owntoneBase()}/api/player/pause`, { method: 'PUT' })
        .then(refreshPlayerState)
        .catch(() => document.getElementById('ws-status').classList.remove('ws-connected'));
      return;
    }

    // progressOffsetSeconds is already set from the pause step above, and
    // OwnTone starts counting up from ~0 again on resume, so raw + offset
    // continues to read correctly with no further adjustment needed here.
    fetch(`${owntoneBase()}/api/player/play`, { method: 'PUT' })
      .then(refreshPlayerState)
      .catch(() => document.getElementById('ws-status').classList.remove('ws-connected'));
  });

  document.getElementById('prev-btn').addEventListener('click', () => playRelative(-1));
  document.getElementById('next-btn').addEventListener('click', () => playRelative(1));
  document.getElementById('stop-btn').addEventListener('click', stopPlayback);

  document.getElementById('progress-track').addEventListener('click', (event) => {
    const track = event.currentTarget;
    if (!track.classList.contains('seekable')) {
      return;
    }
    const rect = track.getBoundingClientRect();
    const ratio = Math.max(0, Math.min((event.clientX - rect.left) / rect.width, 1));
    seekTo(ratio * syncedDurationSeconds);
  });

  document.getElementById('volume-slider').addEventListener('input', (event) => {
    document.getElementById('volume-value').textContent = `${event.target.value}%`;
    document.getElementById('volume-mute-btn').innerHTML = Number(event.target.value) > 0 ? ICONS.volume : ICONS.muted;
  });

  document.getElementById('volume-slider').addEventListener('change', (event) => {
    setVolume(Number(event.target.value));
  });

  document.getElementById('volume-mute-btn').addEventListener('click', () => {
    const current = Number(document.getElementById('volume-slider').value);
    setVolume(current > 0 ? 0 : lastNonZeroVolume || 50);
  });

  // Plays OwnTone's own mixed output (http://<host>:3689/stream.mp3)
  // straight in the browser tab — a separate listening path from whatever
  // physical outputs OwnTone is configured with, independent of the
  // pipe/queue playback this app otherwise controls.
  let streamStarted = false;
  document.getElementById('stream-btn').addEventListener('click', () => {
    const audio = document.getElementById('browser-stream-audio');
    const btn = document.getElementById('stream-btn');

    if (!streamStarted) {
      // Starts muted by default — the stream connects (so unmuting is
      // instant with no re-buffering delay) but stays silent until a
      // second click explicitly opts in to actually hearing it.
      audio.src = `${owntoneBase()}/stream.mp3`;
      audio.muted = true;
      audio.play().catch(() => showError('Could not start browser audio stream'));
      streamStarted = true;
      btn.innerHTML = ICONS.muted;
      btn.classList.remove('active');
      return;
    }

    audio.muted = !audio.muted;
    btn.innerHTML = audio.muted ? ICONS.muted : ICONS.volume;
    btn.classList.toggle('active', !audio.muted);
  });

  connectWebSocket();
  loadLastSearch();
  loadPlaylists();

  // Defense in depth alongside the websocket: even if a reconnect is
  // delayed or a push is somehow missed, this guarantees playing info
  // can't silently go stale for more than this interval without a reload.
  setInterval(refreshPlayerState, 15000);
}

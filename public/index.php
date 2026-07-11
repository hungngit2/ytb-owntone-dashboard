<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>YouTube → OwnTone Dashboard</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <main id="app">
    <div id="player-hero">
      <div class="hero-art">
        <img id="hero-bg-img" class="hero-bg-img" alt="">
        <div class="hero-art-overlay"></div>

        <div id="disc" class="hero-thumb-wrap">
          <img id="disc-thumb" class="hero-thumb" alt="">
        </div>

        <div class="hero-text">
          <div id="now-title">No track playing</div>
          <div id="now-subtitle"></div>
        </div>

        <div class="hero-top-right">
          <button id="shuffle-btn" aria-label="Shuffle" title="Shuffle">🔀</button>
          <span id="status-badge">IDLE</span>
          <span id="ws-status" title="OwnTone connection status">●</span>
        </div>

        <div class="hero-controls">
          <button id="prev-btn" aria-label="Previous">⏮</button>
          <button id="play-pause-btn" aria-label="Play/pause">▶</button>
          <button id="next-btn" aria-label="Next">⏭</button>
        </div>

        <div class="hero-progress">
          <div class="progress-times">
            <span id="time-current">0:00</span>
            <span id="time-total">0:00</span>
          </div>
          <div id="progress-track"><div id="progress-fill"></div></div>
        </div>
      </div>

      <div class="hero-panel">
        <form id="search-form">
          <input id="search-input" type="text" placeholder="Search or paste a YouTube link..." autocomplete="off">
          <button id="search-btn" type="submit" aria-label="Search">🔍</button>
        </form>

        <div id="volume-row">
          <div class="volume-label-row">
            <span>🔊 Volume</span>
            <span id="volume-value">50%</span>
          </div>
          <input id="volume-slider" type="range" min="0" max="100" value="50">
        </div>
      </div>
    </div>

    <div id="view-tabs">
      <button id="tab-search" type="button" class="view-tab active">Search</button>
      <button id="tab-playlist" type="button" class="view-tab">Playlist</button>
    </div>

    <div id="playlist-controls" style="display: none;">
      <div id="playlist-selector"></div>
      <div id="playlist-new-row">
        <input id="new-playlist-name" type="text" placeholder="New playlist name...">
        <button id="create-playlist-btn" type="button">+ Create</button>
      </div>
      <button id="play-all-btn" type="button">▶ Play All</button>
    </div>

    <div id="results-container">
      <div id="results-list"></div>
    </div>
  </main>

  <script src="config.js"></script>
  <script src="app.js"></script>
</body>
</html>

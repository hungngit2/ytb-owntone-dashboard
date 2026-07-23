<?php
require __DIR__ . '/backend.php';
enforce_dashboard_auth();
?>
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
          <div id="now-title"><span id="now-title-text">No track playing</span></div>
          <div id="now-subtitle"></div>
        </div>

        <div class="hero-top-right">
          <div class="hero-status-row">
            <span id="status-badge">IDLE</span>
            <span id="fifo-badge" title="Playing via the fifo pipeline (direct stream unavailable for this track)" hidden>FIFO</span>
            <span id="ws-status" title="OwnTone connection status">●</span>
          </div>
          <div class="hero-toggle-row">
            <button id="shuffle-btn" aria-label="Shuffle" title="Shuffle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg></button>
            <button id="repeat-btn" aria-label="Repeat" title="Repeat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg><span id="repeat-one-badge">1</span></button>
          </div>
        </div>

        <div class="hero-controls">
          <button id="prev-btn" aria-label="Previous"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 6h2v12H6zm3.5 6L19 18V6z"/></svg></button>
          <button id="play-pause-btn" aria-label="Play/pause"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></button>
          <button id="next-btn" aria-label="Next"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 6l9.5 6L6 18V6zM16 6h2v12h-2z"/></svg></button>
          <button id="stop-btn" aria-label="Stop" title="Stop"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12"/></svg></button>
          <button id="stream-btn" aria-label="Listen in browser" title="Listen in browser (direct stream)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" fill="currentColor" stroke="none"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg></button>
        </div>
        <audio id="browser-stream-audio" preload="none"></audio>

        <div class="hero-progress">
          <div class="progress-times">
            <span id="time-current">0:00</span>
            <span id="time-total">0:00</span>
          </div>
          <div id="progress-track"><div id="progress-fill"></div></div>
        </div>
      </div>

      <div class="hero-panel">
        <div id="volume-row">
          <div class="volume-label-row">
            <span class="volume-label">
              <button id="volume-mute-btn" type="button" aria-label="Mute/unmute" title="Mute/unmute"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" fill="currentColor" stroke="none"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg></button>
              Volume
            </span>
            <span id="volume-value">50%</span>
          </div>
          <input id="volume-slider" type="range" min="0" max="100" value="50">
        </div>

        <form id="search-form">
          <input id="search-input" type="text" placeholder="Search or paste a YouTube link..." autocomplete="off">
          <button id="search-btn" type="submit" aria-label="Search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></button>
        </form>
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
      <button id="play-all-btn" type="button"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg> Play All</button>
    </div>

    <div id="results-container">
      <div id="results-list"></div>
    </div>
  </main>

  <script src="config.js"></script>
  <script src="app.js"></script>
</body>
</html>

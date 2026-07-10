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
    <div id="disc" class="disc">
      <img id="disc-thumb" alt="">
    </div>
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

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
  };
}

function mapQueueResponse(queue) {
  const items = queue.items || [];
  const current = items.find((item) => item.id === queue.current_item_id) || items[0];
  return { title: current ? current.title : '' };
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { isYoutubeUrl, paginate, mapPlayerResponse, mapQueueResponse };
}

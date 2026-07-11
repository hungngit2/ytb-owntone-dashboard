<?php
// Runs continuously (via systemd — see docs/queue-daemon.service) so
// auto-play-next works even with no browser open: this is what watches
// OwnTone's player state and decides to advance, not any browser tab.
//
// Tries both layouts: the repo's bin/ + public/ siblings, and a flat
// deployment where this script sits alongside backend.php directly.
$candidates = [__DIR__ . '/backend.php', __DIR__ . '/../public/backend.php'];
$backendPath = null;
foreach ($candidates as $candidate) {
    if (file_exists($candidate)) {
        $backendPath = $candidate;
        break;
    }
}

if ($backendPath === null) {
    fwrite(STDERR, "queue-daemon.php: could not locate backend.php\n");
    exit(1);
}

require $backendPath;

if (php_sapi_name() !== 'cli') {
    // Defense in depth: this script sits inside the web-servable tree on a
    // flat deployment. An infinite loop must never be reachable over HTTP.
    http_response_code(403);
    exit("queue-daemon.php must be run from the CLI.\n");
}

while (true) {
    advance_queue_if_finished();
    sleep(2);
}

<?php
// Copy this file to owntone-auth.php (gitignored) and fill in the
// credentials once OwnTone's web interface basic auth is enabled
// (Settings > Web Interface). Used server-side only by backend.php's
// owntone_* curl calls — never sent to the browser.
define('OWNTONE_AUTH_USERNAME', 'admin');
define('OWNTONE_AUTH_PASSWORD', 'YOUR_PASSWORD_HERE');

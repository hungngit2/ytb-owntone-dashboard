<?php
// Copy this file to dashboard-auth.php (gitignored) and fill in credentials
// to require this app's own login (HTTP Basic Auth) for any client whose IP
// isn't private/LAN. Leave DASHBOARD_FORCE_AUTH_FOR_LOCAL as false to trust
// private/LAN clients without a login; set it to true to require login
// from everyone, LAN included.
define('DASHBOARD_AUTH_USERNAME', 'admin');
define('DASHBOARD_AUTH_PASSWORD', 'YOUR_PASSWORD_HERE');
define('DASHBOARD_FORCE_AUTH_FOR_LOCAL', false);

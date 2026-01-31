<?php

declare(strict_types=1);

define('SITE_NAME', 'DGaO-Proceedings');
define('SITE_DESCRIPTION', 'Online-Zeitschrift der Deutschen Gesellschaft für angewandte Optik e.V.');
define('SITE_ISSN', '1614-8436');
define('SITE_PUBLISHER', 'Deutsche Gesellschaft für angewandte Optik e.V.');
define('BASE_URL', 'https://dgao-proceedings.de');

define('DB_PATH', __DIR__ . '/data/proceedings.db');
define('PDF_BASE_URL', '/download');

// Admin-Zugangsdaten
define('ADMIN_USER', 'admin');
// Passwort ändern mit: php -r "echo password_hash('neuespasswort', PASSWORD_BCRYPT);"
define('ADMIN_PASSWORD_HASH', '$2y$12$UNIaH2p3gAb.enKOvE5LTeCM9Q.MSJXUyFX8XIbCXHLgfN77yenXm');

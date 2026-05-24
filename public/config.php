<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');

define('SITE_NAME', 'DGaO-Proceedings');
define('SITE_DESCRIPTION', 'Online-Zeitschrift der Deutschen Gesellschaft für angewandte Optik e.V.');
define('SITE_ISSN', '1614-8436');
define('SITE_PUBLISHER', 'Deutsche Gesellschaft für angewandte Optik e.V.');
define('BASE_URL', 'https://dgao-proceedings.de');

define('DB_PATH', __DIR__ . '/data/proceedings.db');
define('PDF_BASE_URL', '/download');

// Admin-Zugangsdaten aus database/.env laden. .env ist via .gitignore vom
// Repo ausgeschlossen — neue Deployments koennen .env.example als Vorlage
// kopieren und einen eigenen Hash setzen.
$envPath = __DIR__ . '/../database/.env';
$envData = is_file($envPath) ? parse_ini_file($envPath, false, INI_SCANNER_RAW) : [];

define('ADMIN_USER',          $envData['ADMIN_USER']          ?? '');
define('ADMIN_PASSWORD_HASH', $envData['ADMIN_PASSWORD_HASH'] ?? '');

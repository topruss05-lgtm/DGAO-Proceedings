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

// Admin-Zugangsdaten aus .env laden. Reihenfolge: Project-Root .env (von Ploi
// via "Edit environment" verwaltet), Fallback database/.env (Legacy/lokal).
// Beide Pfade liegen ausserhalb des Webroots (public/) — kein HTTP-Zugriff.
$envCandidates = [
    __DIR__ . '/../.env',
    __DIR__ . '/../database/.env',
];
$envData = [];
foreach ($envCandidates as $envPath) {
    if (is_file($envPath)) {
        $envData = parse_ini_file($envPath, false, INI_SCANNER_RAW) ?: [];
        break;
    }
}

define('ADMIN_USER',          $envData['ADMIN_USER']          ?? '');
define('ADMIN_PASSWORD_HASH', $envData['ADMIN_PASSWORD_HASH'] ?? '');

// Production-Sanity: bei leerem Hash hat das Login keine Chance — frueh und
// laut im PHP-Error-Log loggen, damit "Ungueltiger Login" nicht silent failt.
if (ADMIN_PASSWORD_HASH === '') {
    error_log('DGaO-Proceedings: ADMIN_PASSWORD_HASH ist leer — .env fehlt oder ist unvollstaendig.');
}

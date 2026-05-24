<?php

declare(strict_types=1);

/**
 * Language detection, persistence, and translation lookup.
 *
 * Priority: ?lang= parameter > cookie > default 'de'
 */

const SUPPORTED_LANGS = ['de', 'en'];
const DEFAULT_LANG = 'de';

$lang = DEFAULT_LANG;

if (isset($_GET['lang']) && in_array($_GET['lang'], SUPPORTED_LANGS, true)) {
    $lang = $_GET['lang'];
    setcookie('lang', $lang, [
        'expires'  => time() + 30 * 86400,
        'path'     => '/',
        'secure'   => true,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
} elseif (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], SUPPORTED_LANGS, true)) {
    $lang = $_COOKIE['lang'];
}

$GLOBALS['_lang'] = $lang;
$GLOBALS['_translations'] = require __DIR__ . '/lang/' . $lang . '.php';

function t(string $key): string
{
    $val = $GLOBALS['_translations'][$key] ?? $key;
    // Lang-Strings dürfen HTML-Entities enthalten (Legacy). Wir liefern nach
    // UTF-8 decodiert aus, damit nachgeschaltete e()-Calls nicht doppelt
    // encoden (z. B. e(t('site.description')) → vorher 'f&amp;uuml;r').
    return html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function currentLang(): string
{
    return $GLOBALS['_lang'];
}

function langSwitchUrl(): string
{
    $target = currentLang() === 'de' ? 'en' : 'de';
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $params = $_GET;
    $params['lang'] = $target;
    $query = http_build_query($params);
    return $uri . '?' . $query;
}

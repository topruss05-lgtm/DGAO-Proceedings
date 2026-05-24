<?php

declare(strict_types=1);

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$db = getDb();
$baseUrl = BASE_URL;

$staticPages = [
    '',
    '/archiv',
    '/suche',
    '/autoren',
    '/statistik',
    '/einreichen',
    '/kontakt',
    '/impressum',
    '/datenschutz',
];
$tagungen = $db->query('SELECT nummer FROM tagungen ORDER BY nummer DESC')->fetchAll(PDO::FETCH_COLUMN);
$papers   = $db->query('SELECT id FROM papers')->fetchAll(PDO::FETCH_COLUMN);
$autoren  = $db->query('SELECT id FROM autoren')->fetchAll(PDO::FETCH_COLUMN);

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($staticPages as $page) {
    echo '  <url><loc>' . e($baseUrl . $page) . '</loc><changefreq>monthly</changefreq></url>' . "\n";
}

foreach ($tagungen as $nummer) {
    echo '  <url><loc>' . e($baseUrl . '/archiv/' . $nummer) . '</loc><changefreq>yearly</changefreq></url>' . "\n";
}

foreach ($papers as $id) {
    echo '  <url><loc>' . e($baseUrl . '/paper/' . $id) . '</loc><changefreq>yearly</changefreq></url>' . "\n";
}

foreach ($autoren as $id) {
    echo '  <url><loc>' . e($baseUrl . '/autor/' . $id) . '</loc><changefreq>yearly</changefreq></url>' . "\n";
}

echo '</urlset>';

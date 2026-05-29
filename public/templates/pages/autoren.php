<?php
$pageTitle    = t('autoren.title') . ' - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/autoren');

$db   = getDb();
$lang = $_SESSION['lang'] ?? 'de';
$instCol = $lang === 'en' ? "COALESCE(NULLIF(i.name_en,''), i.name_de)" : 'i.name_de';
// aktuelle Affiliation = aus dem jüngsten Paper über paper_autor_institutionen
// (mit Legacy-Fallback auf autor_institutionen für noch nicht migrierte Einträge)
$autoren = $db->query("
    SELECT a.id, a.vorname, a.nachname, a.anzeige_name, a.orcid_id,
           COALESCE(
             (SELECT $instCol
              FROM paper_autor_institutionen pai
              JOIN institutionen i ON i.id = pai.institut_id
              JOIN papers p ON p.id = pai.paper_id
              JOIN tagungen t ON t.nummer = p.tagung_nummer
              WHERE pai.autor_id = a.id
              ORDER BY t.jahr DESC, pai.created_at DESC
              LIMIT 1),
             (SELECT $instCol
              FROM autor_institutionen ai
              JOIN institutionen i ON i.id = ai.institut_id
              WHERE ai.autor_id = a.id
              ORDER BY ai.ist_aktuell DESC LIMIT 1)
           ) AS affiliation,
           COUNT(pa.paper_id) AS paper_count
    FROM autoren a
    JOIN paper_autoren pa ON pa.autor_id = a.id
    GROUP BY a.id
    ORDER BY a.nachname COLLATE NOCASE, a.vorname COLLATE NOCASE
")->fetchAll();

$grouped = [];
foreach ($autoren as $a) {
    $letter = mb_strtoupper(mb_substr($a['nachname'], 0, 1));
    $grouped[$letter][] = $a;
}
ksort($grouped, SORT_LOCALE_STRING);
?>

<h1 class="h3 mb-4"><?= t('autoren.title') ?></h1>
<p class="text-muted mb-4"><?= count($autoren) ?> <?= t('autoren.count_label') ?></p>

<?php foreach ($grouped as $letter => $authors): ?>
<div class="mb-4">
    <h2 class="h5 letter-header border-bottom pb-1 mb-2"><?= e($letter) ?></h2>
    <div class="row g-1">
        <?php foreach ($authors as $a): ?>
        <div class="col-sm-6 col-lg-4">
            <a href="/autor/<?= $a['id'] ?>" class="accent-link d-block py-1"<?php if (!empty($a['affiliation'])): ?> title="<?= e($a['affiliation']) ?>"<?php endif; ?>>
                <?= e(formatAutorName($a)) ?>
                <small class="text-muted">(<?= $a['paper_count'] ?>)</small>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

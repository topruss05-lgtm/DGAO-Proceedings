<?php
$pageTitle    = t('autoren.title') . ' - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/autoren');

$db   = getDb();
$lang = $_SESSION['lang'] ?? 'de';
$instCol = $lang === 'en' ? 'i.name_en' : 'i.name_de';
$autoren = $db->query("
    SELECT a.id, a.vorname, a.nachname,
           (SELECT COALESCE(NULLIF($instCol, ''), i.name_de)
            FROM autor_institutionen ai
            JOIN institutionen i ON i.id = ai.institut_id
            WHERE ai.autor_id = a.id AND ai.ist_aktuell = 1
            LIMIT 1) AS affiliation,
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
                <?= e(trim($a['vorname'] . ' ' . $a['nachname'])) ?>
                <small class="text-muted">(<?= $a['paper_count'] ?>)</small>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

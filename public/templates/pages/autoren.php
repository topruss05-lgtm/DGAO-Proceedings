<?php
$pageTitle    = 'Autoren - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/autoren');

$db = getDb();
$autoren = $db->query('
    SELECT a.id, a.name, a.name_display, COUNT(pa.paper_id) AS paper_count
    FROM autoren a
    JOIN paper_autoren pa ON pa.autor_id = a.id
    GROUP BY a.id
    ORDER BY a.name COLLATE NOCASE
')->fetchAll();

$grouped = [];
foreach ($autoren as $a) {
    $letter = mb_strtoupper(mb_substr($a['name'], 0, 1));
    $grouped[$letter][] = $a;
}
ksort($grouped, SORT_LOCALE_STRING);
?>

<h1 class="h3 mb-4">Autoren</h1>
<p class="text-muted mb-4"><?= count($autoren) ?> Autoren</p>

<?php foreach ($grouped as $letter => $authors): ?>
<div class="mb-4">
    <h2 class="h5 letter-header border-bottom pb-1 mb-2"><?= e($letter) ?></h2>
    <div class="row g-1">
        <?php foreach ($authors as $a): ?>
        <div class="col-sm-6 col-lg-4">
            <a href="/autor/<?= $a['id'] ?>" class="text-decoration-none d-block py-1">
                <?= e($a['name_display']) ?>
                <small class="text-muted">(<?= $a['paper_count'] ?>)</small>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

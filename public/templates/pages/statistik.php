<?php
$pageTitle    = t('statistik.title') . ' - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/statistik');
$pageSlug     = 'statistik';

$extraHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>';

$db = getDb();

// --- Query 1: Publications per author (ranked) ---
$autorStats = $db->query('
    SELECT a.id, a.vorname, a.nachname, a.affiliation,
           COUNT(pa.paper_id) AS pub_count
    FROM autoren a
    JOIN paper_autoren pa ON pa.autor_id = a.id
    GROUP BY a.id
    ORDER BY pub_count DESC, a.nachname COLLATE NOCASE
')->fetchAll();

// --- Query 2: Publications per author per year (top 15) ---
$topAutorIds = array_slice(array_column($autorStats, 'id'), 0, 15);
$autorJahrData = [];
if (!empty($topAutorIds)) {
    $placeholders = implode(',', array_fill(0, count($topAutorIds), '?'));
    $stmt = $db->prepare("
        SELECT a.id, a.vorname, a.nachname, a.affiliation,
               t.jahr, COUNT(*) as cnt
        FROM autoren a
        JOIN paper_autoren pa ON pa.autor_id = a.id
        JOIN papers p ON p.id = pa.paper_id
        JOIN tagungen t ON t.nummer = p.tagung_nummer
        WHERE a.id IN ($placeholders)
        GROUP BY a.id, t.jahr
        ORDER BY t.jahr
    ");
    $stmt->execute($topAutorIds);
    $autorJahrData = $stmt->fetchAll();
}

// --- Query 3: Publications per affiliation ---
$affiliationStats = $db->query("
    SELECT a.affiliation, COUNT(DISTINCT pa.paper_id) AS pub_count
    FROM autoren a
    JOIN paper_autoren pa ON pa.autor_id = a.id
    WHERE a.affiliation <> ''
    GROUP BY a.affiliation
    ORDER BY pub_count DESC
")->fetchAll();

// --- Query 4: Publications per affiliation per year (top 15) ---
$topAffiliations = array_slice(array_column($affiliationStats, 'affiliation'), 0, 15);
$affiliationJahrData = [];
if (!empty($topAffiliations)) {
    $placeholders = implode(',', array_fill(0, count($topAffiliations), '?'));
    $stmt = $db->prepare("
        SELECT a.affiliation, t.jahr, COUNT(DISTINCT pa.paper_id) as cnt
        FROM autoren a
        JOIN paper_autoren pa ON pa.autor_id = a.id
        JOIN papers p ON p.id = pa.paper_id
        JOIN tagungen t ON t.nummer = p.tagung_nummer
        WHERE a.affiliation IN ($placeholders)
        GROUP BY a.affiliation, t.jahr
        ORDER BY t.jahr
    ");
    $stmt->execute($topAffiliations);
    $affiliationJahrData = $stmt->fetchAll();
}

// --- All years for chart axis ---
$allYears = $db->query('SELECT DISTINCT jahr FROM tagungen ORDER BY jahr')->fetchAll(PDO::FETCH_COLUMN);

// --- Prepare chart data ---
$autorSeries = [];
foreach ($autorJahrData as $row) {
    $key = $row['id'];
    if (!isset($autorSeries[$key])) {
        $autorSeries[$key] = [
            'label' => trim($row['vorname'] . ' ' . $row['nachname']),
            'affiliation' => $row['affiliation'],
            'data' => [],
        ];
    }
    $autorSeries[$key]['data'][$row['jahr']] = (int)$row['cnt'];
}

$affilSeries = [];
foreach ($affiliationJahrData as $row) {
    $key = $row['affiliation'];
    if (!isset($affilSeries[$key])) {
        $affilSeries[$key] = ['label' => $row['affiliation'], 'data' => []];
    }
    $affilSeries[$key]['data'][$row['jahr']] = (int)$row['cnt'];
}

$showLimit = 50;
?>

<h1 class="h3 mb-4"><?= t('statistik.title') ?></h1>

<!-- Table 1: Publications per Author -->
<section class="mb-5">
    <h2 class="h5 mb-3"><?= t('statistik.pub_pro_autor') ?></h2>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" id="table-autor-stats">
                <thead>
                    <tr>
                        <th style="width: 60px;"><?= t('statistik.rang') ?></th>
                        <th><?= t('statistik.autor') ?></th>
                        <th><?= t('statistik.affiliation') ?></th>
                        <th style="width: 80px;"><?= t('statistik.anzahl') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($autorStats as $i => $a): ?>
                    <tr<?= $i >= $showLimit ? ' class="stat-row-hidden d-none"' : '' ?>>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td>
                            <a href="/autor/<?= $a['id'] ?>" class="accent-link">
                                <?= e(trim($a['vorname'] . ' ' . $a['nachname'])) ?>
                            </a>
                        </td>
                        <td class="text-muted small"><?= e($a['affiliation']) ?></td>
                        <td><strong><?= $a['pub_count'] ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (count($autorStats) > $showLimit): ?>
    <button class="btn btn-sm btn-outline-secondary mt-2" onclick="toggleRows(this)"
            data-show="<?= e(t('statistik.alle_anzeigen')) ?> (<?= count($autorStats) ?>)"
            data-hide="<?= e(t('statistik.weniger_anzeigen')) ?>">
        <?= t('statistik.alle_anzeigen') ?> (<?= count($autorStats) ?>)
    </button>
    <?php endif; ?>
</section>

<!-- Chart 1: Publications per Author per Year -->
<section class="mb-5">
    <h2 class="h5 mb-3"><?= t('statistik.pub_pro_autor_jahr') ?></h2>
    <div class="card">
        <div class="card-body" style="position: relative; height: 400px;">
            <canvas id="chart-autor-jahr"></canvas>
        </div>
    </div>
</section>

<!-- Table 2: Publications per Affiliation -->
<section class="mb-5">
    <h2 class="h5 mb-3"><?= t('statistik.pub_pro_affiliation') ?></h2>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" id="table-affiliation-stats">
                <thead>
                    <tr>
                        <th style="width: 60px;"><?= t('statistik.rang') ?></th>
                        <th><?= t('statistik.affiliation') ?></th>
                        <th style="width: 80px;"><?= t('statistik.anzahl') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($affiliationStats as $i => $af): ?>
                    <tr<?= $i >= $showLimit ? ' class="stat-row-hidden d-none"' : '' ?>>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td><?= e($af['affiliation']) ?></td>
                        <td><strong><?= $af['pub_count'] ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (count($affiliationStats) > $showLimit): ?>
    <button class="btn btn-sm btn-outline-secondary mt-2" onclick="toggleRows(this)"
            data-show="<?= e(t('statistik.alle_anzeigen')) ?> (<?= count($affiliationStats) ?>)"
            data-hide="<?= e(t('statistik.weniger_anzeigen')) ?>">
        <?= t('statistik.alle_anzeigen') ?> (<?= count($affiliationStats) ?>)
    </button>
    <?php endif; ?>
</section>

<!-- Chart 2: Publications per Affiliation per Year -->
<section class="mb-5">
    <h2 class="h5 mb-3"><?= t('statistik.pub_pro_affiliation_jahr') ?></h2>
    <div class="card">
        <div class="card-body" style="position: relative; height: 400px;">
            <canvas id="chart-affiliation-jahr"></canvas>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var colors = [
        '#862e42', '#2b5797', '#3a7d44', '#c4652e', '#6b3fa0',
        '#c2185b', '#00838f', '#8d6e63', '#546e7a', '#7b1fa2',
        '#d84315', '#1565c0', '#2e7d32', '#f9a825', '#4527a0'
    ];

    var years = <?= json_encode(array_map('intval', $allYears)) ?>;

    // Chart 1: Author per Year
    var autorDatasets = [];
    <?php $idx = 0; foreach ($autorSeries as $series):
        $dataPoints = [];
        foreach ($allYears as $y) {
            $dataPoints[] = $series['data'][$y] ?? 0;
        }
        $legendLabel = $series['label'] . ($series['affiliation'] ? ' (' . $series['affiliation'] . ')' : '');
    ?>
    autorDatasets.push({
        label: <?= json_encode($legendLabel) ?>,
        data: <?= json_encode($dataPoints) ?>,
        borderColor: colors[<?= $idx % 15 ?>],
        backgroundColor: colors[<?= $idx % 15 ?>] + '33',
        tension: 0.3,
        fill: false,
        pointRadius: 2,
        borderWidth: 2
    });
    <?php $idx++; endforeach; ?>

    new Chart(document.getElementById('chart-autor-jahr'), {
        type: 'line',
        data: { labels: years, datasets: autorDatasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { size: 11 }, padding: 12 }
                }
            },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } },
                x: { title: { display: true, text: <?= json_encode(t('statistik.jahr')) ?> } }
            }
        }
    });

    // Chart 2: Affiliation per Year
    var affilDatasets = [];
    <?php $idx = 0; foreach ($affilSeries as $series):
        $dataPoints = [];
        foreach ($allYears as $y) {
            $dataPoints[] = $series['data'][$y] ?? 0;
        }
    ?>
    affilDatasets.push({
        label: <?= json_encode($series['label']) ?>,
        data: <?= json_encode($dataPoints) ?>,
        borderColor: colors[<?= $idx % 15 ?>],
        backgroundColor: colors[<?= $idx % 15 ?>] + '33',
        tension: 0.3,
        fill: false,
        pointRadius: 2,
        borderWidth: 2
    });
    <?php $idx++; endforeach; ?>

    new Chart(document.getElementById('chart-affiliation-jahr'), {
        type: 'line',
        data: { labels: years, datasets: affilDatasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { size: 11 }, padding: 12 }
                }
            },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } },
                x: { title: { display: true, text: <?= json_encode(t('statistik.jahr')) ?> } }
            }
        }
    });
});

function toggleRows(btn) {
    var section = btn.closest('section');
    var rows = section.querySelectorAll('.stat-row-hidden');
    var expanded = rows[0] && !rows[0].classList.contains('d-none');
    rows.forEach(function(r) { r.classList.toggle('d-none', expanded); });
    btn.textContent = expanded ? btn.dataset.show : btn.dataset.hide;
}
</script>

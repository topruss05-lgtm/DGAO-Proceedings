<?php
$adminPageTitle = 'Cleanup Review-Queue';
$db = getDbAdmin();

// Ensure table exists
$db->exec("CREATE TABLE IF NOT EXISTS merge_review_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL DEFAULT 'author' CHECK (kind IN ('author','institution')),
    cluster_json TEXT NOT NULL,
    verdict_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
)");

$rows = $db->query(
    "SELECT id, kind, cluster_json, verdict_json, created_at
     FROM merge_review_queue
     WHERE status = 'pending'
     ORDER BY id DESC
     LIMIT 100"
)->fetchAll();

// Decode JSON for each row
$items = [];
foreach ($rows as $row) {
    $cluster = json_decode((string) $row['cluster_json'], true) ?? [];
    $verdict = json_decode((string) $row['verdict_json'], true) ?? [];
    $items[] = [
        'id'         => (int) $row['id'],
        'kind'       => (string) $row['kind'],
        'created_at' => (string) $row['created_at'],
        'cluster'    => $cluster,
        'verdict'    => $verdict,
    ];
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Review-Queue</h1>
    <a href="/admin/cleanup" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zum Dashboard
    </a>
</div>

<?php $flash = getFlash(); if ($flash): ?>
<div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (empty($items)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="bi bi-check2-circle fs-1 d-block mb-3"></i>
        Aktuell sind keine offenen Vorschl&auml;ge in der Queue.
        <div class="mt-3">
            <a href="/admin/cleanup" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Zur&uuml;ck zum Dashboard
            </a>
        </div>
    </div>
</div>
<?php else: ?>

<p class="text-muted mb-3"><?= count($items) ?> offene Vorschl&auml;ge (max. 100 werden angezeigt)</p>

<?php foreach ($items as $item):
    $verdict   = $item['verdict'];
    $cluster   = $item['cluster'];
    $vType     = (string) ($verdict['verdict'] ?? 'unknown');
    $vConf     = (float)  ($verdict['confidence'] ?? 0.0);
    $vReason   = (string) ($verdict['reason'] ?? '');
    $candidates = (array) ($cluster['candidates'] ?? []);

    $badgeClass = match ($vType) {
        'merge'         => 'bg-success-subtle text-success-emphasis',
        'keep_separate' => 'bg-secondary-subtle text-secondary-emphasis',
        'unsure'        => 'bg-warning-subtle text-warning-emphasis',
        default         => 'bg-light text-dark',
    };
    $badgeLabel = match ($vType) {
        'merge'         => 'merge',
        'keep_separate' => 'keep_separate',
        'unsure'        => 'unsure',
        default         => e($vType),
    };
?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <strong>#<?= $item['id'] ?></strong>
            <span class="badge bg-light text-dark border ms-1"><?= e($item['kind']) ?></span>
        </span>
        <small class="text-muted"><?= e($item['created_at']) ?></small>
    </div>
    <div class="card-body">
        <div class="mb-2">
            <span class="badge <?= $badgeClass ?> fs-6"><?= $badgeLabel ?></span>
            <span class="ms-2 text-muted">Konfidenz: <strong><?= number_format($vConf * 100, 0) ?>&nbsp;%</strong></span>
        </div>

        <?php if ($vReason !== ''): ?>
        <p class="mb-2 fst-italic text-muted small"><?= e($vReason) ?></p>
        <?php endif; ?>

        <?php if (!empty($candidates)): ?>
        <ul class="list-unstyled mb-2">
            <?php foreach ($candidates as $c): ?>
            <li class="small">
                <i class="bi bi-person text-secondary"></i>
                <strong><?= e((string) ($c['name'] ?? '')) ?></strong>
                &ndash; <?= (int) ($c['papers'] ?? 0) ?> Papers,
                aff: <span class="text-muted"><?= e(mb_strimwidth((string) ($c['affiliations'] ?? ''), 0, 60, '&hellip;')) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <div class="d-flex gap-2 mt-3">
            <form method="post" action="/admin/cleanup/action" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn btn-sm btn-success"
                        onclick="return confirm('Zusammenf&uuml;hrung wirklich ausf&uuml;hren?')">
                    <i class="bi bi-check-circle"></i> Approve
                </button>
            </form>
            <form method="post" action="/admin/cleanup/action" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-x-circle"></i> Reject
                </button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php
$adminPageTitle = 'Tagungen';
$db = getDb();

$tagungen = $db->query('
    SELECT t.nummer, t.jahr, t.ort, t.datum_von, t.datum_bis,
           COUNT(p.id) as paper_count
    FROM tagungen t
    LEFT JOIN papers p ON p.tagung_nummer = t.nummer
    GROUP BY t.nummer
    ORDER BY t.nummer DESC
')->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Tagungen</h1>
    <a href="/admin/tagungen/neu" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Neue Tagung
    </a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nr.</th>
                    <th>Jahr</th>
                    <th>Ort</th>
                    <th>Datum</th>
                    <th>Papers</th>
                    <th class="text-end">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tagungen as $t): ?>
                <tr>
                    <td><strong><?= $t['nummer'] ?></strong></td>
                    <td><?= $t['jahr'] ?></td>
                    <td><?= e($t['ort'] ?? '') ?></td>
                    <td>
                        <?php if ($t['datum_von']): ?>
                            <?= e(formatDate($t['datum_von'])) ?>
                            <?php if ($t['datum_bis']): ?> &ndash; <?= e(formatDate($t['datum_bis'])) ?><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="/admin/papers?tagung=<?= $t['nummer'] ?>"><?= $t['paper_count'] ?></a>
                    </td>
                    <td class="text-end">
                        <a href="/admin/tagungen/<?= $t['nummer'] ?>/edit" class="btn btn-sm btn-outline-primary" title="Bearbeiten">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="/admin/tagungen/<?= $t['nummer'] ?>/delete" class="btn btn-sm btn-outline-danger" title="Löschen">
                            <i class="bi bi-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

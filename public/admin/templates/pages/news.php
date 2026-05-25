<?php
$adminPageTitle = 'News';

$news = getAllNewsForAdmin();
$activeCount  = 0;
$autoCount    = 0;
$manualCount  = 0;
foreach ($news as $n) {
    if ((int)$n['is_active'] === 1) $activeCount++;
    if ($n['source'] === 'auto')    $autoCount++;
    if ($n['source'] === 'manual')  $manualCount++;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">News</h1>
    <a href="/admin/news/neu" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Neue News
    </a>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    Auto-News werden beim Speichern einer Tagung automatisch erzeugt (z.&nbsp;B. Einreichung
    offen, Frist gesetzt, Proceedings online). Sie lassen sich hier deaktivieren oder
    pinnen, aber Titel/Text bleiben aus Konsistenzgr&uuml;nden Template-gesteuert.
    Manuelle News k&ouml;nnen frei gepflegt und gel&ouml;scht werden.
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-4">
        <div class="stat-card">
            <div class="stat-value"><?= $activeCount ?></div>
            <div class="stat-label">Aktiv</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card">
            <div class="stat-value"><?= $autoCount ?></div>
            <div class="stat-label">Auto-generiert</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card">
            <div class="stat-value"><?= $manualCount ?></div>
            <div class="stat-label">Manuell</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th style="width: 110px;">Datum</th>
                    <th style="width: 90px;">Typ</th>
                    <th>Titel (DE)</th>
                    <th style="width: 90px;" class="text-center">Aktiv</th>
                    <th style="width: 70px;" class="text-center">Pin</th>
                    <th style="width: 140px;" class="text-end">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($news)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        Noch keine News. Speichere eine Tagung mit aktiver Vorlagen-Phase,
                        damit die ersten Auto-News entstehen &mdash; oder lege oben manuell eine an.
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($news as $n): ?>
                <tr class="<?= (int)$n['is_active'] === 0 ? 'text-muted' : '' ?>">
                    <td class="text-nowrap">
                        <?= e(formatDateLong($n['display_date'])) ?>
                    </td>
                    <td>
                        <?php if ($n['source'] === 'auto'): ?>
                            <span class="badge bg-info-subtle text-info-emphasis" title="<?= e($n['trigger_key'] ?? '') ?>">
                                <i class="bi bi-robot"></i> Auto
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">
                                <i class="bi bi-pencil-square"></i> Manuell
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div><?= e($n['title_de']) ?></div>
                        <?php if (!empty($n['link_url'])): ?>
                            <small class="text-muted">
                                <i class="bi bi-link-45deg"></i> <?= e($n['link_url']) ?>
                            </small>
                        <?php endif; ?>
                        <?php if ($n['source'] === 'auto' && !empty($n['tagung_nummer'])): ?>
                            <small class="text-muted"> &middot; Tagung <?= (int)$n['tagung_nummer'] ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <form method="post" action="/admin/news/<?= (int)$n['id'] ?>/toggle" class="d-inline">
                            <?= csrfField() ?>
                            <button type="submit"
                                    class="btn btn-sm <?= (int)$n['is_active'] === 1 ? 'btn-success' : 'btn-outline-secondary' ?>"
                                    title="<?= (int)$n['is_active'] === 1 ? 'Aktiv (klicken zum Deaktivieren)' : 'Inaktiv (klicken zum Aktivieren)' ?>">
                                <?php if ((int)$n['is_active'] === 1): ?>
                                    <i class="bi bi-check-circle-fill"></i>
                                <?php else: ?>
                                    <i class="bi bi-circle"></i>
                                <?php endif; ?>
                            </button>
                        </form>
                    </td>
                    <td class="text-center">
                        <?php if ((int)$n['sort_weight'] > 0): ?>
                            <i class="bi bi-pin-angle-fill text-warning" title="Gepinnt (sort_weight=<?= (int)$n['sort_weight'] ?>)"></i>
                        <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="/admin/news/<?= (int)$n['id'] ?>/edit" class="btn btn-sm btn-outline-primary" title="Bearbeiten">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <?php if ($n['source'] === 'manual'): ?>
                            <a href="/admin/news/<?= (int)$n['id'] ?>/delete" class="btn btn-sm btn-outline-danger" title="L&ouml;schen">
                                <i class="bi bi-trash"></i>
                            </a>
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline-danger" disabled
                                    title="Auto-News k&ouml;nnen nur deaktiviert, nicht gel&ouml;scht werden.">
                                <i class="bi bi-trash"></i>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

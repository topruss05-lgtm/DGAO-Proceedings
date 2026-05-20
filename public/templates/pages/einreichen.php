<?php
$activeTagung = getCurrentVorlagenTagung();
$isOpen       = $activeTagung !== null;

$papers = [];
if ($isOpen) {
    $stmt = getDb()->prepare(
        'SELECT id, code, typ, titel, hauptautor
         FROM papers
         WHERE tagung_nummer = ?
         ORDER BY
            CASE typ
                WHEN \'hauptvortrag\'  THEN 1
                WHEN \'sondervortrag\' THEN 2
                WHEN \'vortrag\'       THEN 3
                WHEN \'poster\'        THEN 4
                ELSE 9
            END,
            substr(code,1,1), CAST(substr(code,2) AS INTEGER)'
    );
    $stmt->execute([$activeTagung['nummer']]);
    $papers = $stmt->fetchAll();
}

$pageTitle    = t('einreichen.page_title') . ' — ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/einreichen');
$metaTags = [['name' => 'description', 'content' => t('einreichen.meta_desc')]];

$deadline = $activeTagung['einreichungsfrist'] ?? '';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/"><?= t('nav.home') ?? 'Start' ?></a></li>
        <li class="breadcrumb-item active"><?= t('einreichen.breadcrumb') ?></li>
    </ol>
</nav>

<h1 class="h3 mb-3"><?= t('einreichen.heading') ?></h1>
<p class="lead text-muted mb-4"><?= t('einreichen.lead') ?></p>

<?php if (!$isOpen): ?>

    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-lock-fill flex-shrink-0 mt-1"></i>
        <div>
            <strong><?= t('vorlage.closed_title') ?></strong>
            <div class="small mt-1"><?= t('vorlage.closed_text') ?></div>
        </div>
    </div>

<?php else: ?>

    <div class="alert alert-success d-flex align-items-start gap-2">
        <i class="bi bi-unlock-fill flex-shrink-0 mt-1"></i>
        <div>
            <strong>
                <?= sprintf(
                    t('vorlage.open_title'),
                    (int)$activeTagung['nummer'],
                    (int)$activeTagung['jahr']
                ) ?>
            </strong>
            <div class="small mt-1"><?= t('einreichen.open_text') ?></div>
        </div>
    </div>

    <?php if ($deadline !== ''): ?>
    <div class="alert alert-info d-flex align-items-center gap-2 mt-3">
        <i class="bi bi-calendar-event flex-shrink-0"></i>
        <div>
            <strong><?= t('einreichen.deadline_label') ?>:</strong>
            <?= e(formatDateLong($deadline)) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         Manuskript-Vorlage herunterladen
         ============================================================ -->
    <section id="vorlage" class="mt-4">
        <p class="text-muted mb-3"><?= t('einreichen.step_template_desc') ?></p>

        <div class="card mb-3 border-0" style="background: rgba(8,145,178,.04); border-left: 3px solid var(--accent, #b42e42) !important;">
            <div class="card-body">
                <label for="vorlage-paper-select" class="form-label fw-semibold mb-1">
                    <i class="bi bi-magic"></i> <?= t('vorlage.picker_label') ?>
                </label>
                <div class="form-text mb-2"><?= t('vorlage.picker_help') ?></div>

                <select id="vorlage-paper-select" class="form-select form-select-sm" autocomplete="off">
                    <option value="" data-mode="blank" selected><?= t('vorlage.picker_blank') ?></option>
                    <?php if (!empty($papers)): ?>
                        <optgroup label="<?= e((int)$activeTagung['nummer'] . '. ' . t('archiv_detail.jahrestagung_der_dgao')) ?>">
                            <?php foreach ($papers as $p):
                                $label = $p['code'] . ' — ' . $p['titel'];
                                if (!empty($p['hauptautor'])) $label .= ' (' . $p['hauptautor'] . ')';
                            ?>
                                <option value="<?= e($p['id']) ?>" data-mode="paper"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>

                <div id="vorlage-paper-hint" class="form-text mt-2 d-none">
                    <i class="bi bi-check-circle text-success"></i>
                    <span data-role="hint-text"></span>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center">
            <a class="btn btn-primary vorlage-dl-kit"
               href="/manuskript-vorlage/kit">
                <i class="bi bi-archive"></i> <?= t('einreichen.dl_kit') ?>
            </a>
            <span class="text-muted small">— <?= t('einreichen.dl_kit_desc') ?></span>
        </div>
    </section>

    <script>
    (function() {
        var sel  = document.getElementById('vorlage-paper-select');
        var hint = document.getElementById('vorlage-paper-hint');
        var kit  = document.querySelector('a.vorlage-dl-kit');
        if (!sel || !kit) return;
        var hintTxt = hint.querySelector('[data-role="hint-text"]');
        var msgPaper = <?= json_encode(html_entity_decode(t('vorlage.picker_hint_paper'), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?>;

        function update() {
            var opt  = sel.options[sel.selectedIndex];
            var id   = opt.value;
            var mode = opt.getAttribute('data-mode');
            if (mode === 'paper' && id) {
                kit.href = '/paper/' + encodeURIComponent(id) + '/template/kit';
                hintTxt.textContent = msgPaper.replace('%s', opt.textContent.trim());
                hint.classList.remove('d-none');
            } else {
                kit.href = '/manuskript-vorlage/kit';
                hint.classList.add('d-none');
            }
        }
        sel.addEventListener('change', update);

        var qs  = new URLSearchParams(window.location.search);
        var pre = qs.get('paper') || qs.get('code');
        if (pre) {
            var preU = pre.toUpperCase();
            for (var i = 0; i < sel.options.length; i++) {
                var o = sel.options[i];
                if (!o.value) continue;
                var label = o.textContent.toUpperCase();
                if (o.value.toUpperCase() === preU || label.startsWith(preU + ' ')) {
                    sel.selectedIndex = i;
                    break;
                }
            }
        }
        update();
    })();
    </script>

<?php endif; ?>

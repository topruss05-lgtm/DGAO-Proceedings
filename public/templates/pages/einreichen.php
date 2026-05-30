<?php
$activeTagung = getCurrentVorlagenTagung();
$isOpen       = $activeTagung !== null;

$papers = $isOpen ? getPapersByTagung((int) $activeTagung['nummer']) : [];
attachPaperAutoren($papers);

$pageTitle    = t('einreichen.page_title') . ' — ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/einreichen');
$metaTags = [['name' => 'description', 'content' => t('einreichen.meta_desc')]];

$deadline = $activeTagung['einreichungsfrist'] ?? '';
?>

<h1 class="h3 mb-3"><?= e(t('einreichen.heading')) ?></h1>
<p class="lead text-muted mb-4"><?= t('einreichen.lead') ?></p>

<?php if (!$isOpen): ?>

    <div class="einreichen-status einreichen-status--closed">
        <h2 class="h6 mb-1"><?= e(t('vorlage.closed_title')) ?></h2>
        <p class="small text-muted mb-0"><?= t('vorlage.closed_text') ?></p>
    </div>

<?php else: ?>

    <div class="einreichen-status einreichen-status--open">
        <div class="einreichen-status__main">
            <h2 class="h6 mb-1">
                <?= sprintf(
                    e(t('vorlage.open_title')),
                    (int)$activeTagung['nummer'],
                    (int)$activeTagung['jahr']
                ) ?>
            </h2>
            <?php if (!empty($activeTagung['ort'])): ?>
                <p class="small text-muted mb-0"><?= e($activeTagung['ort']) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($deadline !== ''): ?>
        <div class="einreichen-status__deadline">
            <span class="small text-muted d-block"><?= e(t('einreichen.deadline_label')) ?></span>
            <strong><?= e(formatDateLong($deadline)) ?></strong>
        </div>
        <?php endif; ?>
    </div>

    <ol class="einreichen-steps">
        <li class="einreichen-step">
            <div class="einreichen-step__marker" aria-hidden="true">1</div>
            <div class="einreichen-step__body">
                <h2 class="einreichen-step__title"><?= e(t('einreichen.step1_heading')) ?></h2>
                <p class="einreichen-step__desc"><?= t('einreichen.step1_desc') ?></p>

                <div class="einreichen-picker">
                    <label for="vorlage-paper-select" class="form-label small mb-2"><?= e(t('vorlage.picker_label')) ?></label>
                    <select id="vorlage-paper-select" class="form-select" autocomplete="off">
                        <option value="" data-mode="blank" selected><?= e(t('vorlage.picker_blank')) ?></option>
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
                </div>

                <div class="einreichen-step__action">
                    <a class="btn btn-primary vorlage-dl-kit" href="/manuskript-vorlage/kit">
                        <?= e(t('einreichen.dl_kit')) ?>
                    </a>
                    <span class="text-muted small einreichen-step__action-note"><?= t('einreichen.dl_kit_desc') ?></span>
                </div>
            </div>
        </li>

        <li class="einreichen-step">
            <div class="einreichen-step__marker" aria-hidden="true">2</div>
            <div class="einreichen-step__body">
                <h2 class="einreichen-step__title"><?= e(t('einreichen.step2_heading')) ?></h2>
                <p class="einreichen-step__desc"><?= t('einreichen.step2_desc') ?></p>

                <div class="einreichen-mailbox">
                    <a href="mailto:sekretariat@dgao.de" class="einreichen-mailbox__addr accent-link">sekretariat@dgao.de</a>
                    <p class="einreichen-mailbox__note"><?= t('einreichen.step2_attachments') ?></p>
                </div>
            </div>
        </li>
    </ol>

    <script>
    (function() {
        var sel = document.getElementById('vorlage-paper-select');
        var kit = document.querySelector('a.vorlage-dl-kit');
        if (!sel || !kit) return;

        function update() {
            var opt  = sel.options[sel.selectedIndex];
            var id   = opt.value;
            var mode = opt.getAttribute('data-mode');
            kit.href = (mode === 'paper' && id)
                ? '/paper/' + encodeURIComponent(id) + '/template/kit'
                : '/manuskript-vorlage/kit';
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

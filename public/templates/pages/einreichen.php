<?php
require_once __DIR__ . '/../../submissions.php';
require_once __DIR__ . '/../../mailer.php';

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
            code'
    );
    $stmt->execute([$activeTagung['nummer']]);
    $papers = $stmt->fetchAll();
}

$pageTitle    = t('einreichen.page_title') . ' — ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/einreichen');
$metaTags = [['name' => 'description', 'content' => t('einreichen.meta_desc')]];

// Submit-Form: Upload-Link anfordern
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOpen) {
    $code  = trim($_POST['code']  ?? '');
    $email = trim($_POST['email'] ?? '');
    $result = createSubmissionRequest($code, $email);
    if ($result !== null) {
        $link = BASE_URL . '/einreichen/' . $result['token'];
        $paper = $result['paper'];
        $expires = $result['expires_at'];
        $body = <<<EOT
Hallo,

du hast einen Upload-Link für dein DGaO-Proceedings-Manuskript angefordert.

  Beitrag: {$paper['code']} — {$paper['titel']}
  Tagung:  {$paper['tagung_nummer']}. Jahrestagung der DGaO

Klicke auf den folgenden Link, um deine PDF-Datei hochzuladen:

  {$link}

Der Link ist gültig bis: {$expires}

Solltest du diese Mail nicht angefordert haben, kannst du sie ignorieren —
ohne Klick auf den Link passiert nichts.

Bei Fragen: dgao-sekretariat@dgao.de

—
Tagungsgeschäftsführung der DGaO
EOT;
        sendMail($email, '[DGaO] Upload-Link für Beitrag ' . $paper['code'], $body);
    }
    $flash = ['type' => 'info', 'message' => t('einreichen.flash_generic')];
}
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

    <!-- ============================================================
         Schritt 1: Manuskript-Vorlage herunterladen
         ============================================================ -->
    <section id="vorlage" class="mt-4">
        <h2 class="h5 mb-2">
            <span class="badge text-bg-primary me-1">1</span>
            <?= t('einreichen.step_template') ?>
        </h2>
        <p class="text-muted small mb-3"><?= t('einreichen.step_template_desc') ?></p>

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

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h3 class="h6 card-title">
                            <i class="bi bi-file-earmark-word text-primary"></i>
                            <?= t('vorlage.word_title') ?>
                        </h3>
                        <p class="text-muted small"><?= t('vorlage.word_desc') ?></p>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-outline-primary btn-sm vorlage-dl"
                               data-format="word" data-lang="de" href="/manuskript-vorlage/word/de">
                                <i class="bi bi-download"></i> <?= t('vorlage.dl_de_docx') ?>
                            </a>
                            <a class="btn btn-outline-primary btn-sm vorlage-dl"
                               data-format="word" data-lang="en" href="/manuskript-vorlage/word/en">
                                <i class="bi bi-download"></i> <?= t('vorlage.dl_en_docx') ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h3 class="h6 card-title">
                            <i class="bi bi-file-earmark-code text-primary"></i>
                            <?= t('vorlage.latex_title') ?>
                        </h3>
                        <p class="text-muted small"><?= t('vorlage.latex_desc') ?></p>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-outline-primary btn-sm vorlage-dl"
                               data-format="latex" data-lang="de" href="/manuskript-vorlage/latex/de">
                                <i class="bi bi-file-earmark-zip"></i> <?= t('vorlage.dl_de_zip') ?>
                            </a>
                            <a class="btn btn-outline-primary btn-sm vorlage-dl"
                               data-format="latex" data-lang="en" href="/manuskript-vorlage/latex/en">
                                <i class="bi bi-file-earmark-zip"></i> <?= t('vorlage.dl_en_zip') ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card h-100">
                    <div class="card-body py-2">
                        <span class="text-muted small text-uppercase me-2" style="letter-spacing:.08em;">
                            <i class="bi bi-shield-check"></i> <?= t('vorlage.copyright_title') ?>:
                        </span>
                        <a href="/manuskript-vorlage/copyright/de" class="btn btn-link btn-sm p-0">
                            <i class="bi bi-file-earmark-pdf"></i> <?= t('vorlage.dl_de_copyright') ?>
                        </a>
                        <span class="text-muted">·</span>
                        <a href="/manuskript-vorlage/copyright/en" class="btn btn-link btn-sm p-0">
                            <i class="bi bi-file-earmark-pdf"></i> <?= t('vorlage.dl_en_copyright') ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         Schritt 2: Fertiges Manuskript einreichen
         ============================================================ -->
    <section id="upload" class="mt-5">
        <h2 class="h5 mb-2">
            <span class="badge text-bg-primary me-1">2</span>
            <?= t('einreichen.step_upload') ?>
        </h2>
        <p class="text-muted small mb-3"><?= t('einreichen.step_upload_desc') ?></p>

        <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <div class="card" style="max-width: 540px;">
            <div class="card-body">
                <form method="post" action="/einreichen">
                    <div class="mb-3">
                        <label for="code" class="form-label"><?= t('einreichen.field_code') ?></label>
                        <input type="text" class="form-control" id="code" name="code"
                               placeholder="z. B. A12, H1, P5" required maxlength="6"
                               value="<?= e($_POST['code'] ?? '') ?>">
                        <div class="form-text"><?= t('einreichen.field_code_help') ?></div>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label"><?= t('einreichen.field_email') ?></label>
                        <input type="email" class="form-control" id="email" name="email"
                               placeholder="vorname.nachname@example.com" required
                               value="<?= e($_POST['email'] ?? '') ?>">
                        <div class="form-text"><?= t('einreichen.field_email_help') ?></div>
                    </div>

                    <button type="submit" class="btn btn-accent">
                        <i class="bi bi-envelope-arrow-up"></i> <?= t('einreichen.btn') ?>
                    </button>
                </form>
            </div>
        </div>

        <div class="mt-3 text-muted small" style="max-width: 540px;">
            <strong><?= t('einreichen.hint_title') ?></strong> <?= t('einreichen.hint_text') ?>
        </div>
    </section>

    <script>
    (function() {
        var sel  = document.getElementById('vorlage-paper-select');
        var hint = document.getElementById('vorlage-paper-hint');
        if (!sel) return;
        var hintTxt = hint.querySelector('[data-role="hint-text"]');
        var msgPaper = <?= json_encode(html_entity_decode(t('vorlage.picker_hint_paper'), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?>;

        function update() {
            var opt  = sel.options[sel.selectedIndex];
            var id   = opt.value;
            var mode = opt.getAttribute('data-mode');
            document.querySelectorAll('a.vorlage-dl').forEach(function(a) {
                var fmt  = a.getAttribute('data-format');
                var lang = a.getAttribute('data-lang');
                if (mode === 'paper' && id) {
                    a.href = '/paper/' + encodeURIComponent(id) + '/template/' + fmt + '/' + lang;
                } else {
                    a.href = '/manuskript-vorlage/' + fmt + '/' + lang;
                }
            });
            if (mode === 'paper') {
                hintTxt.textContent = msgPaper.replace('%s', opt.textContent.trim());
                hint.classList.remove('d-none');
            } else {
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

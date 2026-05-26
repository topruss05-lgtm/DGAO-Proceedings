<?php

$newsId = $params['id'] ?? null;
$isNew  = $newsId === null;

$news = null;
if (!$isNew) {
    $news = getNewsById((int)$newsId);
    if (!$news) {
        setFlash('danger', 'News-Eintrag nicht gefunden.');
        header('Location: /admin/news');
        exit;
    }
}

$adminPageTitle = $isNew ? 'News anlegen' : 'News bearbeiten';
$isAuto = !$isNew && $news['source'] === 'auto';

$errors = [];
$form = [
    'display_date' => $news['display_date'] ?? date('Y-m-d'),
    'title_de'     => $news['title_de']     ?? '',
    'title_en'     => $news['title_en']     ?? '',
    'body_de'      => $news['body_de']      ?? '',
    'body_en'      => $news['body_en']      ?? '',
    'link_url'     => $news['link_url']     ?? '',
    'is_active'    => $news['is_active']    ?? 1,
    'sort_weight'  => $news['sort_weight']  ?? 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $form['display_date'] = trim($_POST['display_date'] ?? '');
    $form['title_de']     = trim($_POST['title_de']     ?? '');
    $form['title_en']     = trim($_POST['title_en']     ?? '');
    $form['body_de']      = trim($_POST['body_de']      ?? '');
    $form['body_en']      = trim($_POST['body_en']      ?? '');
    $form['link_url']     = trim($_POST['link_url']     ?? '');
    $form['is_active']    = isset($_POST['is_active']) ? 1 : 0;
    $form['sort_weight']  = (int)($_POST['sort_weight'] ?? 0);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['display_date'])) {
        $errors[] = 'Datum erforderlich (Format YYYY-MM-DD).';
    }
    if ($form['title_de'] === '') $errors[] = 'Titel (DE) erforderlich.';
    if ($form['title_en'] === '') $errors[] = 'Titel (EN) erforderlich.';
    if ($form['link_url'] !== ''
        && !preg_match('#^(https?://|/)#', $form['link_url'])) {
        $errors[] = 'Link-URL muss mit "/" oder "http(s)://" beginnen.';
    }

    if (empty($errors)) {
        try {
            if ($isNew) {
                $id = createManualNews($form);
                setFlash('success', 'News angelegt.');
            } else {
                updateNews((int)$newsId, $form);
                setFlash('success', 'News aktualisiert.');
            }
            header('Location: /admin/news');
            exit;
        } catch (Throwable $e) {
            error_log('news_edit error: ' . $e);
            $errors[] = 'Speichern fehlgeschlagen &mdash; Details im Server-Log.';
        }
    }
}
?>

<h1 class="mb-4"><?= e($adminPageTitle) ?></h1>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?= $err ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($isAuto): ?>
<div class="alert alert-info">
    Auto-News (Trigger: <code><?= e($news['trigger_key'] ?? '') ?></code>,
    Tagung <?= (int)$news['tagung_nummer'] ?>). Titel und Text wurden urspr&uuml;nglich
    aus einem Template generiert. <strong>Du kannst hier alles &auml;ndern</strong> &mdash;
    sobald du speicherst, ist dieser Eintrag als manuell &uuml;berschrieben markiert und
    wird bei k&uuml;nftigen Tagung-Saves nicht mehr aus dem Template &uuml;berschrieben.
    Um die Template-Hoheit wiederherzustellen, l&ouml;sche den Eintrag &mdash; ein Save
    der Tagung erzeugt ihn neu aus dem aktuellen Template.
</div>
<?php endif; ?>

<form method="post">
    <?= csrfField() ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="display_date" class="form-label">Anzeige-Datum *</label>
                            <input type="date" class="form-control" id="display_date" name="display_date"
                                   value="<?= e($form['display_date']) ?>" required>
                        </div>
                        <div class="col-md-8">
                            <label for="link_url" class="form-label">Link (optional)</label>
                            <input type="text" class="form-control" id="link_url" name="link_url"
                                   value="<?= e($form['link_url']) ?>"
                                   placeholder="/einreichen oder https://...">
                            <div class="form-text">Interner Pfad (mit <code>/</code>) oder externe URL.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><strong>Deutsch</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="title_de" class="form-label">Titel *</label>
                        <input type="text" class="form-control" id="title_de" name="title_de"
                               value="<?= e($form['title_de']) ?>" required>
                    </div>
                    <div class="mb-0">
                        <label for="body_de" class="form-label">Text</label>
                        <textarea class="form-control" id="body_de" name="body_de" rows="4"><?= e($form['body_de']) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><strong>Englisch</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="title_en" class="form-label">Title *</label>
                        <input type="text" class="form-control" id="title_en" name="title_en"
                               value="<?= e($form['title_en']) ?>" required>
                    </div>
                    <div class="mb-0">
                        <label for="body_en" class="form-label">Body</label>
                        <textarea class="form-control" id="body_en" name="body_en" rows="4"><?= e($form['body_en']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><strong>Sichtbarkeit</strong></div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                               value="1" <?= (int)$form['is_active'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Aktiv (auf Startseite anzeigen)</label>
                    </div>
                    <div class="mb-0">
                        <label for="sort_weight" class="form-label">Pin / Gewicht</label>
                        <input type="number" class="form-control" id="sort_weight" name="sort_weight"
                               value="<?= (int)$form['sort_weight'] ?>" min="0" step="1">
                        <div class="form-text">
                            <code>0</code> = normal (Datum bestimmt Reihenfolge).
                            <code>&gt;0</code> = oben angepinnt; h&ouml;here Werte zuerst.
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$isNew): ?>
            <div class="card">
                <div class="card-header"><strong>Meta</strong></div>
                <div class="card-body small text-muted">
                    <div>ID: <code><?= (int)$news['id'] ?></code></div>
                    <div>Quelle: <code><?= e($news['source']) ?></code></div>
                    <?php if ($isAuto): ?>
                        <div>Trigger: <code><?= e($news['trigger_key'] ?? '') ?></code></div>
                        <div>Tagung: <code><?= (int)$news['tagung_nummer'] ?></code></div>
                    <?php endif; ?>
                    <div>Erstellt: <?= e($news['created_at']) ?></div>
                    <div>Ge&auml;ndert: <?= e($news['updated_at']) ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> Speichern
        </button>
        <a href="/admin/news" class="btn btn-outline-secondary">Abbrechen</a>
    </div>
</form>

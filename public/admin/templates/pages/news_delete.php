<?php

$newsId = (int)($params['id'] ?? 0);
$news   = getNewsById($newsId);

if (!$news) {
    setFlash('danger', 'News-Eintrag nicht gefunden.');
    header('Location: /admin/news');
    exit;
}

if ($news['source'] !== 'manual') {
    setFlash('warning', 'Auto-News k&ouml;nnen nicht gel&ouml;scht werden &mdash; nur deaktiviert.');
    header('Location: /admin/news');
    exit;
}

$adminPageTitle = 'News l&ouml;schen';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        deleteNews($newsId);
        setFlash('success', 'News gel&ouml;scht.');
    } catch (Throwable $e) {
        error_log('news_delete error: ' . $e);
        setFlash('danger', 'L&ouml;schen fehlgeschlagen &mdash; Details im Server-Log.');
    }
    header('Location: /admin/news');
    exit;
}
?>

<h1 class="mb-4">News l&ouml;schen</h1>

<div class="card border-danger">
    <div class="card-body">
        <h5 class="card-title text-danger">
            <i class="bi bi-exclamation-triangle"></i> News wirklich l&ouml;schen?
        </h5>
        <p class="mb-1"><strong><?= e($news['title_de']) ?></strong></p>
        <p class="text-muted small mb-3">
            <?= e(formatDateLong($news['display_date'])) ?>
            <?php if (!empty($news['link_url'])): ?>
                &middot; <?= e($news['link_url']) ?>
            <?php endif; ?>
        </p>

        <form method="post" class="d-flex gap-2">
            <?= csrfField() ?>
            <button type="submit" class="btn btn-danger">
                <i class="bi bi-trash"></i> Endg&uuml;ltig l&ouml;schen
            </button>
            <a href="/admin/news" class="btn btn-outline-secondary">Abbrechen</a>
        </form>
    </div>
</div>

<?php

$newsId = (int)($params['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/news');
    exit;
}

verifyCsrf();

$news = getNewsById($newsId);
if (!$news) {
    setFlash('danger', 'News-Eintrag nicht gefunden.');
    header('Location: /admin/news');
    exit;
}

try {
    toggleNewsActive($newsId);
    setFlash('success', (int)$news['is_active'] === 1
        ? 'News deaktiviert.'
        : 'News aktiviert.');
} catch (Throwable $e) {
    error_log('news_toggle error: ' . $e);
    setFlash('danger', 'Toggle fehlgeschlagen &mdash; Details im Server-Log.');
}

header('Location: /admin/news');
exit;

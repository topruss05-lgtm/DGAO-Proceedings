<?php

declare(strict_types=1);

require_once __DIR__ . '/template_generator.php';

$id     = $params['id'];
$format = $params['format']; // 'latex' or 'word'
$lang   = $params['lang'];   // 'de' or 'en'

$db = getDb();
$stmt = $db->prepare('SELECT p.*, t.jahr, t.ort FROM papers p JOIN tagungen t ON t.nummer = p.tagung_nummer WHERE p.id = ?');
$stmt->execute([$id]);
$paper = $stmt->fetch();

if (!$paper) {
    http_response_code(404);
    echo 'Beitrag nicht gefunden';
    return;
}

try {
    if ($format === 'latex') {
        $latexLang = $lang === 'en' ? '' : 'german';
        $zipPath = generateLatexZip($paper, $latexLang);
        $stem = sanitizeFilename(($paper['code'] ?? 'paper') . '_' . ($paper['hauptautor'] ?: 'autor'));
        streamDownload($zipPath, $stem . '_LaTeX.zip', 'application/zip');
    } elseif ($format === 'word') {
        $wordLang = $lang === 'en' ? 'eng' : 'deu';
        $docxPath = generateWordDocx($paper, $wordLang);
        $stem = sanitizeFilename(($paper['code'] ?? 'paper') . '_' . ($paper['hauptautor'] ?: 'autor'));
        streamDownload(
            $docxPath,
            $stem . '_Word.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );
    } else {
        http_response_code(400);
        echo 'Ungültiges Format';
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Template-Erzeugung fehlgeschlagen: ' . htmlspecialchars($e->getMessage());
}

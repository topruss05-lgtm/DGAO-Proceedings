<?php

declare(strict_types=1);

require_once __DIR__ . '/template_generator.php';

$id     = $params['id'];
$format = $params['format']; // 'latex' or 'word'
$lang   = $params['lang'];   // 'de' or 'en'

$db = getDb();
$stmt = $db->prepare('SELECT p.*, t.jahr, t.ort, t.vorlage_phase_aktiv FROM papers p JOIN tagungen t ON t.nummer = p.tagung_nummer WHERE p.id = ?');
$stmt->execute([$id]);
$paper = $stmt->fetch();

if (!$paper) {
    http_response_code(404);
    echo 'Beitrag nicht gefunden';
    return;
}

// Vorlagen-Downloads sind an die aktive Vorlagen-Phase gekoppelt — exakt
// dieselbe Regel wie für /manuskript-vorlage/.
if (empty($paper['vorlage_phase_aktiv'])) {
    http_response_code(403);
    echo 'Manuskript-Vorlagen sind aktuell nicht öffentlich verfügbar.';
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
    } elseif ($format === 'kit') {
        $zipPath = generatePaperKitZip($paper);
        $year    = $paper['jahr'] ?: (int)date('Y');
        $stem    = sanitizeFilename(($paper['code'] ?? 'paper') . '_' . ($paper['hauptautor'] ?: 'autor'));
        $name    = sprintf('DGaO-Proceedings-Author-Kit-%d_%s.zip', $year, $stem);
        streamDownload($zipPath, $name, 'application/zip');
    } else {
        http_response_code(400);
        echo 'Ungültiges Format';
    }
} catch (Throwable $e) {
    error_log('paper_template_download error: ' . $e);
    http_response_code(500);
    echo 'Template-Erzeugung fehlgeschlagen. Bitte versuchen Sie es erneut oder kontaktieren Sie das Sekretariat.';
}

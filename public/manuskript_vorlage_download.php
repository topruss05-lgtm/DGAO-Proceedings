<?php

declare(strict_types=1);

require_once __DIR__ . '/template_generator.php';

/**
 * Liefert blanke Manuskript-Vorlagen aus DGaO-Author2025/.
 * Aufruf: $params = ['format' => 'latex'|'word'|'copyright', 'lang' => 'de'|'en']
 *
 * Voraussetzung: Eine Tagung hat vorlage_phase_aktiv = 1.
 * Andernfalls 403 (vom Router-Handler bereits geprüft).
 */

$format = $params['format'] ?? '';
$lang   = $params['lang']   ?? 'de';

if (getCurrentVorlagenTagung() === null) {
    http_response_code(403);
    echo 'Manuskript-Vorlagen sind aktuell nicht öffentlich verfügbar.';
    return;
}

$sourceRoot = __DIR__ . '/../DGaO-Author2025';
if (!is_dir($sourceRoot)) {
    http_response_code(500);
    echo 'Vorlagen-Verzeichnis fehlt auf dem Server.';
    return;
}

try {
    if ($format === 'latex') {
        $zipPath = buildLatexBlankZip($sourceRoot, $lang);
        $name = $lang === 'en'
            ? 'DGaO-Proceedings-Template-LaTeX_en.zip'
            : 'DGaO-Proceedings-Vorlage-LaTeX_de.zip';
        streamDownload($zipPath, $name, 'application/zip');
    } elseif ($format === 'word') {
        $docxPath = buildWordBlankDocx($sourceRoot, $lang);
        $name = $lang === 'en'
            ? 'DGaO-Proceedings-Template_en.docx'
            : 'DGaO-Proceedings-Vorlage_de.docx';
        streamDownload(
            $docxPath,
            $name,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );
    } elseif ($format === 'copyright') {
        $pdf = $sourceRoot . '/' . ($lang === 'en' ? 'Copyright-Agreement_eng.pdf' : 'Copyright-Agreement_deu.pdf');
        if (!is_file($pdf)) {
            http_response_code(404);
            echo 'Copyright-Agreement nicht gefunden.';
            return;
        }
        // Direkter Stream ohne unlink — wir geben das Quell-PDF aus, also nicht löschen.
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($pdf) . '"');
        header('Content-Length: ' . filesize($pdf));
        header('Cache-Control: no-store');
        readfile($pdf);
    } else {
        http_response_code(400);
        echo 'Ungültiges Format.';
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Vorlagen-Download fehlgeschlagen: ' . htmlspecialchars($e->getMessage());
}

/**
 * Baut eine ZIP mit der vollständigen LaTeX-Vorlage + Begleitfiles.
 * Bleibt content-identisch zum Original (Beispieldokument), kein Pre-Fill.
 */
function buildLatexBlankZip(string $sourceRoot, string $lang): string
{
    $latexDir = $sourceRoot . '/LaTeX';
    if (!is_dir($latexDir)) {
        throw new RuntimeException('LaTeX-Verzeichnis fehlt: ' . $latexDir);
    }

    $zipPath = tempnam(sys_get_temp_dir(), 'dgao_blanklatex_');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZIP konnte nicht erstellt werden.');
    }

    $folder = 'DGaO-Proceedings-Vorlage/';
    foreach (scandir($latexDir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $latexDir . '/' . $f;
        if (is_file($p)) $zip->addFile($p, $folder . 'LaTeX/' . $f);
    }

    // Begleit-Files
    $readme = $lang === 'en'
        ? $sourceRoot . '/Readme.txt'
        : $sourceRoot . '/Liesmich.txt';
    if (is_file($readme)) $zip->addFile($readme, $folder . basename($readme));

    $copyright = $sourceRoot . '/' . ($lang === 'en' ? 'Copyright-Agreement_eng.pdf' : 'Copyright-Agreement_deu.pdf');
    if (is_file($copyright)) $zip->addFile($copyright, $folder . basename($copyright));

    $zip->close();
    return $zipPath;
}

/**
 * Konvertiert das DGaO-.dotx-Template in eine blanke .docx (Word öffnet
 * .dotx sonst als Vorlage-Erstellung statt als bearbeitbares Dokument).
 * Inhalt bleibt unverändert — die Platzhalter im Template stehen für
 * den Autor zum Ausfüllen bereit.
 */
function buildWordBlankDocx(string $sourceRoot, string $lang): string
{
    $tplName = $lang === 'en' ? 'DGaO_template_2025_eng.dotx' : 'DGaO_template_2025_deu.dotx';
    $tplPath = $sourceRoot . '/MS Word/' . $tplName;
    if (!is_file($tplPath)) {
        throw new RuntimeException('Word-Template fehlt: ' . $tplPath);
    }

    $outPath = tempnam(sys_get_temp_dir(), 'dgao_blankword_') . '.docx';
    if (!copy($tplPath, $outPath)) {
        throw new RuntimeException('Template-Kopie fehlgeschlagen.');
    }

    // [Content_Types].xml umstellen, damit Word es als Dokument öffnet, nicht als Template.
    $zip = new ZipArchive();
    if ($zip->open($outPath) !== true) {
        throw new RuntimeException('docx-Container konnte nicht geöffnet werden.');
    }
    $ct = $zip->getFromName('[Content_Types].xml');
    if ($ct !== false) {
        $ct = str_replace(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
            $ct
        );
        $zip->addFromString('[Content_Types].xml', $ct);
    }
    $zip->close();

    return $outPath;
}

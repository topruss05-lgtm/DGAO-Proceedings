<?php

declare(strict_types=1);

require_once __DIR__ . '/template_generator.php';

/**
 * Liefert das komplette Manuskript-Autor:innen-Kit als ZIP.
 *
 * Aufruf: $params = ['format' => 'kit'|'latex'|'word'|'copyright', 'lang' => 'de'|'en']
 *   kit      → komplettes Paket (Word DE+EN, LaTeX, Copyrights, Readme) — Standard
 *   latex    → nur LaTeX-Paket
 *   word     → nur Word .docx
 *   copyright→ nur Copyright-PDF
 *
 * Year/Email/Adresse werden in TXT- und LaTeX-Files dynamisch ersetzt
 * (Platzhalter {{YEAR}} → Jahr der aktiven Vorlagen-Tagung).
 *
 * Voraussetzung: vorlage_phase_aktiv == 1 auf irgendeiner Tagung. Sonst 403.
 */

$format = $params['format'] ?? 'kit';
$lang   = $params['lang']   ?? 'de';

$activeTagung = getCurrentVorlagenTagung();
if ($activeTagung === null) {
    http_response_code(403);
    echo 'Manuskript-Vorlagen sind aktuell nicht öffentlich verfügbar.';
    return;
}
$year = (int)$activeTagung['jahr'];

$sourceRoot = __DIR__ . '/../DGaO-Author2025';
if (!is_dir($sourceRoot)) {
    http_response_code(500);
    echo 'Vorlagen-Verzeichnis fehlt auf dem Server.';
    return;
}

try {
    if ($format === 'kit') {
        $zipPath = buildAuthorKitZip($sourceRoot, $year);
        $name = sprintf('DGaO-Proceedings-Author-Kit-%d.zip', $year);
        streamDownload($zipPath, $name, 'application/zip');
    } elseif ($format === 'latex') {
        $zipPath = buildLatexBlankZip($sourceRoot, $lang, $year);
        $name = sprintf('DGaO-Proceedings-LaTeX-%d_%s.zip', $year, $lang);
        streamDownload($zipPath, $name, 'application/zip');
    } elseif ($format === 'word') {
        $docxPath = buildWordBlankDocx($sourceRoot, $lang, $year);
        $name = sprintf('DGaO-Proceedings-Word-%d_%s.docx', $year, $lang);
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
 * Substituiert {{YEAR}} in Textinhalt.
 */
function substituteYearPlaceholders(string $content, int $year): string
{
    return str_replace('{{YEAR}}', (string)$year, $content);
}

/**
 * Liest eine Textdatei und ersetzt {{YEAR}} on the fly.
 * Gibt Inhalt als String zurück.
 */
function readFileWithYearSub(string $path, int $year): string
{
    $c = file_get_contents($path);
    if ($c === false) throw new RuntimeException("Datei nicht lesbar: $path");
    return substituteYearPlaceholders($c, $year);
}

/**
 * Komplettes Autor:innen-Kit: ALLE Files (DE+EN Copyright, LaTeX, Word DE+EN,
 * Liesmich + Readme), Year-substituted.
 */
function buildAuthorKitZip(string $sourceRoot, int $year): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'dgao_kit_');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZIP konnte nicht erstellt werden.');
    }

    $folder = sprintf('DGaO-Proceedings-Author-Kit-%d/', $year);

    // Readme + Liesmich — Year substituted
    foreach (['Liesmich.txt', 'Readme.txt'] as $txt) {
        $p = $sourceRoot . '/' . $txt;
        if (is_file($p)) {
            $zip->addFromString($folder . $txt, readFileWithYearSub($p, $year));
        }
    }

    // Copyright-PDFs (beide Sprachen)
    foreach (['Copyright-Agreement_deu.pdf', 'Copyright-Agreement_eng.pdf'] as $cp) {
        $p = $sourceRoot . '/' . $cp;
        if (is_file($p)) $zip->addFile($p, $folder . $cp);
    }

    // LaTeX-Verzeichnis (Year-substitution für .tex/.cls/.bib)
    $latexDir = $sourceRoot . '/LaTeX';
    if (is_dir($latexDir)) {
        foreach (scandir($latexDir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $latexDir . '/' . $f;
            if (!is_file($p)) continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, ['tex', 'cls', 'bst', 'bib'], true)) {
                $zip->addFromString($folder . 'LaTeX/' . $f, readFileWithYearSub($p, $year));
            } else {
                // Binär (eps/pdf/bbl): unverändert
                $zip->addFile($p, $folder . 'LaTeX/' . $f);
            }
        }
    }

    // Word-Templates (Email + Year-Substitution im document.xml)
    foreach (['DGaO_template_2025_deu.dotx', 'DGaO_template_2025_eng.dotx'] as $wordFile) {
        $p = $sourceRoot . '/MS Word/' . $wordFile;
        if (!is_file($p)) continue;
        $docxBytes = buildWordDocxBytes($p, $year);
        $outName   = sprintf('DGaO_template_%d_%s.docx', $year,
                             str_contains($wordFile, '_eng') ? 'eng' : 'deu');
        $zip->addFromString($folder . 'MS Word/' . $outName, $docxBytes);
    }

    $zip->close();
    return $zipPath;
}

/**
 * Baut eine LaTeX-only ZIP mit Begleitfiles (Copyright, Readme der Sprache),
 * Year-substituted.
 */
function buildLatexBlankZip(string $sourceRoot, string $lang, int $year): string
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

    $folder = sprintf('DGaO-Proceedings-LaTeX-%d/', $year);

    foreach (scandir($latexDir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $latexDir . '/' . $f;
        if (!is_file($p)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['tex', 'cls', 'bst', 'bib'], true)) {
            $zip->addFromString($folder . 'LaTeX/' . $f, readFileWithYearSub($p, $year));
        } else {
            $zip->addFile($p, $folder . 'LaTeX/' . $f);
        }
    }

    $readme = $lang === 'en' ? $sourceRoot . '/Readme.txt' : $sourceRoot . '/Liesmich.txt';
    if (is_file($readme)) {
        $zip->addFromString($folder . basename($readme), readFileWithYearSub($readme, $year));
    }

    $copyright = $sourceRoot . '/' . ($lang === 'en' ? 'Copyright-Agreement_eng.pdf' : 'Copyright-Agreement_deu.pdf');
    if (is_file($copyright)) $zip->addFile($copyright, $folder . basename($copyright));

    $zip->close();
    return $zipPath;
}

/**
 * Wandelt das .dotx in eine .docx (Word öffnet .dotx sonst als Vorlage-
 * Erstellung statt Dokument) UND ersetzt {{YEAR}} sowie alte Email
 * (dgao-sekretariat@dgao.de) durch die aktuelle Adresse direkt im
 * document.xml. Gibt die Bytes der fertigen .docx-Datei zurück.
 */
function buildWordDocxBytes(string $tplPath, int $year): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'dgao_wb_');
    if (!copy($tplPath, $tmp)) throw new RuntimeException('Template-Kopie fehlgeschlagen.');

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        throw new RuntimeException('docx-Container konnte nicht geöffnet werden.');
    }
    // Content-Type: template → document
    $ct = $zip->getFromName('[Content_Types].xml');
    if ($ct !== false) {
        $ct = str_replace(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
            $ct
        );
        $zip->addFromString('[Content_Types].xml', $ct);
    }
    // document.xml: {{YEAR}} ersetzen, alte Email rauswerfen
    $doc = $zip->getFromName('word/document.xml');
    if ($doc !== false) {
        $doc = substituteYearPlaceholders($doc, $year);
        $doc = str_replace('dgao-sekretariat@dgao.de', 'sekretariat@dgao.de', $doc);
        $zip->addFromString('word/document.xml', $doc);
    }
    $zip->close();

    $bytes = file_get_contents($tmp);
    @unlink($tmp);
    if ($bytes === false) throw new RuntimeException('docx konnte nicht zurückgelesen werden.');
    return $bytes;
}

/**
 * Wie buildWordDocxBytes, gibt aber den Pfad zur fertigen .docx-Datei zurück
 * (für direktes streamDownload).
 */
function buildWordBlankDocx(string $sourceRoot, string $lang, int $year): string
{
    $tplName = $lang === 'en' ? 'DGaO_template_2025_eng.dotx' : 'DGaO_template_2025_deu.dotx';
    $tplPath = $sourceRoot . '/MS Word/' . $tplName;
    if (!is_file($tplPath)) {
        throw new RuntimeException('Word-Template fehlt: ' . $tplPath);
    }

    $bytes = buildWordDocxBytes($tplPath, $year);
    $out = tempnam(sys_get_temp_dir(), 'dgao_blankword_') . '.docx';
    if (file_put_contents($out, $bytes) === false) {
        throw new RuntimeException('docx konnte nicht geschrieben werden.');
    }
    return $out;
}

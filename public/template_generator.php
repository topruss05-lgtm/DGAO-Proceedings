<?php

declare(strict_types=1);

/**
 * Generiert vorausgefüllte Manuskript-Templates (LaTeX + Word) für einen
 * Beitrag. Quelle: DGaO-Author2025/ im Repo-Root.
 */

const TEMPLATE_SOURCE_DIR = __DIR__ . '/../DGaO-Author2025';

/**
 * Sammelt die Daten eines Papers in das Template-Format:
 *   ['year', 'title', 'author', 'affiliation', 'email', 'abstract']
 */
function templateDataForPaper(array $paper): array
{
    $year = '';
    if (!empty($paper['datum'])) $year = substr($paper['datum'], 0, 4);
    elseif (!empty($paper['jahr'])) $year = (string)$paper['jahr'];

    return [
        'year'        => $year,
        'title'       => trim($paper['titel'] ?? ''),
        'author'      => trim($paper['autoren_text'] ?? ''),
        'affiliation' => trim($paper['affiliationen'] ?? ''),
        'email'       => trim($paper['kontakt_email'] ?? ''),
        'abstract'    => trim($paper['abstract_text'] ?? ''),
    ];
}

// =============================================================================
// LaTeX
// =============================================================================

/** Escapt einen String für eingehen in ein LaTeX-Macro {…}. */
function latexEscape(string $s): string
{
    $map = [
        '\\' => '\\textbackslash{}',
        '{'  => '\\{',
        '}'  => '\\}',
        '$'  => '\\$',
        '&'  => '\\&',
        '#'  => '\\#',
        '%'  => '\\%',
        '_'  => '\\_',
        '~'  => '\\textasciitilde{}',
        '^'  => '\\textasciicircum{}',
    ];
    return strtr($s, $map);
}

/**
 * Wandelt eine multi-line Affiliation in LaTeX um:
 * Newlines → "\\\\" (Doppel-Backslash für LaTeX-Zeilenumbruch).
 * Sternchen-Marker (*, **) bleiben erhalten — sie sind LaTeX-konform.
 */
function affiliationForLatex(string $affil): string
{
    $lines = array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $affil)));
    $escaped = array_map('latexEscape', $lines);
    return implode('\\\\' . "\n", $escaped);
}

/**
 * Generiert das LaTeX-Header-Block (alles bis vor \bibliographystyle).
 * Der Body bleibt aus dem Original-Template (Beispiel-Inhalt, den der Author löscht).
 */
function generateLatexHeader(array $data, string $langOption): string
{
    $year   = $data['year'] !== '' ? (int)$data['year'] : (int)date('Y');
    $title  = latexEscape($data['title']);
    $author = latexEscape($data['author']);
    $affil  = affiliationForLatex($data['affiliation']);
    $email  = $data['email'];      // Email roh — keine Escapes nötig
    $abstr  = latexEscape($data['abstract']);

    $classOptions = $langOption !== '' ? "[{$langOption}]" : '';

    // Nowdoc + benannte Platzhalter via strtr — kein Escape-Konflikt mit Backslashes oder %-Zeichen.
    $tpl = <<<'TEX'
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%
% DGaO-Proceedings — Manuskript-Vorlage, vorausgefüllt durch das System
% Bitte ergänzen Sie nur den Document-Body unten.
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%

\documentclass{{CLASS_OPTIONS}}{DGaO-Proc}

\let\ifpdf\relax
\setlength{\paperwidth}{210mm}
\setlength{\paperheight}{297mm}

\year{{{YEAR}}}

\title{{{TITLE}}}

\author{{{AUTHOR}}}

\affiliation{{{AFFILIATION}}}

\contactmail{{{EMAIL}}}

\abstract{{{ABSTRACT}}}

\bibliographystyle{osajnl}
TEX;

    return strtr($tpl, [
        '{{CLASS_OPTIONS}}' => $classOptions,
        '{{YEAR}}'          => (string)$year,
        '{{TITLE}}'         => $title,
        '{{AUTHOR}}'        => $author,
        '{{AFFILIATION}}'   => $affil,
        '{{EMAIL}}'         => $email,
        '{{ABSTRACT}}'      => $abstr,
    ]);
}

/**
 * Erstellt eine ZIP-Datei mit dem vorausgefüllten LaTeX-Manuskript +
 * notwendigen Begleitdateien (Class, Bib-Style, Logo, BibTeX).
 *
 * @return string Pfad zur erzeugten temporären ZIP
 */
function generateLatexZip(array $paper, string $lang = 'german'): string
{
    $data = templateDataForPaper($paper);
    $sourceDir = TEMPLATE_SOURCE_DIR . '/LaTeX';
    if (!is_dir($sourceDir)) {
        throw new RuntimeException('LaTeX-Template-Verzeichnis fehlt: ' . $sourceDir);
    }

    $origTex = file_get_contents($sourceDir . '/dgao-demo.tex');
    if ($origTex === false) {
        throw new RuntimeException('dgao-demo.tex nicht lesbar');
    }

    // Marker: alles vor \bibliographystyle wird durch unseren Header ersetzt
    $marker = "\\bibliographystyle{osajnl}";
    $pos = strpos($origTex, $marker);
    if ($pos === false) {
        throw new RuntimeException('LaTeX-Template hat unerwartete Struktur (Marker fehlt)');
    }
    $body = substr($origTex, $pos + strlen($marker));

    $langOption = $lang === 'german' ? 'german' : '';
    $tex = generateLatexHeader($data, $langOption) . $body;

    // Filename-stem für Datei
    $stem = sanitizeFilename(($paper['code'] ?? 'paper') . '_' . ($paper['hauptautor'] ?? 'autor'));

    $zipPath = tempnam(sys_get_temp_dir(), 'dgao_latex_');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZIP konnte nicht erstellt werden');
    }

    $folder = $stem . '/';
    $zip->addFromString($folder . $stem . '.tex', $tex);
    $companion = ['DGaO-Proc.cls', 'osajnl.bst', 'mybib.bib', 'DGAOLOGO.eps', 'DGAOLOGO.pdf'];
    foreach ($companion as $f) {
        $p = $sourceDir . '/' . $f;
        if (is_file($p)) $zip->addFile($p, $folder . $f);
    }
    $zip->close();

    return $zipPath;
}

// =============================================================================
// Word
// =============================================================================

/**
 * Erstellt eine .docx-Datei aus dem .dotx-Template, mit den Header-
 * Paragraphen (Titel/Autoren/Organisation/Kontaktemail/Abstract) ersetzt.
 *
 * Strategie: ZIP entpacken, document.xml via DOMDocument bearbeiten, neu zippen.
 * Identifikation der Paragraphen über pStyle (z.B. <w:pStyle w:val="Titel"/>) —
 * robust gegen Run-Splitting (Word zerlegt oft Texte über mehrere <w:r>-Runs).
 *
 * @return string Pfad zur erzeugten .docx
 */
function generateWordDocx(array $paper, string $lang = 'deu'): string
{
    $data = templateDataForPaper($paper);
    $sourceDir = TEMPLATE_SOURCE_DIR . '/MS Word';
    $templateName = $lang === 'eng' ? 'DGaO_template_2025_eng.dotx' : 'DGaO_template_2025_deu.dotx';
    $templatePath = $sourceDir . '/' . $templateName;
    if (!is_file($templatePath)) {
        throw new RuntimeException('Word-Template fehlt: ' . $templatePath);
    }

    // Output: temp .docx
    $outPath = tempnam(sys_get_temp_dir(), 'dgao_word_') . '.docx';
    if (!copy($templatePath, $outPath)) {
        throw new RuntimeException('Template-Kopie fehlgeschlagen');
    }

    $zip = new ZipArchive();
    if ($zip->open($outPath) !== true) {
        throw new RuntimeException('docx (zip) konnte nicht geöffnet werden');
    }

    $documentXml = $zip->getFromName('word/document.xml');
    if ($documentXml === false) {
        $zip->close();
        throw new RuntimeException('document.xml fehlt im Template');
    }

    // Paragraphen ersetzen
    $documentXml = replaceWordStyledParagraph($documentXml, 'Titel',         $data['title']);
    $documentXml = replaceWordStyledParagraph($documentXml, 'Autoren',       $data['author']);
    $documentXml = replaceWordStyledParagraph($documentXml, 'Organisation',  $data['affiliation'], true);
    $documentXml = replaceWordStyledParagraph($documentXml, 'Kontaktemail',  $data['email']);
    $documentXml = replaceWordStyledParagraph($documentXml, 'Abstract',      $data['abstract'], true);

    // Auch [Content_Types] für .docx (statt .dotx) anpassen
    $ct = $zip->getFromName('[Content_Types].xml');
    if ($ct !== false) {
        // Default-Type für .dotx → .docx, sodass Word es als Dokument öffnet
        $ct = str_replace(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
            $ct
        );
        $zip->addFromString('[Content_Types].xml', $ct);
    }

    $zip->addFromString('word/document.xml', $documentXml);
    $zip->close();

    return $outPath;
}

/**
 * Ersetzt den Inhalt aller <w:p>-Paragraphen mit gegebenem pStyle durch
 * einen einzelnen Text-Run.
 *
 * @param bool $multilineBreak Wenn true: Newlines im Text werden zu <w:br/> (für Affiliation, Abstract).
 */
function replaceWordStyledParagraph(string $xml, string $styleVal, string $text, bool $multilineBreak = false): string
{
    if ($text === '') return $xml;

    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    $doc->formatOutput = false;
    if (!$doc->loadXML($xml)) {
        return $xml;
    }

    $xp = new DOMXPath($doc);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $paragraphs = $xp->query('//w:p[w:pPr/w:pStyle[@w:val="' . $styleVal . '"]]');
    if ($paragraphs->length === 0) return $xml;

    foreach ($paragraphs as $p) {
        // Alle existierenden w:r-Children entfernen, w:pPr behalten
        $children = [];
        foreach ($p->childNodes as $c) $children[] = $c;
        foreach ($children as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === 'r') {
                $p->removeChild($c);
            }
        }

        // Neuen Run-Block erzeugen
        $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        if ($multilineBreak && (str_contains($text, "\n") || str_contains($text, "\r"))) {
            $lines = preg_split('/\r\n|\n|\r/', $text);
            $r = $doc->createElementNS($wNs, 'w:r');
            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    $br = $doc->createElementNS($wNs, 'w:br');
                    $r->appendChild($br);
                }
                $t = $doc->createElementNS($wNs, 'w:t');
                $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                $t->appendChild($doc->createTextNode($line));
                $r->appendChild($t);
            }
            $p->appendChild($r);
        } else {
            $r = $doc->createElementNS($wNs, 'w:r');
            $t = $doc->createElementNS($wNs, 'w:t');
            $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $t->appendChild($doc->createTextNode($text));
            $r->appendChild($t);
            $p->appendChild($r);
        }
    }

    return $doc->saveXML();
}

// =============================================================================
// Helpers
// =============================================================================

function sanitizeFilename(string $s): string
{
    $s = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $s);
    $s = trim($s, '_');
    return $s !== '' ? $s : 'paper';
}

/**
 * Streamt eine Datei als Download mit korrektem Content-Type und Cleanup.
 */
function streamDownload(string $filePath, string $downloadName, string $mimeType): void
{
    if (!is_file($filePath)) {
        http_response_code(500);
        echo 'Download-Datei nicht gefunden';
        return;
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-store');
    readfile($filePath);
    @unlink($filePath);
}

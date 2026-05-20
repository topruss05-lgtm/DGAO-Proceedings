<?php

declare(strict_types=1);

/**
 * Parser für DGaO-Tagungsband-PDFs.
 *
 * Output-Schema kompatibel zu parseCsvFile() in helpers.php — kann direkt
 * durch validateImportRow() und executeImport() laufen.
 */

const PDF_MARGIN_WIDTH = 10;

/**
 * Findet pdftotext im PATH oder gängigen Locations. Vermeidet Shell.
 */
function findPdftotextBinary(): ?string
{
    $candidates = [
        '/opt/homebrew/bin/pdftotext',
        '/usr/local/bin/pdftotext',
        '/usr/bin/pdftotext',
    ];
    foreach ($candidates as $bin) {
        if (is_executable($bin)) return $bin;
    }
    return null;
}

/**
 * Extrahiert Text aus PDF via pdftotext -layout. Kein Shell-Aufruf,
 * kein Injection-Risiko — proc_open erhält Argumente als Array.
 *
 * @return array{error: ?string, text: string}
 */
function extractPdfText(string $filePath): array
{
    if (!is_file($filePath)) {
        return ['error' => 'Datei nicht gefunden.', 'text' => ''];
    }

    $bin = findPdftotextBinary();
    if ($bin === null) {
        return ['error' => 'pdftotext (poppler) nicht installiert.', 'text' => ''];
    }

    $cmd = [$bin, '-layout', '-enc', 'UTF-8', $filePath, '-'];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['error' => 'pdftotext-Aufruf fehlgeschlagen.', 'text' => ''];
    }

    fclose($pipes[0]);
    $text = stream_get_contents($pipes[1]) ?: '';
    $err  = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($exit !== 0) {
        return ['error' => 'pdftotext Fehler (Exit ' . $exit . '): ' . trim($err), 'text' => ''];
    }
    if ($text === '') {
        return ['error' => 'pdftotext lieferte keine Ausgabe.', 'text' => ''];
    }

    return ['error' => null, 'text' => $text];
}

/**
 * Splittet eine Zeile in [margin, body].
 *
 * Bevorzugt deterministisches Pattern-Matching für bekannte Margin-Formate:
 *   - "<DayLetter> <Code|Time>" wie "I H1", "T 09:30"
 *   - "<Code|Range|Time|PageNum>" wie "H1", "B24-B27", "11:30", "92"
 *   - "<DayLetter>" alleine (vertikales Wort wie M/I/T/T/W/O/C/H)
 * Fallback: feste Spaltenbreite PDF_MARGIN_WIDTH.
 *
 * @return array{0: string, 1: string}
 */
function splitMarginBody(string $line): array
{
    // Code-Range-Token (Whitelist-Präfixe).
    $codeRange = '(?:[HABCSPN]{1,2}\d{1,3}(?:[\-\x{2013}\x{2014}\/](?:[HABCSPN]{1,2})?\d{1,3})?)';
    $time      = '\d{1,2}:\d{2}';
    $pageNum   = '\d{1,3}';
    $dayLetter = '[A-Za-zÄÖÜäöü]';

    // Maximale leading-whitespace-Tiefe für Margin-Tokens (verhindert False-Positives auf
    // Karten, Tabellen mit weiter Einrückung etc.)
    $maxLeadingSpaces = 40;

    // 1. DayLetter + Code/Time, dann 2+ Spaces (oder Zeilenende), dann Body
    if (preg_match("/^(\s{0,{$maxLeadingSpaces}}{$dayLetter}\s+(?:{$codeRange}|{$time}))(?:\s{2,}|\s*$)(.*)$/u", $line, $m)) {
        return [$m[1], isset($m[2]) ? rtrim($m[2]) : ''];
    }
    // 2. Reines Margin-Token (Code/Range/Time/PageNum/DayLetter alleine)
    if (preg_match("/^(\s{0,{$maxLeadingSpaces}}(?:{$codeRange}|{$time}|{$dayLetter}|{$pageNum}))(?:\s{2,}|\s*$)(.*)$/u", $line, $m)) {
        return [$m[1], isset($m[2]) ? rtrim($m[2]) : ''];
    }
    // 3. Fallback: feste Breite
    return [rtrim(mb_substr($line, 0, PDF_MARGIN_WIDTH)), rtrim(mb_substr($line, PDF_MARGIN_WIDTH))];
}

/** Whitelist gültiger DGaO-Code-Präfixe. */
const VALID_CODE_PREFIXES = ['H', 'A', 'B', 'C', 'S', 'P', 'N'];

function isCodeToken(string $s): bool
{
    $s = trim($s);
    // Single code: "H1", "A12"
    if (preg_match('/^([A-Z]{1,2})(\d{1,3})$/', $s, $m)) {
        return in_array($m[1], VALID_CODE_PREFIXES, true);
    }
    // Range: "B24-B27", "A11–A14" (em-dash), "A11-14" (kurz)
    if (preg_match('/^([A-Z]{1,2})(\d{1,3})[\-\x{2013}\x{2014}](?:([A-Z]{1,2}))?(\d{1,3})$/u', $s, $m)) {
        if (!in_array($m[1], VALID_CODE_PREFIXES, true)) return false;
        if ($m[3] !== '' && $m[3] !== $m[1]) return false;
        return (int)$m[4] >= (int)$m[2];
    }
    // Slash: "A13/14"
    if (preg_match('/^([A-Z]{1,2})(\d{1,3})\/(\d{1,3})$/', $s, $m)) {
        return in_array($m[1], VALID_CODE_PREFIXES, true) && (int)$m[3] >= (int)$m[2];
    }
    return false;
}

/**
 * Expandiert einen Code-Token in seine einzelnen Codes.
 *   "H1"      → ["H1"]
 *   "B24-B27" → ["B24","B25","B26","B27"]
 *   "A13/14"  → ["A13","A14"]
 *
 * @return array<int, string>
 */
function expandCodeToken(string $s): array
{
    $s = trim($s);
    if (preg_match('/^([A-Z]{1,2})(\d{1,3})$/', $s)) return [$s];
    if (preg_match('/^([A-Z]{1,2})(\d{1,3})[\-\x{2013}\x{2014}](?:[A-Z]{1,2})?(\d{1,3})$/u', $s, $m)) {
        $prefix = $m[1]; $start = (int)$m[2]; $end = (int)$m[3];
        return array_map(fn($n) => $prefix . $n, range($start, $end));
    }
    if (preg_match('/^([A-Z]{1,2})(\d{1,3})\/(\d{1,3})$/', $s, $m)) {
        $prefix = $m[1]; $start = (int)$m[2]; $end = (int)$m[3];
        return array_map(fn($n) => $prefix . $n, range($start, $end));
    }
    return [$s];
}

function isTimeToken(string $s): bool
{
    return (bool)preg_match('/^\d{1,2}:\d{2}$/', trim($s));
}

function lineHasEmail(string $s): bool
{
    return (bool)preg_match('/[\w\.\-]+@[\w\.\-]+\.[a-z]{2,}/i', $s);
}

function extractEmail(string $s): string
{
    if (preg_match('/[\w\.\-]+@[\w\.\-]+\.[a-z]{2,}/i', $s, $m)) {
        return $m[0];
    }
    return '';
}

/** Tag-Header (deutsch + englisch). */
function parseDayHeader(string $line): ?array
{
    $line = trim($line);

    $monthMap = [
        // Deutsch
        'Januar' => '01', 'Februar' => '02', 'März' => '03', 'April' => '04',
        'Mai'    => '05', 'Juni'    => '06', 'Juli'  => '07', 'August' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Dezember' => '12',
        // Englisch
        'January' => '01', 'February' => '02', 'March' => '03',
        'May' => '05', 'June' => '06', 'July' => '07',
        'October' => '10', 'December' => '12',
    ];
    $weekdayMap = [
        'Monday' => 'Montag', 'Tuesday' => 'Dienstag', 'Wednesday' => 'Mittwoch',
        'Thursday' => 'Donnerstag', 'Friday' => 'Freitag',
        'Saturday' => 'Samstag', 'Sunday' => 'Sonntag',
    ];

    // Deutsches Format: "Mittwoch, 27. Mai 2026"
    if (preg_match('/^(Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag),\s*(\d{1,2})\.\s*([A-Za-zäöüÄÖÜ]+)\s*(\d{4})/u', $line, $m)) {
        if (isset($monthMap[$m[3]])) {
            $iso = sprintf('%s-%s-%02d', $m[4], $monthMap[$m[3]], (int)$m[2]);
            return ['weekday' => $m[1], 'date' => $iso];
        }
    }
    // Englisches Format: "Wednesday, May 18, 2016" oder "Wednesday, 18 May 2016"
    if (preg_match('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s*([A-Za-z]+)\s+(\d{1,2}),?\s+(\d{4})/u', $line, $m)) {
        if (isset($monthMap[$m[2]])) {
            $iso = sprintf('%s-%s-%02d', $m[4], $monthMap[$m[2]], (int)$m[3]);
            return ['weekday' => $weekdayMap[$m[1]], 'date' => $iso];
        }
    }
    if (preg_match('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s*(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/u', $line, $m)) {
        if (isset($monthMap[$m[3]])) {
            $iso = sprintf('%s-%s-%02d', $m[4], $monthMap[$m[3]], (int)$m[2]);
            return ['weekday' => $weekdayMap[$m[1]], 'date' => $iso];
        }
    }
    return null;
}

/**
 * Klassifiziert den Margin-Inhalt einer Zeile.
 *
 * Erkennt auch "DayLetter + Code/Time" wie "I H1" (I = vertikales MITTWOCH-Letter).
 *
 * @return array{kind: string, value: string, day_letter: ?string}
 */
function classifyMargin(string $margin): array
{
    $m = trim($margin);
    if ($m === '') return ['kind' => 'empty', 'value' => '', 'day_letter' => null];

    // Day-Letter-Prefix abspalten: "I H1" oder "i H1" (deutsch: "Dienstag" mit "Di" mixed case).
    $dayLetter = null;
    if (preg_match('/^([A-Za-zÄÖÜäöü])\s+(.+)$/u', $m, $mm)) {
        $dayLetter = mb_strtoupper($mm[1]);
        $rest = trim($mm[2]);
        if (isCodeToken($rest)) return ['kind' => 'code',  'value' => $rest, 'day_letter' => $dayLetter];
        if (isTimeToken($rest)) return ['kind' => 'time',  'value' => $rest, 'day_letter' => $dayLetter];
    }

    if (isCodeToken($m)) return ['kind' => 'code', 'value' => $m, 'day_letter' => null];
    if (isTimeToken($m)) return ['kind' => 'time', 'value' => $m, 'day_letter' => null];
    if (preg_match('/^\d{1,3}$/', $m)) return ['kind' => 'page_num', 'value' => $m, 'day_letter' => null];
    if (preg_match('/^[A-Za-zÄÖÜäöü]$/u', $m)) return ['kind' => 'day_letter', 'value' => mb_strtoupper($m), 'day_letter' => mb_strtoupper($m)];
    return ['kind' => 'noise', 'value' => $m, 'day_letter' => null];
}

/**
 * Map Code-Präfix → Saal/Raum.
 * H/A/S → Saal A, B → Saal B, P → Halle (Poster), sonst leer.
 */
function saalFromCode(string $code): string
{
    $prefix = strtoupper((string)preg_replace('/\d+$/', '', $code));
    return match ($prefix) {
        'A', 'H', 'S' => 'A',
        'B'           => 'B',
        'P'           => 'Halle',
        default       => '',
    };
}

/**
 * Aus akkumulierter Day-Letter-Sequenz das Wort und damit den Wochentag finden.
 * "MITTWOCH" / "DONNERSTAG" / "FREITAG" / "POSTER" / "POSTERSESSION".
 */
function detectWeekdayFromLetters(string $letters): ?string
{
    $up = mb_strtoupper($letters);
    if (str_contains($up, 'MONTAG')) return 'Montag';
    if (str_contains($up, 'DIENSTAG')) return 'Dienstag';
    if (str_contains($up, 'MITTWOCH')) return 'Mittwoch';
    if (str_contains($up, 'DONNERSTAG')) return 'Donnerstag';
    if (str_contains($up, 'FREITAG')) return 'Freitag';
    if (str_contains($up, 'SAMSTAG') || str_contains($up, 'SONNABEND')) return 'Samstag';
    if (str_contains($up, 'SONNTAG')) return 'Sonntag';
    // Englisch
    if (str_contains($up, 'WEDNESDAY')) return 'Mittwoch';
    if (str_contains($up, 'THURSDAY')) return 'Donnerstag';
    if (str_contains($up, 'FRIDAY')) return 'Freitag';
    if (str_contains($up, 'SATURDAY')) return 'Samstag';
    if (str_contains($up, 'TUESDAY')) return 'Dienstag';
    if (str_contains($up, 'MONDAY')) return 'Montag';
    if (str_contains($up, 'POSTER')) return 'Poster';
    return null;
}

/** Aus Wochentag und Tagungs-datum_von (ISO) das ISO-Datum dieses Wochentags innerhalb der Tagungswoche berechnen. */
function dateForWeekday(string $weekday, string $datumVon, string $datumBis): string
{
    if ($datumVon === '' || $weekday === 'Poster') return '';

    $weekdayMap = [
        'Montag' => 1, 'Dienstag' => 2, 'Mittwoch' => 3, 'Donnerstag' => 4,
        'Freitag' => 5, 'Samstag' => 6, 'Sonntag' => 7,
    ];
    if (!isset($weekdayMap[$weekday])) return '';

    try {
        $start = new DateTimeImmutable($datumVon);
    } catch (Exception) {
        return '';
    }
    $end = $datumBis !== '' ? new DateTimeImmutable($datumBis) : $start->modify('+6 days');

    $cur = $start;
    while ($cur <= $end) {
        if ((int)$cur->format('N') === $weekdayMap[$weekday]) {
            return $cur->format('Y-m-d');
        }
        $cur = $cur->modify('+1 day');
    }
    return '';
}

/**
 * Parst einen Block (Body-Zeilen) in strukturierte Felder.
 *
 * Layout-agnostische Statemachine: arbeitet zeilenweise unabhängig davon, ob
 * Title/Author/Affil/Email durch Leerzeilen getrennt sind (2026er Layout) oder
 * direkt aufeinander folgen (2024er Layout).
 *
 * @param array<int, string> $bodyLines
 * @return array{titel: string, autoren: string, affiliationen: string, kontakt_email: string, abstract: string}
 */
function parsePaperBlock(array $bodyLines): array
{
    while (!empty($bodyLines) && trim($bodyLines[0]) === '') array_shift($bodyLines);
    while (!empty($bodyLines) && trim(end($bodyLines)) === '') array_pop($bodyLines);

    if (empty($bodyLines)) {
        return ['titel' => '', 'autoren' => '', 'affiliationen' => '', 'kontakt_email' => '', 'abstract' => ''];
    }

    // Statemachine: 0 = TITLE, 1 = AUTHORS, 2 = AFFIL/EMAIL, 3 = ABSTRACT
    $state = 0;

    $titleLines  = [];
    $autorenLines = [];
    $affilLines  = [];
    $email       = '';
    $abstractLines = [];

    foreach ($bodyLines as $rawLine) {
        $line = rtrim($rawLine);
        $trimmed = trim($line);

        if ($state === 0) { // TITLE — sammle bis Author erkannt
            if ($trimmed === '') continue;
            if (!empty($titleLines) && looksLikeAuthorLine($trimmed)) {
                $autorenLines[] = $trimmed;
                $state = 1; // AUTHORS
            } else {
                $titleLines[] = $trimmed;
            }
            continue;
        }

        if ($state === 1) { // AUTHORS — weitere Autorenzeilen anhängen, sonst Affil-State
            if ($trimmed === '') continue;
            if (looksLikeAuthorLine($trimmed)) {
                $autorenLines[] = $trimmed;
                continue;
            }
            // Nicht mehr Author → Affil-Phase
            $state = 2;
            // Fall-through: dieselbe Zeile als Affil/Email behandeln
        }

        if ($state === 2) { // AFFIL — sammle Affiliations bis Email
            if ($trimmed === '') continue;
            if (lineHasEmail($trimmed)) {
                $email = extractEmail($trimmed);
                $state = 3; // ABSTRACT
                continue;
            }
            $affilLines[] = $trimmed;
            continue;
        }

        // state === 3: ABSTRACT
        if ($trimmed === '') {
            if (!empty($abstractLines) && end($abstractLines) !== '') {
                $abstractLines[] = '';
            }
            continue;
        }
        if (preg_match('/^\s*\[\d+\]/', $trimmed)) {
            break;
        }
        $abstractLines[] = $trimmed;
    }

    // Title aus Title-Zeilen mergen (Silbentrennung auflösen)
    $titel = mergeParagraph($titleLines);

    // Mehrzeilige Autorenliste zusammenführen — Trailing-Komma + Leerzeichen
    $autoren = '';
    foreach ($autorenLines as $i => $al) {
        $al = rtrim($al, ', ');
        $autoren = $autoren === '' ? $al : ($autoren . ', ' . $al);
    }

    // Affiliations zeilenweise (mit Newline-Joins)
    $affiliationen = implode("\n", array_filter($affilLines, fn($l) => $l !== ''));

    // Session-Trennseiten zwischen Beiträgen filtern: nach dem Ende des
    // eigentlichen Abstracts kommen typischerweise Programm-Zeitschienen
    // (z. B. „12:30-13:15 Mittagspause"), Page-Nummer + vertikale
    // Wochentag-Buchstaben + Section-Titel + Chair-Name auf einer eigenen
    // Seite. Diese landen sonst im Body des LETZTEN Papers der Session.
    //
    // Wir suchen den frühesten Marker, der das Ende des Abstracts signalisiert:
    //   a) Zeile = nur 1-3-stellige Zahl (= Page-Number-Marker)
    //   b) Zeile enthält Zeitschema-Tokens (Mittagspause, Kaffeepause,
    //      Postersession, Networking, Mitgliederversammlung, Transfer zu, …)
    //      oder beginnt mit HH:MM-HH:MM
    $cutoffIdx = null;
    foreach ($abstractLines as $i => $l) {
        if (preg_match('/^\s*\d{1,3}\s*$/', $l)) { $cutoffIdx = $i; break; }
        if (preg_match('/(Mittagspause|Kaffeepause|Postersession|Poster-?Session|Networking|Mitgliederversammlung|Frauenhofer-?Vorlesung|Fraunhofer-?Vorlesung|Gala\s*Dinner|Transfer\s+zu|Begr[üu][ßs]ungsabend)/u', $l)) {
            $cutoffIdx = $i;
            break;
        }
        if (preg_match('/^\s*\d{1,2}:\d{2}\s*[-–]\s*\d{1,2}:\d{2}\b/', $l)) {
            $cutoffIdx = $i;
            break;
        }
    }
    if ($cutoffIdx !== null) {
        $abstractLines = array_slice($abstractLines, 0, $cutoffIdx);
        while (!empty($abstractLines) && trim(end($abstractLines)) === '') array_pop($abstractLines);
    }

    $paragraphs = [];
    $current = [];
    foreach ($abstractLines as $l) {
        if ($l === '') {
            if (!empty($current)) { $paragraphs[] = mergeParagraph($current); $current = []; }
        } else {
            $current[] = $l;
        }
    }
    if (!empty($current)) $paragraphs[] = mergeParagraph($current);
    $abstract = implode("\n\n", $paragraphs);

    return [
        'titel'         => $titel,
        'autoren'       => $autoren,
        'affiliationen' => $affiliationen,
        'kontakt_email' => $email,
        'abstract'      => $abstract,
    ];
}

/**
 * Erkennt Autorenzeilen wie "A. Müller", "K.-H. Brenner", "Ç. Ataman",
 * "G. von Bally" oder "A. Müller*, B. Schmidt**".
 *
 * Strenge Regel: JEDES Komma-Token muss wie ein Autorenname aussehen
 * (Initial.+Nachname oder „Vorname Nachname"). Sonst werden Affiliation-
 * Zeilen wie „Department of X, University of Y" fälschlich als Autoren
 * erkannt (siehe A22 in DGaO_2025.pdf).
 *
 * Unicode: \p{Lu} und \p{Ll} statt [A-ZÄÖÜ]/[a-zäöüß], damit Ç, Ş, É, …
 * korrekt erkannt werden.
 */
function looksLikeAuthorLine(string $line): bool
{
    $t = trim($line);
    if ($t === '' || mb_strlen($t) > 250) return false;
    // Satzende? — Punkt nach Kleinbuchstaben („…modulator.") oder „!"/„?"
    // verbietet Autorenzeile. Punkt nach Großbuchstaben („…, M.") ist eine
    // Initiale und erlaubt.
    if (preg_match('/[!?]$/u', $t)) return false;
    if (preg_match('/\p{Ll}\.$/u', $t)) return false;

    // Institutional-Keyword-Filter: enthält die Zeile ein klar institutionelles
    // Wort, ist es eine Affil-Zeile (auch wenn die Tokens namenähnlich aussehen).
    if (containsInstitutionKeyword($t)) return false;

    // Trailing Komma (mehrzeilige Autorenliste, „A,\nB,\nC") tolerieren.
    $t = rtrim($t, ',');
    $tokens = preg_split('/\s*,\s*/u', $t);
    $hasMultipleTokens = count(array_filter($tokens, fn($x) => trim($x) !== '')) > 1;

    // Single-Token-Zeilen MÜSSEN Pattern A (Initial+Nachname) erfüllen, weil
    // Pattern B (Vorname-Nachname) sonst Affil-Zeilen wie „Physikalisch-Technische
    // Bundesanstalt" als Autor erkennt — siehe A9 in DGaO_2024.pdf.
    $checked = 0;
    foreach ($tokens as $tok) {
        if (trim($tok) === '') continue;
        $clean = preg_replace('/[\*†‡§¹²³⁴\d\s]+$/u', '', $tok);
        if ($clean === '') continue;
        if (!looksLikeAuthorToken($clean, $hasMultipleTokens)) return false;
        $checked++;
    }
    return $checked > 0;
}

/**
 * Ein Autoren-Token sieht aus wie:
 *   "A. Müller", "K.-H. Brenner", "Ç. Ataman", "G. von Bally",
 *   "Hans Peter Müller", "M. R. Schmidt-Werner", "M.R. Schmidt"
 * NICHT wie: "Department of X", "University of Freiburg",
 *            "Institut für Technische Optik".
 */
/**
 * Extrahiert Tagungs-Metadaten (Nummer, Jahr, Ort, Datum von/bis) aus dem
 * Booklet-Cover bzw. Einladungstext. Sucht das stabile Pattern:
 *
 *   „zur N. Jahrestagung der DGaO
 *    vom DD. Monat bis DD. Monat YYYY
 *    ...
 *    in Stadt"
 *
 * Dieser Block ist seit mind. 2024 identisch — Cover/Header sind unzuverlässig
 * (oft Reste alter Vorlagen, siehe DGaO_2026.pdf), die Einladung dagegen klar.
 *
 * @return array{nummer:int,jahr:int,ort:string,datum_von:string,datum_bis:string,errors:array}
 */
function extractTagungMetadata(string $text): array
{
    $monate = [
        'januar'=>1,'februar'=>2,'märz'=>3,'maerz'=>3,'april'=>4,'mai'=>5,
        'juni'=>6,'juli'=>7,'august'=>8,'september'=>9,'oktober'=>10,
        'november'=>11,'dezember'=>12,
    ];
    $errors = [];
    $nummer = 0; $jahr = 0; $ort = ''; $von = ''; $bis = '';

    if (preg_match('/zur\s+(\d{1,4})\.\s*Jahrestagung\s+der\s+DGaO/iu', $text, $m)) {
        $nummer = (int)$m[1];
    } else $errors[] = 'Tagungsnummer nicht gefunden („zur N. Jahrestagung der DGaO").';

    if (preg_match(
        '/vom\s+(\d{1,2})\.\s*([A-Za-zäÄ]+)\s+bis\s+(\d{1,2})\.\s*([A-Za-zäÄ]+)\s+(\d{4})/iu',
        $text, $m
    )) {
        $tag1 = (int)$m[1];
        $mon1 = $monate[mb_strtolower($m[2])] ?? 0;
        $tag2 = (int)$m[3];
        $mon2 = $monate[mb_strtolower($m[4])] ?? 0;
        $jahr = (int)$m[5];
        if ($mon1 && $mon2) {
            $von = sprintf('%04d-%02d-%02d', $jahr, $mon1, $tag1);
            $bis = sprintf('%04d-%02d-%02d', $jahr, $mon2, $tag2);
        } else $errors[] = 'Monatsname konnte nicht aufgelöst werden.';
    } else $errors[] = 'Datum nicht gefunden („vom DD. Monat bis DD. Monat YYYY").';

    // Ort: nach „Mitgliederversammlung … in Stadt" — Stadt bis zum Zeilenende.
    if (preg_match('/Mitgliederversammlung\s+der\s+DGaO[\s\S]{0,300}?\bin\s+([A-ZÄÖÜ][\p{L}\-]+(?:[ \t]+[A-ZÄÖÜ][\p{L}\-]+)?)\s*(?:\r?\n|$)/u', $text, $m)) {
        $ort = trim($m[1]);
    } elseif (preg_match('/\bin\s+([A-ZÄÖÜ][\p{L}\-]+(?:[ \t]+[A-ZÄÖÜ][\p{L}\-]+)?)\s*(?:\r?\n)/u', $text, $m)) {
        $ort = trim($m[1]);
    }

    // Einreichungsfrist Manuskript: Liesmich-Text im Booklet enthält
    // typisch „Die Frist für die Einreichung der Beiträge … endet am DD.MM.YYYY"
    $einreichungsfrist = '';
    if (preg_match('/Frist[\s\S]{0,200}?endet\s+am\s+(\d{1,2})\.(\d{1,2})\.(\d{4})/iu', $text, $m)) {
        $einreichungsfrist = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }

    return [
        'einreichungsfrist' => $einreichungsfrist,
        'nummer'    => $nummer,
        'jahr'      => $jahr,
        'ort'       => $ort,
        'datum_von' => $von,
        'datum_bis' => $bis,
        'errors'    => $errors,
    ];
}

/**
 * Heuristik: Enthält der Text ein eindeutig institutionelles Schlagwort?
 * Wenn ja, ist die Zeile eine Affiliation, kein Autor.
 *
 * Bewusst restriktive Liste — nur Begriffe, die kein Personenname je sind.
 */
function containsInstitutionKeyword(string $line): bool
{
    static $keywords = [
        'universität','university','université','università','universitat',
        'hochschule','fachhochschule',
        'fakultät','faculty',
        'institut','institute','instituto',
        'lehrstuhl','chair',
        'department','dept\\.?','abteilung','abt\\.?',
        'fachgebiet','fachbereich',
        'school','college','escola',
        'laboratory','laboratoire',
        'center','centre','zentrum',
        'fraunhofer','max-planck','leibniz','helmholtz',
        'gmbh','ag','kg','llc','inc\\.?','corp\\.?','co\\.?','ltd\\.?','e\\.v\\.',
        'rwth','tu','ptb','desy','cern','mit','eth',
    ];
    $pattern = '/(?<![\p{L}])(' . implode('|', $keywords) . ')(?![\p{L}])/iu';
    return (bool)preg_match($pattern, $line);
}

/**
 * Erkennt zurückgezogene Beiträge im Booklet (Platzhalter wie „- ZURÜCKGEZOGEN -",
 * „withdrawn", „cancelled" — Titel-only, keine Autoren/Abstract).
 */
function isWithdrawnPaper(string $titel, string $autoren, string $abstract): bool
{
    if ($autoren !== '' || $abstract !== '') return false;
    $t = mb_strtolower(trim($titel));
    return (bool)preg_match('/(zur[üu]ckgezogen|withdrawn|cancell?ed|canceled)/u', $t);
}

function looksLikeAuthorToken(string $tok, bool $allowSpacedNames = true): bool
{
    $tok = trim($tok);
    if ($tok === '' || mb_strlen($tok) > 60) return false;

    // Pattern A: ein oder mehrere Initial-Gruppen + Nachname.
    //   "A. Müller", "K.-H. Brenner", "M. R. Schmidt-Werner", "Ç. Ataman"
    if (preg_match('/^(\p{Lu}\.\s*-?\s*)+(\p{Lu}[\p{L}\'\-]*\s+)*\p{Lu}[\p{L}\'\-]+$/u', $tok)) {
        return true;
    }

    // Pattern A': nur Initialen — "M.", "K.-H.", "M.R." (z.B. „Abdou Ahmed, M.")
    if (preg_match('/^(\p{Lu}\.\s*-?\s*)+$/u', $tok)) {
        return true;
    }

    // Pattern B (Vorname Nachname ohne Punkt) ist riskant — schluckt sonst
    // Institutsnamen. Daher nur erlauben, wenn die Zeile mehrere Komma-Tokens
    // hat (Autorenliste-Kontext) ODER der Token einen Initial-Punkt enthält.
    if (!$allowSpacedNames) return false;

    $particles = ['von','van','de','der','den','del','di','da','le','la','du','zu','zur','dos','das'];
    $words = preg_split('/\s+/u', $tok);
    if (count($words) < 2) return false;
    $firstSeen = false;
    foreach ($words as $w) {
        if ($w === '') return false;
        if (mb_strlen($w) > 18) return false; // Lange Wörter → Komposita = Institut
        if (preg_match('/^\p{Lu}+\.$/u', $w)) { $firstSeen = true; continue; }
        if ($firstSeen && in_array(mb_strtolower($w), $particles, true)) continue;
        if (preg_match('/^\p{Lu}[\p{L}\'\-]*$/u', $w)) { $firstSeen = true; continue; }
        return false;
    }
    return true;
}

function mergeParagraph(array $lines): string
{
    $out = '';
    foreach ($lines as $i => $line) {
        $line = trim($line);
        if ($i === 0) { $out = $line; continue; }

        $endsHyphen     = preg_match('/-$/u', $out);
        $nextStartsLower = preg_match('/^\p{Ll}/u', $line);
        $endsLowerHyphen = preg_match('/\p{Ll}-$/u', $out);

        if ($endsLowerHyphen && $nextStartsLower) {
            // Echte Silbentrennung („Anord-\nnung") — Bindestrich entfernen
            $out = mb_substr($out, 0, -1) . $line;
        } elseif ($endsHyphen && !$nextStartsLower) {
            // Bindestrich-Komposita („Quantum-\nComputing") — ohne Space joinen
            $out .= $line;
        } else {
            $out .= ' ' . $line;
        }
    }
    return $out;
}

/**
 * Parst kompletten PDF-Text in Paper-Records.
 *
 * @param string $datumVon ISO-Datum Tagungsbeginn (für Wochentag→Datum-Mapping)
 * @param string $datumBis ISO-Datum Tagungsende
 * @return array{rows: array, stats: array}
 */
function parsePdfText(string $text, int $defaultJahr, string $datumVon = '', string $datumBis = ''): array
{
    $pages = explode("\f", $text);

    // Poster-Datum-Mapping aus Programmübersicht:
    //   1 Postersession  → alle Poster auf dieses Datum (eindeutig)
    //   ≥2 Sessions      → erste Postersession als Default verwenden, damit Poster
    //                      ein Datum bekommen. Die Booklet-Vorlage sollte für
    //                      Multi-Session-Tagungen die Sessions pro Poster ausweisen.
    $posterSessions = extractPosterSessionDates($text, $datumVon, $datumBis);
    $posterDate = !empty($posterSessions) ? $posterSessions[0] : '';

    $currentWeekday = '';
    $currentDate = '';

    $blocks = [];
    $pendingBlock = null;
    $pendingBody = [];

    foreach ($pages as $pageIdx => $pageText) {
        if (trim($pageText) === '') continue;

        $pageLines = preg_split("/\r\n|\n|\r/", $pageText);

        // Per-Page: Day-Letters akkumulieren (alle Margin-Tokens, die einen Day-Letter enthalten).
        // Vertikale Marker wie "MITTWOCH" / "DONNERSTAG" / "FREITAG" / "POSTER".
        $pageDayLetters = '';
        foreach ($pageLines as $pl) {
            [$mg] = splitMarginBody($pl);
            $cls = classifyMargin($mg);
            if ($cls['day_letter'] !== null) {
                $pageDayLetters .= $cls['day_letter'];
            }
        }
        $pageWeekday = detectWeekdayFromLetters($pageDayLetters);
        if ($pageWeekday !== null) {
            $currentWeekday = $pageWeekday;
            $currentDate = dateForWeekday($pageWeekday, $datumVon, $datumBis);
        }

        // Body-Parsing
        foreach ($pageLines as $line) {
            [$margin, $body] = splitMarginBody($line);
            $cls = classifyMargin($margin);

            switch ($cls['kind']) {
                case 'code':
                    // Block abschließen
                    if ($pendingBlock !== null) {
                        $pendingBlock['body'] = $pendingBody;
                        $blocks[] = $pendingBlock;
                    }
                    $pendingBlock = [
                        'code'  => $cls['value'],
                        'time'  => '',
                        'date'  => $currentDate,
                        'page'  => $pageIdx + 1,
                        'body'  => [],
                    ];
                    $pendingBody = [];
                    if (trim($body) !== '') $pendingBody[] = $body;
                    break;

                case 'time':
                    if ($pendingBlock !== null && $pendingBlock['time'] === '') {
                        $pendingBlock['time'] = $cls['value'];
                    }
                    if (trim($body) !== '') $pendingBody[] = $body;
                    break;

                case 'page_num':
                case 'day_letter':
                    // Body trotzdem mitnehmen (auch wenn leer): Empty-Lines sind Block-Separator.
                    if ($pendingBlock !== null) $pendingBody[] = $body;
                    break;

                case 'empty':
                case 'noise':
                default:
                    if ($pendingBlock !== null) {
                        $bt = trim($body);
                        // Footer-Marker (Pausen) → Block-Ende
                        if (preg_match('/^\d{1,2}:\d{2}\s+(Kaffeepause|Mittagspause|Pause)$/u', $bt)) {
                            $pendingBlock['body'] = $pendingBody;
                            $blocks[] = $pendingBlock;
                            $pendingBlock = null;
                            $pendingBody = [];
                        } else {
                            $pendingBody[] = $body;
                        }
                    }
                    break;
            }
        }
    }

    if ($pendingBlock !== null) {
        $pendingBlock['body'] = $pendingBody;
        $blocks[] = $pendingBlock;
    }

    $rows = [];
    $rowIdx = 0;
    foreach ($blocks as $blk) {
        $parsed = parsePaperBlock($blk['body']);

        // Zurückgezogene Beiträge überspringen: Titel ist „- ZURÜCKGEZOGEN -"
        // o.ä. und alle Felder sind leer. Diese tauchen im PDF nur als Platzhalter
        // auf — sie gehören nicht in die Proceedings.
        if (isWithdrawnPaper($parsed['titel'], $parsed['autoren'], $parsed['abstract'])) {
            continue;
        }

        // Range-Codes (B24-B27, A13/14) zu mehreren Records expandieren — dieselben
        // Inhalte (Podiumsdiskussion etc.), nur jeweils unterschiedlicher Code.
        $codeList = expandCodeToken($blk['code']);

        foreach ($codeList as $code) {
            $typ = guessTypFromCode($code);
            $datum = $blk['date'];
            if ($typ === 'poster' && $datum === '' && $posterDate !== '') {
                $datum = $posterDate;
            }
            $rows[] = [
                '_line'         => ++$rowIdx,
                '_page'         => $blk['page'],
                'code'          => $code,
                'typ'           => $typ,
                'titel'         => $parsed['titel'],
                'autoren'       => $parsed['autoren'],
                'hauptautor'    => '',
                'abstract'      => $parsed['abstract'],
                'keywords'      => '',
                'affiliationen' => $parsed['affiliationen'],
                'kontakt_email' => $parsed['kontakt_email'],
                'datum'         => $datum,
                'zeit'          => $blk['time'],
                'raum'          => saalFromCode($code),
            ];
        }
    }

    return [
        'rows'  => $rows,
        'stats' => ['pages' => count($pages), 'blocks' => count($blocks)],
    ];
}

/**
 * Findet die Datums aller Postersessions aus der Programmübersicht.
 * Konvention: Wenn nur eine Session existiert, ist das Poster-Datum eindeutig.
 *             Bei mehreren Sessions ist die Zuordnung nicht eindeutig — Default leer.
 *
 * @return array<int, string> ISO-Daten aller Postersessions
 */
function extractPosterSessionDates(string $text, string $datumVon, string $datumBis): array
{
    $relevantText = extractOverviewPages($text);
    if ($relevantText === '') return [];

    $dates = [];
    $lines = preg_split("/\r\n|\n|\r/", $relevantText);
    $currentDate = '';
    foreach ($lines as $line) {
        $hdr = parseDayHeader($line);
        if ($hdr !== null) { $currentDate = dateForWeekday($hdr['weekday'], $datumVon, $datumBis); continue; }
        if ($currentDate !== '' && preg_match('/Poster-?[Ss]ession/u', $line)) {
            $dates[] = $currentDate;
        }
    }
    return array_values(array_unique(array_filter($dates)));
}

/**
 * Extrahiert die erwartete Code-Liste aus der Programmübersicht (S. 4-5).
 *
 * Sucht Tabellenzeilen mit Pattern `Zeit  Saal  Code-or-Range  Titel  Seite` und
 * expandiert Ranges (A1-A6 → A1..A6). Lücken im Schema (z.B. B11 nach B7-B9, dann B15-B20)
 * werden korrekt abgebildet, weil nur was in der Übersicht steht expandiert wird.
 *
 * @return array<int, string> Liste der erwarteten Codes
 */
/**
 * Liefert den Text aller Seiten, die die Programmübersicht enthalten.
 *
 * Robust gegen Layout-Varianten: erkennt sowohl "Programmübersicht"-Marker
 * (2026/2025) als auch reine Tabellen-Header "Zeit Saal Vortrag" (2020 mit
 * "Tag 1/2/3"-Schema), englischsprachige Varianten ("Overview of the Program"
 * bzw. "Time Hall Talk") und Inhalt-Verweise nicht (Inhaltsverzeichnis-
 * Erwähnungen würden sonst Page 2/3 reinziehen).
 */
function extractOverviewPages(string $text): string
{
    $pages = explode("\f", $text);
    $relevantText = '';
    foreach ($pages as $pageText) {
        // Inhaltsverzeichnis-Verweise rausfiltern: Tabellen-Header-Indiz nötig.
        $hasTableHeader =
            preg_match('/Zeit\s+Saal\s+Vortrag\s+Titel/u', $pageText)
            || preg_match('/Time\s+Hall\s+Talk\s+Title/u', $pageText)
            || preg_match('/Time\s+Hall\s+(?:Lecture|Presentation)/u', $pageText);
        $hasOverviewMarker =
            str_contains($pageText, 'Programmübersicht')
            || stripos($pageText, 'Overview of the Program') !== false
            || stripos($pageText, 'Programme overview') !== false;

        if ($hasTableHeader || $hasOverviewMarker) {
            $relevantText .= $pageText . "\n";
        }
    }
    return $relevantText;
}

function extractExpectedCodes(string $text): array
{
    $relevantText = extractOverviewPages($text);
    if ($relevantText === '') return [];

    $prefixClass = '[' . implode('', VALID_CODE_PREFIXES) . ']';

    $codes = [];
    $lines = preg_split("/\r\n|\n|\r/", $relevantText);
    foreach ($lines as $line) {
        if (preg_match_all("/(?<![A-Za-z0-9])({$prefixClass}{1,2})(\d{1,3})(?:[\-\x{2013}\x{2014}]({$prefixClass}{1,2})(\d{1,3}))?(?![A-Za-z0-9])/u", $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $prefix = $m[1];
                $start  = (int)$m[2];
                $endPrefix = $m[3] ?? $prefix;
                $end    = isset($m[4]) ? (int)$m[4] : $start;
                if ($endPrefix !== $prefix) continue;
                // "—" (em-dash) als Titel/Seite → cancelled. Skip wenn Zeile zwei "—" hintereinander.
                if (preg_match('/[\x{2013}\x{2014}\-]\s+[\x{2013}\x{2014}\-]\s*$/u', rtrim($line))) {
                    continue;
                }
                for ($n = $start; $n <= $end; $n++) {
                    $codes[] = $prefix . $n;
                }
            }
        }
    }
    return array_values(array_unique($codes));
}

function guessTypFromCode(string $code): string
{
    $prefix = preg_replace('/\d+$/', '', $code);
    return match (strtoupper((string)$prefix)) {
        'H'     => 'hauptvortrag',
        'P'     => 'poster',
        'S'     => 'sondervortrag',
        default => 'vortrag',
    };
}

/**
 * Komfort-Wrapper: Datei → Rows.
 *
 * @return array{error: ?string, rows: array, stats: array}
 */
function parsePdfFile(string $filePath, int $defaultJahr): array
{
    $extracted = extractPdfText($filePath);
    if ($extracted['error'] !== null) {
        return ['error' => $extracted['error'], 'rows' => [], 'stats' => []];
    }

    $parsed = parsePdfText($extracted['text'], $defaultJahr);
    return [
        'error' => null,
        'rows'  => $parsed['rows'],
        'stats' => $parsed['stats'],
    ];
}

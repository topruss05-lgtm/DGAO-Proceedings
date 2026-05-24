<?php

declare(strict_types=1);

/**
 * Parser für die Programmübersicht der DGaO-Tagungsband-PDFs.
 *
 * Liest aus der Tabelle "Programmübersicht" pro Wochentag die Themen-Sessions
 * (z.B. "Optische Messtechnik I", "Biomedizinische Anwendungen") und gibt
 * strukturierte Records zurück, die in die `sessions`-Tabelle importiert
 * werden können.
 *
 * Output-Schema pro Session:
 *   [
 *     'titel'     => string,          // 'Optische Messtechnik I'
 *     'saal'      => string|null,     // 'A' | 'B' | 'H 0104' | '1A' | null
 *     'datum'     => string|null,     // 'YYYY-MM-DD'
 *     'zeit_von'  => string|null,     // 'HH:MM'
 *     'zeit_bis'  => string|null,     // 'HH:MM'  (optional, aus "HH:MM bis HH:MM")
 *     'codes'     => array<string>,   // ['A1','A2','A3','A4','A5','A6']
 *     'code_range_raw' => string,     // 'A1-A6' für Debug
 *     'sortorder' => int,             // 0..N
 *   ]
 *
 * Annahmen:
 *  - Tabellen-Layout: "Zeit Saal Vortrag Titel/Topic Seite" (Deutsch)
 *  - "Vortrag"-Spalte ist entweder ein einzelner Code (H1, A1, S1, B11)
 *    oder ein Code-Range (A1-A6, B11-B15, S1-S3) mit Trennzeichen `-`, `–`, `—`.
 *  - Hauptvorträge (H-Codes) ohne Themen-Titel werden NICHT als Session abgelegt
 *    (Paper bleibt mit session_id=NULL).
 *  - Pausen (Kaffeepause, Mittagspause, Pause, Tagungsfoto, ...) werden ignoriert.
 *  - Layout 2016 (englisch, mehrspaltig nebeneinander) wird nicht unterstützt
 *    und liefert leeres Ergebnis.
 */

require_once __DIR__ . '/pdf_parser.php';

const SESSION_VALID_PREFIXES = ['H', 'A', 'B', 'C', 'S', 'P', 'N'];

/**
 * Wochentag-Header → Wochentag-Name → Datum-Helfer.
 *
 * @var array<string,int>
 */
const SESSION_MONTHS = [
    'januar' => 1, 'jan' => 1,
    'februar' => 2, 'feb' => 2,
    'märz' => 3, 'maerz' => 3, 'mär' => 3,
    'april' => 4, 'apr' => 4,
    'mai' => 5,
    'juni' => 6, 'jun' => 6,
    'juli' => 7, 'jul' => 7,
    'august' => 8, 'aug' => 8,
    'september' => 9, 'sep' => 9, 'sept' => 9,
    'oktober' => 10, 'okt' => 10,
    'november' => 11, 'nov' => 11,
    'dezember' => 12, 'dez' => 12,
];

const SESSION_WEEKDAYS = [
    'montag', 'dienstag', 'mittwoch', 'donnerstag',
    'freitag', 'samstag', 'sonntag',
];

/**
 * Tokens, die wir als reine Termin-/Pausen-Zeilen erkennen und ignorieren.
 * (Wenn diese im Titel-Feld stehen UND keine Codes in der Zeile sind.)
 */
const SESSION_SKIP_TITLE_PATTERNS = [
    '/^kaffeepause/iu',
    '/^mittagspause/iu',
    '/^pause$/iu',
    '/^er[öo]ffnung/iu',
    '/^tagungsfoto/iu',
    '/^poster/iu',
    '/^networking/iu',
    '/^transfer/iu',
    '/^gala/iu',
    '/^fraunhofer/iu',
    '/^begr[üu]ßung/iu',
    '/^ende der tagung/iu',
    '/^dgao[- ]?nachwuchspreis/iu',
    '/^dgao[- ]?mitgliederversammlung/iu',
    '/^dgao mitgliederversammlung/iu',
    '/^mitgliederversammlung/iu',
    '/^wechsel/iu',
    '/^gang zu/iu',
    '/^promotionspreis/iu',
    '/^ankunft/iu',
    '/^registrierung/iu',
    '/^dinner/iu',
];

/**
 * Liest und parst eine PDF-Datei.
 *
 * @return array{error: ?string, sessions: array, warnings: array<string>, raw_overview: string}
 */
function parseSessionsFromPdf(string $filePath): array
{
    $extracted = extractPdfText($filePath);
    if ($extracted['error'] !== null) {
        return [
            'error'    => $extracted['error'],
            'sessions' => [],
            'warnings' => [],
            'raw_overview' => '',
        ];
    }
    return parseSessionsFromText($extracted['text']);
}

/**
 * Parst Sessions aus dem rohen PDF-Text. Sucht die Programmübersicht-Seiten
 * und arbeitet sie zeilenweise ab.
 *
 * @return array{error: ?string, sessions: array, warnings: array<string>, raw_overview: string}
 */
function parseSessionsFromText(string $text): array
{
    $warnings = [];

    // Englische Booklets (2016) skippen — Layout nebeneinander spaltenweise
    // ist mit pdftotext -layout nicht robust zu parsen.
    if (preg_match('/Overview of the Program/iu', $text)
        && !str_contains($text, 'Programmübersicht')
    ) {
        return [
            'error'    => 'Englisches Booklet-Layout (Overview of the Program) wird nicht unterstützt.',
            'sessions' => [],
            'warnings' => [],
            'raw_overview' => '',
        ];
    }

    $overview = extractOverviewPages($text);
    if ($overview === '') {
        return [
            'error'    => 'Programmübersicht-Seiten nicht gefunden.',
            'sessions' => [],
            'warnings' => [],
            'raw_overview' => '',
        ];
    }

    // Tabellen-Header-Indiz nötig — wenn keiner gefunden, ist das vermutlich
    // ein Layout, das wir nicht kennen.
    $hasGermanHeader = (bool)preg_match('/Zeit\s+Saal\s+Vortrag\s+Titel/u', $overview);
    if (!$hasGermanHeader) {
        return [
            'error'    => 'Tabellen-Header "Zeit Saal Vortrag Titel" nicht gefunden.',
            'sessions' => [],
            'warnings' => [],
            'raw_overview' => $overview,
        ];
    }

    // Heuristik für aktuelles Jahr (default fallback aus PDF).
    $defaultYear = sessionGuessDefaultYear($text);

    $rows = sessionExtractRows($overview, $defaultYear, $warnings);

    // Zu Sessions verdichten + sortorder vergeben.
    $sessions = [];
    $sort = 0;
    foreach ($rows as $row) {
        $row['sortorder'] = $sort++;
        $sessions[] = $row;
    }

    return [
        'error'    => null,
        'sessions' => $sessions,
        'warnings' => $warnings,
        'raw_overview' => $overview,
    ];
}

/**
 * Greift das Tagungsjahr aus dem Booklet-Text (für Datumsauflösung).
 */
function sessionGuessDefaultYear(string $text): int
{
    // "vom DD. Monat bis DD. Monat YYYY"
    if (preg_match('/vom\s+\d{1,2}\.\s*[A-Za-zäÄ]+\s+bis\s+\d{1,2}\.\s*[A-Za-zäÄ]+\s+(\d{4})/iu', $text, $m)) {
        return (int)$m[1];
    }
    // "JAHRESTAGUNG ... YYYY"
    if (preg_match('/(\d{4})\b/u', $text, $m)) {
        $y = (int)$m[1];
        if ($y >= 2000 && $y <= 2099) return $y;
    }
    return 0;
}

/**
 * Schiebt Folge-Zeilen, die nur eine Titel-Fortsetzung enthalten,
 * an die zugehörige Datenzeile.
 *
 * Folding-Logik:
 *  - Pre-Pass: Wrap-Zeilen DIREKT vor einer Datenzeile werden als Prefix-
 *    Pool gesammelt und der nachfolgenden Datenzeile vorangestellt — sofern
 *    diese sonst nur eine Pagenum/leeren Titel hätte.
 *  - Post-Pass: Wrap-Zeilen NACH einer Datenzeile werden angehängt.
 *
 * @return array<int,string>
 */
function sessionFoldContinuationLines(string $overview): array
{
    $lines = preg_split("/\r\n|\n|\r/", $overview) ?: [];

    $skipLine = static function (string $line): bool {
        $t = trim($line);
        if ($t === '') return true;
        if ($t === 'Programmübersicht') return true;
        if (preg_match('/^Zeit\s+Saal\s+Vortrag\s+Titel/u', $t)) return true;
        if (preg_match('/^\d{1,3}$/', $t)) return true;
        if (preg_match('/^Tag\s+\d+\b/iu', $t)) return true;
        if (preg_match('/^geplantes Programm$/iu', $t)) return true;
        if (preg_match('/^Tagungsprogramm entfällt$/iu', $t)) return true;
        if (preg_match('/^Tagungsprogramm$/iu', $t)) return true;
        if (preg_match('/^Die Programmübersicht/iu', $t)) return true;
        if (preg_match('/^Auffinden der Abstracts/iu', $t)) return true;
        return false;
    };

    $isDataLineStart = static function (string $line): bool {
        return (bool)preg_match('/^\s*\d{1,2}[:.]\d{2}\b/u', $line);
    };

    $isWeekdayHeader = static function (string $line): bool {
        $stripped = ltrim($line);
        foreach (SESSION_WEEKDAYS as $wd) {
            if (stripos($stripped, $wd) === 0) return true;
        }
        return false;
    };

    // Filter erstmal alle uninteressanten Zeilen raus, aber behalte
    // die Reihenfolge von Wrap-Zeilen, Wochentag-Headern und Datenzeilen.
    $cleaned = [];
    foreach ($lines as $line) {
        if ($skipLine($line)) continue;
        $cleaned[] = $line;
    }

    // Pre-Pass: Sammle Wrap-Zeilen vor jeder Datenzeile als optionalen Prefix.
    // Wenn die nachfolgende Datenzeile zwischen Code und Page-Nummer hauptsächlich
    // leer ist, wird der Prefix-Pool dort eingefügt.
    //
    // Wir mergen die Wrap-Zeilen in einem 2-Pass-Verfahren:
    //   Iteration 1: Marker-Zeilen identifizieren (Datenzeilen + Wochentag).
    //   Iteration 2: Wrap-Zeilen einer Datenzeile zuordnen (vor/nach).
    $markers = []; // index in $cleaned, Typ: 'data' | 'weekday'
    foreach ($cleaned as $i => $line) {
        if ($isWeekdayHeader($line)) {
            $markers[$i] = 'weekday';
        } elseif ($isDataLineStart($line)) {
            $markers[$i] = 'data';
        }
    }
    $markerIndices = array_keys($markers);

    $folded = [];
    $n = count($cleaned);
    for ($i = 0; $i < $n; $i++) {
        $line = $cleaned[$i];
        if (isset($markers[$i])) {
            // Sonderzeile: "bis HH:MM" alleine — gehört zur vorherigen Zeile
            if (preg_match('/^\s*bis\s+\d{1,2}:\d{2}\s*$/iu', $line)) {
                if (!empty($folded)) {
                    $folded[count($folded) - 1] .= ' ' . trim($line);
                }
                continue;
            }
            $folded[] = $line;
            continue;
        }

        // Wrap-Zeile. Entscheidung: an vorige oder nächste Datenzeile?
        $prevMarker = sessionLastMarkerBefore($markerIndices, $i);
        $nextMarker = sessionFirstMarkerAfter($markerIndices, $i);

        // 1. Wenn keine vorige Datenzeile: an nächste anhängen (als Prefix-Pool).
        // 2. Wenn vorige Datenzeile vorhanden UND nächste Datenzeile vorhanden:
        //    Prüfen, ob die nächste Datenzeile einen "leeren Titel" hat (= Pagenum
        //    direkt nach dem Code). Falls ja, gilt diese Wrap-Zeile als Prefix
        //    der nächsten Datenzeile.
        //    Sonst (default): anhängen an die vorige Datenzeile.
        $attachToPrev = true;
        if ($prevMarker === null) {
            $attachToPrev = false;
        } elseif ($nextMarker !== null && $markers[$nextMarker] === 'data') {
            $nextLine = $cleaned[$nextMarker];
            if (sessionDataLineHasEmptyTitle($nextLine)) {
                $attachToPrev = false;
            }
        }

        $trimmedWrap = trim($line);
        if ($trimmedWrap === '') continue;

        if ($attachToPrev && $prevMarker !== null && $markers[$prevMarker] === 'data') {
            // an die zuletzt gefoldete Datenzeile anhängen
            for ($j = count($folded) - 1; $j >= 0; $j--) {
                if ($isDataLineStart($folded[$j])) {
                    $folded[$j] = rtrim($folded[$j]) . ' ' . $trimmedWrap;
                    break;
                }
            }
        } elseif (!$attachToPrev && $nextMarker !== null && $markers[$nextMarker] === 'data') {
            // Prefix-Pool: an die nächste Datenzeile vorne anhängen.
            // Wir notieren das in einem virtuellen Buffer pro nächster Datenzeile.
            // Da wir bei Index $i sind, müssen wir die Daten-Zeile in $cleaned
            // modifizieren, BEVOR sie verarbeitet wird.
            $cleaned[$nextMarker] = sessionInjectTitlePrefix($cleaned[$nextMarker], $trimmedWrap);
        }
    }

    return $folded;
}

/**
 * @param array<int,int> $markerIndices Sortierte Liste der Marker-Positionen.
 */
function sessionLastMarkerBefore(array $markerIndices, int $i): ?int
{
    $last = null;
    foreach ($markerIndices as $idx) {
        if ($idx < $i) {
            $last = $idx;
        } else {
            break;
        }
    }
    return $last;
}

/**
 * @param array<int,int> $markerIndices
 */
function sessionFirstMarkerAfter(array $markerIndices, int $i): ?int
{
    foreach ($markerIndices as $idx) {
        if ($idx > $i) return $idx;
    }
    return null;
}

/**
 * Prüft, ob eine Datenzeile zwischen "Code" und Page-Nummer keinen Titel hat.
 *
 * Beispiel-Layout:
 *   "  08:30       A           S1-S3                                             70"
 *   Code = S1-S3, danach folgen nur whitespace + "70" → leer.
 */
function sessionDataLineHasEmptyTitle(string $line): bool
{
    $prefixClass = '[' . implode('', SESSION_VALID_PREFIXES) . ']';
    $codePattern = "/(?<![A-Za-z0-9])({$prefixClass}{1,2})(\d{1,3})(?:\s*[\-\x{2013}\x{2014}]\s*({$prefixClass}{1,2})?(\d{1,3}))?(?![A-Za-z0-9])/u";

    if (!preg_match($codePattern, $line, $m, PREG_OFFSET_CAPTURE)) {
        return false;
    }
    $end = $m[0][1] + strlen($m[0][0]);
    $rest = substr($line, $end);
    // Nach dem Code: nur Whitespace + optional 1-3 Ziffern (Seitenzahl)?
    if (preg_match('/^\s*(\d{1,3})?\s*$/', $rest)) return true;
    return false;
}

/**
 * Schiebt einen Titel-Prefix in eine Datenzeile DIREKT hinter den Code.
 */
function sessionInjectTitlePrefix(string $line, string $prefix): string
{
    $prefixClass = '[' . implode('', SESSION_VALID_PREFIXES) . ']';
    $codePattern = "/(?<![A-Za-z0-9])({$prefixClass}{1,2})(\d{1,3})(?:\s*[\-\x{2013}\x{2014}]\s*({$prefixClass}{1,2})?(\d{1,3}))?(?![A-Za-z0-9])/u";

    if (!preg_match($codePattern, $line, $m, PREG_OFFSET_CAPTURE)) {
        // Kein Code → vorne anhängen.
        return rtrim($line) . ' ' . $prefix;
    }
    $end = $m[0][1] + strlen($m[0][0]);
    $head = substr($line, 0, $end);
    $tail = ltrim(substr($line, $end));
    return $head . ' ' . $prefix . ($tail !== '' ? ' ' . $tail : '');
}

/**
 * Parst die zusammengefalteten Zeilen in Session-Records.
 *
 * @param array<string> $warnings_out
 * @return array<int,array<string,mixed>>
 */
function sessionExtractRows(string $overview, int $defaultYear, array &$warnings_out): array
{
    $lines = sessionFoldContinuationLines($overview);

    $currentDate = null;
    $rows = [];

    foreach ($lines as $line) {
        $line = rtrim($line);
        $stripped = ltrim($line);

        // Wochentag-Header? z.B. "Mittwoch, 27. Mai 2026"
        $dateFromHeader = sessionParseDateHeader($stripped, $defaultYear);
        if ($dateFromHeader !== null) {
            $currentDate = $dateFromHeader;
            continue;
        }

        // Datenzeile: beginnt mit Uhrzeit
        if (!preg_match('/^\s*(\d{1,2})[:.](\d{2})\s+(.*)$/u', $line, $m)) {
            continue;
        }
        $zeitVon = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
        $rest    = $m[3];
        $zeitBis = null;

        // "bis HH:MM" optional am Ende der Zeile (Postersession etc.)
        if (preg_match('/\s+bis\s+(\d{1,2}):(\d{2})\s*$/iu', $rest, $bm)) {
            $zeitBis = sprintf('%02d:%02d', (int)$bm[1], (int)$bm[2]);
            $rest = preg_replace('/\s+bis\s+\d{1,2}:\d{2}\s*$/iu', '', $rest) ?? $rest;
        }

        // Code-Range in der Zeile?
        $codeInfo = sessionExtractCode($rest);
        if ($codeInfo === null) {
            continue; // keine Codes → Pause/Eröffnung/Mittagspause/Postersession ohne Codes
        }

        // Codes auflösen (Range oder Einzel).
        $codes = $codeInfo['codes'];
        if (count($codes) === 0) {
            continue;
        }

        // Hauptvorträge (genau 1 H-Code) ohne Themen-Titel überspringen.
        // Heuristik: bei H-Codes ist der Titel meistens "<Name>: <Titel>" —
        // dann ist es ein Hauptvortrag-Slot, kein Themen-Block.
        // Wir legen das Filtern aber konservativ aus: nur skippen, wenn der
        // Titel eindeutig ein Autor-Doppelpunkt-Muster ist (also Personenname).
        $titel = $codeInfo['titel'];
        if ($titel === '') {
            continue;
        }

        // Saal-Spalte: alles VOR der Codes-Position.
        $saal = $codeInfo['saal'];

        // Titel-Aufräumen: Trailing UND innen-eingebettete Seitenzahlen.
        // Page-Nummer steht direkt zwischen Code und Titel-Wrap-Zeile
        // (z.B. "Ehrensymposium 70 von Prof. Lohmann"), oder am Zeilenende.
        $titel = preg_replace('/\s+\d{1,3}\s*$/u', '', $titel) ?? $titel;
        // Innen-Pagenum: ein einzelnes 1-3-stelliges Zahlentoken, das den
        // Titel optisch trennt — nur einmal, defensiv.
        $titel = preg_replace('/\s+\d{1,3}\s+/u', ' ', $titel, 1) ?? $titel;
        $titel = preg_replace('/\s{2,}/u', ' ', $titel) ?? $titel;
        $titel = trim($titel);

        // Skip-Patterns prüfen.
        foreach (SESSION_SKIP_TITLE_PATTERNS as $pat) {
            if (preg_match($pat, $titel)) {
                $titel = '';
                break;
            }
        }
        if ($titel === '') continue;

        // Cancelled-Vortrag: Titel besteht nur aus en-/em-Dashes & Whitespace.
        if (preg_match('/^[\s\x{2013}\x{2014}\-]+$/u', $titel)) {
            continue;
        }

        // Single-H-Code → IMMER Hauptvortrag, niemals Themen-Session.
        $firstPrefix = preg_replace('/\d+$/', '', $codes[0]);
        if (count($codes) === 1 && $firstPrefix === 'H') {
            continue;
        }

        // Single-Code (S/A/B/C/N) mit Autor-Doppelpunkt-Muster → Einzel-Vortrag, skippen.
        if (count($codes) === 1 && sessionLooksLikeAuthorTitle($titel)) {
            continue;
        }

        // Titel sollte nicht numerisch sein und nicht leer.
        if (preg_match('/^\d+$/', $titel)) continue;

        $rows[] = [
            'titel'         => $titel,
            'saal'          => $saal,
            'datum'         => $currentDate,
            'zeit_von'      => $zeitVon,
            'zeit_bis'      => $zeitBis,
            'codes'         => $codes,
            'code_range_raw'=> $codeInfo['raw'],
        ];
    }

    return $rows;
}

/**
 * "Mittwoch, 27. Mai 2026" → "2026-05-27" (oder null).
 */
function sessionParseDateHeader(string $line, int $defaultYear): ?string
{
    $line = trim($line);
    // Mit Jahr
    if (preg_match('/^(?:Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag),?\s+(\d{1,2})\.\s*([A-Za-zäÄö]+)\s+(\d{4})/iu', $line, $m)) {
        $day = (int)$m[1];
        $mon = SESSION_MONTHS[mb_strtolower($m[2])] ?? 0;
        $year = (int)$m[3];
        if ($mon) {
            return sprintf('%04d-%02d-%02d', $year, $mon, $day);
        }
    }
    // Ohne Jahr (fällt auf defaultYear zurück)
    if (preg_match('/^(?:Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag),?\s+(\d{1,2})\.\s*([A-Za-zäÄö]+)\s*$/iu', $line, $m)) {
        $day = (int)$m[1];
        $mon = SESSION_MONTHS[mb_strtolower($m[2])] ?? 0;
        if ($mon && $defaultYear > 0) {
            return sprintf('%04d-%02d-%02d', $defaultYear, $mon, $day);
        }
    }
    return null;
}

/**
 * Sucht in der "Saal Vortrag Titel"-Sektion einen Code oder Code-Range.
 *
 * @return array{saal: ?string, codes: array<string>, titel: string, raw: string}|null
 */
function sessionExtractCode(string $rest): ?array
{
    $prefixClass = '[' . implode('', SESSION_VALID_PREFIXES) . ']';
    // Range-Pattern mit Bindestrich-Varianten: -, –, —, /
    $codeRangePattern = "/(?<![A-Za-z0-9])({$prefixClass}{1,2})(\d{1,3})(?:\s*[\-\x{2013}\x{2014}]\s*({$prefixClass}{1,2})?(\d{1,3}))?(?![A-Za-z0-9])/u";

    if (!preg_match($codeRangePattern, $rest, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $matchStart = $m[0][1];
    $matchEnd   = $matchStart + strlen($m[0][0]);
    $prefix     = $m[1][0];
    $startNum   = (int)$m[2][0];
    $endPrefix  = (isset($m[3]) && $m[3][0] !== '') ? $m[3][0] : $prefix;
    $endNum     = (isset($m[4]) && $m[4][0] !== '') ? (int)$m[4][0] : $startNum;

    if ($endPrefix !== $prefix) {
        return null;
    }
    if ($endNum < $startNum) {
        return null;
    }

    $codes = [];
    for ($n = $startNum; $n <= $endNum; $n++) {
        $codes[] = $prefix . $n;
    }

    // Saal = alles VOR dem Code (Trim).
    $saalRaw = trim(substr($rest, 0, $matchStart));
    $saal = $saalRaw === '' ? null : sessionNormalizeSaal($saalRaw);

    // Titel = alles NACH dem Code (Trim).
    $titel = trim(substr($rest, $matchEnd));

    return [
        'saal'  => $saal,
        'codes' => $codes,
        'titel' => $titel,
        'raw'   => $m[0][0],
    ];
}

/**
 * "1A+1B" → "1A+1B", "Audimax" → "Audimax", "A/B" → "A/B", "Aula 1" → "Aula 1".
 * Normalisiert reine Whitespace-Eskapaden, behält aber den originalen
 * Saal-Bezeichner.
 */
function sessionNormalizeSaal(string $saal): string
{
    $s = preg_replace('/\s+/u', ' ', trim($saal)) ?? $saal;
    return $s;
}

/**
 * Heuristik: Sieht der Titel-Text aus wie "<Autor>: <Titel>", also ein
 * Hauptvortrag-Slot statt ein Themen-Block?
 *
 * Beispiele:
 *  - "O. Baumann: Angewandte Optik an der HAW Hamburg" → true
 *  - "U. Boehm: Super-Resolution Insights …" → true
 *  - "S. Reichelt: Single-Shot Optical Metrology: Principles" → true (erstes ":")
 *  - "Optische Messtechnik I" → false
 *  - "Podiumsdiskussion" → false
 *  - "Ehrensymposium zum 100sten Geburtstag von Prof. Adolf Lohmann" → false
 */
function sessionLooksLikeAuthorTitle(string $titel): bool
{
    // Muss ein Doppelpunkt drin sein.
    if (!str_contains($titel, ':')) return false;

    // Linke Hälfte vor erstem ":" — sollte kurz sein und nach Personenname aussehen
    // (Initialen+Nachname oder Vorname+Nachname, oft mit Punkten).
    $left = trim(strstr($titel, ':', true) ?: '');
    if ($left === '') return false;
    if (mb_strlen($left) > 60) return false; // Sessions können einen Doppelpunkt haben, aber keinen so langen Vorspann

    // Heuristik: Wenn linke Hälfte mehrere Wörter mit Großbuchstaben enthält
    // und mind. ein Initial (1-2 Buchstaben + Punkt) ODER ein typischer Name.
    if (preg_match('/^[A-ZÄÖÜ]\.\s*[A-ZÄÖÜ]?\.?\s*[A-ZÄÖÜ][\p{L}\-]+/u', $left)) return true; // "O. Baumann", "R. B. Bergmann"
    if (preg_match('/^[A-ZÄÖÜ][\p{L}\-]+,?\s+[A-ZÄÖÜ][\p{L}\-]+$/u', $left)) return true;     // "Hans Müller" — selten

    return false;
}

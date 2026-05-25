<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * JSON-Encoded fuer sichere Einbettung in <script>-Tag. JSON_HEX_*-Flags
 * verhindern, dass User-Daten </script>-Sequenzen einschleusen koennen.
 * JSON_THROW_ON_ERROR macht Encoding-Fehler sichtbar statt stumm.
 */
function jsonForScript(mixed $value): string
{
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}

function formatDate(?string $iso): string
{
    if (!$iso) return '';
    return (new DateTimeImmutable($iso))->format('d.m.Y');
}

function formatDateLong(?string $iso): string
{
    if (!$iso) return '';
    $dt = new DateTimeImmutable($iso);
    $locale = currentLang() === 'en' ? 'en_US' : 'de_DE';
    $formatter = new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE);
    return $formatter->format($dt);
}

function typeBadgeClass(string $typ): string
{
    return match ($typ) {
        'hauptvortrag'  => 'badge-hauptvortrag',
        'sondervortrag' => 'badge-sondervortrag',
        'poster'        => 'badge-poster',
        default         => 'badge-vortrag',
    };
}

function typeLabel(string $typ): string
{
    return match ($typ) {
        'hauptvortrag'  => t('type.hauptvortrag'),
        'sondervortrag' => t('type.sondervortrag'),
        'poster'        => t('type.poster'),
        default         => t('type.vortrag'),
    };
}

function generateBibtex(array $paper): string
{
    $key = 'dgao' . $paper['tagung_nummer'] . '-' . strtolower($paper['code']);
    $year = $paper['datum'] ? substr($paper['datum'], 0, 4) : ($paper['jahr'] ?? '');
    $note = ($paper['typ'] === 'poster' ? t('type.poster') : t('type.vortrag')) . ' ' . $paper['code'];

    return "@inproceedings{{$key},\n"
         . "  title     = {{$paper['titel']}},\n"
         . "  author    = {{$paper['autoren_text']}},\n"
         . "  booktitle = {DGaO-Proceedings, {$paper['tagung_nummer']}. Jahrestagung},\n"
         . "  year      = {{$year}},\n"
         . "  publisher = {" . SITE_PUBLISHER . "},\n"
         . "  issn      = {" . SITE_ISSN . "},\n"
         . "  note      = {{$note}}\n"
         . "}";
}

/**
 * Bereitet eine User-Query so auf, dass sie als FTS5-MATCH-Ausdruck
 * sicher ausfuehrbar ist UND die wichtigsten FTS5-Features unterstuetzt:
 *   - Phrase-Suche per "..."         z.B.   "laser pulse"
 *   - Wildcard-Prefix per word*      z.B.   hologr*
 *   - Boolean Operatoren AND/OR/NOT  z.B.   laser OR maser, optik NOT linse
 *   - Negation per -word             z.B.   laser -plasma  (umgesetzt zu NOT)
 *
 * Defensive: FTS5-Spezialzeichen, die nicht zu diesen Features gehoeren,
 * werden entfernt. Einzelbuchstaben (<2 chars) werden verworfen.
 * Gibt null zurueck, wenn nichts Sinnvolles uebrig bleibt.
 */
function sanitizeFtsQuery(string $q): ?string
{
    $q = trim($q);
    if ($q === '') return null;

    $tokens = [];
    $len    = strlen($q);
    $cursor = 0;

    while ($cursor < $len) {
        // Whitespace ueberspringen.
        while ($cursor < $len && ctype_space($q[$cursor])) $cursor++;
        if ($cursor >= $len) break;

        // Phrase: "..."
        if ($q[$cursor] === '"') {
            $cursor++;
            $end = strpos($q, '"', $cursor);
            if ($end === false) $end = $len;
            $phrase = substr($q, $cursor, $end - $cursor);
            // FTS5-Spezialzeichen aus dem Phrase-Inneren entfernen.
            $phrase = preg_replace('/["\(\)\*:^]/', ' ', $phrase);
            $phrase = trim(preg_replace('/\s+/u', ' ', $phrase));
            if ($phrase !== '' && mb_strlen($phrase) >= 2) {
                $tokens[] = '"' . $phrase . '"';
            }
            $cursor = ($end < $len) ? $end + 1 : $len;
            continue;
        }

        // Wort bis Whitespace oder Anfuehrungszeichen.
        $start = $cursor;
        while ($cursor < $len && !ctype_space($q[$cursor]) && $q[$cursor] !== '"') $cursor++;
        $word = substr($q, $start, $cursor - $start);

        // Boolean-Operatoren (uppercase) durchreichen.
        if ($word === 'AND' || $word === 'OR' || $word === 'NOT') {
            $tokens[] = $word;
            continue;
        }

        // Negation: -word -> NOT word
        $negate = false;
        if (strlen($word) > 1 && $word[0] === '-') {
            $negate = true;
            $word   = substr($word, 1);
        }

        // Wildcard-Prefix?
        $isPrefix = str_ends_with($word, '*');
        if ($isPrefix) $word = rtrim($word, '*');

        // Restliche FTS5-Spezialzeichen entfernen.
        $word = preg_replace('/["\(\)\*:^]/', '', $word);
        if (mb_strlen($word) < 2) continue;

        // Worte mit Sonderzeichen (Bindestrich, Slash etc.) sicher quoten —
        // dann allerdings ohne Wildcard, da FTS5 *"foo" nicht kennt.
        if (preg_match('/[^\p{L}\p{N}]/u', $word)) {
            $term = '"' . $word . '"';
        } else {
            $term = $word . ($isPrefix ? '*' : '');
        }

        if ($negate) {
            // FTS5-NOT braucht linken Operanden — kein Lead-Negate.
            $prev = end($tokens);
            if ($prev !== false && !in_array($prev, ['AND', 'OR', 'NOT'], true)) {
                $tokens[] = 'NOT';
            }
        }
        $tokens[] = $term;
    }

    // Trailing/leading operators aufraeumen.
    while (!empty($tokens) && in_array(end($tokens), ['AND', 'OR', 'NOT'], true)) array_pop($tokens);
    while (!empty($tokens) && in_array(reset($tokens), ['AND', 'OR'], true)) array_shift($tokens);

    if (empty($tokens)) return null;
    return implode(' ', $tokens);
}

function pdfUrl(array $paper): ?string
{
    if (!$paper['hat_pdf'] || !$paper['pdf_dateiname']) return null;
    return PDF_BASE_URL . '/' . $paper['tagung_nummer'] . '/' . $paper['pdf_dateiname'];
}

function fullPdfUrl(array $paper): ?string
{
    $rel = pdfUrl($paper);
    return $rel ? BASE_URL . $rel : null;
}

function isActivePage(string $path): bool
{
    $current = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $current = rtrim($current, '/') ?: '/';
    $path = rtrim($path, '/') ?: '/';

    if ($path === '/') return $current === '/';
    return str_starts_with($current, $path);
}

function canonicalUrl(string $path): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function renderMetaTag(array $tag): string
{
    $attrs = '';
    if (isset($tag['name'])) {
        $attrs .= ' name="' . e($tag['name']) . '"';
    }
    if (isset($tag['property'])) {
        $attrs .= ' property="' . e($tag['property']) . '"';
    }
    return '<meta' . $attrs . ' content="' . e($tag['content']) . '">';
}

/**
 * Liefert die Papers einer Tagung mit ihrer Session-Zuordnung (LEFT JOIN
 * auf sessions). Sortierung nach Tagungsprogramm-Reihenfolge: erst nach
 * Code-Buchstabe (H/A/B/C/P/S), dann Code-Nummer.
 */
function getPapersByTagung(int $tagungNummer): array
{
    $stmt = getDb()->prepare("
        SELECT p.id, p.code, p.typ, p.titel, p.autoren_text, p.hauptautor,
               p.zeit, p.raum, p.datum, p.hat_pdf, p.pdf_dateiname,
               p.affiliationen, p.abstract_text,
               p.session_id,
               s.titel       AS session_titel,
               s.saal        AS session_saal,
               s.datum       AS session_datum,
               s.zeit_von    AS session_zeit_von,
               s.zeit_bis    AS session_zeit_bis,
               s.sortorder   AS session_sortorder
        FROM papers p
        LEFT JOIN sessions s ON s.id = p.session_id
        WHERE p.tagung_nummer = ?
        ORDER BY
            CASE substr(p.code, 1, 1)
                WHEN 'H' THEN 1
                WHEN 'A' THEN 2
                WHEN 'B' THEN 3
                WHEN 'C' THEN 4
                WHEN 'P' THEN 5
                WHEN 'S' THEN 6
                ELSE 9
            END,
            CAST(substr(p.code, 2) AS INTEGER)
    ");
    $stmt->execute([$tagungNummer]);
    return $stmt->fetchAll();
}

/**
 * Liefert ein Sortier-Gewicht fuer einen Paper-Code-Buchstaben (Programm-
 * Reihenfolge im Booklet: Hauptvortrag, Vorträge A/B/C, Poster, Sondervortrag,
 * danach Sonstige).
 */
function paperCodeOrder(string $code): int
{
    return match (substr($code, 0, 1)) {
        'H' => 1,
        'A' => 2,
        'B' => 3,
        'C' => 4,
        'P' => 5,
        'S' => 6,
        default => 9,
    };
}

/**
 * Gruppiert eine bereits sortierte Paper-Liste (aus getPapersByTagung) zu
 * Anzeige-Gruppen fuer das Archiv-Detail:
 *   - Hauptvortraege (alle H-Codes, eine Gruppe)
 *   - Themen-Sessions (nach session.sortorder)
 *   - Vortraege A/B/C ohne Session-Zuordnung (eigene Gruppen pro Code-Letter)
 *   - Sondervortraege (alle S ohne Session)
 *   - Poster (alle P ohne Session)
 *   - Sonstige (W/Z/...)
 *
 * Liefert: [ ['key'=>..., 'type'=>..., 'titel'=>..., 'saal'=>..., 'datum'=>...,
 *             'zeit_von'=>..., 'papers'=>[...]], ... ] in Anzeige-Reihenfolge.
 */
function groupPapersForArchiveDetail(array $papers): array
{
    $groups = [];

    foreach ($papers as $p) {
        $letter = substr($p['code'], 0, 1);

        // Session-Zuordnung hat Vorrang (ausser fuer H, die immer gemeinsame
        // Hauptvortraege-Gruppe bekommen — im Programm-Heft sind H-Slots
        // einzeln, im Archiv aber kompakter zusammengefasst).
        if ($letter !== 'H' && $p['session_id'] !== null) {
            $key = 'session_' . $p['session_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'       => $key,
                    'type'      => 'session',
                    'titel'     => $p['session_titel'] ?? '',
                    'saal'      => $p['session_saal'] ?? null,
                    'datum'     => $p['session_datum'] ?? null,
                    'zeit_von'  => $p['session_zeit_von'] ?? null,
                    'sortkey'   => [2, (int)($p['session_sortorder'] ?? 0)],
                    'papers'    => [],
                ];
            }
            $groups[$key]['papers'][] = $p;
            continue;
        }

        // Fallback nach Code-Buchstabe
        $key = 'cat_' . $letter;
        if (!isset($groups[$key])) {
            $titel = match ($letter) {
                'H' => 'Hauptvorträge',
                'A' => 'Vorträge — A',
                'B' => 'Vorträge — B',
                'C' => 'Vorträge — C',
                'P' => 'Poster',
                'S' => 'Sondervorträge',
                default => 'Sonstige Beiträge',
            };
            $sortPrimary = match ($letter) {
                'H' => 1,
                'P' => 4,
                'S' => 5,
                default => 3, // A/B/C/W/Z zwischen Sessions und Poster/Sondervorträge
            };
            $groups[$key] = [
                'key'      => $key,
                'type'     => 'category',
                'titel'    => $titel,
                'saal'     => null,
                'datum'    => null,
                'zeit_von' => null,
                'sortkey'  => [$sortPrimary, ord($letter)],
                'papers'   => [],
            ];
        }
        $groups[$key]['papers'][] = $p;
    }

    // Anzeige-Reihenfolge: sortiert nach 'sortkey' (Primary + Secondary).
    uasort($groups, fn($a, $b) => $a['sortkey'] <=> $b['sortkey']);

    return array_values($groups);
}

/**
 * Globale Autoren-Liste mit Treffer-Anzahl. Fuer Suggestions auf /suche.
 * Limitiert auf die N Autoren mit meisten Beitraegen.
 * @return list<array{label:string, papers:int}>
 */
function getTopAuthorSuggestions(int $limit = 200): array
{
    $stmt = getDb()->prepare("
        SELECT a.vorname, a.nachname, COUNT(DISTINCT pa.paper_id) AS papers
        FROM autoren a
        JOIN paper_autoren pa ON pa.autor_id = a.id
        GROUP BY a.id
        HAVING papers > 0
        ORDER BY papers DESC, a.nachname COLLATE NOCASE
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $seen = [];
    $out  = [];
    foreach ($stmt as $row) {
        $nach = trim(preg_replace('/\*+/', '', (string)$row['nachname']));
        $vor  = trim(preg_replace('/\*+/', '', (string)$row['vorname']));
        $label = $nach . ($vor === '' ? '' : ', ' . $vor);
        if ($label === '' || isset($seen[$label])) continue;
        $seen[$label] = true;
        $out[] = ['label' => $label, 'papers' => (int)$row['papers']];
    }
    return $out;
}

/**
 * Top-N Affiliations aus autoren.affiliation (nach Häufigkeit).
 * @return list<string>
 */
function getTopAffiliationSuggestions(int $limit = 100): array
{
    $stmt = getDb()->prepare("
        SELECT affiliation, COUNT(*) AS n
        FROM autoren
        WHERE affiliation <> ''
        GROUP BY affiliation
        ORDER BY n DESC, affiliation COLLATE NOCASE
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $out = [];
    foreach ($stmt as $row) {
        $aff = trim((string)$row['affiliation']);
        if ($aff !== '') $out[] = $aff;
    }
    return $out;
}

/**
 * Alle Tagungen für ein Dropdown-Filter (id + Label).
 * @return list<array{nummer:int, jahr:int, ort:?string}>
 */
function getAllTagungenForFilter(): array
{
    $rows = getDb()->query('SELECT nummer, jahr, ort FROM tagungen ORDER BY nummer DESC')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'nummer' => (int)$r['nummer'],
            'jahr'   => (int)$r['jahr'],
            'ort'    => $r['ort'] ?: null,
        ];
    }
    return $out;
}

/**
 * Liefert alle Autoren einer Tagung (Erst- und Coautoren) als Liste fuer
 * Autocomplete/Filter im Archiv-Detail.
 *
 * @return list<array{id:int, label:string}>
 */
function getAuthorsByTagung(int $tagungNummer): array
{
    $stmt = getDb()->prepare("
        SELECT DISTINCT a.id, a.vorname, a.nachname
        FROM autoren a
        JOIN paper_autoren pa ON pa.autor_id = a.id
        JOIN papers p ON p.id = pa.paper_id
        WHERE p.tagung_nummer = ?
        ORDER BY a.nachname COLLATE NOCASE, a.vorname COLLATE NOCASE
    ");
    $stmt->execute([$tagungNummer]);
    $seen = [];
    $out  = [];
    foreach ($stmt as $row) {
        // Sternchen-Marker (Affiliation-Hinweise im Originalformat) aus
        // Display-Label entfernen — der originale Autor-Record bleibt
        // unangetastet.
        $nachname = trim(preg_replace('/\*+/', '', (string)$row['nachname']));
        $vorname  = trim(preg_replace('/\*+/', '', (string)$row['vorname']));
        $label    = trim($nachname . ($vorname === '' ? '' : ', ' . $vorname));
        if ($label === '' || isset($seen[$label])) continue;
        $seen[$label] = true;
        $out[] = ['id' => (int)$row['id'], 'label' => $label];
    }
    return $out;
}

function getAllTagungen(): array
{
    $db = getDb();
    return $db->query('
        SELECT t.nummer, t.jahr, t.ort, t.datum_von, t.datum_bis,
               COUNT(p.id) AS paper_anzahl
        FROM tagungen t
        LEFT JOIN papers p ON p.tagung_nummer = t.nummer
        GROUP BY t.nummer
        ORDER BY t.nummer DESC
    ')->fetchAll();
}

/**
 * Tagung, für die der Admin die Vorlagen-Download-Phase aktiviert hat.
 * null = keine aktive Phase (Downloads sind dann gesperrt).
 */
function getCurrentVorlagenTagung(): ?array
{
    $db = getDb();
    $row = $db->query('
        SELECT nummer, jahr, ort, datum_von, datum_bis, einreichungsfrist
        FROM tagungen
        WHERE vorlage_phase_aktiv = 1
        ORDER BY nummer DESC
        LIMIT 1
    ')->fetch();
    return $row ?: null;
}

function getSiteStats(): array
{
    $db = getDb();
    $papers   = (int) $db->query('SELECT COUNT(*) FROM papers')->fetchColumn();
    $tagungen = (int) $db->query('SELECT COUNT(*) FROM tagungen')->fetchColumn();
    $autoren  = (int) $db->query('SELECT COUNT(*) FROM autoren')->fetchColumn();
    return compact('papers', 'tagungen', 'autoren');
}

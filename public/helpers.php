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

/**
 * Normalisiert einen Autoren-/Affiliations-String fuer den Alias-Index-Vergleich.
 *
 * Gibt ausschließlich lowercase ASCII-Alphanumerika ([a-z0-9]) zurück — keine anderen
 * Zeichen, kein Whitespace, keine Satzzeichen. Leerer Input liefert ''.
 *
 * Eigenschaften:
 *  - Rückgabe: nur [a-z0-9]* — garantiert rein-ASCII, keine anderen Zeichen.
 *  - Locale-unabhängig; Transliterierung nicht-lateinischer Skripte ist ICU-versionsabhängig
 *    (z.B. Pinyin für Chinesisch: '李' → 'li', Kyrillisch: 'Иванов' → 'ivanov').
 *  - Deterministisch auf einem System mit fixer ICU-Version.
 *  - Fußnoten-Marker (*, †, ‡, §, #, ^) werden entfernt.
 *  - Apostrophe (gerade ' U+0027, geschwungen ' U+2018, ' U+2019) werden entfernt.
 *    Hinweis: ICU wandelt geschwungene Apostrophe in Schritt 4 zu geradem ' um,
 *    daher muss Schritt 6 das gerade ' explizit entfernen.
 *  - Alle Dash-Varianten (Bindestrich, En-Dash, Em-Dash) werden entfernt.
 *  - Alle Whitespace-Typen (ASCII-Space, NBSP U+00A0, Narrow-NBSP U+202F,
 *    ZWSP U+200B) werden entfernt — Unicode-Spaces via Schritt 5 (Safety-Net),
 *    ASCII-Space via Schritt 6.
 *  - Diakritika und Combining-Marks werden durch NFD-Zerlegung via ICU entfernt.
 *  - NFD-Eingabe (z.B. 'Mu\u{0308}ller') und NFC-Eingabe ('Müller') liefern
 *    dasselbe Ergebnis.
 *  - HTML-Entities (z.B. '&uuml;') werden NICHT dekodiert — das muss der Aufrufer
 *    tun. '&' und ';' werden von Schritt 6 als Nicht-Alphanumerika entfernt, die
 *    dazwischenliegenden Buchstaben bleiben: 'Mu&uuml;ller' → 'muuumlller'.
 *
 * Beispiele:
 *   'C. Pruß'                    -> 'cpruss'
 *   'Müller, H.-P.'              -> 'mullerhp'
 *   'Institut für Optik, Uni UL' -> 'institutfuroptikuniul'
 *   'O'Brien'                    -> 'obrien'
 *   'Иванов'                     -> 'ivanov'
 *   '李'                         -> 'li'
 *   ''                           -> ''
 */
function normalizeForAliasMatch(string $s): string
{
    // 1. Fußnoten-Marker entfernen (PDF-Importer-Artefakte).
    $s = preg_replace('/[\*†‡§#^]+/u', '', $s);
    // 2. Kleinschreibung.
    $s = mb_strtolower($s);
    // 3. ß -> ss (vor der ICU-Transliterierung, die ß -> s kuerzt).
    $s = str_replace('ß', 'ss', $s);
    // 4. Diakritika -> ASCII (ü -> u, ö -> o, etc.).
    //    ICU wandelt dabei auch geschwungene Apostrophe (U+2018, U+2019) in
    //    geraden Apostroph (U+0027) um — dieser wird in Schritt 6 entfernt.
    //    Unicode-Whitespace (NBSP, Narrow-NBSP, ZWSP) überlebt ICU und wird
    //    in Schritt 5 entfernt, da nicht im Bereich \x20-\x7E.
    $result = transliterator_transliterate('Any-Latin; Latin-ASCII; [:Nonspacing Mark:] Remove; Lower()', $s);
    $s = ($result !== false && $result !== null) ? $result : $s;
    // 5. Safety-Net: alle verbleibenden Nicht-ASCII-Bytes entfernen
    //    (seltene Skripte ohne ICU-Transliteration, kaputte Encodings,
    //    Unicode-Whitespace wie NBSP/Narrow-NBSP/ZWSP).
    $s = preg_replace('/[^\x20-\x7E]+/', '', $s);
    // 6. Alle verbleibenden Nicht-Alphanumerika entfernen:
    //    Punkte, Spaces, Kommas, Bindestriche, Apostrophe (gerade und geschwungen
    //    — nach ICU als gerader ' vorliegend), sonstige ASCII-Satzzeichen.
    $s = preg_replace("/[^a-z0-9]+/", '', $s);
    // 7. Trim (sicherheitshalber, sollte nach Schritt 6 leer sein wenn nötig).
    return trim($s);
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

    // Zweiter Pass: Sessions mit identischem Titel+Datum+Saal mergen.
    // Booklet-Slots, die im Programm durch eine Pause/anderen Block geteilt
    // sind (z.B. das Ehrensymposium auf Tagung 127, das vor und nach der
    // Kaffeepause stattfindet), sind im Importer korrekt als zwei sessions-
    // Zeilen mit unterschiedlichem zeit_von erfasst — fuer den Leser aber
    // EIN Programmpunkt. Frueheste zeit_von gewinnt, Papers werden
    // zusammengefuehrt und chronologisch sortiert.
    $merged = [];
    $mergeIndex = [];
    foreach ($groups as $g) {
        if ($g['type'] !== 'session') {
            $merged[] = $g;
            continue;
        }
        $mergeKey = 'session_' . md5(
            ($g['titel'] ?? '') . '|' . ($g['datum'] ?? '') . '|' . ($g['saal'] ?? '')
        );
        if (!isset($mergeIndex[$mergeKey])) {
            $g['key'] = $mergeKey;
            $merged[] = $g;
            $mergeIndex[$mergeKey] = count($merged) - 1;
            continue;
        }
        $idx = $mergeIndex[$mergeKey];
        $merged[$idx]['papers'] = array_merge($merged[$idx]['papers'], $g['papers']);
        if (
            !empty($g['zeit_von']) &&
            (empty($merged[$idx]['zeit_von']) || $g['zeit_von'] < $merged[$idx]['zeit_von'])
        ) {
            $merged[$idx]['zeit_von'] = $g['zeit_von'];
        }
    }

    // Papers innerhalb gemergeter Sessions nach Uhrzeit sortieren (Code als
    // Tiebreaker), damit S1..S6 in lesbarer Reihenfolge erscheinen.
    foreach ($merged as &$g) {
        if ($g['type'] !== 'session' || count($g['papers']) < 2) {
            continue;
        }
        usort($g['papers'], function ($a, $b) {
            $za = (string)($a['zeit'] ?? '');
            $zb = (string)($b['zeit'] ?? '');
            if ($za !== '' && $zb !== '' && $za !== $zb) {
                return strcmp($za, $zb);
            }
            return strcmp((string)($a['code'] ?? ''), (string)($b['code'] ?? ''));
        });
    }
    unset($g);

    return $merged;
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
 * Top-N Institut-Namen (lokalisiert) nach Anzahl verknüpfter Autoren.
 * Verwendet die kanonische institutionen-Tabelle (Schema v8+).
 * @return list<string>
 */
function getTopAffiliationSuggestions(int $limit = 100): array
{
    $lang    = $_SESSION['lang'] ?? 'de';
    $instCol = $lang === 'en' ? 'i.name_en' : 'i.name_de';
    $stmt = getDb()->prepare("
        SELECT COALESCE(NULLIF($instCol, ''), i.name_de) AS affiliation,
               COUNT(DISTINCT ai.autor_id) AS n
        FROM institutionen i
        JOIN autor_institutionen ai ON ai.institut_id = i.id
        GROUP BY i.id
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

/**
 * Self-Heal: stellt sicher, dass die Auto-News fuer aktuelle/kommende
 * Tagungen in der DB existieren. Wichtig fuer frisch deployte
 * Installationen (Production), wo die news-Tabelle leer ist obwohl
 * tagungen mit vorlage_phase_aktiv=1 oder kommendem Datum existieren.
 *
 * Idempotent: die news_events-Trigger nutzen UPSERT mit unique-Index,
 * mehrfacher Aufruf erzeugt keine Duplikate.
 *
 * Wird auf der Home aufgerufen vor getActiveNews(). Memoized pro
 * Request via static-Flag — kostet effektiv nur einen Query + ggf.
 * ein paar UPSERTs auf den ersten Hit nach Deploy.
 */
function bootstrapAutoNewsForCurrentTagungen(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    require_once __DIR__ . '/admin/news_events.php';

    $db           = getDb();
    $sixtyDaysAgo = date('Y-m-d', strtotime('-60 days'));

    $stmt = $db->prepare("
        SELECT *
        FROM tagungen
        WHERE vorlage_phase_aktiv = 1
           OR (datum_von IS NOT NULL AND datum_von >= :cutoff)
    ");
    $stmt->execute([':cutoff' => $sixtyDaysAgo]);

    foreach ($stmt as $tagung) {
        try {
            newsOnTagungSaved($tagung, $tagung);
        } catch (Throwable $e) {
            // Fehler nicht weiterwerfen — News-Seed darf die Home nicht killen.
            error_log('bootstrapAutoNews: ' . $e->getMessage());
        }
    }
}

/**
 * Aktive News-Items in der aktuellen Sprache, sortiert nach Pin (sort_weight)
 * + display_date DESC.
 *
 * @return list<array{id:int, source:string, display_date:string,
 *                    title:string, body:string, link_url:?string}>
 */
function getActiveNews(int $limit = 3): array
{
    $lang  = currentLang() === 'en' ? 'en' : 'de';
    $stmt  = getDb()->prepare("
        SELECT id, source, display_date,
               title_{$lang} AS title, body_{$lang} AS body,
               link_url
        FROM news
        WHERE is_active = 1
        ORDER BY sort_weight DESC, display_date DESC, id DESC
        LIMIT :n
    ");
    $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Action-Label fuer den CTA-Pill auf der Home-News-Row. Aus link_url
 * abgeleitet — zeigt dem User auf einen Blick, wohin der Klick geht
 * (Pendant zum Count-Pill auf /archiv).
 */
function newsCtaLabel(?string $url): string
{
    if (!$url) return '';
    $isEn = currentLang() === 'en';

    if (str_contains($url, '/einreichen')) {
        return $isEn ? 'Submit' : 'Einreichen';
    }
    if (preg_match('#^/archiv/\d+#', $url) || str_starts_with($url, '/archiv')) {
        return $isEn ? 'Papers' : 'Beiträge';
    }
    if (str_contains($url, '/jahrestagung')) {
        return $isEn ? 'Conference' : 'Programm';
    }
    if (str_starts_with($url, 'http')) {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        return $host !== '' ? preg_replace('/^www\./', '', $host) : ($isEn ? 'More' : 'Mehr');
    }
    return $isEn ? 'More' : 'Mehr';
}

function getSiteStats(): array
{
    $db = getDb();
    $papers   = (int) $db->query('SELECT COUNT(*) FROM papers')->fetchColumn();
    $tagungen = (int) $db->query('SELECT COUNT(*) FROM tagungen')->fetchColumn();
    $autoren  = (int) $db->query('SELECT COUNT(*) FROM autoren')->fetchColumn();
    return compact('papers', 'tagungen', 'autoren');
}

// ===========================================================================
// v9: Zentrale Helper für Namens-Anzeige + paper_autor_institutionen
// ===========================================================================

/**
 * Vollständiger Autorname für Anzeige.
 * Priorität: anzeige_name (ORCID credit-name) > vorname + nachname.
 * Footnote-Marker werden entfernt.
 *
 * @param array{vorname?:string, nachname?:string, anzeige_name?:?string} $row
 */
function formatAutorName(array $row): string
{
    $anzeige = trim((string)($row['anzeige_name'] ?? ''));
    if ($anzeige !== '') {
        return trim(preg_replace('/\*+/', '', $anzeige));
    }
    $vor  = trim(preg_replace('/\*+/', '', (string)($row['vorname']  ?? '')));
    $nach = trim(preg_replace('/\*+/', '', (string)($row['nachname'] ?? '')));
    return trim($vor . ($vor !== '' && $nach !== '' ? ' ' : '') . $nach);
}

/**
 * "Nachname, Vorname" für Listen-Sortierung (z.B. Autorenseite).
 *
 * @param array{vorname?:string, nachname?:string, anzeige_name?:?string} $row
 */
function formatAutorNameNachLast(array $row): string
{
    $anzeige = trim((string)($row['anzeige_name'] ?? ''));
    $nach = trim(preg_replace('/\*+/', '', (string)($row['nachname'] ?? '')));
    if ($anzeige !== '') {
        return $nach !== '' ? $nach : $anzeige;
    }
    $vor  = trim(preg_replace('/\*+/', '', (string)($row['vorname']  ?? '')));
    if ($nach === '') return $vor;
    return $vor === '' ? $nach : $nach . ', ' . $vor;
}

/**
 * Alle Affiliations eines Autors (über paper_autor_institutionen abgeleitet),
 * aggregiert mit Min/Max-Jahr aus papers/tagungen, sortiert nach jüngstem
 * Auftreten zuerst.
 *
 * Fallback: wenn paper_autor_institutionen leer für diesen Autor, nutze
 * autor_institutionen (Legacy/uncovered Autoren).
 *
 * @return list<array{institut_id:int, name:string, jahr_von:int, jahr_bis:int, n_papers:int, ist_aktuell:bool}>
 */
function getAutorAffiliations(int $autorId): array
{
    $lang    = currentLang() === 'en' ? 'en' : 'de';
    $instCol = $lang === 'en' ? "COALESCE(NULLIF(i.name_en,''), i.name_de)" : 'i.name_de';
    $db = getDb();

    // Bevorzugt SICHERE Quellen (pdf/single_affil/anker/openalex/orcid).
    // Unscharfe Eintraege nur wenn keine sicheren vorhanden sind — sonst
    // ueberfluten sie die Anzeige bei Autoren mit vielen Multi-Affil-Papers.
    $stmt = $db->prepare("
        SELECT pai.institut_id,
               $instCol AS name,
               MIN(t.jahr) AS jahr_von,
               MAX(t.jahr) AS jahr_bis,
               COUNT(DISTINCT pai.paper_id) AS n_papers
        FROM paper_autor_institutionen pai
        JOIN papers p ON p.id = pai.paper_id
        JOIN tagungen t ON t.nummer = p.tagung_nummer
        JOIN institutionen i ON i.id = pai.institut_id
        WHERE pai.autor_id = ?
          AND pai.quelle IN ('nuextract','single_affil','anker','openalex','orcid')
        GROUP BY pai.institut_id
        ORDER BY jahr_bis DESC, n_papers DESC, name COLLATE NOCASE
    ");
    $stmt->execute([$autorId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        // Fallback auf unscharfe Einträge
        $stmt = $db->prepare("
            SELECT pai.institut_id,
                   $instCol AS name,
                   MIN(t.jahr) AS jahr_von,
                   MAX(t.jahr) AS jahr_bis,
                   COUNT(DISTINCT pai.paper_id) AS n_papers
            FROM paper_autor_institutionen pai
            JOIN papers p ON p.id = pai.paper_id
            JOIN tagungen t ON t.nummer = p.tagung_nummer
            JOIN institutionen i ON i.id = pai.institut_id
            WHERE pai.autor_id = ?
            GROUP BY pai.institut_id
            ORDER BY jahr_bis DESC, n_papers DESC, name COLLATE NOCASE
            LIMIT 5
        ");
        $stmt->execute([$autorId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!$rows) {
        // Legacy-Fallback: autor_institutionen (binaeres ist_aktuell)
        $stmt = $db->prepare("
            SELECT ai.institut_id,
                   $instCol AS name,
                   NULL AS jahr_von, NULL AS jahr_bis,
                   0 AS n_papers,
                   ai.ist_aktuell
            FROM autor_institutionen ai
            JOIN institutionen i ON i.id = ai.institut_id
            WHERE ai.autor_id = ?
            ORDER BY ai.ist_aktuell DESC, name COLLATE NOCASE
        ");
        $stmt->execute([$autorId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['ist_aktuell'] = (bool)($r['ist_aktuell'] ?? false);
        }
        unset($r);
        return $rows;
    }

    // jüngste(s) Institut(e) als "ist_aktuell" markieren
    $maxYear = 0;
    foreach ($rows as $r) $maxYear = max($maxYear, (int)$r['jahr_bis']);
    foreach ($rows as &$r) {
        $r['ist_aktuell'] = ((int)$r['jahr_bis'] === $maxYear);
    }
    unset($r);
    return $rows;
}

/**
 * Pro Paper die Autoren in Position-Reihenfolge mit jeweils ihren Affils
 * im Kontext dieses Papers. Schlüssel: autor_id.
 *
 * @return list<array{
 *     autor_id:int, position:int, ist_hauptautor:bool,
 *     vorname:string, nachname:string, anzeige_name:?string, orcid_id:?string,
 *     name:string, affils:list<array{institut_id:int,name:string}>
 * }>
 */
function getPaperAutorenWithAffils(string $paperId): array
{
    $lang    = currentLang() === 'en' ? 'en' : 'de';
    $instCol = $lang === 'en' ? "COALESCE(NULLIF(i.name_en,''), i.name_de)" : 'i.name_de';
    $db = getDb();

    $stmt = $db->prepare("
        SELECT pa.autor_id, pa.position, pa.ist_hauptautor,
               a.vorname, a.nachname, a.anzeige_name, a.orcid_id
        FROM paper_autoren pa
        JOIN autoren a ON a.id = pa.autor_id
        WHERE pa.paper_id = ?
        ORDER BY pa.position
    ");
    $stmt->execute([$paperId]);
    $autoren = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$autoren) return [];

    // Affils pro (Paper, Autor) — bevorzugt aus paper_autor_institutionen
    $affStmt = $db->prepare("
        SELECT pai.autor_id, pai.institut_id, $instCol AS name
        FROM paper_autor_institutionen pai
        JOIN institutionen i ON i.id = pai.institut_id
        WHERE pai.paper_id = ?
        ORDER BY name COLLATE NOCASE
    ");
    $affStmt->execute([$paperId]);
    $affsByAutor = [];
    foreach ($affStmt as $r) {
        $affsByAutor[(int)$r['autor_id']][] = [
            'institut_id' => (int)$r['institut_id'],
            'name'        => (string)$r['name'],
        ];
    }

    $out = [];
    foreach ($autoren as $row) {
        $aid = (int)$row['autor_id'];
        $out[] = [
            'autor_id'      => $aid,
            'position'      => (int)$row['position'],
            'ist_hauptautor'=> (bool)$row['ist_hauptautor'],
            'vorname'       => (string)$row['vorname'],
            'nachname'      => (string)$row['nachname'],
            'anzeige_name'  => $row['anzeige_name'] ?? null,
            'orcid_id'      => $row['orcid_id'] ?? null,
            'name'          => formatAutorName($row),
            'affils'        => $affsByAutor[$aid] ?? [],
        ];
    }
    return $out;
}

/**
 * Eindeutige Affil-Liste eines Papers (Reihenfolge nach Position der ersten
 * Erwähnung). Für Anzeige "Affiliations" als zusammengefasste Liste.
 *
 * @return list<array{institut_id:int, name:string, autor_ids:list<int>}>
 */
function getPaperAffiliations(string $paperId): array
{
    $lang    = currentLang() === 'en' ? 'en' : 'de';
    $instCol = $lang === 'en' ? "COALESCE(NULLIF(i.name_en,''), i.name_de)" : 'i.name_de';
    $stmt = getDb()->prepare("
        SELECT pai.institut_id, $instCol AS name, pai.autor_id, pa.position
        FROM paper_autor_institutionen pai
        JOIN institutionen i ON i.id = pai.institut_id
        JOIN paper_autoren pa ON pa.paper_id = pai.paper_id AND pa.autor_id = pai.autor_id
        WHERE pai.paper_id = ?
        ORDER BY pa.position
    ");
    $stmt->execute([$paperId]);
    $by = [];
    foreach ($stmt as $r) {
        $iid = (int)$r['institut_id'];
        if (!isset($by[$iid])) {
            $by[$iid] = [
                'institut_id' => $iid,
                'name'        => (string)$r['name'],
                'autor_ids'   => [],
            ];
        }
        $by[$iid]['autor_ids'][] = (int)$r['autor_id'];
    }
    return array_values($by);
}

/**
 * Zentrale Paper-Suche fuer Frontend (/suche) UND Admin (/admin/papers).
 *
 * Eine Wahrheit, ein WHERE-Builder, ein SQL — wird ueberall wiederverwendet.
 *
 * @param array{
 *   q?: string,            // Volltext (FTS oder LIKE)
 *   titel?: string,        // Filter: titel
 *   autor?: string,        // Filter: autor (incl. alias_norm)
 *   institut?: string,     // Filter: institution
 *   abstract?: string,     // Filter: abstract_text
 *   tagung?: int,          // Filter: tagung_nummer
 *   session?: int,         // Filter: session_id
 *   sort?: string,         // 'relevanz'|'tagung_neu'|'tagung_alt'|'titel_az'
 *   limit?: int,           // default 100
 *   offset?: int,          // default 0
 *   count_only?: bool,     // wenn true: nur Anzahl zurueck
 * } $opts
 *
 * @return array{rows:list<array>, total:int, used_fts:bool}
 */
function searchPapers(array $opts): array
{
    $db = getDb();
    $q       = trim((string)($opts['q'] ?? ''));
    $fTitel  = trim((string)($opts['titel'] ?? ''));
    $fAutor  = trim((string)($opts['autor'] ?? ''));
    $fInst   = trim((string)($opts['institut'] ?? ''));
    $fAbs    = trim((string)($opts['abstract'] ?? ''));
    $fTagung = (int)($opts['tagung'] ?? 0);
    $fSession= (int)($opts['session'] ?? 0);
    $sort    = (string)($opts['sort'] ?? 'tagung_neu');
    $limit   = max(1, (int)($opts['limit'] ?? 100));
    $offset  = max(0, (int)($opts['offset'] ?? 0));
    $countOnly = !empty($opts['count_only']);

    $paperCodeOrderSql = "CASE substr(p.code, 1, 1)
        WHEN 'H' THEN 1 WHEN 'A' THEN 2 WHEN 'B' THEN 3
        WHEN 'C' THEN 4 WHEN 'P' THEN 5 WHEN 'S' THEN 6 ELSE 9 END";

    $wheres = [];
    $params = [];
    $sanitized = $q !== '' ? sanitizeFtsQuery($q) : null;
    $useFts    = $sanitized !== null && $sort === 'relevanz' && !$countOnly;

    if ($fTitel !== '') {
        $wheres[] = 'p.titel LIKE :titel COLLATE NOCASE';
        $params[':titel'] = '%' . $fTitel . '%';
    }
    if ($fAutor !== '') {
        $aNorm = normalizeForAliasMatch($fAutor);
        $wheres[] = '(EXISTS (SELECT 1 FROM paper_autoren pa
                       JOIN autor_aliase al ON al.autor_id = pa.autor_id
                       WHERE pa.paper_id = p.id AND al.alias_norm LIKE :anorm)
                     OR p.hauptautor LIKE :autor2 COLLATE NOCASE
                     OR p.autoren_text LIKE :autor1 COLLATE NOCASE)';
        $params[':anorm']  = '%' . $aNorm . '%';
        $params[':autor1'] = '%' . $fAutor . '%';
        $params[':autor2'] = '%' . $fAutor . '%';
    }
    if ($fInst !== '') {
        $iNorm = normalizeForAliasMatch($fInst);
        $wheres[] = '(p.affiliationen LIKE :inst1 COLLATE NOCASE
                     OR EXISTS (SELECT 1 FROM paper_autor_institutionen pai
                                JOIN institutionen i ON i.id = pai.institut_id
                                LEFT JOIN institut_aliase ia ON ia.institut_id = i.id
                                WHERE pai.paper_id = p.id
                                  AND (   i.name_de LIKE :inst2 COLLATE NOCASE
                                       OR i.name_en LIKE :inst3 COLLATE NOCASE
                                       OR i.kuerzel LIKE :inst4 COLLATE NOCASE
                                       OR ia.alias_norm LIKE :inorm)))';
        $params[':inst1'] = '%' . $fInst . '%';
        $params[':inst2'] = '%' . $fInst . '%';
        $params[':inst3'] = '%' . $fInst . '%';
        $params[':inst4'] = '%' . $fInst . '%';
        $params[':inorm'] = '%' . $iNorm . '%';
    }
    if ($fAbs !== '') {
        $wheres[] = 'p.abstract_text LIKE :abs COLLATE NOCASE';
        $params[':abs'] = '%' . $fAbs . '%';
    }
    if ($fTagung > 0) {
        $wheres[] = 'p.tagung_nummer = :tagung';
        $params[':tagung'] = $fTagung;
    }
    if ($fSession > 0) {
        $wheres[] = 'p.session_id = :session';
        $params[':session'] = $fSession;
    }

    $orderBy = match ($sort) {
        'tagung_neu' => "ORDER BY p.tagung_nummer DESC, $paperCodeOrderSql, CAST(substr(p.code,2) AS INTEGER)",
        'tagung_alt' => "ORDER BY p.tagung_nummer ASC, $paperCodeOrderSql, CAST(substr(p.code,2) AS INTEGER)",
        'titel_az'   => "ORDER BY p.titel COLLATE NOCASE",
        'relevanz'   => 'ORDER BY rank',  // nur mit FTS-Pfad sinnvoll
        default      => 'ORDER BY p.tagung_nummer DESC, p.code',
    };

    if ($useFts && $q !== '') {
        $whereSql = !empty($wheres) ? 'AND ' . implode(' AND ', $wheres) : '';
        $sql = "FROM papers_fts fts JOIN papers p ON p.rowid = fts.rowid
                WHERE papers_fts MATCH :q $whereSql";
        $params[':q'] = $sanitized;
    } else {
        if ($q !== '') {
            $wheres[] = '(p.titel LIKE :qa COLLATE NOCASE
                       OR p.autoren_text LIKE :qb COLLATE NOCASE
                       OR p.abstract_text LIKE :qc COLLATE NOCASE
                       OR p.affiliationen LIKE :qd COLLATE NOCASE)';
            $params[':qa'] = '%' . $q . '%';
            $params[':qb'] = '%' . $q . '%';
            $params[':qc'] = '%' . $q . '%';
            $params[':qd'] = '%' . $q . '%';
        }
        $whereSql = !empty($wheres) ? 'WHERE ' . implode(' AND ', $wheres) : '';
        $sql = "FROM papers p $whereSql";
    }

    if ($countOnly) {
        $stmt = $db->prepare("SELECT COUNT(*) $sql");
        $stmt->execute($params);
        return ['rows' => [], 'total' => (int)$stmt->fetchColumn(), 'used_fts' => $useFts];
    }

    // Count separat (für Pagination)
    try {
        $countStmt = $db->prepare("SELECT COUNT(*) $sql");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('searchPapers count failed: ' . $e);
        $total = 0;
    }

    $selectSql = "SELECT p.id, p.tagung_nummer, p.code, p.typ, p.titel, p.autoren_text,
                         p.hat_pdf, p.pdf_dateiname, p.session_id, p.datum, p.zeit
                  $sql $orderBy LIMIT :limit OFFSET :offset";
    $params[':limit']  = $limit;
    $params[':offset'] = $offset;
    try {
        $stmt = $db->prepare($selectSql);
        foreach ($params as $k => $v) {
            $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($k, $v, $type);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('searchPapers query failed: ' . $e);
        $rows = [];
    }

    return ['rows' => $rows, 'total' => $total, 'used_fts' => $useFts];
}

/**
 * ASCII-normalisierte Suche-Input-Vorbereitung: ß->ss, Diakritik weg, lower.
 * Spiegel der normalizeForAliasMatch-Logik, nutzt aber dieselbe ICU-Variante.
 */
function normalizeSearchInput(string $q): string
{
    $q = str_replace(['ß','ẞ'], ['ss','SS'], $q);
    if (class_exists(\Transliterator::class)) {
        $tr = \Transliterator::create('Any-Latin; Latin-ASCII; NFKD; [:Nonspacing Mark:] Remove; Lower()');
        if ($tr) {
            $folded = $tr->transliterate($q);
            if ($folded !== false) $q = $folded;
        }
    } else {
        $q = mb_strtolower($q, 'UTF-8');
    }
    return trim(preg_replace('/\s+/', ' ', $q));
}

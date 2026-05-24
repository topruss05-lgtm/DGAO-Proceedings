<?php

declare(strict_types=1);

// --- Validierung ---

function getTypMap(): array
{
    return [
        'vortrag'        => 'vortrag',
        'talk'           => 'vortrag',
        'poster'         => 'poster',
        'hauptvortrag'   => 'hauptvortrag',
        'keynote'        => 'hauptvortrag',
        'invited'        => 'hauptvortrag',
        'invited talk'   => 'hauptvortrag',
        'sondervortrag'  => 'sondervortrag',
        'nachwuchspreis' => 'sondervortrag',
        'special'        => 'sondervortrag',
    ];
}

function validateImportRow(array $row): array
{
    $errors = [];
    $line = $row['_line'] ?? '?';

    // Code
    if (empty($row['code'] ?? '')) {
        $errors[] = "Code fehlt";
    } elseif (!preg_match('/^[A-Za-z]\d+$/i', $row['code'])) {
        $errors[] = "Ungültiges Code-Format '" . ($row['code'] ?? '') . "'";
    }

    // Titel
    if (empty($row['titel'] ?? '')) {
        $errors[] = "Titel fehlt";
    }

    // Autoren
    if (empty($row['autoren'] ?? '')) {
        $errors[] = "Autoren fehlt";
    }

    // Typ normalisieren
    $typMap = getTypMap();
    $typRaw = strtolower(trim($row['typ'] ?? 'vortrag'));
    $typNorm = $typMap[$typRaw] ?? null;
    if ($typNorm === null && !empty($row['typ'])) {
        $errors[] = "Unbekannter Typ '" . ($row['typ'] ?? '') . "'";
        $typNorm = 'vortrag';
    } elseif ($typNorm === null) {
        $typNorm = 'vortrag';
    }

    // Datum normalisieren
    $datum = $row['datum'] ?? '';
    if (!empty($datum)) {
        if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $datum)) {
            $parts = explode('.', $datum);
            $datum = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
            $errors[] = "Ungültiges Datum '{$datum}'";
        }
    }

    return [
        'errors'   => $errors,
        'typ_norm' => $typNorm,
        'datum'    => $datum,
    ];
}

// --- Autorenname-Konvertierung ---

/**
 * Parst einen Autorennamen im Display-Format in vorname/nachname.
 *
 * Beispiele:
 *   "A. Schiebelbein"   → ['vorname' => 'A.',      'nachname' => 'Schiebelbein']
 *   "J. P. Weißhaar"    → ['vorname' => 'J. P.',   'nachname' => 'Weißhaar']
 *   "K.-H. Brenner"     → ['vorname' => 'K.-H.',   'nachname' => 'Brenner']
 *   "Hans Peter Müller"  → ['vorname' => 'Hans Peter', 'nachname' => 'Müller']
 *   "G. von Bally"      → ['vorname' => 'G.',      'nachname' => 'von Bally']
 *   "Schiebelbein"      → ['vorname' => '',         'nachname' => 'Schiebelbein']
 *   "A.Schiebelbein"    → ['vorname' => 'A.',      'nachname' => 'Schiebelbein']
 */
function parseAuthorDisplayName(string $displayName): array
{
    $name = trim($displayName);
    if ($name === '') {
        return ['vorname' => '', 'nachname' => ''];
    }

    // Normalisierung: "A.Schiebelbein" → "A. Schiebelbein"
    $name = preg_replace('/\.([A-ZÄÖÜ])/u', '. $1', $name);

    // Nobiliary particles
    $particles = ['von', 'van', 'de', 'del', 'della', 'dalla', 'di', 'du', 'le', 'la', 'den', 'der', 'ten', 'ter', 'zu'];

    $parts = preg_split('/\s+/', $name);
    if (count($parts) === 1) {
        return ['vorname' => '', 'nachname' => $parts[0]];
    }

    // Initialen erkennen (z.B. "A.", "K.-H.", "Chr.", "Th.")
    $initialPattern = '/^[A-ZÄÖÜ][a-zäöü]{0,2}\.(-[A-ZÄÖÜ][a-zäöü]{0,2}\.)?$/u';

    // Sammle führende Initialen
    $initials = [];
    $rest = $parts;
    while (!empty($rest) && preg_match($initialPattern, $rest[0])) {
        $initials[] = array_shift($rest);
    }

    if (!empty($initials) && !empty($rest)) {
        // Prüfe ob rest mit nobiliary particle beginnt
        $nachnameParts = [];
        $foundParticle = false;
        foreach ($rest as $i => $word) {
            if ($i === 0 && in_array(mb_strtolower($word), $particles)) {
                $foundParticle = true;
            }
            $nachnameParts[] = $word;
        }
        return [
            'vorname'  => implode(' ', $initials),
            'nachname' => implode(' ', $nachnameParts),
        ];
    }

    // Kein Initialen-Muster → Vollname-Heuristik
    // Suche nobiliary particle als Start des Nachnamens
    for ($i = 1; $i < count($parts); $i++) {
        if (in_array(mb_strtolower($parts[$i]), $particles)) {
            return [
                'vorname'  => implode(' ', array_slice($parts, 0, $i)),
                'nachname' => implode(' ', array_slice($parts, $i)),
            ];
        }
    }

    // Fallback: letztes Wort = Nachname, Rest = Vorname
    $nachname = array_pop($parts);
    return [
        'vorname'  => implode(' ', $parts),
        'nachname' => $nachname,
    ];
}

// --- Paper-Persistenz: Autoren- und Keyword-Sync ---
// Beide Helpers werden von executeImport (CSV-Import) und paper_edit (UI) genutzt.

/**
 * Verknüpft die im autoren_text genannten Autoren mit einem Paper.
 * Legt neue autoren-Zeilen on the fly an. Caller ist fuer das vorherige
 * Loeschen alter paper_autoren-Eintraege verantwortlich.
 *
 * @return int Anzahl erfolgreich verknuepfter Autoren.
 */
function syncPaperAuthors(PDO $db, string $paperId, string $autorenText): int
{
    if ($autorenText === '') return 0;

    static $stmtInsert = null, $stmtGet = null, $stmtLink = null;
    static $boundDb = null;
    if ($boundDb !== $db) {
        $stmtInsert = $db->prepare('INSERT OR IGNORE INTO autoren (vorname, nachname) VALUES (?, ?)');
        $stmtGet    = $db->prepare('SELECT id FROM autoren WHERE nachname = ? AND vorname = ?');
        $stmtLink   = $db->prepare('INSERT OR REPLACE INTO paper_autoren (paper_id, autor_id, position, ist_hauptautor) VALUES (?, ?, ?, ?)');
        $boundDb = $db;
    }

    $linked = 0;
    $autoren = array_map('trim', explode(',', $autorenText));
    foreach ($autoren as $pos => $display) {
        if ($display === '') continue;
        $parsed = parseAuthorDisplayName($display);
        $stmtInsert->execute([$parsed['vorname'], $parsed['nachname']]);
        $stmtGet->execute([$parsed['nachname'], $parsed['vorname']]);
        $row = $stmtGet->fetch();
        if ($row) {
            $stmtLink->execute([$paperId, $row['id'], $pos + 1, $pos === 0 ? 1 : 0]);
            $linked++;
        }
    }
    return $linked;
}

/**
 * Verknüpft die komma-separierten Keywords mit einem Paper. Legt neue
 * keyword-Zeilen on the fly an. Caller ist fuer das vorherige Loeschen
 * alter paper_keywords-Eintraege verantwortlich.
 *
 * @return int Anzahl erfolgreich verknuepfter Keywords.
 */
function syncPaperKeywords(PDO $db, string $paperId, string $keywordsRaw): int
{
    if ($keywordsRaw === '') return 0;

    static $stmtInsert = null, $stmtGet = null, $stmtLink = null;
    static $boundDb = null;
    if ($boundDb !== $db) {
        $stmtInsert = $db->prepare('INSERT OR IGNORE INTO keywords (keyword) VALUES (?)');
        $stmtGet    = $db->prepare('SELECT id FROM keywords WHERE keyword = ?');
        $stmtLink   = $db->prepare('INSERT OR REPLACE INTO paper_keywords (paper_id, keyword_id) VALUES (?, ?)');
        $boundDb = $db;
    }

    $linked = 0;
    $keywords = array_map('trim', explode(',', $keywordsRaw));
    foreach ($keywords as $kw) {
        if ($kw === '') continue;
        $stmtInsert->execute([$kw]);
        $stmtGet->execute([$kw]);
        $row = $stmtGet->fetch();
        if ($row) {
            $stmtLink->execute([$paperId, $row['id']]);
            $linked++;
        }
    }
    return $linked;
}

// --- Import-Logik ---

/**
 * Führt den CSV-Import in die Datenbank aus.
 * @return array ['success' => bool, 'message' => string, 'stats' => array]
 */
/**
 * Importiert geparste Beitrags-Zeilen für eine bereits angelegte Tagung.
 *
 * Caller-Verantwortung: $tagungNummer muss vor dem Aufruf in der tagungen-
 * Tabelle existieren (sonst FK-Fehler). Diese Funktion fasst die tagung-Row
 * NICHT mehr an, damit Felder wie vorlage_phase_aktiv erhalten bleiben.
 */
function executeImport(int $tagungNummer, array $rows, bool $overwrite): array
{
    $db = getDbAdmin();
    $db->beginTransaction();

    try {
        // 2. Bei Überschreiben: alte Daten löschen.
        //    Wir leeren Junction-Tabellen und Submissions zuerst, weil submissions.paper_id
        //    eine FK ohne CASCADE auf papers(id) ist. „Ersetzen" ist eine bewusst
        //    destruktive Aktion — der Admin hat sie aktiv angefordert.
        if ($overwrite) {
            $paperIds = $db->prepare('SELECT id FROM papers WHERE tagung_nummer = ?');
            $paperIds->execute([$tagungNummer]);
            $ids = $paperIds->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("DELETE FROM paper_keywords WHERE paper_id IN ($placeholders)")->execute($ids);
                $db->prepare("DELETE FROM paper_autoren WHERE paper_id IN ($placeholders)")->execute($ids);
                $db->prepare("DELETE FROM submissions WHERE paper_id IN ($placeholders)")->execute($ids);
                $db->prepare("DELETE FROM papers WHERE tagung_nummer = ?")->execute([$tagungNummer]);
            }
        }

        // 3. Papers einfügen
        $paperCount = 0;
        $authorCount = 0;
        $keywordCount = 0;

        $stmtPaper = $db->prepare('
            INSERT OR REPLACE INTO papers
            (id, tagung_nummer, code, typ, titel, autoren_text, hauptautor,
             abstract_text, zeit, raum, datum, affiliationen, kontakt_email,
             pdf_dateiname, hat_pdf)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        foreach ($rows as $row) {
            $validation = validateImportRow($row);
            if (!empty($validation['errors'])) {
                continue; // Fehlerhafte Zeilen überspringen
            }

            $code = strtoupper(trim($row['code']));
            $paperId = $tagungNummer . '-' . strtolower($code);
            $autorenText = $row['autoren'] ?? '';
            $hauptautor = $row['hauptautor'] ?? '';

            if (empty($hauptautor) && !empty($autorenText)) {
                $autoren = array_map('trim', explode(',', $autorenText));
                $hauptautor = $autoren[0] ?? '';
            }

            // PDF: Standardname generieren. hat_pdf = 1, falls die Datei
            // schon im download-Ordner liegt (typisch bei Re-Import nach
            // bereits freigegebenen Submissions).
            $pdfDateiname = $tagungNummer . '_' . strtolower($code) . '.pdf';
            $pdfPath = __DIR__ . '/../download/' . $tagungNummer . '/' . $pdfDateiname;
            $hatPdf = is_file($pdfPath) ? 1 : 0;

            $stmtPaper->execute([
                $paperId,
                $tagungNummer,
                $code,
                $validation['typ_norm'],
                $row['titel'] ?? '',
                $autorenText,
                $hauptautor,
                $row['abstract'] ?? '',
                $row['zeit'] ?? '',
                $row['raum'] ?? '',
                $validation['datum'],
                $row['affiliationen'] ?? '',
                $row['kontakt_email'] ?? '',
                $pdfDateiname,
                $hatPdf,
            ]);
            $paperCount++;

            $authorCount  += syncPaperAuthors($db, $paperId, $autorenText);
            $keywordCount += syncPaperKeywords($db, $paperId, $row['keywords'] ?? '');
        }

        // 4. FTS-Index neu aufbauen
        rebuildFtsIndex($db);

        $db->commit();

        return [
            'success' => true,
            'message' => "{$paperCount} Papers importiert ({$authorCount} Autoren-Verknüpfungen, {$keywordCount} Keyword-Verknüpfungen).",
            'stats'   => [
                'papers'   => $paperCount,
                'authors'  => $authorCount,
                'keywords' => $keywordCount,
            ],
        ];
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('executeImport error: ' . $e);
        return [
            'success' => false,
            'message' => 'Import fehlgeschlagen — Details im Server-Log.',
            'stats'   => [],
        ];
    }
}

// --- Paginierung ---

function paginate(int $total, int $perPage, int $currentPage): array
{
    $totalPages = max(1, (int)ceil($total / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;

    return [
        'total'       => $total,
        'per_page'    => $perPage,
        'current'     => $currentPage,
        'total_pages' => $totalPages,
        'offset'      => $offset,
    ];
}

function renderPagination(array $pag, string $baseUrl): string
{
    if ($pag['total_pages'] <= 1) return '';

    $html = '<nav><ul class="pagination pagination-sm justify-content-center">';

    // Zurück
    if ($pag['current'] > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . e($baseUrl . '&page=' . ($pag['current'] - 1)) . '">&laquo;</a></li>';
    }

    for ($i = 1; $i <= $pag['total_pages']; $i++) {
        if ($i === $pag['current']) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . e($baseUrl . '&page=' . $i) . '">' . $i . '</a></li>';
        }
    }

    // Vor
    if ($pag['current'] < $pag['total_pages']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . e($baseUrl . '&page=' . ($pag['current'] + 1)) . '">&raquo;</a></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

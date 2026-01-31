<?php

declare(strict_types=1);

// --- CSV Spalten-Mapping ---

function getColumnMap(): array
{
    return [
        'code'          => ['code', 'beitragscode', 'paper_code', 'id', 'nr'],
        'typ'           => ['typ', 'type', 'beitragstyp', 'kategorie', 'category'],
        'titel'         => ['titel', 'title', 'beitragstitel', 'paper_title'],
        'autoren'       => ['autoren', 'authors', 'autor', 'author', 'verfasser'],
        'hauptautor'    => ['hauptautor', 'main_author', 'erstautor', 'kontaktautor', 'presenting_author'],
        'abstract'      => ['abstract', 'abstract_text', 'zusammenfassung', 'kurzfassung'],
        'keywords'      => ['keywords', 'stichworte', 'schlagworte', 'schlagwörter', 'tags'],
        'affiliationen' => ['affiliationen', 'affiliation', 'affiliations', 'institution', 'einrichtung'],
        'kontakt_email' => ['kontakt_email', 'email', 'e-mail', 'contact_email', 'mail'],
        'datum'         => ['datum', 'date', 'vortragsdatum', 'presentation_date'],
        'zeit'          => ['zeit', 'time', 'uhrzeit', 'vortragszeit'],
        'raum'          => ['raum', 'room', 'saal', 'hall'],
    ];
}

/**
 * Mappt CSV-Header auf interne Feldnamen (case-insensitiv).
 */
function mapCsvHeaders(array $headers): array
{
    $columnMap = getColumnMap();
    $mapping = [];

    foreach ($headers as $idx => $header) {
        $normalized = strtolower(trim($header));
        // BOM entfernen
        $normalized = preg_replace('/^\x{FEFF}/u', '', $normalized);
        $normalized = str_replace(['-', '_', ' '], '_', $normalized);
        $normalized = preg_replace('/[^a-z0-9_äöüß]/', '', $normalized);

        foreach ($columnMap as $field => $aliases) {
            foreach ($aliases as $alias) {
                $aliasNorm = str_replace(['-', ' '], '_', strtolower($alias));
                if ($normalized === $aliasNorm) {
                    $mapping[$idx] = $field;
                    break 2;
                }
            }
        }
    }

    return $mapping;
}

/**
 * Parst eine CSV-Datei und gibt die Zeilen als assoziative Arrays zurück.
 */
function parseCsvFile(string $filePath, string $delimiter = ';'): array
{
    $content = file_get_contents($filePath);
    if ($content === false) {
        return ['error' => 'Datei konnte nicht gelesen werden.', 'rows' => []];
    }

    // BOM entfernen
    $content = preg_replace('/^\x{FEFF}/u', '', $content);

    // Encoding erkennen und zu UTF-8 konvertieren
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
    }

    // Temporäre Datei mit bereinigtem Inhalt
    $tmpFile = tempnam(sys_get_temp_dir(), 'csv_');
    file_put_contents($tmpFile, $content);

    $handle = fopen($tmpFile, 'r');
    if ($handle === false) {
        unlink($tmpFile);
        return ['error' => 'Datei konnte nicht geöffnet werden.', 'rows' => []];
    }

    // Header lesen
    $headerRow = fgetcsv($handle, 0, $delimiter, '"', '\\');
    if ($headerRow === false || count($headerRow) < 2) {
        fclose($handle);
        unlink($tmpFile);
        return ['error' => 'CSV-Header ungültig oder falsches Trennzeichen.', 'rows' => []];
    }

    $mapping = mapCsvHeaders($headerRow);

    // Pflichtfelder prüfen
    $mappedFields = array_values($mapping);
    $missing = [];
    foreach (['code', 'titel', 'autoren'] as $required) {
        if (!in_array($required, $mappedFields)) {
            $missing[] = $required;
        }
    }
    if (!empty($missing)) {
        fclose($handle);
        unlink($tmpFile);
        return [
            'error' => 'Pflicht-Spalten nicht gefunden: ' . implode(', ', $missing) .
                       '. Gefundene Spalten: ' . implode(', ', $headerRow),
            'rows' => [],
        ];
    }

    // Zeilen lesen
    $rows = [];
    $lineNum = 1;
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $lineNum++;
        if (count($row) === 1 && empty(trim($row[0] ?? ''))) {
            continue; // Leere Zeile
        }

        $mapped = ['_line' => $lineNum];
        foreach ($mapping as $idx => $field) {
            $mapped[$field] = trim($row[$idx] ?? '');
        }
        $rows[] = $mapped;
    }

    fclose($handle);
    unlink($tmpFile);

    return ['error' => null, 'rows' => $rows, 'headers' => $headerRow, 'mapping' => $mapping];
}

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

// --- Import-Logik ---

/**
 * Führt den CSV-Import in die Datenbank aus.
 * @return array ['success' => bool, 'message' => string, 'stats' => array]
 */
function executeImport(array $tagung, array $rows, bool $overwrite): array
{
    $db = getDbAdmin();
    $db->beginTransaction();

    try {
        $tagungNummer = (int)$tagung['nummer'];

        // 1. Tagung anlegen/aktualisieren
        $stmt = $db->prepare('INSERT OR REPLACE INTO tagungen (nummer, jahr, ort, datum_von, datum_bis) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            $tagungNummer,
            (int)$tagung['jahr'],
            $tagung['ort'] ?? '',
            $tagung['datum_von'] ?? '',
            $tagung['datum_bis'] ?? '',
        ]);

        // 2. Bei Überschreiben: alte Daten löschen
        if ($overwrite) {
            $paperIds = $db->prepare('SELECT id FROM papers WHERE tagung_nummer = ?');
            $paperIds->execute([$tagungNummer]);
            $ids = $paperIds->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("DELETE FROM paper_keywords WHERE paper_id IN ($placeholders)")->execute($ids);
                $db->prepare("DELETE FROM paper_autoren WHERE paper_id IN ($placeholders)")->execute($ids);
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

        $stmtAutorInsert = $db->prepare('INSERT OR IGNORE INTO autoren (vorname, nachname) VALUES (?, ?)');
        $stmtAutorGet = $db->prepare('SELECT id FROM autoren WHERE nachname = ? AND vorname = ?');
        $stmtPaperAutor = $db->prepare('INSERT OR REPLACE INTO paper_autoren (paper_id, autor_id, position, ist_hauptautor) VALUES (?, ?, ?, ?)');

        $stmtKwInsert = $db->prepare('INSERT OR IGNORE INTO keywords (keyword) VALUES (?)');
        $stmtKwGet = $db->prepare('SELECT id FROM keywords WHERE keyword = ?');
        $stmtPaperKw = $db->prepare('INSERT OR REPLACE INTO paper_keywords (paper_id, keyword_id) VALUES (?, ?)');

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

            // PDF: Standardname generieren falls nicht vorhanden
            $pdfDateiname = $tagungNummer . '_' . strtolower($code) . '.pdf';

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
                0, // hat_pdf = 0 (muss manuell gesetzt werden)
            ]);
            $paperCount++;

            // Autoren verarbeiten
            if (!empty($autorenText)) {
                $autoren = array_map('trim', explode(',', $autorenText));
                foreach ($autoren as $pos => $autorDisplay) {
                    if (empty($autorDisplay)) continue;

                    $parsed = parseAuthorDisplayName($autorDisplay);

                    $stmtAutorInsert->execute([$parsed['vorname'], $parsed['nachname']]);
                    $stmtAutorGet->execute([$parsed['nachname'], $parsed['vorname']]);
                    $autorRow = $stmtAutorGet->fetch();

                    if ($autorRow) {
                        $stmtPaperAutor->execute([
                            $paperId,
                            $autorRow['id'],
                            $pos + 1,
                            $pos === 0 ? 1 : 0,
                        ]);
                        $authorCount++;
                    }
                }
            }

            // Keywords verarbeiten
            $keywordsRaw = $row['keywords'] ?? '';
            if (!empty($keywordsRaw)) {
                $keywords = array_map('trim', explode(',', $keywordsRaw));
                foreach ($keywords as $kw) {
                    if (empty($kw)) continue;

                    $stmtKwInsert->execute([$kw]);
                    $stmtKwGet->execute([$kw]);
                    $kwRow = $stmtKwGet->fetch();

                    if ($kwRow) {
                        $stmtPaperKw->execute([$paperId, $kwRow['id']]);
                        $keywordCount++;
                    }
                }
            }
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
    } catch (Exception $e) {
        $db->rollBack();
        return [
            'success' => false,
            'message' => 'Import fehlgeschlagen: ' . $e->getMessage(),
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

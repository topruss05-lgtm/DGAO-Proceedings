<?php
$paperId = $params['id'] ?? null;
$isNew = $paperId === null;
$adminPageTitle = $isNew ? 'Neues Paper' : "Paper bearbeiten";

$db = getDb();

// Tagungen für Dropdown
$tagungen = $db->query('SELECT nummer, jahr, ort FROM tagungen ORDER BY nummer DESC')->fetchAll();

$paper = null;
$paperAutoren = [];
$paperInsts   = [];
$paperAssigns = [];
if (!$isNew) {
    $stmt = $db->prepare('SELECT * FROM papers WHERE id = ?');
    $stmt->execute([$paperId]);
    $paper = $stmt->fetch();

    if (!$paper) {
        setFlash('danger', 'Paper nicht gefunden.');
        header('Location: /admin/papers');
        exit;
    }

    // Schema v11: autoren_text/hauptautor/affiliationen sind nicht mehr in papers —
    // aus paper_autoren / paper_autor_institutionen rekonstruieren (Form-Templates lesen sie weiter)
    $paper['autoren_text']  = buildPaperAutorenString($paperId);
    $paper['hauptautor']    = buildPaperHauptautor($paperId);
    $paper['affiliationen'] = buildPaperAffiliationenString($paperId);

    // Autoren des Papers (Position-Reihenfolge)
    $pa = $db->prepare('
        SELECT pa.position, pa.autor_id, pa.ist_hauptautor, a.vorname, a.nachname
        FROM paper_autoren pa JOIN autoren a ON a.id = pa.autor_id
        WHERE pa.paper_id = ? ORDER BY pa.position
    ');
    $pa->execute([$paperId]);
    $paperAutoren = $pa->fetchAll();

    // Institute am Paper (distinct)
    $pi = $db->prepare('
        SELECT DISTINCT pai.institut_id, i.name_de, i.kuerzel
        FROM paper_autor_institutionen pai JOIN institutionen i ON i.id = pai.institut_id
        WHERE pai.paper_id = ?
        ORDER BY i.name_de COLLATE NOCASE
    ');
    $pi->execute([$paperId]);
    $paperInsts = $pi->fetchAll();

    // Bestehende Zuordnungen (autor_id -> [institut_id => quelle])
    $pas = $db->prepare('SELECT autor_id, institut_id, quelle FROM paper_autor_institutionen WHERE paper_id = ?');
    $pas->execute([$paperId]);
    foreach ($pas->fetchAll() as $r) {
        $paperAssigns[(int)$r['autor_id']][(int)$r['institut_id']] = $r['quelle'];
    }
}

// POST verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'tagung_nummer' => (int)($_POST['tagung_nummer'] ?? 0),
        'code'          => strtoupper(trim($_POST['code'] ?? '')),
        'typ'           => trim($_POST['typ'] ?? 'vortrag'),
        'titel'         => trim($_POST['titel'] ?? ''),
        'autoren_text'  => trim($_POST['autoren_text'] ?? ''),
        'hauptautor'    => trim($_POST['hauptautor'] ?? ''),
        'abstract_text' => trim($_POST['abstract_text'] ?? ''),
        'affiliationen' => trim($_POST['affiliationen'] ?? ''),
        'kontakt_email' => trim($_POST['kontakt_email'] ?? ''),
        'datum'         => trim($_POST['datum'] ?? ''),
        'zeit'          => trim($_POST['zeit'] ?? ''),
        'raum'          => trim($_POST['raum'] ?? ''),
        'pdf_dateiname' => trim($_POST['pdf_dateiname'] ?? ''),
        'hat_pdf'       => isset($_POST['hat_pdf']) ? 1 : 0,
    ];

    // Hauptautor: falls leer, erster Autor
    if (empty($data['hauptautor']) && !empty($data['autoren_text'])) {
        $autoren = array_map('trim', explode(',', $data['autoren_text']));
        $data['hauptautor'] = $autoren[0] ?? '';
    }

    $errors = [];
    if ($data['tagung_nummer'] < 1) $errors[] = 'Tagung auswählen.';
    if (empty($data['code'])) $errors[] = 'Code erforderlich.';
    if (empty($data['titel'])) $errors[] = 'Titel erforderlich.';
    if (empty($data['autoren_text'])) $errors[] = 'Autoren erforderlich.';

    if (empty($errors)) {
        $dbw = getDbAdmin();
        $dbw->beginTransaction();

        try {
            $newPaperId = $data['tagung_nummer'] . '-' . strtolower($data['code']);

            // Falls ID sich geändert hat und es ein Edit ist: alte Verknüpfungen löschen
            if (!$isNew && $newPaperId !== $paperId) {
                $dbw->prepare('DELETE FROM paper_autoren WHERE paper_id = ?')->execute([$paperId]);
                $dbw->prepare('DELETE FROM papers WHERE id = ?')->execute([$paperId]);
            } elseif (!$isNew) {
                $dbw->prepare('DELETE FROM paper_autoren WHERE paper_id = ?')->execute([$newPaperId]);
            }

            // PDF-Dateiname generieren falls leer
            if (empty($data['pdf_dateiname'])) {
                $data['pdf_dateiname'] = $data['tagung_nummer'] . '_' . strtolower($data['code']) . '.pdf';
            }

            // Paper speichern (autoren_text/hauptautor/affiliationen werden NICHT mehr in papers
            // gespeichert — Schema v11 droppt die Spalten; Werte landen via syncPaperAuthors() und
            // paper_autor_institutionen in den normalisierten Tabellen)
            $stmt = $dbw->prepare('
                INSERT OR REPLACE INTO papers
                (id, tagung_nummer, code, typ, titel,
                 abstract_text, zeit, raum, datum, kontakt_email,
                 pdf_dateiname, hat_pdf)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $newPaperId, $data['tagung_nummer'], $data['code'], $data['typ'],
                $data['titel'],
                $data['abstract_text'], $data['zeit'], $data['raum'], $data['datum'],
                $data['kontakt_email'],
                $data['pdf_dateiname'], $data['hat_pdf'],
            ]);

            syncPaperAuthors($dbw, $newPaperId, $data['autoren_text']);

            // Autor->Affiliation-Zuordnung speichern (nur falls submitted und nicht new)
            $assignInput = $_POST['assign'] ?? null;
            if (is_array($assignInput)) {
                // Bestehende nuextract-Zuordnungen merken (haben Vorrang vor manuell)
                $keepNuextract = $dbw->prepare("
                    SELECT autor_id, institut_id FROM paper_autor_institutionen
                    WHERE paper_id = ? AND quelle = 'nuextract'
                ");
                $keepNuextract->execute([$newPaperId]);
                $protected = [];
                foreach ($keepNuextract->fetchAll() as $r) {
                    $protected[(int)$r['autor_id']][(int)$r['institut_id']] = true;
                }
                $dbw->prepare('DELETE FROM paper_autor_institutionen WHERE paper_id = ?')
                    ->execute([$newPaperId]);
                $ins = $dbw->prepare("
                    INSERT OR IGNORE INTO paper_autor_institutionen
                    (paper_id, autor_id, institut_id, quelle)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($assignInput as $aid => $iids) {
                    $aid = (int)$aid;
                    if (!is_array($iids)) continue;
                    foreach ($iids as $iid) {
                        $iid = (int)$iid;
                        if ($iid <= 0) continue;
                        $quelle = isset($protected[$aid][$iid]) ? 'nuextract' : 'manuell';
                        $ins->execute([$newPaperId, $aid, $iid, $quelle]);
                    }
                }
                // protected, die im Form nicht waren (z.B. Autor nicht angezeigt), zurueckschreiben
                foreach ($protected as $aid => $iids) {
                    foreach (array_keys($iids) as $iid) {
                        $ins->execute([$newPaperId, $aid, $iid, 'nuextract']);
                    }
                }
            }

            rebuildFtsIndex($dbw);
            $dbw->commit();

            setFlash('success', $isNew ? 'Paper angelegt.' : 'Paper aktualisiert.');
            header('Location: /admin/papers?tagung=' . $data['tagung_nummer']);
            exit;
        } catch (Throwable $e) {
            $dbw->rollBack();
            error_log('paper_edit save error: ' . $e);
            $errors[] = 'Speichern fehlgeschlagen — Details im Server-Log.';
        }
    }
}
?>

<h1 class="mb-4"><?= e($adminPageTitle) ?></h1>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>

            <div class="row g-3">
                <div class="col-md-4">
                    <label for="tagung_nummer" class="form-label">Tagung *</label>
                    <select class="form-select" id="tagung_nummer" name="tagung_nummer" required>
                        <option value="">-- wählen --</option>
                        <?php foreach ($tagungen as $t): ?>
                        <option value="<?= $t['nummer'] ?>"
                            <?= ($data['tagung_nummer'] ?? $paper['tagung_nummer'] ?? '') == $t['nummer'] ? 'selected' : '' ?>>
                            <?= $t['nummer'] ?>. Tagung (<?= e($t['jahr']) ?>, <?= e($t['ort'] ?? '') ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="code" class="form-label">Code *</label>
                    <input type="text" class="form-control" id="code" name="code"
                           value="<?= e($data['code'] ?? $paper['code'] ?? '') ?>" required placeholder="A1">
                </div>
                <div class="col-md-3">
                    <label for="typ" class="form-label">Typ *</label>
                    <select class="form-select" id="typ" name="typ" required>
                        <?php foreach (['vortrag' => 'Vortrag', 'poster' => 'Poster', 'hauptvortrag' => 'Hauptvortrag', 'sondervortrag' => 'Sondervortrag'] as $val => $label): ?>
                        <option value="<?= $val ?>"
                            <?= ($data['typ'] ?? $paper['typ'] ?? 'vortrag') === $val ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="kontakt_email" class="form-label">Kontakt-E-Mail</label>
                    <input type="email" class="form-control" id="kontakt_email" name="kontakt_email"
                           value="<?= e($data['kontakt_email'] ?? $paper['kontakt_email'] ?? '') ?>">
                </div>

                <div class="col-12">
                    <label for="titel" class="form-label">Titel *</label>
                    <input type="text" class="form-control" id="titel" name="titel"
                           value="<?= e($data['titel'] ?? $paper['titel'] ?? '') ?>" required>
                </div>

                <div class="col-md-8">
                    <label for="autoren_text" class="form-label">Autoren (kommasepariert) *</label>
                    <input type="text" class="form-control" id="autoren_text" name="autoren_text"
                           value="<?= e($data['autoren_text'] ?? $paper['autoren_text'] ?? '') ?>" required
                           placeholder="A. Schiebelbein, T. Schmitt, C. Glasenapp">
                </div>
                <div class="col-md-4">
                    <label for="hauptautor" class="form-label">Hauptautor</label>
                    <input type="text" class="form-control" id="hauptautor" name="hauptautor"
                           value="<?= e($data['hauptautor'] ?? $paper['hauptautor'] ?? '') ?>"
                           placeholder="leer = erster Autor">
                </div>

                <div class="col-12">
                    <label for="abstract_text" class="form-label">Abstract</label>
                    <textarea class="form-control" id="abstract_text" name="abstract_text" rows="6"><?= e($data['abstract_text'] ?? $paper['abstract_text'] ?? '') ?></textarea>
                </div>

                <div class="col-md-12">
                    <label for="affiliationen" class="form-label">Affiliationen</label>
                    <div class="form-text mb-1">Nur Anzeige — pro Autor/Affil wird unten zugeordnet.</div>
                    <input type="text" class="form-control" id="affiliationen" name="affiliationen"
                           value="<?= e($data['affiliationen'] ?? $paper['affiliationen'] ?? '') ?>">
                </div>

                <div class="col-md-3">
                    <label for="datum" class="form-label">Datum</label>
                    <input type="date" class="form-control" id="datum" name="datum"
                           value="<?= e($data['datum'] ?? $paper['datum'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label for="zeit" class="form-label">Zeit</label>
                    <input type="time" class="form-control" id="zeit" name="zeit"
                           value="<?= e($data['zeit'] ?? $paper['zeit'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label for="raum" class="form-label">Raum</label>
                    <input type="text" class="form-control" id="raum" name="raum"
                           value="<?= e($data['raum'] ?? $paper['raum'] ?? '') ?>" placeholder="A">
                </div>

                <div class="col-md-3">
                    <label for="pdf_dateiname" class="form-label">PDF-Dateiname</label>
                    <input type="text" class="form-control" id="pdf_dateiname" name="pdf_dateiname"
                           value="<?= e($data['pdf_dateiname'] ?? $paper['pdf_dateiname'] ?? '') ?>"
                           placeholder="auto-generiert">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="hat_pdf" name="hat_pdf"
                               <?= ($data['hat_pdf'] ?? $paper['hat_pdf'] ?? 0) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="hat_pdf">Hat PDF</label>
                    </div>
                </div>
            </div>

            </div>

            <?php if (!$isNew && !empty($paperAutoren)): ?>
            <hr class="my-4">
            <h2 class="h6 fw-bold mb-3">Autor &harr; Affiliation-Zuordnung</h2>
            <?php if (empty($paperInsts)): ?>
            <div class="alert alert-light border small mb-0">
                Noch keine Institutionen im Paper-Pool. Verknüpfe Institute zuerst über die einzelnen
                <a href="/admin/autoren">Autoren</a>-Edits (autor_institutionen), oder die OCR-Pipeline.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr>
                            <th style="width: 28%">Autor</th>
                            <th>Institute (mehrere wählbar)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paperAutoren as $pa): $aid = (int)$pa['autor_id']; ?>
                        <tr>
                            <td>
                                <a href="/admin/autoren/<?= $aid ?>/edit" class="text-decoration-none">
                                    <strong><?= e($pa['nachname']) ?></strong><?= $pa['vorname'] ? ', ' . e($pa['vorname']) : '' ?>
                                </a>
                                <?php if ($pa['ist_hauptautor']): ?><i class="bi bi-star-fill text-warning small ms-1" title="Hauptautor"></i><?php endif; ?>
                                <div class="text-muted" style="font-size:0.75rem">#<?= e($pa['position']) ?></div>
                            </td>
                            <td>
                                <?php foreach ($paperInsts as $pi):
                                    $iid = (int)$pi['institut_id'];
                                    $checked = isset($paperAssigns[$aid][$iid]);
                                    $quelle  = $paperAssigns[$aid][$iid] ?? null;
                                ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           name="assign[<?= $aid ?>][]" value="<?= $iid ?>"
                                           id="ass_<?= $aid ?>_<?= $iid ?>"
                                           <?= $checked ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="ass_<?= $aid ?>_<?= $iid ?>">
                                        <a href="/admin/institute/<?= $iid ?>/edit" class="text-decoration-none">
                                            <?= e($pi['name_de']) ?>
                                        </a>
                                        <?php if ($pi['kuerzel']): ?><span class="text-muted small">(<?= e($pi['kuerzel']) ?>)</span><?php endif; ?>
                                        <?php if ($quelle): ?><span class="badge bg-light text-muted ms-1" style="font-weight:400"><?= e($quelle) ?></span><?php endif; ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-muted small mt-2">
                Beim Speichern werden Zuordnungen ausser <code>nuextract</code> als <code>manuell</code> markiert.
                Neue Institutionen hängst du über die <a href="/admin/autoren">Autor-Edit-Seite</a> an (autor_institutionen).
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Speichern
                </button>
                <a href="/admin/papers<?= $paper ? '?tagung=' . $paper['tagung_nummer'] : '' ?>" class="btn btn-outline-secondary">Abbrechen</a>
            </div>
        </form>
    </div>
</div>

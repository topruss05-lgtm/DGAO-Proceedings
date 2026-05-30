<?php
$paperId = $params['id'] ?? null;
$isNew = $paperId === null;
$adminPageTitle = $isNew ? 'Neues Paper' : "Paper bearbeiten";

$db = getDb();

// Tagungen für Dropdown
$tagungen = $db->query('SELECT nummer, jahr, ort FROM tagungen ORDER BY nummer DESC')->fetchAll();

$paper = null;
$paperAutoren = [];          // pa.position, pa.autor_id, pa.ist_hauptautor, a.vorname, a.nachname
$paperAssigns = [];          // autor_id => [ ['institut_id'=>..., 'name'=>..., 'quelle'=>...] ]
if (!$isNew) {
    $stmt = $db->prepare('SELECT * FROM papers WHERE id = ?');
    $stmt->execute([$paperId]);
    $paper = $stmt->fetch();

    if (!$paper) {
        setFlash('danger', 'Paper nicht gefunden.');
        header('Location: /admin/papers');
        exit;
    }

    // Autoren des Papers (Position-Reihenfolge)
    $pa = $db->prepare('
        SELECT pa.position, pa.autor_id, pa.ist_hauptautor,
               a.vorname, a.nachname, a.anzeige_name
        FROM paper_autoren pa JOIN autoren a ON a.id = pa.autor_id
        WHERE pa.paper_id = ? ORDER BY pa.position
    ');
    $pa->execute([$paperId]);
    $paperAutoren = $pa->fetchAll();

    // Pro Autor: bestehende Affil-Zuordnungen (mit Institut-Name + Quelle)
    $pas = $db->prepare('
        SELECT pai.autor_id, pai.institut_id, pai.quelle,
               i.name_de AS institut_name, i.kuerzel
        FROM paper_autor_institutionen pai
        JOIN institutionen i ON i.id = pai.institut_id
        WHERE pai.paper_id = ?
        ORDER BY pai.autor_id, i.name_de COLLATE NOCASE
    ');
    $pas->execute([$paperId]);
    foreach ($pas->fetchAll() as $r) {
        $paperAssigns[(int)$r['autor_id']][] = [
            'institut_id'   => (int)$r['institut_id'],
            'name'          => (string)$r['institut_name'],
            'kuerzel'       => (string)($r['kuerzel'] ?? ''),
            'quelle'        => (string)($r['quelle'] ?? ''),
        ];
    }
}

$errors = [];

// POST verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'tagung_nummer' => (int)($_POST['tagung_nummer'] ?? 0),
        'code'          => strtoupper(trim($_POST['code'] ?? '')),
        'typ'           => trim($_POST['typ'] ?? 'vortrag'),
        'titel'         => trim($_POST['titel'] ?? ''),
        'abstract_text' => trim($_POST['abstract_text'] ?? ''),
        'kontakt_email' => trim($_POST['kontakt_email'] ?? ''),
        'datum'         => trim($_POST['datum'] ?? ''),
        'zeit'          => trim($_POST['zeit'] ?? ''),
        'raum'          => trim($_POST['raum'] ?? ''),
        'pdf_dateiname' => trim($_POST['pdf_dateiname'] ?? ''),
        'hat_pdf'       => isset($_POST['hat_pdf']) ? 1 : 0,
    ];

    $autorIds = array_values(array_filter(array_map('intval', (array)($_POST['autor_ids'] ?? [])), fn($x) => $x > 0));
    $hauptId  = (int)($_POST['hauptautor_id'] ?? 0);
    if ($hauptId === 0 && !empty($autorIds)) {
        $hauptId = $autorIds[0];
    }

    if ($data['tagung_nummer'] < 1) $errors[] = 'Tagung auswählen.';
    if (empty($data['code']))       $errors[] = 'Code erforderlich.';
    if (empty($data['titel']))      $errors[] = 'Titel erforderlich.';
    if (empty($autorIds))           $errors[] = 'Mindestens ein Autor erforderlich.';

    if (empty($errors)) {
        $dbw = getDbAdmin();
        $dbw->beginTransaction();

        try {
            $newPaperId = $data['tagung_nummer'] . '-' . strtolower($data['code']);

            if (!$isNew && $newPaperId !== $paperId) {
                $dbw->prepare('DELETE FROM paper_autoren WHERE paper_id = ?')->execute([$paperId]);
                $dbw->prepare('DELETE FROM papers WHERE id = ?')->execute([$paperId]);
            } elseif (!$isNew) {
                $dbw->prepare('DELETE FROM paper_autoren WHERE paper_id = ?')->execute([$newPaperId]);
            }

            if (empty($data['pdf_dateiname'])) {
                $data['pdf_dateiname'] = $data['tagung_nummer'] . '_' . strtolower($data['code']) . '.pdf';
            }

            $stmt = $dbw->prepare('
                INSERT OR REPLACE INTO papers
                (id, tagung_nummer, code, typ, titel,
                 abstract_text, zeit, raum, datum, kontakt_email,
                 pdf_dateiname, hat_pdf)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $newPaperId, $data['tagung_nummer'], $data['code'], $data['typ'],
                $data['titel'], $data['abstract_text'], $data['zeit'], $data['raum'],
                $data['datum'], $data['kontakt_email'],
                $data['pdf_dateiname'], $data['hat_pdf'],
            ]);

            // paper_autoren direkt aus autor_ids[] setzen (kein String-Parsing)
            $insAut = $dbw->prepare('INSERT INTO paper_autoren (paper_id, autor_id, position, ist_hauptautor) VALUES (?,?,?,?)');
            foreach ($autorIds as $pos => $aid) {
                $insAut->execute([$newPaperId, $aid, $pos + 1, $aid === $hauptId ? 1 : 0]);
            }

            // Autor->Affiliation aus assign[$aid][] = [institut_ids]
            $assignInput = $_POST['assign'] ?? [];
            // Bestehende nuextract-Zuordnungen erhalten
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
            $insAff = $dbw->prepare("
                INSERT OR IGNORE INTO paper_autor_institutionen
                (paper_id, autor_id, institut_id, quelle)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($assignInput as $aidStr => $iids) {
                $aid = (int)$aidStr;
                if ($aid <= 0 || !in_array($aid, $autorIds, true)) continue;
                if (!is_array($iids)) continue;
                foreach ($iids as $iid) {
                    $iid = (int)$iid;
                    if ($iid <= 0) continue;
                    $quelle = isset($protected[$aid][$iid]) ? 'nuextract' : 'manuell';
                    $insAff->execute([$newPaperId, $aid, $iid, $quelle]);
                }
            }
            // protected, die im Form nicht waren, zurueckschreiben
            foreach ($protected as $aid => $iids) {
                if (!in_array($aid, $autorIds, true)) continue;
                foreach (array_keys($iids) as $iid) {
                    $insAff->execute([$newPaperId, $aid, $iid, 'nuextract']);
                }
            }

            rebuildFtsIndex($dbw);
            $dbw->commit();

            setFlash('success', $isNew ? 'Paper angelegt.' : 'Paper aktualisiert.');
            header('Location: /admin/papers/' . $newPaperId . '/edit');
            exit;
        } catch (Throwable $e) {
            $dbw->rollBack();
            error_log('paper_edit save error: ' . $e);
            $errors[] = 'Speichern fehlgeschlagen: ' . $e->getMessage();
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

<form method="post">
    <?= csrfField() ?>

    <div class="card mb-3">
        <div class="card-header py-2"><strong>Stammdaten</strong></div>
        <div class="card-body">
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

                <div class="col-12">
                    <label for="abstract_text" class="form-label">Abstract</label>
                    <textarea class="form-control" id="abstract_text" name="abstract_text" rows="6"><?= e($data['abstract_text'] ?? $paper['abstract_text'] ?? '') ?></textarea>
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
    </div>

    <div class="card mb-3">
        <div class="card-header py-2"><strong>Autoren <span class="text-muted small">(Position = Reihenfolge im Select)</span></strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label for="paper-autoren" class="form-label">Autoren *</label>
                    <select id="paper-autoren" name="autor_ids[]" multiple required>
                        <?php foreach ($paperAutoren as $pa):
                            $aid = (int)$pa['autor_id'];
                            $label = trim(($pa['anzeige_name'] ?: ($pa['vorname'] . ' ' . $pa['nachname'])));
                        ?>
                        <option value="<?= $aid ?>" selected><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Suche nach Vor-/Nachname oder Affiliation. Drag&Drop zum Sortieren.</div>
                </div>
                <div class="col-md-4">
                    <label for="paper-hauptautor" class="form-label">Hauptautor</label>
                    <select id="paper-hauptautor" name="hauptautor_id" class="form-select">
                        <option value="">— erster Autor (Standard) —</option>
                        <?php foreach ($paperAutoren as $pa):
                            $aid = (int)$pa['autor_id'];
                            $label = trim(($pa['anzeige_name'] ?: ($pa['vorname'] . ' ' . $pa['nachname'])));
                        ?>
                        <option value="<?= $aid ?>" <?= $pa['ist_hauptautor'] ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$isNew && !empty($paperAutoren)): ?>
    <div class="card mb-3">
        <div class="card-header py-2"><strong>Affiliationen pro Autor</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr>
                            <th style="width: 25%">Autor</th>
                            <th>Institute (mehrere wählbar, Suche aktivieren durch Tippen)</th>
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
                            </td>
                            <td>
                                <select name="assign[<?= $aid ?>][]" multiple class="paper-affil-select" data-autor-id="<?= $aid ?>">
                                    <?php foreach (($paperAssigns[$aid] ?? []) as $af): ?>
                                    <option value="<?= $af['institut_id'] ?>" selected>
                                        <?= e($af['name']) ?><?php if ($af['kuerzel']): ?> (<?= e($af['kuerzel']) ?>)<?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-muted small p-2 border-top">
                Beim Speichern werden Zuordnungen ausser <code>nuextract</code> als <code>manuell</code> markiert.
                Neue Institutionen kannst du <a href="/admin/institute/neu">hier anlegen</a>.
            </div>
        </div>
    </div>
    <?php elseif ($isNew): ?>
    <div class="alert alert-light border small">
        Autor↔Affiliation-Zuordnung wird verfügbar, sobald das Paper angelegt + Autoren gespeichert sind.
    </div>
    <?php endif; ?>

    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> Speichern
        </button>
        <a href="/admin/papers<?= $paper ? '?tagung=' . $paper['tagung_nummer'] : '' ?>" class="btn btn-outline-secondary">Abbrechen</a>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof TomSelect === 'undefined') {
        console.warn('TomSelect not loaded');
        return;
    }

    const autorenRender = {
        option(item, escape) {
            const sub = item.sublabel ? `<div class="text-muted small">${escape(item.sublabel)}</div>` : '';
            return `<div><strong>${escape(item.label)}</strong>${sub}</div>`;
        },
        item(item, escape) {
            return `<div>${escape(item.label)}</div>`;
        },
    };

    const tsAut = new TomSelect('#paper-autoren', {
        plugins: ['remove_button', 'drag_drop'],
        valueField: 'id',
        labelField: 'label',
        searchField: ['label', 'sublabel'],
        load(query, callback) {
            if (!query.length || query.length < 2) return callback();
            fetch('/admin/api/search/autoren?q=' + encodeURIComponent(query))
                .then(r => r.json())
                .then(callback)
                .catch(() => callback());
        },
        render: autorenRender,
        onChange: syncHauptautor,
    });

    function syncHauptautor() {
        const sel = document.getElementById('paper-hauptautor');
        if (!sel) return;
        const currentVal = sel.value;
        // Sichere DOM-API: createElement + textContent (kein innerHTML mit Fremd-Strings)
        while (sel.firstChild) sel.removeChild(sel.firstChild);
        const defaultOpt = document.createElement('option');
        defaultOpt.value = '';
        defaultOpt.textContent = '— erster Autor (Standard) —';
        sel.appendChild(defaultOpt);
        tsAut.items.forEach(id => {
            const opt = tsAut.options[id];
            const label = opt && opt.label ? String(opt.label) : String(id);
            const el = document.createElement('option');
            el.value = String(id);
            el.textContent = label;
            if (currentVal == id) el.selected = true;
            sel.appendChild(el);
        });
    }

    // Affil-Tom-Selects pro Autor
    document.querySelectorAll('.paper-affil-select').forEach(sel => {
        new TomSelect(sel, {
            plugins: ['remove_button'],
            valueField: 'id',
            labelField: 'label',
            searchField: ['label', 'sublabel'],
            load(query, callback) {
                if (!query.length || query.length < 2) return callback();
                fetch('/admin/api/search/institute?q=' + encodeURIComponent(query))
                    .then(r => r.json())
                    .then(callback)
                    .catch(() => callback());
            },
            render: autorenRender,
        });
    });
});
</script>

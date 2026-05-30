<?php
$autorId = (int)($params['id'] ?? 0);
$adminPageTitle = 'Autor bearbeiten';

$db = getDb();

$autorStmt = $db->prepare('SELECT * FROM autoren WHERE id = ?');
$autorStmt->execute([$autorId]);
$autor = $autorStmt->fetch();

if (!$autor) {
    setFlash('danger', 'Autor nicht gefunden.');
    header('Location: /admin/autoren');
    exit;
}

$errors = [];

// POST-Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'save_basics';
    $dbw = getDbAdmin();

    try {
        if ($action === 'save_basics') {
            $vorname  = trim($_POST['vorname'] ?? '');
            $nachname = trim($_POST['nachname'] ?? '');
            $anzeige  = trim($_POST['anzeige_name'] ?? '');
            $orcid    = trim($_POST['orcid_id'] ?? '');

            if ($nachname === '') {
                $errors[] = 'Nachname erforderlich.';
            } else {
                $dbw->prepare('UPDATE autoren SET vorname=?, nachname=?, anzeige_name=?, orcid_id=? WHERE id=?')
                    ->execute([$vorname, $nachname, $anzeige ?: null, $orcid ?: null, $autorId]);
                setFlash('success', 'Stammdaten gespeichert.');
                header("Location: /admin/autoren/$autorId/edit");
                exit;
            }
        } elseif ($action === 'add_alias') {
            $aliasText = trim($_POST['alias_text'] ?? '');
            if ($aliasText === '') {
                $errors[] = 'Alias-Text leer.';
            } else {
                $norm = function_exists('normalizeForAliasMatch')
                    ? normalizeForAliasMatch($aliasText)
                    : mb_strtolower($aliasText);
                $dbw->prepare('INSERT OR IGNORE INTO autor_aliase (autor_id, alias_text, alias_norm) VALUES (?, ?, ?)')
                    ->execute([$autorId, $aliasText, $norm]);
                setFlash('success', 'Alias hinzugefuegt.');
                header("Location: /admin/autoren/$autorId/edit");
                exit;
            }
        } elseif ($action === 'delete_alias') {
            $aliasId = (int)($_POST['alias_id'] ?? 0);
            $dbw->prepare('DELETE FROM autor_aliase WHERE id=? AND autor_id=?')
                ->execute([$aliasId, $autorId]);
            setFlash('success', 'Alias entfernt.');
            header("Location: /admin/autoren/$autorId/edit");
            exit;
        } elseif ($action === 'add_affil') {
            $institutId = (int)($_POST['institut_id'] ?? 0);
            $aktuell    = isset($_POST['ist_aktuell']) ? 1 : 0;
            if ($institutId <= 0) {
                $errors[] = 'Bitte Institution waehlen (ID).';
            } else {
                $exists = $dbw->prepare('SELECT 1 FROM institutionen WHERE id=?');
                $exists->execute([$institutId]);
                if (!$exists->fetchColumn()) {
                    $errors[] = "Institution #$institutId existiert nicht.";
                } else {
                    $dbw->prepare('INSERT OR REPLACE INTO autor_institutionen (autor_id, institut_id, ist_aktuell) VALUES (?, ?, ?)')
                        ->execute([$autorId, $institutId, $aktuell]);
                    setFlash('success', 'Affiliation hinzugefuegt.');
                    header("Location: /admin/autoren/$autorId/edit");
                    exit;
                }
            }
        } elseif ($action === 'delete_affil') {
            $institutId = (int)($_POST['institut_id'] ?? 0);
            $dbw->prepare('DELETE FROM autor_institutionen WHERE autor_id=? AND institut_id=?')
                ->execute([$autorId, $institutId]);
            setFlash('success', 'Affiliation entfernt.');
            header("Location: /admin/autoren/$autorId/edit");
            exit;
        } elseif ($action === 'toggle_aktuell') {
            $institutId = (int)($_POST['institut_id'] ?? 0);
            $dbw->prepare('UPDATE autor_institutionen SET ist_aktuell = 1 - ist_aktuell WHERE autor_id=? AND institut_id=?')
                ->execute([$autorId, $institutId]);
            header("Location: /admin/autoren/$autorId/edit");
            exit;
        }
    } catch (Throwable $e) {
        error_log('autor_edit save: ' . $e);
        $errors[] = 'Fehler: ' . $e->getMessage();
    }
}

// Aliase laden
$aliase = $db->prepare('SELECT id, alias_text, alias_norm FROM autor_aliase WHERE autor_id = ? ORDER BY alias_text COLLATE NOCASE');
$aliase->execute([$autorId]);
$aliase = $aliase->fetchAll();

// Affiliations (autor_institutionen)
$affils = $db->prepare('
    SELECT ai.institut_id, ai.ist_aktuell,
           i.name_de, i.kuerzel, i.ort, i.land,
           (SELECT COUNT(DISTINCT pai.paper_id)
              FROM paper_autor_institutionen pai
              WHERE pai.autor_id = ai.autor_id AND pai.institut_id = ai.institut_id) AS n_papers_pai
    FROM autor_institutionen ai
    JOIN institutionen i ON i.id = ai.institut_id
    WHERE ai.autor_id = ?
    ORDER BY ai.ist_aktuell DESC, n_papers_pai DESC, i.name_de COLLATE NOCASE
');
$affils->execute([$autorId]);
$affils = $affils->fetchAll();

// Pro Paper: welche Institute (paper_autor_institutionen) — read-only Anzeige
$paperAffils = $db->prepare('
    SELECT p.id, p.code, p.tagung_nummer, p.titel,
           pai.institut_id, pai.quelle,
           i.name_de
    FROM paper_autoren pa
    JOIN papers p ON p.id = pa.paper_id
    LEFT JOIN paper_autor_institutionen pai ON pai.paper_id = pa.paper_id AND pai.autor_id = pa.autor_id
    LEFT JOIN institutionen i ON i.id = pai.institut_id
    WHERE pa.autor_id = ?
    ORDER BY p.tagung_nummer DESC, p.code, i.name_de COLLATE NOCASE
');
$paperAffils->execute([$autorId]);
$rows = $paperAffils->fetchAll();
$papersGrouped = [];
foreach ($rows as $r) {
    $pid = $r['id'];
    if (!isset($papersGrouped[$pid])) {
        $papersGrouped[$pid] = [
            'id' => $pid, 'code' => $r['code'], 'tagung' => $r['tagung_nummer'],
            'titel' => $r['titel'], 'affils' => [],
        ];
    }
    if ($r['institut_id']) {
        $papersGrouped[$pid]['affils'][] = ['name' => $r['name_de'], 'quelle' => $r['quelle']];
    }
}
$paperCount = count($papersGrouped);
?>

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb small mb-1">
            <li class="breadcrumb-item"><a href="/admin/autoren">Autoren</a></li>
            <li class="breadcrumb-item active">#<?= $autorId ?></li>
        </ol></nav>
        <h1 class="mb-0"><?= e(trim(($autor['vorname'] ?? '') . ' ' . $autor['nachname'])) ?></h1>
        <div class="text-muted small mt-1">
            <?= $paperCount ?> Paper<?= $paperCount === 1 ? '' : 's' ?>
            &middot; ID <?= $autorId ?>
            <?php if ($autor['orcid_id']): ?> &middot; ORCID <code><?= e($autor['orcid_id']) ?></code><?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="/autor/<?= $autorId ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-box-arrow-up-right"></i> Frontend
        </a>
        <a href="/admin/autoren/<?= $autorId ?>/delete" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-trash"></i> Loeschen
        </a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-3">
    <!-- Stammdaten -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header py-2"><strong>Stammdaten</strong></div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_basics">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small">Vorname / Initialen</label>
                            <input type="text" class="form-control form-control-sm" name="vorname"
                                   value="<?= e($autor['vorname']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Nachname *</label>
                            <input type="text" class="form-control form-control-sm" name="nachname"
                                   value="<?= e($autor['nachname']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Anzeige-Name (optional)</label>
                            <input type="text" class="form-control form-control-sm" name="anzeige_name"
                                   value="<?= e($autor['anzeige_name'] ?? '') ?>" placeholder="leer = Vorname Nachname">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">ORCID iD</label>
                            <input type="text" class="form-control form-control-sm" name="orcid_id"
                                   value="<?= e($autor['orcid_id'] ?? '') ?>" placeholder="0000-0002-XXXX-XXXX">
                        </div>
                    </div>
                    <div class="mt-3"><button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Speichern</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Aliase -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <strong>Aliase</strong>
                <span class="text-muted small"><?= count($aliase) ?> Eintrag/Eintraege</span>
            </div>
            <div class="card-body">
                <?php if (empty($aliase)): ?>
                <div class="text-muted small mb-2">Noch keine Aliase. Aliase decken Schreibvarianten (z.B. "Pruss" / "Pruß").</div>
                <?php else: ?>
                <ul class="list-group list-group-flush mb-2 small">
                    <?php foreach ($aliase as $al): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center py-1 px-2">
                        <span>
                            <?= e($al['alias_text']) ?>
                            <code class="text-muted small ms-2" style="font-size:0.7rem"><?= e($al['alias_norm']) ?></code>
                        </span>
                        <form method="post" class="d-inline" onsubmit="return confirm('Alias loeschen?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_alias">
                            <input type="hidden" name="alias_id" value="<?= (int)$al['id'] ?>">
                            <button type="submit" class="btn btn-link btn-sm text-danger p-0"><i class="bi bi-x-lg"></i></button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <form method="post" class="d-flex gap-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_alias">
                    <input type="text" class="form-control form-control-sm" name="alias_text"
                           placeholder="z.B. T. Pruss" required>
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> Hinzufuegen</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Affiliationen -->
    <div class="col-12">
        <div class="card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <strong>Affiliationen (autor_institutionen)</strong>
                <span class="text-muted small"><?= count($affils) ?> verknuepft</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($affils)): ?>
                <div class="p-3 text-muted small">Keine Affiliations verknuepft. Fuege unten eine Institution per ID hinzu (oder ueber das Frontend-OCR).</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Institut</th>
                                <th>Kuerzel</th>
                                <th>Ort</th>
                                <th>Land</th>
                                <th class="text-center">aktuell?</th>
                                <th class="text-end">Papers</th>
                                <th class="text-end">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($affils as $af): ?>
                            <tr>
                                <td><code><?= (int)$af['institut_id'] ?></code></td>
                                <td>
                                    <a href="/admin/institute/<?= (int)$af['institut_id'] ?>/edit" class="text-decoration-none">
                                        <?= e($af['name_de']) ?>
                                    </a>
                                </td>
                                <td><?= e($af['kuerzel'] ?? '') ?></td>
                                <td class="small text-muted"><?= e($af['ort'] ?? '') ?></td>
                                <td class="small text-muted"><?= e($af['land'] ?? '') ?></td>
                                <td class="text-center">
                                    <form method="post" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle_aktuell">
                                        <input type="hidden" name="institut_id" value="<?= (int)$af['institut_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-link p-0" title="aktuell umschalten">
                                            <?= $af['ist_aktuell'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-circle text-muted"></i>' ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="text-end small"><?= (int)$af['n_papers_pai'] ?></td>
                                <td class="text-end">
                                    <form method="post" class="d-inline" onsubmit="return confirm('Verknuepfung loesen?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_affil">
                                        <input type="hidden" name="institut_id" value="<?= (int)$af['institut_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-link p-0 text-danger" title="Verknuepfung loesen"><i class="bi bi-x-lg"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <div class="border-top p-2">
                    <form method="post" class="d-flex gap-2 align-items-center">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="add_affil">
                        <input type="number" class="form-control form-control-sm" name="institut_id"
                               placeholder="Institut-ID" required style="max-width: 130px;">
                        <div class="form-check form-check-inline mb-0">
                            <input class="form-check-input" type="checkbox" id="ist_akt_new" name="ist_aktuell" value="1" checked>
                            <label class="form-check-label small" for="ist_akt_new">aktuell</label>
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> Verknuepfen</button>
                        <span class="text-muted small ms-2">ID findest du ueber den Institut-Link in einer anderen Zeile oder ueber Paper-Affils.</span>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Pro Paper -->
    <div class="col-12">
        <div class="card">
            <div class="card-header py-2"><strong>Pro Paper: zugeordnete Institute (paper_autor_institutionen)</strong></div>
            <div class="card-body p-0">
                <?php if (empty($papersGrouped)): ?>
                <div class="p-3 text-muted small">Keine Papers verknuepft.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Tag.</th>
                                <th>Code</th>
                                <th>Titel</th>
                                <th>Zugeordnete Institute</th>
                                <th class="text-end">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($papersGrouped as $p): ?>
                            <tr>
                                <td class="text-muted small"><?= e($p['tagung']) ?></td>
                                <td><strong><?= e($p['code']) ?></strong></td>
                                <td title="<?= e($p['titel']) ?>"><?= e(mb_strimwidth($p['titel'], 0, 70, '…')) ?></td>
                                <td class="small">
                                    <?php if (empty($p['affils'])): ?>
                                    <span class="text-muted">keine</span>
                                    <?php else: foreach ($p['affils'] as $af): ?>
                                        <span class="badge bg-light text-dark border me-1" style="font-weight:400">
                                            <?= e($af['name']) ?>
                                            <?php if ($af['quelle']): ?><code class="text-muted ms-1" style="font-size:0.65rem"><?= e($af['quelle']) ?></code><?php endif; ?>
                                        </span>
                                    <?php endforeach; endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="/admin/papers/<?= e($p['id']) ?>/affils" class="btn btn-sm btn-outline-info" title="Editor">
                                        <i class="bi bi-diagram-3"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

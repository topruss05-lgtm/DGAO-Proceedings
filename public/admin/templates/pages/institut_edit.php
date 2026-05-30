<?php
$institutId = (int)($params['id'] ?? 0);
$isNew = $institutId === 0;
$adminPageTitle = $isNew ? 'Neue Institution' : 'Institut bearbeiten';

$db = getDb();
if ($isNew) {
    $inst = [
        'id' => 0, 'name_de' => '', 'name_en' => '', 'kuerzel' => null,
        'universitaet' => null, 'ort' => null, 'land' => null, 'ror_id' => null,
        'wikidata_id' => null, 'homepage_url' => null, 'typ' => null, 'parent_id' => null,
    ];
} else {
    $inst = $db->prepare('SELECT * FROM institutionen WHERE id = ?');
    $inst->execute([$institutId]);
    $inst = $inst->fetch();
    if (!$inst) {
        setFlash('danger', 'Institution nicht gefunden.');
        header('Location: /admin/institute');
        exit;
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'save_basics';
    $dbw = getDbAdmin();

    try {
        if ($action === 'save_basics') {
            $nameDe   = trim($_POST['name_de'] ?? '');
            $nameEn   = trim($_POST['name_en'] ?? '');
            $kuerzel  = trim($_POST['kuerzel'] ?? '');
            $uni      = trim($_POST['universitaet'] ?? '');
            $ort      = trim($_POST['ort'] ?? '');
            $land     = trim($_POST['land'] ?? '');
            $ror      = trim($_POST['ror_id'] ?? '');
            $wiki     = trim($_POST['wikidata_id'] ?? '');
            $hp       = trim($_POST['homepage_url'] ?? '');
            $typ      = trim($_POST['typ'] ?? '');
            $parentId = (int)($_POST['parent_id'] ?? 0);

            if ($nameDe === '') {
                $errors[] = 'Name (DE) erforderlich.';
            } elseif (!$isNew && $parentId === $institutId) {
                $errors[] = 'parent_id darf nicht auf das Institut selbst zeigen.';
            } else {
                if ($isNew) {
                    $dbw->prepare('
                        INSERT INTO institutionen
                        (name_de, name_en, kuerzel, universitaet, ort, land, ror_id, wikidata_id, homepage_url, typ, parent_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ')->execute([
                        $nameDe, $nameEn, $kuerzel ?: null, $uni ?: null, $ort ?: null,
                        $land ?: null, $ror ?: null, $wiki ?: null, $hp ?: null,
                        $typ ?: null, $parentId ?: null,
                    ]);
                    $newId = (int)$dbw->lastInsertId();
                    // Hauptname als ersten Alias
                    $norm = function_exists('normalizeForAliasMatch')
                        ? normalizeForAliasMatch($nameDe)
                        : mb_strtolower($nameDe);
                    $dbw->prepare('INSERT OR IGNORE INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)')
                        ->execute([$newId, $nameDe, $norm]);
                    setFlash('success', 'Institution angelegt.');
                    header("Location: /admin/institute/$newId/edit");
                    exit;
                }
                $dbw->prepare('
                    UPDATE institutionen
                    SET name_de=?, name_en=?, kuerzel=?, universitaet=?, ort=?, land=?,
                        ror_id=?, wikidata_id=?, homepage_url=?, typ=?, parent_id=?
                    WHERE id=?
                ')->execute([
                    $nameDe, $nameEn, $kuerzel ?: null, $uni ?: null, $ort ?: null,
                    $land ?: null, $ror ?: null, $wiki ?: null, $hp ?: null,
                    $typ ?: null, $parentId ?: null, $institutId,
                ]);
                setFlash('success', 'Stammdaten gespeichert.');
                header("Location: /admin/institute/$institutId/edit");
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
                $dbw->prepare('INSERT OR IGNORE INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?, ?, ?)')
                    ->execute([$institutId, $aliasText, $norm]);
                setFlash('success', 'Alias hinzugefuegt.');
                header("Location: /admin/institute/$institutId/edit");
                exit;
            }
        } elseif ($action === 'delete_alias') {
            $aliasId = (int)($_POST['alias_id'] ?? 0);
            $dbw->prepare('DELETE FROM institut_aliase WHERE id=? AND institut_id=?')
                ->execute([$aliasId, $institutId]);
            setFlash('success', 'Alias entfernt.');
            header("Location: /admin/institute/$institutId/edit");
            exit;
        } elseif ($action === 'merge_into') {
            $targetId = (int)($_POST['target_id'] ?? 0);
            if ($targetId <= 0 || $targetId === $institutId) {
                $errors[] = 'Ziel-ID ungueltig.';
            } else {
                $tgt = $dbw->prepare('SELECT id, name_de FROM institutionen WHERE id=?');
                $tgt->execute([$targetId]);
                $target = $tgt->fetch();
                if (!$target) {
                    $errors[] = "Ziel-Institution #$targetId existiert nicht.";
                } else {
                    $dbw->beginTransaction();
                    try {
                        // 1. paper_autor_institutionen: jeder Eintrag mit alter ID -> Ziel
                        $dbw->prepare('
                            INSERT OR IGNORE INTO paper_autor_institutionen (paper_id, autor_id, institut_id, quelle, created_at)
                            SELECT paper_id, autor_id, ?, quelle, created_at
                            FROM paper_autor_institutionen WHERE institut_id=?
                        ')->execute([$targetId, $institutId]);
                        $dbw->prepare('DELETE FROM paper_autor_institutionen WHERE institut_id=?')
                            ->execute([$institutId]);

                        // 2. Aliase uebertragen (inkl. eigener Hauptname als Alias)
                        $dbw->prepare('
                            INSERT OR IGNORE INTO institut_aliase (institut_id, alias_text, alias_norm)
                            SELECT ?, alias_text, alias_norm FROM institut_aliase WHERE institut_id=?
                        ')->execute([$targetId, $institutId]);
                        $oldName = (string)$inst['name_de'];
                        $oldNorm = function_exists('normalizeForAliasMatch')
                            ? normalizeForAliasMatch($oldName)
                            : mb_strtolower($oldName);
                        $dbw->prepare('INSERT OR IGNORE INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)')
                            ->execute([$targetId, $oldName, $oldNorm]);
                        $dbw->prepare('DELETE FROM institut_aliase WHERE institut_id=?')
                            ->execute([$institutId]);

                        // 3. Parent-Referenzen: andere Institute, die als parent das alte Institut hatten
                        $dbw->prepare('UPDATE institutionen SET parent_id=? WHERE parent_id=?')
                            ->execute([$targetId, $institutId]);

                        // 4. Altes Institut loeschen
                        $dbw->prepare('DELETE FROM institutionen WHERE id=?')->execute([$institutId]);

                        $dbw->commit();
                        setFlash('success', "Institut #$institutId in #$targetId (" . $target['name_de'] . ") gemerged.");
                        header("Location: /admin/institute/$targetId/edit");
                        exit;
                    } catch (Throwable $e) {
                        $dbw->rollBack();
                        throw $e;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('institut_edit: ' . $e);
        $errors[] = 'Fehler: ' . $e->getMessage();
    }
}

// Detail-Daten nur bei bestehendem Institut
$aliase = $autoren = $papers = $subs = [];
$nAutoren = $nPapers = 0;
$parent = null;
if (!$isNew) {
$aliase = $db->prepare('SELECT id, alias_text, alias_norm FROM institut_aliase WHERE institut_id = ? ORDER BY alias_text COLLATE NOCASE');
$aliase->execute([$institutId]);
$aliase = $aliase->fetchAll();

// Statistik
$nAutoren = (int)$db->query('SELECT COUNT(DISTINCT autor_id) FROM paper_autor_institutionen WHERE institut_id = ' . $institutId)->fetchColumn();
$nPapers  = (int)$db->query('SELECT COUNT(DISTINCT paper_id) FROM paper_autor_institutionen WHERE institut_id = ' . $institutId)->fetchColumn();

// Verknuepfte Autoren (Top 30)
$autoren = $db->prepare('
    SELECT a.id, a.vorname, a.nachname,
           (SELECT COUNT(DISTINCT pai2.paper_id) FROM paper_autor_institutionen pai2
             WHERE pai2.autor_id = a.id AND pai2.institut_id = ?) AS n_papers_inst
    FROM (SELECT DISTINCT autor_id FROM paper_autor_institutionen WHERE institut_id = ?) ai
    JOIN autoren a ON a.id = ai.autor_id
    ORDER BY n_papers_inst DESC, a.nachname COLLATE NOCASE
    LIMIT 30
');
$autoren->execute([$institutId, $institutId]);
$autoren = $autoren->fetchAll();

// Verknuepfte Papers (Top 30)
$papers = $db->prepare('
    SELECT DISTINCT p.id, p.code, p.tagung_nummer, p.titel
    FROM paper_autor_institutionen pai
    JOIN papers p ON p.id = pai.paper_id
    WHERE pai.institut_id = ?
    ORDER BY p.tagung_nummer DESC, p.code
    LIMIT 30
');
$papers->execute([$institutId]);
$papers = $papers->fetchAll();

// Parent-Institut + Sub-Institute
$parent = null;
if ($inst['parent_id']) {
    $p = $db->prepare('SELECT id, name_de FROM institutionen WHERE id=?');
    $p->execute([$inst['parent_id']]);
    $parent = $p->fetch();
}
$subs = $db->prepare('SELECT id, name_de FROM institutionen WHERE parent_id = ? ORDER BY name_de COLLATE NOCASE');
$subs->execute([$institutId]);
$subs = $subs->fetchAll();
}
?>

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb small mb-1">
            <li class="breadcrumb-item"><a href="/admin/institute">Affiliationen</a></li>
            <li class="breadcrumb-item active"><?= $isNew ? 'Neu' : '#' . $institutId ?></li>
        </ol></nav>
        <h1 class="mb-0"><?= $isNew ? 'Neue Institution' : e($inst['name_de']) ?></h1>
        <?php if (!$isNew): ?>
        <div class="text-muted small mt-1">
            ID <?= $institutId ?>
            &middot; <?= $nAutoren ?> Autor<?= $nAutoren === 1 ? '' : 'en' ?>
            &middot; <?= $nPapers ?> Paper<?= $nPapers === 1 ? '' : 's' ?>
            <?php if ($inst['kuerzel']): ?> &middot; <code><?= e($inst['kuerzel']) ?></code><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-3">
    <!-- Stammdaten -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header py-2"><strong>Stammdaten</strong></div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_basics">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small">Name (DE) *</label>
                            <input type="text" class="form-control form-control-sm" name="name_de"
                                   value="<?= e($inst['name_de']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Name (EN)</label>
                            <input type="text" class="form-control form-control-sm" name="name_en"
                                   value="<?= e($inst['name_en'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Kuerzel</label>
                            <input type="text" class="form-control form-control-sm" name="kuerzel"
                                   value="<?= e($inst['kuerzel'] ?? '') ?>" placeholder="z.B. ITO">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small">Universitaet</label>
                            <input type="text" class="form-control form-control-sm" name="universitaet"
                                   value="<?= e($inst['universitaet'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Ort</label>
                            <input type="text" class="form-control form-control-sm" name="ort"
                                   value="<?= e($inst['ort'] ?? '') ?>">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label small">Land</label>
                            <input type="text" class="form-control form-control-sm" name="land"
                                   value="<?= e($inst['land'] ?? '') ?>" maxlength="3">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Typ</label>
                            <input type="text" class="form-control form-control-sm" name="typ"
                                   value="<?= e($inst['typ'] ?? '') ?>" placeholder="z.B. universitaet, fraunhofer, mpi, firma">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Parent-Institut (parent_id)</label>
                            <input type="number" class="form-control form-control-sm" name="parent_id"
                                   value="<?= e($inst['parent_id'] ?? '') ?>" placeholder="z.B. 17 fuer Uni Stuttgart">
                            <?php if ($parent): ?>
                            <div class="form-text small">→ <a href="/admin/institute/<?= (int)$parent['id'] ?>/edit"><?= e($parent['name_de']) ?></a></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Homepage URL</label>
                            <input type="url" class="form-control form-control-sm" name="homepage_url"
                                   value="<?= e($inst['homepage_url'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">ROR-ID</label>
                            <input type="text" class="form-control form-control-sm" name="ror_id"
                                   value="<?= e($inst['ror_id'] ?? '') ?>" placeholder="z.B. 04vnq7t77">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Wikidata-ID</label>
                            <input type="text" class="form-control form-control-sm" name="wikidata_id"
                                   value="<?= e($inst['wikidata_id'] ?? '') ?>" placeholder="z.B. Q123456">
                        </div>
                    </div>
                    <div class="mt-3"><button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Speichern</button></div>
                </form>
            </div>
        </div>
    </div>

    <?php if (!$isNew): ?>
    <!-- Sub-Institute + Merge -->
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header py-2"><strong>Sub-Institute</strong></div>
            <div class="card-body p-2">
                <?php if (empty($subs)): ?>
                <div class="text-muted small">Keine Sub-Institute. Andere Institute koennen ueber parent_id auf dieses zeigen.</div>
                <?php else: ?>
                <ul class="list-group list-group-flush small mb-0">
                    <?php foreach ($subs as $s): ?>
                    <li class="list-group-item py-1 px-2">
                        <a href="/admin/institute/<?= (int)$s['id'] ?>/edit" class="text-decoration-none"><?= e($s['name_de']) ?></a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-warning">
            <div class="card-header py-2"><strong>In anderes Institut mergen</strong></div>
            <div class="card-body">
                <form method="post" onsubmit="return confirm('Wirklich Institut #<?= $institutId ?> in das Ziel-Institut mergen? Alle Verknuepfungen wandern um. Diese Operation ist nicht rueckgaengig.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="merge_into">
                    <label class="form-label small">Ziel-Institut-ID</label>
                    <input type="number" name="target_id" class="form-control form-control-sm mb-2" required placeholder="z.B. 17">
                    <button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-intersect"></i> Mergen</button>
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
                <div class="text-muted small mb-2">Noch keine Aliase. Aliase erkennen Schreibvarianten (z.B. "ITO" / "Institut fuer Technische Optik").</div>
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
                    <input type="text" class="form-control form-control-sm" name="alias_text" placeholder="z.B. ITO Stuttgart" required>
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> Hinzufuegen</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Verknuepfte Autoren -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <strong>Verknuepfte Autoren</strong>
                <span class="text-muted small">Top 30 von <?= $nAutoren ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($autoren)): ?>
                <div class="p-3 text-muted small">Keine Autoren verknuepft.</div>
                <?php else: ?>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($autoren as $a): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center py-1 px-2">
                        <a href="/admin/autoren/<?= (int)$a['id'] ?>/edit" class="text-decoration-none">
                            <strong><?= e($a['nachname']) ?></strong><?= $a['vorname'] ? ', ' . e($a['vorname']) : '' ?>
                        </a>
                        <span class="text-muted small"><?= (int)$a['n_papers_inst'] ?> Papers</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Verknuepfte Papers -->
    <div class="col-12">
        <div class="card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <strong>Verknuepfte Papers (paper_autor_institutionen)</strong>
                <span class="text-muted small">Top 30 von <?= $nPapers ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($papers)): ?>
                <div class="p-3 text-muted small">Keine Papers verknuepft.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead>
                            <tr><th>Tag.</th><th>Code</th><th>Titel</th><th class="text-end">Aktion</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($papers as $p): ?>
                            <tr>
                                <td class="text-muted small"><?= e($p['tagung_nummer']) ?></td>
                                <td><strong><?= e($p['code']) ?></strong></td>
                                <td title="<?= e($p['titel']) ?>"><?= e(mb_strimwidth($p['titel'], 0, 80, '…')) ?></td>
                                <td class="text-end">
                                    <a href="/admin/papers/<?= e($p['id']) ?>/edit" class="btn btn-sm btn-outline-primary" title="Paper-Edit (inkl. Affil-Zuordnung)"><i class="bi bi-pencil"></i></a>
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
    <?php endif; ?>
</div>

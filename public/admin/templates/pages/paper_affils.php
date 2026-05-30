<?php
$paperId = $params['id'] ?? null;
$adminPageTitle = 'Autor-Affiliation-Zuordnung';

$db = getDb();
$paper = $db->prepare('SELECT id, code, tagung_nummer, titel, autoren_text FROM papers WHERE id = ?');
$paper->execute([$paperId]);
$paper = $paper->fetch();

if (!$paper) {
    setFlash('danger', 'Paper nicht gefunden.');
    header('Location: /admin/papers');
    exit;
}

// Autoren des Papers in Position-Reihenfolge
$autoren = $db->prepare('
    SELECT pa.position, pa.autor_id, pa.ist_hauptautor,
           a.vorname, a.nachname
    FROM paper_autoren pa
    JOIN autoren a ON a.id = pa.autor_id
    WHERE pa.paper_id = ?
    ORDER BY pa.position
');
$autoren->execute([$paperId]);
$autoren = $autoren->fetchAll();

// Alle Institute, die mit diesem Paper bereits verknuepft sind (egal welcher Autor)
$paperInsts = $db->prepare('
    SELECT DISTINCT pai.institut_id, i.name_de, i.kuerzel
    FROM paper_autor_institutionen pai
    JOIN institutionen i ON i.id = pai.institut_id
    WHERE pai.paper_id = ?
    ORDER BY i.name_de COLLATE NOCASE
');
$paperInsts->execute([$paperId]);
$paperInsts = $paperInsts->fetchAll();

// Bestehende Zuordnungen (autor_id -> [institut_id => quelle])
$assigns = $db->prepare('SELECT autor_id, institut_id, quelle FROM paper_autor_institutionen WHERE paper_id = ?');
$assigns->execute([$paperId]);
$current = [];
foreach ($assigns->fetchAll() as $r) {
    $current[(int)$r['autor_id']][(int)$r['institut_id']] = $r['quelle'];
}

// POST: Save
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $dbw = getDbAdmin();
    $dbw->beginTransaction();
    try {
        // Bestehende loeschen, durch manuell ersetzen
        $dbw->prepare('DELETE FROM paper_autor_institutionen WHERE paper_id = ?')->execute([$paperId]);

        $ins = $dbw->prepare('
            INSERT OR IGNORE INTO paper_autor_institutionen (paper_id, autor_id, institut_id, quelle)
            VALUES (?, ?, ?, ?)
        ');
        $assignInput = $_POST['assign'] ?? [];
        $kept = 0;
        foreach ($autoren as $a) {
            $aid = (int)$a['autor_id'];
            $iids = $assignInput[$aid] ?? [];
            if (!is_array($iids)) continue;
            foreach ($iids as $iid) {
                $iid = (int)$iid;
                if ($iid <= 0) continue;
                $quelle = $current[$aid][$iid] ?? 'manuell';
                // Wenn der Eintrag schon da war und nicht-nuextract: 'manuell' setzen
                if ($quelle !== 'nuextract') $quelle = 'manuell';
                $ins->execute([$paperId, $aid, $iid, $quelle]);
                $kept++;
            }
        }

        $dbw->commit();
        setFlash('success', "Zuordnung gespeichert ($kept Einträge).");
        header('Location: /admin/papers/' . $paperId . '/affils');
        exit;
    } catch (Throwable $e) {
        $dbw->rollBack();
        error_log('paper_affils save: ' . $e);
        $errors[] = 'Speichern fehlgeschlagen: ' . $e->getMessage();
    }
}

// Quelle-Statistik (welche Quellen gibt's gerade)
$quellen = $db->prepare('
    SELECT quelle, COUNT(*) c FROM paper_autor_institutionen
    WHERE paper_id = ? GROUP BY quelle
');
$quellen->execute([$paperId]);
$quellenStats = $quellen->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-1">
                <li class="breadcrumb-item"><a href="/admin/papers">Papers</a></li>
                <li class="breadcrumb-item"><a href="/admin/papers?tagung=<?= e($paper['tagung_nummer']) ?>"><?= e($paper['tagung_nummer']) ?>. Tagung</a></li>
                <li class="breadcrumb-item active"><?= e($paper['code']) ?></li>
            </ol>
        </nav>
        <h1 class="mb-0">Autor-Affiliation-Zuordnung</h1>
        <div class="text-muted small mt-1"><strong><?= e($paper['code']) ?></strong> &middot; <?= e($paper['titel']) ?></div>
    </div>
    <div class="d-flex gap-2">
        <a href="/paper/<?= e($paper['id']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-box-arrow-up-right"></i> Frontend ansehen
        </a>
        <a href="/admin/papers/<?= e($paper['id']) ?>/edit" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-pencil"></i> Paper bearbeiten
        </a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header py-2"><strong>Pro Autor: zugeordnete Institute</strong></div>
            <div class="card-body p-0">
                <?php if (empty($autoren)): ?>
                <div class="p-3 text-muted small">Keine Autoren am Paper. Erst <a href="/admin/papers/<?= e($paper['id']) ?>/edit">Paper-Edit</a> nutzen.</div>
                <?php else: ?>
                <form method="post">
                    <?= csrfField() ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th style="width:30%">Autor</th>
                                    <th>Institutionen (auswählen)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($autoren as $a): $aid = (int)$a['autor_id']; ?>
                                <tr>
                                    <td>
                                        <a href="/admin/autoren/<?= $aid ?>/edit" class="text-decoration-none">
                                            <strong><?= e($a['nachname']) ?></strong><?= $a['vorname'] ? ', ' . e($a['vorname']) : '' ?>
                                        </a>
                                        <?php if ($a['ist_hauptautor']): ?><i class="bi bi-star-fill text-warning small ms-1" title="Hauptautor"></i><?php endif; ?>
                                        <div class="text-muted" style="font-size:0.75rem">#<?= e($a['position']) ?></div>
                                    </td>
                                    <td>
                                        <?php if (empty($paperInsts)): ?>
                                        <div class="text-muted small">Keine Institute im Paper-Pool. Erst über Autor-Edit oder Cleanup ein Institut anhaengen.</div>
                                        <?php else: foreach ($paperInsts as $pi):
                                            $iid = (int)$pi['institut_id'];
                                            $checked = isset($current[$aid][$iid]);
                                            $quelle = $current[$aid][$iid] ?? null;
                                        ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   name="assign[<?= $aid ?>][]" value="<?= $iid ?>"
                                                   id="ass_<?= $aid ?>_<?= $iid ?>"
                                                   <?= $checked ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="ass_<?= $aid ?>_<?= $iid ?>">
                                                <?= e($pi['name_de']) ?>
                                                <?php if ($pi['kuerzel']): ?><span class="text-muted small">(<?= e($pi['kuerzel']) ?>)</span><?php endif; ?>
                                                <?php if ($quelle): ?>
                                                <span class="badge bg-light text-muted ms-1" style="font-weight:400"><?= e($quelle) ?></span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                        <?php endforeach; endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 border-top d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Speichern</button>
                        <a href="/admin/papers?tagung=<?= e($paper['tagung_nummer']) ?>" class="btn btn-outline-secondary btn-sm">Abbrechen</a>
                        <div class="text-muted small align-self-center ms-2">Beim Speichern werden bestehende Quellen ausser <code>nuextract</code> als <code>manuell</code> markiert.</div>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header py-2"><strong>Institute am Paper</strong></div>
            <div class="card-body p-0">
                <?php if (empty($paperInsts)): ?>
                <div class="p-3 text-muted small">Noch keine Institute. Hinzufuegen erfolgt aktuell ueber Autor-Edit (autor_institutionen) oder die Cleanup-Pipeline.</div>
                <?php else: ?>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($paperInsts as $pi): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center py-1">
                        <a href="/admin/institute/<?= (int)$pi['institut_id'] ?>/edit" class="text-decoration-none">
                            <?= e($pi['name_de']) ?>
                            <?php if ($pi['kuerzel']): ?><span class="text-muted">(<?= e($pi['kuerzel']) ?>)</span><?php endif; ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header py-2"><strong>Quellen-Verteilung</strong></div>
            <div class="card-body p-2">
                <?php if (empty($quellenStats)): ?>
                <div class="text-muted small">Noch keine Zuordnungen.</div>
                <?php else: ?>
                <table class="table table-sm small mb-0">
                    <?php foreach ($quellenStats as $q): ?>
                    <tr>
                        <td><code><?= e($q['quelle'] ?? '(null)') ?></code></td>
                        <td class="text-end"><?= e($q['c']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <div class="text-muted small mt-2" style="font-size:0.75rem">
                    Priorität: <code>nuextract</code> &gt; <code>single_affil</code>/<code>anker</code>/<code>openalex</code>/<code>orcid</code> &gt; <code>manuell</code> &gt; <code>fitz_marker</code>/<code>pdf</code>/<code>unscharf</code>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

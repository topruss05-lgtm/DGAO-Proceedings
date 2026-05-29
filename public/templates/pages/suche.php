<?php
$pageTitle    = t('suche.title') . ' - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/suche');

// --- Input-Parsing ---
$q        = trim((string)($_GET['q']        ?? ''));
$fTitel   = trim((string)($_GET['titel']    ?? ''));
$fAutor   = trim((string)($_GET['autor']    ?? ''));
$fInst    = trim((string)($_GET['institut'] ?? ''));
$fAbs     = trim((string)($_GET['abstract'] ?? ''));
$fTagung  = (int)($_GET['tagung']   ?? 0);

$sortRaw = (string)($_GET['sort'] ?? 'relevanz');
$ALLOWED_SORTS = ['relevanz', 'tagung_neu', 'tagung_alt', 'titel_az'];
$sort = in_array($sortRaw, $ALLOWED_SORTS, true) ? $sortRaw : 'relevanz';

// Hat der User irgendwas eingegeben?
$hasInput = $q !== '' || $fTitel !== '' || $fAutor !== '' || $fInst !== ''
         || $fAbs !== '' || $fTagung > 0;

$advancedOpen = $fTitel !== '' || $fAutor !== '' || $fInst !== '' || $fAbs !== ''
             || $fTagung > 0
             || $sort !== 'relevanz'
             || isset($_GET['adv']);

$results       = [];
$authorMatches = [];
$searched      = false;

if ($hasInput) {
    $searched = true;
    $db = getDb();

    $paperCodeOrderSql = "
        CASE substr(p.code, 1, 1)
            WHEN 'H' THEN 1 WHEN 'A' THEN 2 WHEN 'B' THEN 3
            WHEN 'C' THEN 4 WHEN 'P' THEN 5 WHEN 'S' THEN 6
            ELSE 9
        END
    ";

    // --- WHERE-Klauseln dynamisch ---
    $wheres = [];
    $params = [];

    // "Alle Felder"-Volltext (FTS oder LIKE) – wird unten je nach
    // Sort-Modus + Vorhandensein angewendet
    $sanitized = $q !== '' ? sanitizeFtsQuery($q) : null;
    $useFts    = $sanitized !== null && $sort === 'relevanz';

    if ($fTitel !== '') {
        $wheres[] = 'p.titel LIKE :titel COLLATE NOCASE';
        $params[':titel'] = '%' . $fTitel . '%';
    }
    if ($fAutor !== '') {
        $aNorm = normalizeForAliasMatch($fAutor);
        $wheres[] = '(
            EXISTS (SELECT 1 FROM paper_autoren pa
                    JOIN autor_aliase al ON al.autor_id = pa.autor_id
                    WHERE pa.paper_id = p.id AND al.alias_norm LIKE :anorm)
            OR p.hauptautor   LIKE :autor2 COLLATE NOCASE
            OR p.autoren_text LIKE :autor1 COLLATE NOCASE
        )';
        $likeAutor = '%' . $fAutor . '%';
        $params[':anorm']  = '%' . $aNorm . '%';
        $params[':autor1'] = $likeAutor;
        $params[':autor2'] = $likeAutor;
    }
    if ($fInst !== '') {
        $iNorm = normalizeForAliasMatch($fInst);
        $wheres[] = '(
            p.affiliationen LIKE :inst1 COLLATE NOCASE
            OR EXISTS (SELECT 1 FROM paper_autoren pa
                       JOIN autor_institutionen ai ON ai.autor_id = pa.autor_id
                       JOIN institutionen i ON i.id = ai.institut_id
                       LEFT JOIN institut_aliase ia ON ia.institut_id = i.id
                       WHERE pa.paper_id = p.id
                         AND (   i.name_de LIKE :inst2 COLLATE NOCASE
                              OR i.name_en LIKE :inst3 COLLATE NOCASE
                              OR i.kuerzel LIKE :inst4 COLLATE NOCASE
                              OR ia.alias_norm LIKE :inorm))
        )';
        $likeInst = '%' . $fInst . '%';
        $params[':inst1'] = $likeInst;
        $params[':inst2'] = $likeInst;
        $params[':inst3'] = $likeInst;
        $params[':inst4'] = $likeInst;
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

    // --- ORDER BY ---
    $orderBy = match ($sort) {
        'tagung_neu' => "ORDER BY p.tagung_nummer DESC, $paperCodeOrderSql, CAST(substr(p.code,2) AS INTEGER)",
        'tagung_alt' => "ORDER BY p.tagung_nummer ASC, $paperCodeOrderSql, CAST(substr(p.code,2) AS INTEGER)",
        'titel_az'   => "ORDER BY p.titel COLLATE NOCASE",
        default      => 'ORDER BY p.tagung_nummer DESC, p.code',
    };

    // --- Volltext-Suche ueber FTS (wenn $q da und sort=relevanz) ---
    if ($useFts && $q !== '') {
        // FTS-Pfad: papers_fts MATCH liefert primaere Treffer + Rank.
        // Die zusaetzlichen Filter werden via WHERE auf p (joined) angewendet.
        $sql = "
            SELECT p.id, p.tagung_nummer, p.code, p.typ, p.titel, p.autoren_text,
                   p.hat_pdf, p.pdf_dateiname
            FROM papers_fts fts
            JOIN papers p ON p.rowid = fts.rowid
            WHERE papers_fts MATCH :q
            " . (!empty($wheres) ? 'AND ' . implode(' AND ', $wheres) : '') . "
            ORDER BY rank
            LIMIT 100
        ";
        $params[':q'] = $sanitized;
    } else {
        // LIKE-Pfad. Wenn $q da, dann zusaetzliches OR-Filter ueber
        // titel/autor/abstract/affiliation.
        if ($q !== '') {
            $wheres[] = '(p.titel LIKE :qa COLLATE NOCASE
                       OR p.autoren_text LIKE :qb COLLATE NOCASE
                       OR p.abstract_text LIKE :qc COLLATE NOCASE
                       OR p.affiliationen LIKE :qd COLLATE NOCASE)';
            $likeQ = '%' . $q . '%';
            $params[':qa'] = $likeQ;
            $params[':qb'] = $likeQ;
            $params[':qc'] = $likeQ;
            $params[':qd'] = $likeQ;
        }
        $sql = "
            SELECT p.id, p.tagung_nummer, p.code, p.typ, p.titel, p.autoren_text,
                   p.hat_pdf, p.pdf_dateiname
            FROM papers p
            " . (!empty($wheres) ? 'WHERE ' . implode(' AND ', $wheres) : '') . "
            $orderBy
            LIMIT 100
        ";
    }

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Search query failed: ' . $e);
        $results = [];
    }

    // --- Autoren-Treffer-Sektion: nur wenn Autor- oder Allg.-Query gesetzt ---
    $authorQuery = $fAutor !== '' ? $fAutor : $q;
    if ($authorQuery !== '' && mb_strlen($authorQuery) >= 2) {
        $aNorm = normalizeForAliasMatch($authorQuery);
        $stmtA = $db->prepare("
            SELECT a.id, a.vorname, a.nachname, a.anzeige_name, COUNT(DISTINCT pa.paper_id) AS paper_count
            FROM autoren a
            JOIN paper_autoren pa ON pa.autor_id = a.id
            WHERE EXISTS (SELECT 1 FROM autor_aliase al
                          WHERE al.autor_id = a.id AND al.alias_norm LIKE :anorm)
            GROUP BY a.id
            HAVING paper_count > 0
            ORDER BY paper_count DESC, a.nachname COLLATE NOCASE
            LIMIT 10
        ");
        $stmtA->execute([':anorm' => '%' . $aNorm . '%']);
        $authorMatches = $stmtA->fetchAll();
    }
}

// Suggestions
$authorSuggest  = getTopAuthorSuggestions(200);
$affilSuggest   = getTopAffiliationSuggestions(100);
$tagungenAll    = getAllTagungenForFilter();
?>

<h1 class="h3 mb-4"><?= e(t('suche.title')) ?></h1>

<form action="/suche" method="get" class="suche-form mb-4" role="search">
    <div class="row g-2 align-items-stretch">
        <div class="col">
            <label for="suche-q" class="form-label small text-muted mb-1">
                <?= e(t('suche.field_q')) ?>
            </label>
            <div class="suche-combobox position-relative">
                <input type="text" name="q" id="suche-q"
                       class="form-control search-input pe-5"
                       value="<?= e($q) ?>"
                       role="combobox"
                       aria-expanded="false"
                       aria-autocomplete="list"
                       aria-controls="suche-q-listbox"
                       aria-activedescendant=""
                       placeholder="<?= e(t('suche.placeholder')) ?>"
                       autocomplete="off" spellcheck="false">
                <button type="button"
                        id="suche-q-clear"
                        class="suche-combobox__clear"
                        aria-label="<?= e(t('archiv_detail.filter_clear_label')) ?>"
                        hidden>
                    <i class="bi bi-x-circle-fill"></i>
                </button>
                <div id="suche-q-listbox"
                     class="suche-combobox__listbox"
                     role="listbox"
                     aria-label="<?= e(t('suche.title')) ?>"
                     hidden></div>
            </div>
        </div>
        <div class="col-auto d-flex align-items-end">
            <button type="submit" class="btn btn-accent">
                <i class="bi bi-search"></i> <?= e(t('suche.btn')) ?>
            </button>
        </div>
    </div>
    <details class="suche-advanced mt-3"<?= $advancedOpen ? ' open' : '' ?>>
        <summary class="small text-muted suche-advanced__summary">
            <i class="bi bi-sliders"></i> <?= e(t('suche.advanced_toggle')) ?>
        </summary>
        <input type="hidden" name="adv" value="1">

        <div class="row g-3 mt-1">
            <div class="col-md-6">
                <label for="suche-titel" class="form-label small text-muted mb-1">
                    <?= e(t('suche.field_titel')) ?>
                </label>
                <input type="text" name="titel" id="suche-titel"
                       class="form-control form-control-sm"
                       value="<?= e($fTitel) ?>" autocomplete="off">
            </div>
            <div class="col-md-6">
                <label for="suche-autor" class="form-label small text-muted mb-1">
                    <?= e(t('suche.field_autor')) ?>
                </label>
                <input type="text" name="autor" id="suche-autor"
                       class="form-control form-control-sm"
                       value="<?= e($fAutor) ?>"
                       list="suche-autor-suggestions" autocomplete="off">
                <datalist id="suche-autor-suggestions">
                    <?php foreach ($authorSuggest as $a): ?>
                        <option value="<?= e($a['label']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-6">
                <label for="suche-institut" class="form-label small text-muted mb-1">
                    <?= e(t('suche.field_institut')) ?>
                </label>
                <input type="text" name="institut" id="suche-institut"
                       class="form-control form-control-sm"
                       value="<?= e($fInst) ?>"
                       list="suche-institut-suggestions" autocomplete="off">
                <datalist id="suche-institut-suggestions">
                    <?php foreach ($affilSuggest as $aff): ?>
                        <option value="<?= e($aff) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-6">
                <label for="suche-abstract" class="form-label small text-muted mb-1">
                    <?= e(t('suche.field_abstract')) ?>
                </label>
                <input type="text" name="abstract" id="suche-abstract"
                       class="form-control form-control-sm"
                       value="<?= e($fAbs) ?>" autocomplete="off">
            </div>

            <div class="col-md-6">
                <label for="suche-tagung" class="form-label small text-muted mb-1">
                    <?= e(t('suche.field_tagung')) ?>
                </label>
                <select name="tagung" id="suche-tagung" class="form-select form-select-sm">
                    <option value="0"><?= e(t('suche.field_tagung_all')) ?></option>
                    <?php foreach ($tagungenAll as $tg): ?>
                        <option value="<?= $tg['nummer'] ?>" <?= $fTagung === $tg['nummer'] ? 'selected' : '' ?>>
                            <?= $tg['nummer'] ?>. (<?= $tg['jahr'] ?><?= $tg['ort'] ? ', ' . e($tg['ort']) : '' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label for="suche-sort" class="form-label small text-muted mb-1">
                    <?= e(t('suche.sort_label')) ?>
                </label>
                <select id="suche-sort" name="sort" class="form-select form-select-sm">
                    <option value="relevanz"   <?= $sort === 'relevanz'   ? 'selected' : '' ?>><?= e(t('suche.sort_relevanz')) ?></option>
                    <option value="tagung_neu" <?= $sort === 'tagung_neu' ? 'selected' : '' ?>><?= e(t('suche.sort_tagung_neu')) ?></option>
                    <option value="tagung_alt" <?= $sort === 'tagung_alt' ? 'selected' : '' ?>><?= e(t('suche.sort_tagung_alt')) ?></option>
                    <option value="titel_az"   <?= $sort === 'titel_az'   ? 'selected' : '' ?>><?= e(t('suche.sort_titel_az')) ?></option>
                </select>
            </div>

            <div class="col-12 d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-sm btn-accent">
                    <i class="bi bi-search"></i> <?= e(t('suche.btn')) ?>
                </button>
                <a href="/suche" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-counterclockwise"></i> <?= e(t('suche.reset')) ?>
                </a>
            </div>
        </div>
    </details>
</form>

<?php if ($searched): ?>

    <?php if (!empty($authorMatches)): ?>
    <section class="mb-4">
        <h2 class="h6 text-uppercase text-muted" style="letter-spacing:.08em;">
            <i class="bi bi-people"></i> <?= e(t('suche.authors_heading')) ?>
        </h2>
        <div class="suche-author-cards">
            <?php foreach ($authorMatches as $a):
                $name = formatAutorNameNachLast($a);
            ?>
            <a href="/autor/<?= (int)$a['id'] ?>" class="suche-author-card">
                <span class="suche-author-card__name"><?= e($name) ?></span>
                <span class="suche-author-card__count">
                    <?= sprintf(t('suche.authors_paper_count'), (int)$a['paper_count']) ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section>
        <h2 class="h6 text-uppercase text-muted mb-2" style="letter-spacing:.08em;">
            <i class="bi bi-file-earmark-text"></i> <?= e(t('suche.papers_heading')) ?>
            <span class="ms-2 text-muted text-lowercase fw-normal">
                · <?= count($results) ?> <?= count($results) === 1 ? t('suche.result_singular') : t('suche.result_plural') ?>
            </span>
        </h2>

        <?php if (empty($results)): ?>
            <p class="text-muted">
                <?= e(sprintf(t('suche.no_results_for'), $q !== '' ? $q : ($fTitel ?: $fAutor ?: $fInst ?: $fAbs))) ?>
                <?= e(t('suche.hint_shorter')) ?>
            </p>
        <?php else: ?>
            <div id="suche-results"
                 data-highlight="<?= e(implode(' ', array_filter([$q, $fTitel, $fAutor, $fInst, $fAbs]))) ?>">
                <?php foreach ($results as $p):
                    $showTagung = true;
                    $tagungLabel = (string)$p['tagung_nummer'];
                ?>
                    <?php require __DIR__ . '/../partials/paper_card.php'; ?>
                <?php endforeach; ?>
            </div>
            <?php if (count($results) >= 100): ?>
                <p class="small text-muted mt-2"><i class="bi bi-info-circle"></i> <?= e(t('suche.limit_hint')) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

<?php endif; ?>

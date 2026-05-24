<?php
$pageTitle    = t('suche.title') . ' - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/suche');

// --- Input-Parsing ---
$q        = trim((string)($_GET['q']        ?? ''));
$fTitel   = trim((string)($_GET['titel']    ?? ''));
$fAutor   = trim((string)($_GET['autor']    ?? ''));
$fInst    = trim((string)($_GET['institut'] ?? ''));
$fAbs     = trim((string)($_GET['abstract'] ?? ''));
$fJahrVon = (int)($_GET['jahr_von'] ?? 0);
$fJahrBis = (int)($_GET['jahr_bis'] ?? 0);
$fTagung  = (int)($_GET['tagung']   ?? 0);

$sortRaw = (string)($_GET['sort'] ?? 'relevanz');
$ALLOWED_SORTS = ['relevanz', 'tagung_neu', 'tagung_alt', 'vortragsart'];
$sort = in_array($sortRaw, $ALLOWED_SORTS, true) ? $sortRaw : 'relevanz';

$KNOWN_TYP_LETTERS = ['H', 'A', 'B', 'C', 'P', 'S'];
$OTHER_LETTERS     = ['W', 'Z'];
$typIn  = $_GET['typ'] ?? null;
if (!is_array($typIn)) $typIn = null;
$typFilter = null;
if ($typIn !== null) {
    $typFilter = [];
    foreach ($typIn as $t) {
        $t = strtoupper((string)$t);
        if (in_array($t, $KNOWN_TYP_LETTERS, true)) {
            $typFilter[] = $t;
        } elseif ($t === 'OTHER') {
            $typFilter = array_merge($typFilter, $OTHER_LETTERS);
        }
    }
    if (empty($typFilter)) $typFilter = null;
}

// Hat der User irgendwas eingegeben?
$hasInput = $q !== '' || $fTitel !== '' || $fAutor !== '' || $fInst !== ''
         || $fAbs !== '' || $fJahrVon > 0 || $fJahrBis > 0 || $fTagung > 0;

$advancedOpen = $fTitel !== '' || $fAutor !== '' || $fInst !== '' || $fAbs !== ''
             || $fJahrVon > 0 || $fJahrBis > 0 || $fTagung > 0
             || $sort !== 'relevanz' || $typFilter !== null
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
        $wheres[] = '(p.autoren_text LIKE :autor1 COLLATE NOCASE
                    OR p.hauptautor LIKE :autor2 COLLATE NOCASE
                    OR EXISTS (SELECT 1 FROM paper_autoren pa
                               JOIN autoren a ON a.id = pa.autor_id
                               WHERE pa.paper_id = p.id
                               AND (a.nachname LIKE :autor3 COLLATE NOCASE
                                 OR a.vorname  LIKE :autor4 COLLATE NOCASE)))';
        $likeAutor = '%' . $fAutor . '%';
        $params[':autor1'] = $likeAutor;
        $params[':autor2'] = $likeAutor;
        $params[':autor3'] = $likeAutor;
        $params[':autor4'] = $likeAutor;
    }
    if ($fInst !== '') {
        $wheres[] = '(p.affiliationen LIKE :inst1 COLLATE NOCASE
                    OR EXISTS (SELECT 1 FROM paper_autoren pa
                               JOIN autoren a ON a.id = pa.autor_id
                               WHERE pa.paper_id = p.id
                               AND a.affiliation LIKE :inst2 COLLATE NOCASE))';
        $likeInst = '%' . $fInst . '%';
        $params[':inst1'] = $likeInst;
        $params[':inst2'] = $likeInst;
    }
    if ($fAbs !== '') {
        $wheres[] = 'p.abstract_text LIKE :abs COLLATE NOCASE';
        $params[':abs'] = '%' . $fAbs . '%';
    }
    if ($fTagung > 0) {
        $wheres[] = 'p.tagung_nummer = :tagung';
        $params[':tagung'] = $fTagung;
    }
    if ($fJahrVon > 0 || $fJahrBis > 0) {
        // Tagungsjahr via Tagungen-JOIN
        $wheres[] = 'EXISTS (SELECT 1 FROM tagungen tg WHERE tg.nummer = p.tagung_nummer'
                  . ($fJahrVon > 0 ? ' AND tg.jahr >= :jvon' : '')
                  . ($fJahrBis > 0 ? ' AND tg.jahr <= :jbis' : '')
                  . ')';
        if ($fJahrVon > 0) $params[':jvon'] = $fJahrVon;
        if ($fJahrBis > 0) $params[':jbis'] = $fJahrBis;
    }
    if ($typFilter !== null) {
        $typPlaceholders = [];
        foreach (array_values($typFilter) as $i => $code) {
            $key = ':typ' . $i;
            $typPlaceholders[] = $key;
            $params[$key] = $code;
        }
        $wheres[] = 'substr(p.code, 1, 1) IN (' . implode(',', $typPlaceholders) . ')';
    }

    // --- ORDER BY ---
    $orderBy = match ($sort) {
        'tagung_neu'   => "ORDER BY p.tagung_nummer DESC, $paperCodeOrderSql, CAST(substr(p.code,2) AS INTEGER)",
        'tagung_alt'   => "ORDER BY p.tagung_nummer ASC, $paperCodeOrderSql, CAST(substr(p.code,2) AS INTEGER)",
        'vortragsart'  => "ORDER BY $paperCodeOrderSql, p.tagung_nummer DESC, CAST(substr(p.code,2) AS INTEGER)",
        default        => 'ORDER BY p.tagung_nummer DESC, p.code',
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
        $stmtA = $db->prepare("
            SELECT a.id, a.vorname, a.nachname, COUNT(DISTINCT pa.paper_id) AS paper_count
            FROM autoren a
            JOIN paper_autoren pa ON pa.autor_id = a.id
            WHERE a.nachname LIKE :q1 COLLATE NOCASE
               OR a.vorname  LIKE :q2 COLLATE NOCASE
            GROUP BY a.id
            HAVING paper_count > 0
            ORDER BY paper_count DESC, a.nachname COLLATE NOCASE
            LIMIT 10
        ");
        $like = '%' . $authorQuery . '%';
        $stmtA->execute([':q1' => $like, ':q2' => $like]);
        $authorMatches = $stmtA->fetchAll();
    }
}

// Suggestions
$authorSuggest  = getTopAuthorSuggestions(200);
$affilSuggest   = getTopAffiliationSuggestions(100);
$tagungenAll    = getAllTagungenForFilter();

$isTypChecked = function (string $code) use ($typIn, $typFilter): bool {
    if ($typIn === null) return false;
    return $typFilter !== null && in_array($code, $typFilter, true);
};
$isOtherChecked = $typIn !== null && $typFilter !== null
    && count(array_intersect($OTHER_LETTERS, $typFilter)) > 0;
?>

<h1 class="h3 mb-4"><?= e(t('suche.title')) ?></h1>

<form action="/suche" method="get" class="suche-form mb-4">
    <div class="row g-2 align-items-stretch">
        <div class="col">
            <label for="suche-q" class="form-label small text-muted mb-1">
                <?= e(t('suche.field_q')) ?>
            </label>
            <input type="search" name="q" id="suche-q"
                   class="form-control search-input"
                   value="<?= e($q) ?>"
                   list="suche-q-suggestions"
                   placeholder="<?= e(t('suche.placeholder')) ?>"
                   autocomplete="off" autofocus>
        </div>
        <div class="col-auto d-flex align-items-end">
            <button type="submit" class="btn btn-accent">
                <i class="bi bi-search"></i> <?= e(t('suche.btn')) ?>
            </button>
        </div>
    </div>

    <datalist id="suche-q-suggestions">
        <?php foreach (array_slice($authorSuggest, 0, 50) as $a): ?>
            <option value="<?= e($a['label']) ?>"></option>
        <?php endforeach; ?>
        <?php foreach (array_slice($affilSuggest, 0, 30) as $aff): ?>
            <option value="<?= e($aff) ?>"></option>
        <?php endforeach; ?>
    </datalist>

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

            <div class="col-md-4">
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
            <div class="col-md-2">
                <label for="suche-jahr-von" class="form-label small text-muted mb-1">
                    <?= e(t('suche.field_jahr_von')) ?>
                </label>
                <input type="number" name="jahr_von" id="suche-jahr-von"
                       class="form-control form-control-sm"
                       min="1949" max="2099" placeholder="1949"
                       value="<?= $fJahrVon > 0 ? $fJahrVon : '' ?>">
            </div>
            <div class="col-md-2">
                <label for="suche-jahr-bis" class="form-label small text-muted mb-1">
                    <?= e(t('suche.field_jahr_bis')) ?>
                </label>
                <input type="number" name="jahr_bis" id="suche-jahr-bis"
                       class="form-control form-control-sm"
                       min="1949" max="2099" placeholder="2099"
                       value="<?= $fJahrBis > 0 ? $fJahrBis : '' ?>">
            </div>
            <div class="col-md-4">
                <label for="suche-sort" class="form-label small text-muted mb-1">
                    <?= e(t('suche.sort_label')) ?>
                </label>
                <select id="suche-sort" name="sort" class="form-select form-select-sm">
                    <option value="relevanz"    <?= $sort === 'relevanz'    ? 'selected' : '' ?>><?= e(t('suche.sort_relevanz')) ?></option>
                    <option value="tagung_neu"  <?= $sort === 'tagung_neu'  ? 'selected' : '' ?>><?= e(t('suche.sort_tagung_neu')) ?></option>
                    <option value="tagung_alt"  <?= $sort === 'tagung_alt'  ? 'selected' : '' ?>><?= e(t('suche.sort_tagung_alt')) ?></option>
                    <option value="vortragsart" <?= $sort === 'vortragsart' ? 'selected' : '' ?>><?= e(t('suche.sort_vortragsart')) ?></option>
                </select>
            </div>

            <div class="col-12">
                <fieldset>
                    <legend class="form-label small text-muted mb-1"><?= e(t('suche.filter_typ_label')) ?></legend>
                    <div class="suche-typ-checks">
                        <?php foreach ([
                            'H' => 'suche.filter_typ_h',
                            'A' => 'suche.filter_typ_a',
                            'B' => 'suche.filter_typ_b',
                            'C' => 'suche.filter_typ_c',
                            'P' => 'suche.filter_typ_p',
                            'S' => 'suche.filter_typ_s',
                        ] as $code => $key): ?>
                        <label class="form-check form-check-inline mb-1">
                            <input class="form-check-input" type="checkbox" name="typ[]" value="<?= $code ?>"
                                   <?= $isTypChecked($code) ? 'checked' : '' ?>>
                            <span class="form-check-label small"><?= e(t($key)) ?></span>
                        </label>
                        <?php endforeach; ?>
                        <label class="form-check form-check-inline mb-1">
                            <input class="form-check-input" type="checkbox" name="typ[]" value="OTHER"
                                   <?= $isOtherChecked ? 'checked' : '' ?>>
                            <span class="form-check-label small"><?= e(t('suche.filter_typ_other')) ?></span>
                        </label>
                    </div>
                </fieldset>
            </div>

            <div class="col-12 d-flex gap-2 mt-1">
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
                $name = trim(preg_replace('/\*+/', '', (string)$a['nachname']));
                if (!empty($a['vorname'])) $name .= ', ' . trim(preg_replace('/\*+/', '', (string)$a['vorname']));
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
            <p class="text-muted"><?= e(t('suche.no_results')) ?></p>
        <?php else: ?>
            <div>
                <?php foreach ($results as $p):
                    $showTagung = true;
                    $tagungLabel = (string)$p['tagung_nummer'];
                ?>
                    <?php require __DIR__ . '/../partials/paper_card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

<?php endif; ?>

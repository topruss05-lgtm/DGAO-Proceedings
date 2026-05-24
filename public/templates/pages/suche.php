<?php
$pageTitle    = t('suche.title') . ' - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/suche');

// --- Input-Parsing ---
$q       = trim($_GET['q'] ?? '');
$sortRaw = (string)($_GET['sort'] ?? 'relevanz');
$ALLOWED_SORTS = ['relevanz', 'tagung_neu', 'tagung_alt', 'vortragsart'];
$sort = in_array($sortRaw, $ALLOWED_SORTS, true) ? $sortRaw : 'relevanz';

$KNOWN_TYP_LETTERS = ['H', 'A', 'B', 'C', 'P', 'S'];
$OTHER_LETTERS     = ['W', 'Z']; // weniger gebraeuchliche Codes, "Sonstige"
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

$results        = [];
$authorMatches  = [];
$searched       = false;
$advancedOpen   = isset($_GET['adv']) || $sort !== 'relevanz' || $typFilter !== null;

// --- ORDER BY je Sort-Mode ---
$paperCodeOrderSql = "
    CASE substr(p.code, 1, 1)
        WHEN 'H' THEN 1 WHEN 'A' THEN 2 WHEN 'B' THEN 3
        WHEN 'C' THEN 4 WHEN 'P' THEN 5 WHEN 'S' THEN 6
        ELSE 9
    END
";

if (mb_strlen($q) >= 2) {
    $searched = true;
    $db = getDb();

    // --- ORDER BY ---
    $orderBy = match ($sort) {
        'tagung_neu'   => "ORDER BY p.tagung_nummer DESC, $paperCodeOrderSql, CAST(substr(p.code,2) AS INTEGER)",
        'tagung_alt'   => "ORDER BY p.tagung_nummer ASC, $paperCodeOrderSql, CAST(substr(p.code,2) AS INTEGER)",
        'vortragsart'  => "ORDER BY $paperCodeOrderSql, p.tagung_nummer DESC, CAST(substr(p.code,2) AS INTEGER)",
        default        => 'ORDER BY rank',  // relevanz (nur fuer FTS gültig)
    };

    $sanitized = sanitizeFtsQuery($q);
    $useFts    = $sanitized !== null && $sort === 'relevanz';

    // WHERE-Typ-Filter als named placeholders (saubere PDO-Konvention,
    // verhindert Mix mit positional).
    $whereTypNamed = '';
    $typBindings = [];
    if ($typFilter !== null) {
        $typPlaceholders = [];
        foreach (array_values($typFilter) as $i => $code) {
            $key = ':typ' . $i;
            $typPlaceholders[] = $key;
            $typBindings[$key] = $code;
        }
        $whereTypNamed = ' AND substr(p.code, 1, 1) IN (' . implode(',', $typPlaceholders) . ')';
    }

    try {
        if ($useFts) {
            $sql = "
                SELECT p.id, p.tagung_nummer, p.code, p.typ, p.titel, p.autoren_text,
                       p.hat_pdf, p.pdf_dateiname
                FROM papers_fts fts
                JOIN papers p ON p.rowid = fts.rowid
                WHERE papers_fts MATCH :q
                $whereTypNamed
                $orderBy
                LIMIT 100
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute(array_merge([':q' => $sanitized], $typBindings));
            $results = $stmt->fetchAll();
        } else {
            $likeQ = '%' . $q . '%';
            $sql = "
                SELECT p.id, p.tagung_nummer, p.code, p.typ, p.titel, p.autoren_text,
                       p.hat_pdf, p.pdf_dateiname
                FROM papers p
                WHERE (p.titel LIKE :q1 OR p.autoren_text LIKE :q2 OR p.abstract_text LIKE :q3)
                $whereTypNamed
                $orderBy
                LIMIT 100
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute(array_merge(
                [':q1' => $likeQ, ':q2' => $likeQ, ':q3' => $likeQ],
                $typBindings
            ));
            $results = $stmt->fetchAll();
        }
    } catch (Throwable $e) {
        error_log('Search query failed: ' . $e);
        // Letzter Fallback: einfacher LIKE ohne Filter
        $likeQ = '%' . $q . '%';
        $stmt = $db->prepare('
            SELECT p.id, p.tagung_nummer, p.code, p.typ, p.titel, p.autoren_text,
                   p.hat_pdf, p.pdf_dateiname
            FROM papers p
            WHERE p.titel LIKE :q1 OR p.autoren_text LIKE :q2 OR p.abstract_text LIKE :q3
            ORDER BY p.tagung_nummer DESC LIMIT 100
        ');
        $stmt->execute([':q1' => $likeQ, ':q2' => $likeQ, ':q3' => $likeQ]);
        $results = $stmt->fetchAll();
    }

    // --- Autoren-Treffer-Sektion ---
    $likeAuthor = '%' . $q . '%';
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
    $stmtA->execute([':q1' => $likeAuthor, ':q2' => $likeAuthor]);
    $authorMatches = $stmtA->fetchAll();
}

// Helper fuer Form-Build (verfuegbare Filter-Wahl)
$activeTyps = $typFilter ?? array_merge($KNOWN_TYP_LETTERS, ['OTHER']);
$isTypActive = function (string $code) use ($typIn, $typFilter): bool {
    if ($typIn === null) return false; // Default: gar nicht selektiert (alles)
    return $typFilter !== null && in_array($code, $typFilter, true);
};
$isOtherActive = $typIn !== null && $typFilter !== null
    && count(array_intersect($OTHER_LETTERS, $typFilter)) > 0;
?>

<h1 class="h3 mb-4"><?= e(t('suche.title')) ?></h1>

<form action="/suche" method="get" class="suche-form mb-4">
    <div class="row g-2">
        <div class="col">
            <input type="search" name="q" class="form-control search-input" value="<?= e($q) ?>"
                   placeholder="<?= e(t('suche.placeholder')) ?>" autofocus>
        </div>
        <div class="col-auto">
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
            <div class="col-md-5">
                <label for="suche-sort" class="form-label small text-muted">
                    <?= e(t('suche.sort_label')) ?>
                </label>
                <select id="suche-sort" name="sort" class="form-select form-select-sm">
                    <option value="relevanz"    <?= $sort === 'relevanz'    ? 'selected' : '' ?>><?= e(t('suche.sort_relevanz')) ?></option>
                    <option value="tagung_neu"  <?= $sort === 'tagung_neu'  ? 'selected' : '' ?>><?= e(t('suche.sort_tagung_neu')) ?></option>
                    <option value="tagung_alt"  <?= $sort === 'tagung_alt'  ? 'selected' : '' ?>><?= e(t('suche.sort_tagung_alt')) ?></option>
                    <option value="vortragsart" <?= $sort === 'vortragsart' ? 'selected' : '' ?>><?= e(t('suche.sort_vortragsart')) ?></option>
                </select>
            </div>
            <div class="col-md-7">
                <fieldset>
                    <legend class="form-label small text-muted"><?= e(t('suche.filter_typ_label')) ?></legend>
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
                                   <?= $isTypActive($code) ? 'checked' : '' ?>>
                            <span class="form-check-label small"><?= e(t($key)) ?></span>
                        </label>
                        <?php endforeach; ?>
                        <label class="form-check form-check-inline mb-1">
                            <input class="form-check-input" type="checkbox" name="typ[]" value="OTHER"
                                   <?= $isOtherActive ? 'checked' : '' ?>>
                            <span class="form-check-label small"><?= e(t('suche.filter_typ_other')) ?></span>
                        </label>
                    </div>
                </fieldset>
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
                $name = trim(preg_replace('/\*+/', '', $a['nachname']));
                if (!empty($a['vorname'])) $name .= ', ' . trim(preg_replace('/\*+/', '', $a['vorname']));
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

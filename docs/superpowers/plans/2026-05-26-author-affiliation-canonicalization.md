# Autoren- und Institutions-Kanonisierung — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** DB-Chaos aus 20 Jahren PDF-Imports konsolidieren: 5.184 Autoren-Records → ~3.500 kanonische Personen, 1.974 Affiliation-Strings → ~300–500 kanonische Institute, mit Alias-Index für Schreibvariante-Matching. Suche findet danach „C. Pruss", „C. Pruß", „Ch. Pruß" als dieselbe Person.

**Architecture:** Drei-Layer-Modell (siehe Spec [`2026-05-26-author-affiliation-canonicalization-design.md`](../specs/2026-05-26-author-affiliation-canonicalization-design.md)): `autoren` (kanonische Identität) + `autor_aliase` (Schreibvarianten) + `institutionen` mit `institut_aliase` + `autor_institutionen` (N:M mit `ist_aktuell`-Flag). Migration in fünf Phasen, jede reversibel via Backup.

**Tech Stack:** PHP 8.5 (CLI + Web), SQLite 3 (WAL-Modus), `intl`/`mbstring` für Normalisierung. Subagents (Sonnet) via `Agent` Tool für unscharfe Cluster-Bewertung und Institut-Online-Recherche. Keine externen Test-Libs — leichtgewichtiger eigener Test-Runner (`tests/run.php`).

**Spec-Quelle:** [`docs/superpowers/specs/2026-05-26-author-affiliation-canonicalization-design.md`](../specs/2026-05-26-author-affiliation-canonicalization-design.md)

---

## File Structure

**Neue Dateien:**
- `tests/run.php` — Test-Runner (lädt alle `tests/test_*.php`)
- `tests/lib.php` — Test-Helper (`assert_equals`, `with_test_db`)
- `tests/test_normalize.php` — Tests für `normalizeForAliasMatch`
- `tests/test_migration_v8.php` — Tests für Schema-Migration auf DB-Kopie
- `tests/test_auto_merge.php` — Tests für regelbasiertes Cluster-Merging
- `bin/cleanup_auto_merge.php` — CLI für Phase 2
- `bin/cleanup_subagent_authors.php` — CLI für Phase 3
- `bin/cleanup_institutions.php` — CLI für Phase 4 (mit Online-Recherche)
- `bin/cleanup_drop_affiliation.php` — CLI für Phase 5
- `public/admin/cleanup.php` — Admin-Routes
- `public/admin/templates/pages/cleanup_dashboard.php`
- `public/admin/templates/pages/cleanup_queue.php`

**Geänderte Dateien:**
- `public/helpers.php` — `normalizeForAliasMatch()` ergänzen
- `public/db.php` — `DB_SCHEMA_VERSION = 8` + Migration v8
- `database/schema.sql` — neue Tabellen für Grüne-Wiese-Deploys
- `public/suggest.php` — Author-Query auf `autor_aliase` umstellen
- `public/templates/pages/suche.php` — Author/Institut-Filter auf Alias-Joins
- `public/router.php` — Routen für `/admin/cleanup/*` + Redirect-Logik

---

## Phase A — Foundation: Test-Infrastruktur + Normalize-Helper

### Task 1: Test-Runner und Test-Helper anlegen

**Files:**
- Create: `tests/lib.php`
- Create: `tests/run.php`

- [ ] **Step 1: `tests/lib.php` schreiben — Test-Assertions + DB-Sandbox**

Inhalt: globaler `$GLOBALS['__tests']`-Counter mit Pass/Fail/Failures-Array. Funktionen `assert_equals(expected, actual, msg)`, `assert_true(cond, msg)`, `assert_count(expected, arr, msg)`. Die Funktion `with_test_db(callable $fn)` kopiert `database/backups/proceedings_pre_migration_2026-05-26.db` nach `tempnam(...)`, öffnet PDO mit `PRAGMA foreign_keys=ON`, ruft Closure mit `$pdo`, löscht Temp-Files in `finally`.

- [ ] **Step 2: `tests/run.php` schreiben — Test-Runner**

`require_once lib.php`, dann `glob(__DIR__.'/test_*.php')` durchgehen und jeweils per `require` laden. Am Ende Pass/Fail-Summary, bei Fail die `failures[]` ausgeben und `exit(1)`.

- [ ] **Step 3: Smoke-Test**

```
php tests/run.php
```
Erwartet: `Pass: 0, Fail: 0`, exit 0.

- [ ] **Step 4: Commit**

```
git add tests/
git commit -m "test: einfacher PHP-Test-Runner ohne externe Dependencies"
```

---

### Task 2: `normalizeForAliasMatch` mit TDD

**Files:**
- Modify: `public/helpers.php`
- Create: `tests/test_normalize.php`

- [ ] **Step 1: Failing-Tests schreiben (`tests/test_normalize.php`)**

`require_once helpers.php`. Assertions für:
- `'C. Pruß'` → `'cpruss'`
- `'C. Pruß*'` → `'cpruss'` (einzelner Stern)
- `'C. Pruß** ***'` → `'cpruss'` (multi-Sterne mit Space)
- `'Ch. Pruß'` → `'chpruss'`
- `'Müller, H.-P.'` → `'mullerhp'` (Komma + Bindestrich + Umlaut)
- `'C. Pruss'` → `'cpruss'` (ohne ß identisch)
- `'  C.  Pruß  '` → `'cpruss'` (Whitespace überall)
- `'Institut für Technische Optik, Universität Stuttgart'` → `'institutfurtechnischeoptikuniversitatstuttgart'`
- `''` → `''`
- `'***'` → `''`

- [ ] **Step 2: Tests laufen, müssen FAIL produzieren**

```
php tests/run.php
```

- [ ] **Step 3: `normalizeForAliasMatch` in `public/helpers.php` implementieren**

Direkt nach `sanitizeFtsQuery` (etwa Zeile 88) einfügen. Body in dieser Reihenfolge:
1. `preg_replace('/[\*†‡§#^]+/u', '', $s)` — Fußnoten-Marker entfernen
2. `mb_strtolower($s)`
3. `str_replace('ß', 'ss', $s)`
4. `transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s) ?? $s`
5. `preg_replace('/[\.\s,\-]+/u', '', $s)` — Punkte/Spaces/Kommas/Bindestriche
6. `return trim($s)`

Mit Docblock-Beispielen.

- [ ] **Step 4: Tests laufen, alle PASS**

- [ ] **Step 5: Commit**

```
git add public/helpers.php tests/test_normalize.php
git commit -m "feat(helpers): normalizeForAliasMatch für Alias-Index"
```

---

## Phase B — Schema-Migration (v8)

### Task 3: Schema-Migration v8 schreiben

**Files:**
- Modify: `public/db.php` (Konstante + `runMigrations()`)
- Modify: `database/schema.sql`
- Create: `tests/test_migration_v8.php`

- [ ] **Step 1: Failing-Test schreiben (`tests/test_migration_v8.php`)**

`with_test_db(function ($pdo) { ... })`:
- Vorher: `PRAGMA user_version` == 7
- `runMigrations($pdo)` aufrufen
- Nachher: `PRAGMA user_version` == 8
- Neue Tabellen `autor_aliase`, `institutionen`, `institut_aliase`, `autor_institutionen`, `autor_id_redirects` existieren (Query auf `sqlite_master`)
- `autoren` hat Spalte `orcid_id`
- `autoren` hat noch `affiliation` (wird erst Phase 5 gedroppt)
- `autor_aliase`-Count ≥ `autoren`-Count (mind. 1 Alias pro Autor)
- `autor_aliase.alias_text NOT LIKE '%*%'` (Sterne weg)
- `autor_aliase.alias_norm != ''` (alle gefüllt)
- Pro Autor max 1 `autor_institutionen WHERE ist_aktuell=1`
- Idempotenz: zweiter `runMigrations()`-Call lässt Version bei 8

- [ ] **Step 2: Tests laufen, FAIL**

- [ ] **Step 3: `DB_SCHEMA_VERSION = 8` setzen in `public/db.php` Zeile 7**

- [ ] **Step 4: Migration v8 in `runMigrations()` ergänzen**

Vor der finalen `user_version`-PRAGMA-Zeile einfügen. Logik:

a) Gate: `SELECT COUNT(*) FROM sqlite_master WHERE name='autor_aliase'` → wenn schon vorhanden, skip.

b) Transaktion start:
- DDL für `autor_aliase` (PK id, FK autor_id→autoren CASCADE, alias_text, alias_norm, created_at, UNIQUE(autor_id, alias_norm)) + 2 Indizes
- DDL für `institutionen` (PK id, name_de NOT NULL, name_en DEFAULT '', kuerzel, universitaet, ort, land DEFAULT 'DE', ror_id, created_at) + Index auf kuerzel
- DDL für `institut_aliase` (analog zu autor_aliase, FK institut_id) + 2 Indizes
- DDL für `autor_institutionen` (composite PK autor_id+institut_id, beide FK CASCADE, ist_aktuell DEFAULT 0) + Index (autor_id, ist_aktuell)
- DDL für `autor_id_redirects` (alte_id PK, neue_id FK→autoren CASCADE, merged_at)

c) `autoren` rebuild (UNIQUE entfernen, orcid_id ergänzen, Sterne strippen):
- `CREATE TABLE autoren_new (id INTEGER PRIMARY KEY, vorname TEXT NOT NULL DEFAULT '', nachname TEXT NOT NULL, affiliation TEXT NOT NULL DEFAULT '', orcid_id TEXT)`
- `INSERT INTO autoren_new (id, vorname, nachname, affiliation) SELECT id, TRIM(...stripped vorname...), TRIM(...stripped nachname...), affiliation FROM autoren` — Strip via verschachtelte `REPLACE(..., '*', '')`, `REPLACE(..., '†', '')`, `REPLACE(..., '‡', '')`, `REPLACE(..., '§', '')`, `REPLACE(..., '#', '')`, `REPLACE(..., '^', '')`
- `DROP TABLE autoren`
- `ALTER TABLE autoren_new RENAME TO autoren`
- `CREATE INDEX idx_autoren_nachname ON autoren(nachname)`

d) Initial-Aliase aus gestrippten Namen:
- `SELECT id, vorname, nachname FROM autoren` durchgehen
- Pro Zeile: `$text = trim("$vorname $nachname")`, `$norm = normalizeForAliasMatch($text)`
- Wenn `$norm !== ''`: `INSERT OR IGNORE INTO autor_aliase (autor_id, alias_text, alias_norm) VALUES (?, ?, ?)`

e) Initial-`institutionen` aus `autoren.affiliation`:
- `INSERT INTO institutionen (name_de) SELECT DISTINCT affiliation FROM autoren WHERE affiliation != ''`
- Pro `institutionen.id`: Initial-Alias mit `alias_text = name_de`, `alias_norm = normalizeForAliasMatch(name_de)`

f) `autor_institutionen` initial befüllen:
- `INSERT INTO autor_institutionen (autor_id, institut_id, ist_aktuell) SELECT a.id, i.id, 0 FROM autoren a JOIN institutionen i ON i.name_de = a.affiliation WHERE a.affiliation != ''`
- `UPDATE autor_institutionen SET ist_aktuell = 1 WHERE autor_id IN (SELECT autor_id FROM autor_institutionen GROUP BY autor_id HAVING COUNT(*) = 1)` (pro Autor genau eine Verknüpfung → automatisch aktuell)

g) Commit der Transaktion. Bei Throwable → rollBack + rethrow.

- [ ] **Step 5: Tests laufen, alle PASS**

- [ ] **Step 6: `database/schema.sql` aktualisieren**

`autoren`-Definition (Zeile 58-63) ersetzen (kein UNIQUE-Constraint mehr, plus `orcid_id`-Spalte). Vor `PRAGMA user_version = 6;` die neuen Tabellen einfügen (identische DDL wie in `runMigrations()` Schritt b). Letzte Zeile zu `PRAGMA user_version = 8;`.

- [ ] **Step 7: Commit**

```
git add public/db.php database/schema.sql tests/test_migration_v8.php
git commit -m "feat(db): Schema v8 — autor_aliase, institutionen, institut_aliase, autor_institutionen, autor_id_redirects"
```

---

## Phase C — Regelbasierter Auto-Merge (Phase 2 der Spec)

### Task 4: Cluster-Identifikation mit TDD

**Files:**
- Create: `bin/cleanup_auto_merge.php`
- Create: `tests/test_auto_merge.php`

- [ ] **Step 1: Failing-Test für `findAuthorAutoMergeClusters`**

`with_test_db`, `runMigrations`, dann `$clusters = findAuthorAutoMergeClusters($pdo)`. Erwartung:
- Mind. ein Cluster mit `key === 'cpruss'` und `count(ids) >= 2`
- Mind. ein Cluster mit `key === 'cfranke'` (aus DB-Analyse bekannt)

- [ ] **Step 2: Tests laufen, FAIL**

- [ ] **Step 3: Funktion in `bin/cleanup_auto_merge.php` anlegen**

```
<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/helpers.php';

function findAuthorAutoMergeClusters(PDO $db): array
{
    $sql = "SELECT alias_norm AS key, GROUP_CONCAT(autor_id, ',') AS ids
            FROM autor_aliase
            GROUP BY alias_norm
            HAVING COUNT(*) > 1
            ORDER BY alias_norm";
    $clusters = [];
    foreach ($db->query($sql) as $row) {
        $clusters[] = [
            'key' => $row['key'],
            'ids' => array_map('intval', explode(',', $row['ids'])),
        ];
    }
    return $clusters;
}
```

- [ ] **Step 4: Tests laufen, PASS**

- [ ] **Step 5: Commit**

```
git add bin/cleanup_auto_merge.php tests/test_auto_merge.php
git commit -m "feat(cleanup): findAuthorAutoMergeClusters via alias_norm"
```

---

### Task 5: Merge-Operation implementieren

**Files:**
- Modify: `bin/cleanup_auto_merge.php`
- Modify: `tests/test_auto_merge.php`

- [ ] **Step 1: Failing-Test für `mergeAuthorCluster`**

`with_test_db` + `runMigrations` + `findAuthorAutoMergeClusters`. Nimm `$clusters[0]`, summiere Paper-Counts vorher (`SELECT COUNT(*) FROM paper_autoren WHERE autor_id IN (...)`), rufe `$anker = mergeAuthorCluster($pdo, $cluster['ids'])`. Assertions:
- `$anker` ist eine der Original-IDs
- Alle Nicht-Anker-IDs sind aus `autoren` weg
- Pro Nicht-Anker-ID: ein Eintrag in `autor_id_redirects` mit `neue_id = $anker`
- `COUNT(paper_autoren WHERE autor_id = $anker)` == Paper-Count vorher (vollständig übertragen)
- `autor_institutionen WHERE autor_id = $anker AND ist_aktuell = 1` hat genau eine Zeile

- [ ] **Step 2: Tests laufen, FAIL**

- [ ] **Step 3: `mergeAuthorCluster` implementieren**

Funktion mit Signatur `(PDO $db, array $ids): int`. Atomare Transaktion. Schritte:

1. **Anker bestimmen.** SQL mit `IN (?...)` Platzhaltern: `SELECT a.id, COALESCE((SELECT MAX(p.tagung_nummer) FROM paper_autoren pa JOIN papers p ON p.id = pa.paper_id WHERE pa.autor_id = a.id), 0) AS max_tg FROM autoren a WHERE a.id IN (...) ORDER BY max_tg DESC, a.id ASC`. Erste Zeile → Anker.

2. **`paper_autoren` umhängen** (Dedup-Strategie: Konflikte vorher löschen). Erst `DELETE FROM paper_autoren WHERE autor_id IN (duplicates) AND paper_id IN (SELECT paper_id FROM paper_autoren WHERE autor_id = anker)`. Dann `UPDATE paper_autoren SET autor_id = anker WHERE autor_id IN (duplicates)`.

3. **`autor_institutionen` umhängen** mit gleicher Dedup-Logik (Konflikt-Key: `institut_id`).

4. **`ist_aktuell` neu berechnen für Anker.** Erst alle auf 0: `UPDATE autor_institutionen SET ist_aktuell = 0 WHERE autor_id = anker`. Dann diejenige mit höchster `MAX(tagung_nummer)` aus `paper_autoren JOIN papers` auf 1 setzen. Query liefert `institut_id` der gewinnenden Verknüpfung.

5. **`autor_aliase` umhängen.** `UPDATE OR IGNORE autor_aliase SET autor_id = anker WHERE autor_id IN (duplicates)` (UNIQUE schluckt Konflikte). Dann `DELETE FROM autor_aliase WHERE autor_id IN (duplicates)` für übrig gebliebene Konflikt-Zeilen.

6. **Redirects schreiben.** Pro Duplikat: `INSERT OR REPLACE INTO autor_id_redirects (alte_id, neue_id) VALUES (?, ?)`.

7. **Duplikate aus `autoren` löschen.** `DELETE FROM autoren WHERE id IN (duplicates)`.

Bei Throwable: rollBack + rethrow. Return: `$anker`.

- [ ] **Step 4: Tests laufen, PASS**

- [ ] **Step 5: Commit**

```
git add bin/cleanup_auto_merge.php tests/test_auto_merge.php
git commit -m "feat(cleanup): mergeAuthorCluster atomar mit Redirect-Map"
```

---

### Task 6: CLI-Driver für Phase-2-Run

**Files:**
- Modify: `bin/cleanup_auto_merge.php`

- [ ] **Step 1: CLI-Main-Block einfügen**

Am Anfang der Datei (nach `require_once`s, vor Funktions-Definitionen):
- Detect `PHP_SAPI === 'cli'` + Skript direkt aufgerufen
- Argumente: `--dry-run` Flag + optional DB-Pfad (Default `public/data/proceedings.db`)
- PDO öffnen mit `PRAGMA foreign_keys = ON`
- `runMigrations($pdo)` aufrufen (idempotent)
- `findAuthorAutoMergeClusters($pdo)` → Stats ausgeben
- Bei `--dry-run`: Top-10 Cluster zeigen, exit 0
- Sonst: Loop über Cluster, `mergeAuthorCluster()` aufrufen, Fehler in stderr loggen
- Am Ende: Stats (`X gemerged, Y Fehler`) + exit-Code

- [ ] **Step 2: Dry-Run auf Backup-Kopie**

```
cp database/backups/proceedings_pre_migration_2026-05-26.db /tmp/test_dryrun.db
php bin/cleanup_auto_merge.php --dry-run /tmp/test_dryrun.db
```
Erwartet: ~400+ Cluster, Top-10 Liste, kein Crash.

- [ ] **Step 3: Echter Run auf Kopie**

```
cp database/backups/proceedings_pre_migration_2026-05-26.db /tmp/test_realrun.db
php bin/cleanup_auto_merge.php /tmp/test_realrun.db
```
Erwartet: `~488 Records gemerged, 0 Fehler`.

- [ ] **Step 4: Verifikation**

```
sqlite3 /tmp/test_realrun.db "SELECT 'autoren: ' || COUNT(*) FROM autoren; SELECT 'redirects: ' || COUNT(*) FROM autor_id_redirects;"
```
Erwartet: autoren ~4700, redirects ~488.

- [ ] **Step 5: Commit**

```
git add bin/cleanup_auto_merge.php
git commit -m "feat(cleanup): CLI für Phase-2 Auto-Merge mit --dry-run"
```

---

### Task 7: Auto-Merge auf lokaler Live-DB

- [ ] **Step 1: Inkremental-Backup**

```
cp public/data/proceedings.db database/backups/proceedings_pre_phase2_$(date +%Y-%m-%d_%H%M).db
```

- [ ] **Step 2: Dry-Run auf Live-DB**

```
php bin/cleanup_auto_merge.php --dry-run public/data/proceedings.db
```

- [ ] **Step 3: Echter Run**

```
php bin/cleanup_auto_merge.php public/data/proceedings.db
```

- [ ] **Step 4: Verifikation + Browser-Smoke-Test**

```
sqlite3 public/data/proceedings.db "SELECT COUNT(*) AS autoren FROM autoren; SELECT COUNT(*) AS aliase FROM autor_aliase; SELECT COUNT(*) AS redirects FROM autor_id_redirects;"
php -S 127.0.0.1:8000 -t public/ public/router.php
```

Im Browser `/suche?q=Pruss` und `/suche?q=Pruß` aufrufen.

- [ ] **Step 5: CHANGELOG-Notiz**

```
echo "$(date +%Y-%m-%d): Phase 2 Auto-Merge ausgeführt, lokale DB: 5184 → $(sqlite3 public/data/proceedings.db 'SELECT COUNT(*) FROM autoren') Autoren" >> docs/superpowers/CHANGELOG.md
git add docs/superpowers/CHANGELOG.md
git commit -m "chore: Phase 2 Auto-Merge auf lokaler DB ausgeführt"
```

---

## Phase D — Suggest/Search-Refactor

### Task 8: Suggest auf alias_norm umstellen

**Files:**
- Modify: `public/suggest.php`
- Create: `tests/test_suggest_aliases.php`

- [ ] **Step 1: Failing-Test schreiben**

`with_test_db` + `runMigrations` + Phase-2-Loop (`findAuthorAutoMergeClusters` + `mergeAuthorCluster`). Dann simuliere die neue Suggest-Query direkt im Test mit `$qnorm = normalizeForAliasMatch('Pruss')`. Erwartet: mind. 1 Treffer, Top-Treffer hat `Pru*` im Nachnamen.

- [ ] **Step 2: Tests laufen (passt, Query funktioniert auf Test-DB)**

- [ ] **Step 3: Suggest-Autor-Block in `public/suggest.php` ersetzen**

Ersetze Zeilen 41-70 (den `$stmtA = $db->prepare(...)` Block + Loop). Neuer Code:

a) `$qNorm = normalizeForAliasMatch($q)`, `$lang = $_SESSION['lang'] ?? 'de'`, `$instCol = $lang === 'en' ? 'i.name_en' : 'i.name_de'`.

b) Statement mit Subqueries für Affiliation (im SELECT, mit Locale-Switch + COALESCE auf name_de bei leerem name_en) und WHERE-Clause: `EXISTS (SELECT 1 FROM autor_aliase al WHERE al.autor_id = a.id AND al.alias_norm LIKE :qnorm) OR EXISTS (... institut_aliase + institutionen-Match auf name_de/name_en/kuerzel ...)`. `GROUP BY a.id`, `HAVING papers > 0`, `ORDER BY papers DESC`, `LIMIT 5`.

c) Loop unverändert (Display: `nachname, vorname`), aber `affiliation` aus `aff`-Spalte (string cast).

`$strip`-Helper am Anfang der Datei bleibt für Papers/Tagungen-Sektionen.

- [ ] **Step 4: Browser-Test**

```
php -S 127.0.0.1:8000 -t public/ public/router.php
```

`http://127.0.0.1:8000/api/suggest?q=Pruss` und `?q=Pruß` — beide gleicher Treffer „Pruß, C." mit allen Papers gemerged.

- [ ] **Step 5: Commit**

```
git add public/suggest.php tests/test_suggest_aliases.php
git commit -m "feat(suggest): Autor-Match via autor_aliase.alias_norm"
```

---

### Task 9: Search-Page auf Alias-Joins + Autor-ID-Redirect-Route

**Files:**
- Modify: `public/templates/pages/suche.php`
- Modify: `public/router.php`

- [ ] **Step 1: Autor-Filter (Zeile 55-68) umstellen**

`$aNorm = normalizeForAliasMatch($fAutor)`. WHERE-Clause `EXISTS (SELECT 1 FROM paper_autoren pa JOIN autor_aliase al ON al.autor_id = pa.autor_id WHERE pa.paper_id = p.id AND al.alias_norm LIKE :anorm) OR p.hauptautor LIKE :autor2 OR p.autoren_text LIKE :autor1`. Params analog.

- [ ] **Step 2: Instituts-Filter (Zeile 69-78) umstellen**

`$iNorm = normalizeForAliasMatch($fInst)`. WHERE-Clause: PDF-Text-Match `p.affiliationen LIKE :inst1` ODER `EXISTS (... paper_autoren JOIN autor_institutionen JOIN institutionen LEFT JOIN institut_aliase ... WHERE name_de/name_en/kuerzel LIKE :inst* OR ia.alias_norm LIKE :inorm)`.

- [ ] **Step 3: Autoren-Treffer-Sektion (Zeile 147-160) umstellen**

`$aNorm = normalizeForAliasMatch($authorQuery)`. WHERE-Clause `EXISTS (SELECT 1 FROM autor_aliase al WHERE al.autor_id = a.id AND al.alias_norm LIKE :anorm)`. Sonst unverändert.

- [ ] **Step 4: Redirect-Route in `public/router.php`**

In dem Handler, der `/autor/{id}` dispatched (per grep `'/autor/'` finden), vor dem normalen Lookup:

```
$redir = $db->prepare("SELECT neue_id FROM autor_id_redirects WHERE alte_id = ?");
$redir->execute([(int)$id]);
$neue = $redir->fetchColumn();
if ($neue) {
    header('Location: /autor/' . (int)$neue, true, 301);
    exit;
}
```

- [ ] **Step 5: Browser-Test**

`/suche?q=Pruß&sort=relevanz`, `/suche?q=Pruss`, `/suche?autor=Pruss`, `/autor/{eine_alte_id}` (sollte 301-redirecten).

- [ ] **Step 6: Commit**

```
git add public/templates/pages/suche.php public/router.php
git commit -m "feat(search): Autor/Institut-Filter via alias_norm + /autor/{id}-Redirects"
```

---

## Phase E — Subagent-Pipeline für unscharfe Autoren-Cluster

### Task 10: Cluster-Generator für unscharfe Fälle

**Files:**
- Create: `bin/cleanup_subagent_authors.php`

- [ ] **Step 1: Generator + Detail-Renderer schreiben**

a) `findFuzzyAuthorClusters(PDO $db): array` — gruppiert nach normalisiertem Nachnamen (ohne Vorname). SQL: `SELECT LOWER(REPLACE(REPLACE(REPLACE(nachname, 'ß', 'ss'), '-', ''), ' ', '')) AS nn_key, GROUP_CONCAT(id, ',') AS ids FROM autoren GROUP BY nn_key HAVING COUNT(*) > 1`. Return: Array von `['nachname' => key, 'ids' => [int...]]`.

b) `renderClusterForSubagent(PDO $db, array $ids): array` — pro ID: id, vorname, nachname, papers-count, affiliations (concat), 3 jüngste Paper-Titel. Subqueries auf `paper_autoren`, `autor_institutionen JOIN institutionen`, `paper_autoren JOIN papers ORDER BY tagung_nummer DESC LIMIT 3`.

- [ ] **Step 2: Smoke-Test**

```
php -r "require_once 'bin/cleanup_subagent_authors.php'; \$p = new PDO('sqlite:public/data/proceedings.db'); print_r(array_slice(findFuzzyAuthorClusters(\$p), 0, 3));"
```

- [ ] **Step 3: Commit**

```
git add bin/cleanup_subagent_authors.php
git commit -m "feat(cleanup): Generator für unscharfe Autoren-Cluster"
```

---

### Task 11: Subagent-Pipeline (Export/Process)

**Files:**
- Modify: `bin/cleanup_subagent_authors.php`

> **Subagent-Hinweis für den ausführenden Agent:** In Step 3 dieses Tasks dispatchst du **parallel Sonnet-Subagents** via `Agent`-Tool mit `subagent_type: general-purpose` und `model: sonnet`. Einer pro Cluster, Konkurrenz-Limit ~10 via `dispatching-parallel-agents` Skill. Jeder Subagent liest eine `tmp/subagent/cluster_NNNN.json`-Datei und schreibt `tmp/subagent/verdict_NNNN.json`.

- [ ] **Step 1: `exportClustersForSubagents(PDO, string $outDir): int`**

Verzeichnis anlegen, alte `cluster_*.json` löschen. Für jeden Cluster aus `findFuzzyAuthorClusters` eine JSON-Datei `cluster_NNNN.json` mit `{cluster_id, nachname, candidates:[{id, name, papers, affiliations, sample_titles}, ...]}`.

- [ ] **Step 2: `processSubagentVerdicts(PDO, string $verdictDir, float $threshold = 0.9): array`**

Tabelle `merge_review_queue` bei Bedarf anlegen (`id PK, cluster_json TEXT, verdict_json TEXT, status DEFAULT 'pending' CHECK, created_at`).

Pro `verdict_NNNN.json`:
- Cluster-File analog laden
- Wenn `verdict == 'merge'` und `confidence >= threshold`: für jede `group` in `verdict.groups` → `mergeAuthorCluster()` (require_once auf `cleanup_auto_merge.php`)
- Sonst: Insert in `merge_review_queue` mit Status `pending`

Returns Stats `['auto_merged', 'queued', 'errors']`.

- [ ] **Step 3: CLI-Block**

Subkommandos: `export` (default-DB) und `process`. Output-Verzeichnis: `tmp/subagent`.

- [ ] **Step 4: Export ausführen**

```
php bin/cleanup_subagent_authors.php export public/data/proceedings.db
ls tmp/subagent/ | wc -l
```

- [ ] **Step 5: SUBAGENT-DISPATCH (ausführender Agent)**

Pro `cluster_NNNN.json` einen Sonnet-Subagent dispatchen, parallel mit Limit 10. Prompt-Template:

```
Du bewertest, ob die Autoren-Schreibvarianten in der JSON-Datei
tmp/subagent/cluster_NNNN.json dieselbe Person sind. Schreibe das
Ergebnis nach tmp/subagent/verdict_NNNN.json.

Bewertungs-Kriterien:
- Initialen-Varianten (C. vs Ch. vs Chr.) → meistens gleich, ABER nur
  bei Überschneidung in Affiliations oder Co-Autoren-Bereich.
- ß/ss-Unterschiede (Pruss vs Pruß) → fast immer gleich.
- Disjunkte Affiliations und Themen → keep_separate.
- Bei Zweifel: unsure.

Output-Format:
{
  "verdict": "merge"|"keep_separate"|"unsure",
  "confidence": <0.0-1.0>,
  "groups": [[id, id, ...], ...],
  "reason": "<1 Satz>"
}

Konservativ. Konfidenz ≥ 0.9 nur bei echter Sicherheit.
```

Vor dem Process-Schritt: Stichproben-Review der ersten 5 Verdicts.

- [ ] **Step 6: Process**

```
php bin/cleanup_subagent_authors.php process public/data/proceedings.db
sqlite3 public/data/proceedings.db "SELECT COUNT(*) FROM autoren; SELECT COUNT(*) FROM merge_review_queue;"
```

- [ ] **Step 7: Commit**

```
echo "tmp/" >> .gitignore
git add .gitignore bin/cleanup_subagent_authors.php
git commit -m "feat(cleanup): Subagent-Pipeline für unscharfe Autoren-Cluster"
```

---

## Phase F — Institutionen-Konsolidierung mit Online-Recherche

### Task 12: Institut-Cluster + Subagent-Pipeline mit Web-Recherche

**Files:**
- Create: `bin/cleanup_institutions.php`

> **Subagent-Hinweis:** Hier nutzen die Subagents **`WebFetch`** für Recherche auf api.ror.org, Wikipedia und Institut-Webseiten.

- [ ] **Step 1: `findInstitutionClusters(PDO): array`**

Token-basiertes Clustering. Stoppwörter-Liste (der, die, das, für, von, am, im, de, of, for, the, at, in). Pro `institutionen.id`: name_de tokenisieren (lowercase, ß→ss, Split per Whitespace/Komma/Bindestrich/Punkt/Klammer), Stoppwörter weg, nur Token >4 Zeichen. Greedy-Cluster-Bau: zwei Institute landen im gleichen Cluster, wenn sie ≥3 gemeinsame Token haben. Return: Array von Arrays mit IDs.

- [ ] **Step 2: `exportInstitutionClusters(PDO, string $outDir): int`**

Pro Cluster JSON-Datei `inst_NNNN.json` mit `{cluster_id, variants:[{id, name_de}, ...]}`.

- [ ] **Step 3: `processInstitutionVerdicts(PDO, string $verdictDir, float $threshold = 0.85): array`**

Pro `verdict_inst_NNNN.json`:
- Wenn nicht Auto-Merge: skip (Queue analog zu Autoren, optional)
- Sonst: pro Gruppe → niedrigste ID = Anker. Anker-Daten aus `canonical[group_index]` setzen (name_de, name_en, kuerzel, universitaet, ort, land, ror_id via UPDATE). `institut_aliase` umhängen (`UPDATE OR IGNORE` + `DELETE`-Cleanup). `autor_institutionen` umhängen (Konflikte vorher löschen, dann UPDATE). Duplikate aus `institutionen` löschen.

Atomare Transaktion pro Cluster.

- [ ] **Step 4: CLI-Block**

`export` und `process` Subkommandos, `tmp/institutions` als Verzeichnis.

- [ ] **Step 5: Export**

```
php bin/cleanup_institutions.php export public/data/proceedings.db
ls tmp/institutions/ | wc -l
```

- [ ] **Step 6: SUBAGENT-DISPATCH mit Online-Recherche (ausführender Agent)**

Pro `inst_NNNN.json` einen Sonnet-Subagent mit Tools für Web-Zugriff. Prompt-Template:

```
Du analysierst einen Cluster von Schreibvarianten desselben oder
ähnlicher Institute. Lies tmp/institutions/inst_NNNN.json und
recherchiere die offiziellen Bezeichnungen online.

Recherche-Quellen (in Reihenfolge):
1. ROR API: https://api.ror.org/v2/organizations?query=<name>
2. Wikipedia-Suche
3. Institut-Webseite

Für jedes erkannte Institut bestimme:
- name_de (offizielle deutsche Bezeichnung)
- name_en (offizielle englische Bezeichnung)
- kuerzel (übliche Abkürzung, z.B. ITO, PTB, LZH)
- universitaet (übergeordnete Hochschule)
- ort
- land (ISO-Code: DE, AT, CH, ...)
- ror_id (ROR-URL falls gefunden)

Bewerte, welche IDs wirklich dasselbe Institut sind. Achtung: bei
Konzernstrukturen (z.B. Carl Zeiss AG vs Carl Zeiss SMT GmbH)
lieber keep_separate.

Schreibe nach tmp/institutions/verdict_inst_NNNN.json:
{
  "verdict": "merge"|"keep_separate"|"unsure",
  "confidence": <0.0-1.0>,
  "groups": [[id, id, ...], ...],
  "canonical": {
    "0": {"name_de":"...","name_en":"...","kuerzel":"...",
          "universitaet":"...","ort":"...","land":"DE","ror_id":"..."}
  },
  "reason": "..."
}
```

Konkurrenz-Limit 10. Stichproben-Review der ersten 5 Verdicts.

- [ ] **Step 7: Process**

```
php bin/cleanup_institutions.php process public/data/proceedings.db
sqlite3 public/data/proceedings.db "SELECT COUNT(*) AS institutionen, SUM(CASE WHEN name_en != '' THEN 1 ELSE 0 END) AS mit_en FROM institutionen;"
```

- [ ] **Step 8: Commit**

```
git add bin/cleanup_institutions.php
git commit -m "feat(cleanup): Institut-Konsolidierung mit Online-Recherche-Subagents"
```

---

## Phase G — Admin-UI

### Task 13: Cleanup-Dashboard + Review-Queue

**Files:**
- Create: `public/admin/cleanup.php`, `public/admin/templates/pages/cleanup_dashboard.php`, `public/admin/templates/pages/cleanup_queue.php`
- Modify: `public/router.php`

- [ ] **Step 1: Dashboard-Controller**

`public/admin/cleanup.php`: `require_admin()`, DB-Stats laden (autoren, autor_aliase, institutionen, institut_aliase, redirects, merge_review_queue pending count). Template inkludieren.

- [ ] **Step 2: Dashboard-Template**

Tabelle mit den Stats, Links zur Queue. Anlehnung an bestehende Admin-Templates (siehe `public/admin/templates/pages/news.php` als Referenz für Layout/Styling).

- [ ] **Step 3: Queue-Template**

`public/admin/templates/pages/cleanup_queue.php`: listet `merge_review_queue WHERE status='pending'` mit Cluster-JSON (formatiert), Verdict-Begründung, POST-Form mit Approve/Reject-Buttons.

- [ ] **Step 4: Approve/Reject-Controller**

`public/admin/cleanup_approve.php`: POST mit `id` und `action` (`approve` oder `reject`). Bei approve: aus `verdict_json` die `groups` parsen, `mergeAuthorCluster` pro Gruppe (für Autoren) bzw. den Institut-Merge-Code aus Task 12 (für Institute) aufrufen, Status auf `approved` setzen. Bei reject: Status auf `rejected`.

- [ ] **Step 5: Routen in `public/router.php`**

```
'/admin/cleanup'         => __DIR__ . '/admin/cleanup.php',
'/admin/cleanup/queue'   => __DIR__ . '/admin/cleanup_queue.php',
'/admin/cleanup/approve' => __DIR__ . '/admin/cleanup_approve.php',
```

- [ ] **Step 6: Browser-Test**

```
php -S 127.0.0.1:8000 -t public/ public/router.php
```
Login als Admin, `/admin/cleanup` zeigt Stats, `/admin/cleanup/queue` zeigt offene Vorschläge.

- [ ] **Step 7: Commit**

```
git add public/admin/cleanup.php public/admin/cleanup_approve.php public/admin/templates/pages/cleanup_*.php public/router.php
git commit -m "feat(admin): Cleanup-Dashboard + Review-Queue + Approve/Reject"
```

---

## Phase H — Finaler Cleanup

### Task 14: `autoren.affiliation` droppen

**Files:**
- Create: `bin/cleanup_drop_affiliation.php`

- [ ] **Step 1: Script schreiben**

CLI-Script mit DB-Pfad-Argument. Logik:

a) Pre-Check: `SELECT COUNT(*) FROM autoren WHERE affiliation != '' AND id NOT IN (SELECT autor_id FROM autor_institutionen)`. Wenn > 0: stderr-Fehler, exit 1.

b) Transaktion: Table-Rebuild von `autoren` ohne `affiliation`-Spalte. `CREATE TABLE autoren_new (id PK, vorname TEXT DEFAULT '', nachname TEXT NOT NULL, orcid_id TEXT)`, `INSERT INTO autoren_new SELECT id, vorname, nachname, orcid_id FROM autoren`, `DROP TABLE autoren`, `ALTER TABLE autoren_new RENAME TO autoren`, `CREATE INDEX idx_autoren_nachname`.

c) Commit, success-Meldung.

- [ ] **Step 2: Auf Backup-Kopie testen**

```
cp public/data/proceedings.db /tmp/test_dropcol.db
php bin/cleanup_drop_affiliation.php /tmp/test_dropcol.db
sqlite3 /tmp/test_dropcol.db "PRAGMA table_info(autoren)"
```
Erwartet: keine `affiliation`-Spalte mehr.

- [ ] **Step 3: Auf Live-DB ausführen mit Backup**

```
cp public/data/proceedings.db database/backups/proceedings_pre_drop_$(date +%Y-%m-%d_%H%M).db
php bin/cleanup_drop_affiliation.php public/data/proceedings.db
```

- [ ] **Step 4: Auch `database/schema.sql` updaten** (autoren-DDL ohne affiliation).

- [ ] **Step 5: Commit**

```
git add bin/cleanup_drop_affiliation.php database/schema.sql
git commit -m "feat(cleanup): Phase 5 — autoren.affiliation droppen"
```

---

## Selbst-Review

**Spec-Abdeckung:**

| Spec-Section | Task(s) |
|---|---|
| 4.1 Schema | Task 3 |
| 4.2 Normalisierung | Task 2 |
| 4.3 Lese-Pfad | Tasks 8, 9 |
| 4.3 Schreib-Pfad (Importer) | **GAP** — Folge-Plan |
| 5 Phase 1 Schema | Task 3 |
| 5 Phase 2 Auto-Merge | Tasks 4-7 |
| 5 Phase 3 Subagent Autoren | Tasks 10-11 |
| 5 Phase 4 Affiliations | Task 12 |
| 5 Phase 5 Cleanup | Task 14 |
| 6.1 Suggest | Task 8 |
| 6.2 Search | Task 9 |
| 6.3 Profil-Redirects | Task 9 Step 4 |
| 7 Admin-Tools Dashboard/Queue | Task 13 |
| 7 Manueller Merge/Unmerge | **GAP** — Folge-Plan |
| 8 Risiken | adressiert (Tests + Backups vor jeder Phase) |

**Gaps explizit:**
- Importer-Pfad (`public/admin/pdf_parser.php`) wird nicht angepasst. Neue PDF-Imports nach Migration würden weiter „dumme" Autoren-Records anlegen. → Folge-Plan oder ad-hoc beim nächsten Import.
- Manueller Merge/Unmerge im Admin-UI nicht enthalten. → Folge-Plan.
- Performance-Profiling nach Refactor → manueller Smoke-Test in Task 9.

**Placeholder-Scan:** keine TBD/TODO.

**Type-Consistency:**
- `findAuthorAutoMergeClusters(PDO): array<['key', 'ids']>` — Tasks 4, 5, 7.
- `mergeAuthorCluster(PDO, int[]): int` — Tasks 5, 7, 11.
- `normalizeForAliasMatch(string): string` — Tasks 2, 3, 8, 9, 10, 12.
- `findFuzzyAuthorClusters(PDO): array<['nachname','ids']>` — Tasks 10, 11.

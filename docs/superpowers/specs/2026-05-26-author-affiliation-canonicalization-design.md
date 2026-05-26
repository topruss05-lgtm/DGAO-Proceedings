# Autoren- und Institutions-Kanonisierung

**Status:** Draft (Brainstorming)
**Datum:** 2026-05-26
**Autor:** Tobias Pruß
**Kontext:** [`public/data/proceedings.db`](../../../public/data/proceedings.db), 23 Tagungen (2004–2026), 3.143 Papers, 5.184 Autoren-Records

---

## 1. Problem

Über 20 Jahre DGaO-Tagungen sind in `autoren` und `papers.affiliationen` als reine PDF-Strings importiert worden. Folgen:

| Problem | Quantifiziert |
|---|---|
| Autoren-Records mit Fußnoten-Markern (`*`, `**`, `***`, `†`, `§`, `^`, `‡`) | **545 von 5.184** (10,5 %) |
| Eindeutig zusammenführbare Cluster (nach Stern-Strip exakt gleich) | **407 Cluster, 488 Records einsparbar** |
| ß/ss-Konflikte (`Pruß` vs `Pruss`) | **378 Gruppen** |
| Initialen-Varianten (`C.` vs `Ch.`) als separate IDs | dutzende, Stichprobe Pruß: 5 IDs für 1 Person, 51 Papers |
| Affiliation-Strings (`distinct`) | **1.974** auf 4.380 nicht-leere Records (≈ 3–5× Übersättigung) — z. B. 16 Schreibweisen für „Institut für Technische Optik, Universität Stuttgart" |
| Leere Affiliations | 804 Records |

**Folge für die Such-Qualität (`public/suggest.php`, `public/templates/pages/suche.php`):**
Beide Endpoints sind reine `LIKE %q% COLLATE NOCASE` auf den Rohstrings. Konsequenzen:

* Suche **„Pruss"** findet nicht `Pruß`, `Pruß*`, `Pruß**`, `Ch. Pruß`
* Suche **„ITO Stuttgart"** verfehlt `Institut für Technische Optik, Universität Stuttgart` (Wort-Reihenfolge)
* Eine Person verteilt auf 5 IDs → Autoren-Treffer-Sektion zeigt 5 separate Einträge mit Teilmengen ihrer Papers

**Warum die Sterne überhaupt drin sind:** Im PDF stehen `*`/`**`/… als Fußnoten-Marker, die Autoren zu Affiliations zuordnen. Der frühere Importer war zu primitiv und hat die Marker als Teil des Namens übernommen statt sie nach der Affiliation-Zuordnung wegzuwerfen. Sterne sind **keine** Schreibvarianten — sie werden beim Import konsequent gestrippt und tauchen weder in `autoren` noch in `autor_aliase` je auf.

**Constraint:** Die PDFs sind veröffentlicht und unveränderlich. Display-Strings *in den PDFs* bleiben erhalten. Kanonisierung betrifft nur die DB.

---

## 2. Ziele

1. **Eine kanonische Identität pro Person.** Suche und Profil führen alle Papers einer Person zusammen, unabhängig von Schreibvariante im PDF.
2. **Eine kanonische Institution pro Institut**, mit DE/EN-Namen, Kürzel und Universitäts-Zuordnung.
3. **DE/EN-Lokalisierung nur bei Institutionen** (Institute haben echte Übersetzungen). Personennamen werden **nicht** übersetzt — eine Person hat einen Namen, Punkt. Unicode-Zeichen (`ß`, `é`, `Ω`, …) werden korrekt gespeichert (UTF-8, kein Mapping).
4. **PDF-Wahrheit erhalten.** `papers.autoren_text` / `papers.affiliationen` bleiben unverändert als Anzeige-Strings für die Paper-Detailseite.
5. **Bessere Suche als Nebenprodukt.** Alias-Index ermöglicht Match auf Schreibvarianten und Tippfehler (Ch. Pruss → C. Pruß), ohne dass der User es merkt.
6. **Reversibilität.** Aliase und Merges sind über separate Tabellen rückgängig zu machen, ohne dass Papers ihre Verknüpfung verlieren.

---

## 3. Nicht-Ziele

* Volle ORCID-/ROR-Integration. (`orcid_id` / `ror_id` als nullable Spalten angelegt für die Zukunft, aber jetzt nicht zu pflegen.)
* Co-Autoren-Graph-Algorithmen wie OpenAlex. Subagents bewerten Cluster heuristisch, kein Graph.
* Zeitstrahl-Affiliation („von 2010 bis 2015 an PTB"). Wir speichern „aktuelles Institut" + „frühere Institute" als flache Liste, weil zuverlässige Wechseldaten fehlen.
* Änderungen an Paper-Metadaten oder PDFs selbst.
* Lokalisierung von Personennamen.

---

## 4. Architektur

```
┌────────────────────────────────────────────────────────────────┐
│  papers (unverändert)                                          │
│  ─ autoren_text, affiliationen = PDF-Wahrheit (Read-only)      │
└────────────────────────────────────────────────────────────────┘
                          │
                          │ paper_autoren (unverändert)
                          ▼
┌────────────────────────────────────────────────────────────────┐
│  autoren (kanonisch — 1 Zeile pro echter Person)              │
│  ─ id, vorname, nachname, orcid_id                            │
│  ─ KEINE Sterne, KEINE display_de/_en                          │
└────────────────────────────────────────────────────────────────┘
       │                                              │
       │ autor_aliase (Schreibvarianten)              │ autor_institutionen
       ▼                                              ▼
┌──────────────────────────┐         ┌────────────────────────────────────┐
│  autor_aliase            │         │  institutionen (kanonisch)         │
│  ─ alias_text (ohne *)   │         │  ─ name_de, name_en, kuerzel,      │
│  ─ alias_norm (gematcht) │         │    universitaet, ort, land, ror_id │
└──────────────────────────┘         └────────────────────────────────────┘
                                                    │
                                                    │ institut_aliase
                                                    ▼
                                     ┌──────────────────────────────┐
                                     │  institut_aliase             │
                                     │  ─ alias_text                │
                                     │  ─ alias_norm                │
                                     └──────────────────────────────┘
```

### 4.1 Schema (additiv zu Schema-Version 6 → 7)

```sql
-- ── Autoren (Identity) ──────────────────────────────────────────────
-- Bestehende autoren-Tabelle behält Spalten: id, vorname, nachname.
-- Bestehende Spalte `affiliation` wird in Phase 4 entfernt (überflüssig,
-- ersetzt durch autor_institutionen-Verknüpfung).
ALTER TABLE autoren ADD COLUMN orcid_id TEXT;  -- nullable, NULL für jetzt

-- ── Schreibvarianten (Aliase) ───────────────────────────────────────
CREATE TABLE autor_aliase (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    autor_id     INTEGER NOT NULL REFERENCES autoren(id) ON DELETE CASCADE,
    alias_text   TEXT NOT NULL,            -- z.B. "Ch. Pruß", "C. Pruss" (OHNE Sterne)
    alias_norm   TEXT NOT NULL,            -- normalisiert: "chpruss"
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (autor_id, alias_norm)          -- Auto-Dedup beim Merge
);

CREATE INDEX idx_autor_aliase_norm  ON autor_aliase(alias_norm);
CREATE INDEX idx_autor_aliase_autor ON autor_aliase(autor_id);

-- ── Institutionen (Identity) ────────────────────────────────────────
CREATE TABLE institutionen (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name_de         TEXT NOT NULL,
    name_en         TEXT NOT NULL DEFAULT '',  -- Fallback DE bei leer
    kuerzel         TEXT,                       -- "ITO", "PTB", "LZH"
    universitaet    TEXT,                       -- "Universität Stuttgart" / "TU Ilmenau"
    ort             TEXT,
    land            TEXT DEFAULT 'DE',
    ror_id          TEXT,                       -- nullable
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_institutionen_kuerzel ON institutionen(kuerzel);

CREATE TABLE institut_aliase (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    institut_id     INTEGER NOT NULL REFERENCES institutionen(id) ON DELETE CASCADE,
    alias_text      TEXT NOT NULL,             -- Original-PDF-String
    alias_norm      TEXT NOT NULL,             -- normalisiert für Match
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (institut_id, alias_norm)
);

CREATE INDEX idx_institut_aliase_norm ON institut_aliase(alias_norm);
CREATE INDEX idx_institut_aliase_inst ON institut_aliase(institut_id);

-- ── Verknüpfung Autor ↔ Institut ────────────────────────────────────
-- Pro Autor genau eine Zeile mit ist_aktuell=1, beliebig viele mit 0.
-- "Aktuell" = Institut vom Paper mit der höchsten tagung_nummer dieses Autors.
-- Wird beim Import berechnet (kein gecachtes Datumsfeld, immer dynamisch
-- via JOIN auf paper_autoren + papers).
CREATE TABLE autor_institutionen (
    autor_id        INTEGER NOT NULL REFERENCES autoren(id) ON DELETE CASCADE,
    institut_id     INTEGER NOT NULL REFERENCES institutionen(id) ON DELETE CASCADE,
    ist_aktuell     INTEGER NOT NULL DEFAULT 0,   -- 1 = aktuell, 0 = vergangen
    PRIMARY KEY (autor_id, institut_id)
);

CREATE INDEX idx_autor_inst_aktuell ON autor_institutionen(autor_id, ist_aktuell);

-- ── Redirect-Map für ge-mergede IDs (Audit + URL-Stabilität) ────────
-- Wird in Phase 2 beim Merge gefüllt: alle Duplikat-IDs → Anker-ID.
-- /autor/{alte_id} antwortet 301 auf /autor/{neue_id}.
CREATE TABLE autor_id_redirects (
    alte_id   INTEGER PRIMARY KEY,
    neue_id   INTEGER NOT NULL REFERENCES autoren(id) ON DELETE CASCADE,
    merged_at TEXT NOT NULL DEFAULT (datetime('now'))
);

PRAGMA user_version = 7;
```

### 4.2 Normalisierungs-Funktion

Konsistent in PHP-Helper UND beim Import:

```php
function normalizeForAliasMatch(string $s): string {
    // 1. Sterne und Fußnoten-Marker raus (Importer-Artefakte)
    $s = preg_replace('/[\*†‡§#^]+/u', '', $s);
    // 2. Lowercase
    $s = mb_strtolower($s);
    // 3. ß → ss (häufiger Konflikt-Treiber)
    $s = str_replace('ß', 'ss', $s);
    // 4. Diakritika → ASCII (é → e, Ö → o, …)
    $s = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
    // 5. Punkte, Spaces, Kommas raus
    $s = preg_replace('/[\.\s,]+/u', '', $s);
    return trim($s);
}
```

Beispiele:
* `"C. Pruß*"`     → `"cpruss"`
* `"Ch. Pruss"`    → `"chpruss"` (Achtung: `chpruss` ≠ `cpruss` → Subagent entscheidet Merge)
* `"Müller, H.-P."` → `"mullerhp"`

### 4.3 Datenfluss

**Lese-Pfad (Suggest + Suche):**
1. User-Query `q` → `normalizeForAliasMatch(q)` → `q_norm`
2. Match: `WHERE alias_norm LIKE :q_norm`
3. `JOIN autoren ON autor_aliase.autor_id = autoren.id`
4. `GROUP BY autoren.id` → eine Zeile pro Person, auch wenn mehrere Aliase matchen
5. Display: `autoren.nachname || ", " || autoren.vorname`
6. Affiliation: `JOIN autor_institutionen WHERE ist_aktuell=1 LIMIT 1` → `institutionen.name_de/en` (Locale aus `$_SESSION['lang']`)

**Schreib-Pfad (neue PDF-Imports):**
1. Parser extrahiert `(autor_string, affiliation_string)` aus PDF
2. Sterne strippen, normalisieren → `alias_norm`
3. Lookup in `autor_aliase.alias_norm`:
   * Treffer → `autor_id` aus dem Treffer holen
   * Kein Treffer → neuen `autoren`-Record (gestrippter Name als kanonisch) + Initial-Alias mit dem gestrippten PDF-String
4. Analog für Affiliation gegen `institut_aliase` → `institut_id`
5. `paper_autoren` einfügen. `autor_institutionen` upserten: falls die neue Tagung höher ist als die bisher höchste dieses Autors (Query: `MAX(p.tagung_nummer) FROM paper_autoren pa JOIN papers p ON p.id=pa.paper_id WHERE pa.autor_id=?`), wird `ist_aktuell` umgesetzt — alle bisherigen auf 0, die neue auf 1

---

## 5. Migration (in fünf Phasen, inkrementell und reversibel)

> **Pre-Flight:** Backup der Production-DB unter `database/backups/prod_YYYY-MM-DD.db` (User zieht via scp). Jede Phase läuft zuerst auf einer Kopie, dann erst auf der Live-DB. Vor Phase 2+ jeweils neues Inkremental-Backup.

### Phase 1 — Schema + Initial-Aliase (verlustfrei)

* Schema-Migration in `runMigrations()`, `DB_SCHEMA_VERSION = 7`.
* Für jeden existierenden `autoren`-Record:
  * Sterne aus `vorname`/`nachname` strippen (in-place UPDATE)
  * Initial-Alias mit dem **gestrippten** Original-String erstellen
* Für jeden distinct `papers.affiliationen`-String:
  * Initial-`institutionen`-Record (name_de = Original-String, name_en = '')
  * Initial-Alias
* `autor_institutionen` aus den bestehenden `paper_autoren`-Verknüpfungen füllen:
  * Pro `(autor_id, institut_id)`-Paar eine Zeile
  * `ist_aktuell=1` für genau die Verknüpfung, deren zugehöriges Paper die höchste `tagung_nummer` hat (ermittelt via JOIN `paper_autoren` + `papers`)

**Ergebnis:** Schema steht, funktional kein Unterschied zur alten DB. Bei Rollback einfach Backup einspielen.

### Phase 2 — Quick-Win Auto-Merge (regelbasiert)

Auto-merge ohne Review für Cluster, deren normalisierte Form **exakt** übereinstimmt (nach Stern-Strip):

* Beispiel: `C. Franke`, `C. Franke* **`, `C. Franke** *** ****` → nach Strip alle `c.franke` / `cfranke` → 1 Record

```sql
-- Cluster-Identifikation
SELECT key, GROUP_CONCAT(id, ',') AS ids, COUNT(*) AS n
FROM (
  SELECT id, normalize(vorname) || '|' || normalize(nachname) AS key
  FROM autoren
)
GROUP BY key HAVING n > 1;
```

**Merge-Operation pro Cluster:**
1. **Anker wählen:** Record mit höchster `MAX(papers.tagung_nummer)` (= aktuellster). Bei Gleichstand: niedrigste `autoren.id`.
2. `UPDATE paper_autoren SET autor_id = anker WHERE autor_id IN (duplikate)`
3. `UPDATE autor_institutionen SET autor_id = anker WHERE autor_id IN (duplikate)` — Duplikate via `INSERT OR IGNORE` (PRIMARY KEY auf `(autor_id, institut_id)` schluckt doppelte Paare automatisch)
4. `ist_aktuell` neu berechnen: pro Autor genau eine Verknüpfung = 1, ermittelt via JOIN auf `paper_autoren` + `papers` (Verknüpfung mit höchster Tagungs-Nummer)
5. `UPDATE autor_aliase SET autor_id = anker WHERE autor_id IN (duplikate)` — `UNIQUE(autor_id, alias_norm)` macht Auto-Dedup via `INSERT OR IGNORE`
6. `DELETE FROM autoren WHERE id IN (duplikate)` — sicher, weil keine FKs mehr darauf zeigen
7. Alle Aliase des Ankers behalten die Original-Schreibung (kein Anker-Alias-Verlust)

**Erwartung:** ~407 Cluster → 488 Records gelöscht, alle ihre Schreibvarianten als Aliase am Anker erhalten.

### Phase 3 — Subagent-gestützter Merge für unscharfe Cluster

Cluster, die regelbasiert nicht eindeutig sind:
* ß/ss-Konflikte (378 Gruppen)
* Initialen-Varianten (`C.` vs `Ch.` vs `Chr.`)
* Affiliations-Cluster mit Token-Ähnlichkeit

**Pipeline:**
1. Heuristik baut Cluster-Kandidaten mit Konfidenz-Vorab-Score:
   * Match auf `alias_norm` ohne ß/ss-Unterschied
   * Schnittmenge in Affiliations (mindestens 1 gemeinsames Token, z. B. „Stuttgart")
   * Co-Autoren-Schnittmenge (gemeinsame Co-Autoren in Papers)
   * Zeitliche Überlappung (Tagungs-Nummern-Bereich überlappt)
2. Pro Cluster: **Sonnet-Subagent** mit kompaktem JSON-Prompt:

```json
{
  "task": "are_these_the_same_person",
  "candidates": [
    {"id":50, "name":"C. Pruss", "papers":28,
     "affiliations":["Institut für Technische Optik, Uni Stuttgart"],
     "sample_titles":["Calibration of CGH-based...", "..."]},
    {"id":1466,"name":"C. Pruß", "papers":17, "...": "..."},
    {"id":7524,"name":"Ch. Pruß","papers":2,  "...": "..."}
  ]
}
```
3. Antwort-Format:
```json
{"verdict":"merge"|"keep_separate"|"unsure",
 "confidence":0.0-1.0,
 "groups":[[50,1466,7524]],  // optional bei "merge"
 "reason":"..."}
```
4. Auto-Merge bei `verdict=merge` und `confidence ≥ 0.9` (Phase-2-Logik).
5. Alles andere landet in einer `merge_review_queue`-Tabelle für die Admin-UI.

**Konkurrenz:** parallel via `dispatching-parallel-agents`, Limit ~10 gleichzeitig. Stichproben-Review der ersten 50 auto-merged-by-LLM Fälle vor Roll-out.

### Phase 4 — Affiliations-Konsolidierung

Gleicher Mechanismus für `institutionen`:
1. Token-basiertes Clustering: extrahiere `{kuerzel, universitaet, ort}` aus name_de
2. Auto-merge bei exakter Token-Übereinstimmung (z. B. alle 16 ITO-Stuttgart-Varianten)
3. Subagent für unscharfe Fälle (`"Carl Zeiss AG"` vs `"Carl Zeiss SMT GmbH"` → keep_separate)
4. Manuelle Pflege von `name_en` + `kuerzel` für die Top-N Institute (geschätzt 50–100 decken 80 % der Papers)
5. **Erst danach** die alte `autoren.affiliation`-Spalte droppen (alles über `autor_institutionen`)

### Phase 5 — Cleanup + Index-Refresh

* `autoren.affiliation` Spalte droppen
* `papers_fts` Reindex (nur falls geändert; `autoren_text` selbst bleibt unverändert, also vermutlich nicht nötig)
* Stichproben-Test der Suche: 20 typische Queries, Vergleich Vor/Nach

---

## 6. Suggest/Search-Anpassungen

### 6.1 Suggest (`public/suggest.php`)

Autor-Query gegen `autor_aliase.alias_norm`:

```sql
SELECT a.id, a.vorname, a.nachname,
       (SELECT i.name_de FROM autor_institutionen ai
        JOIN institutionen i ON i.id = ai.institut_id
        WHERE ai.autor_id = a.id AND ai.ist_aktuell = 1 LIMIT 1) AS aff_de,
       (SELECT i.name_en FROM autor_institutionen ai
        JOIN institutionen i ON i.id = ai.institut_id
        WHERE ai.autor_id = a.id AND ai.ist_aktuell = 1 LIMIT 1) AS aff_en,
       COUNT(DISTINCT pa.paper_id) AS papers
FROM autoren a
JOIN autor_aliase al ON al.autor_id = a.id
JOIN paper_autoren pa ON pa.autor_id = a.id
WHERE al.alias_norm LIKE :q_norm
GROUP BY a.id
HAVING papers > 0
ORDER BY papers DESC, a.nachname COLLATE NOCASE
LIMIT 5
```

Display: `nachname, vorname` (kein DE/EN-Switch bei Personen).
Affiliation: `name_de` oder `name_en` je nach `$_SESSION['lang']`, Fallback DE bei leerem EN.

### 6.2 Search-Page (`public/templates/pages/suche.php`)

* **Autor-Filter** matcht jetzt gegen `autor_aliase.alias_norm` → resolve auf `autoren.id` → `paper_autoren` → Papers (statt direkter LIKE auf `autoren.nachname`).
* **Institutions-Filter** analog gegen `institut_aliase.alias_norm` + `institutionen.kuerzel`.
* **FTS-Index** `papers_fts` bleibt wie er ist (über `papers.autoren_text` = PDF-Wahrheit). Zusätzliche Filter werden post-FTS via Join auf `autoren`/`institutionen` angewendet — wer nach „Pruß" sucht, bekommt auch Papers wo `Ch. Pruss` im PDF-Text steht, weil `Ch. Pruss` als Alias auf den gleichen `autor_id` zeigt und die Paper-Liste der Person über `paper_autoren` resolved wird.

### 6.3 Autor-Profilseite (`/autor/{id}`)

* URL bleibt `/autor/{autoren.id}` — kein Redirect nötig, weil keine Records „weggemergt" werden in einer URL-relevanten Form (Duplikate werden gelöscht, ihre IDs waren ohnehin meist nicht öffentlich verlinkt; für die wenigen relevanten kann eine Redirect-Map als `WHERE alte_id → neue_id` in einer winzigen Hilfstabelle gepflegt werden — siehe Phase 2).
* Anzeige: Name, alle Papers gruppiert nach Tagung, aktuelles Institut, frühere Institute (Sortierung der Vergangenen: nach höchster Tagung wo diese Verknüpfung gesehen wurde, DESC — via JOIN ermittelt).
* Aliase werden **nicht** öffentlich angezeigt (nur im Admin).

### 6.4 Redirect-Map

`autor_id_redirects` (siehe 4.1) wird in Phase 2 beim Merge gefüllt. Im Router:
* `/autor/{id}` → wenn `id` in `autor_id_redirects.alte_id` → 301 auf `/autor/{neue_id}`
* sonst normales Lookup

---

## 7. Admin-Tools

Neue Routes unter `/admin/cleanup/`:

* `/admin/cleanup/dashboard` — Übersicht: Records, Aliase, Auto-Merges, Queue-Größe
* `/admin/cleanup/queue` — Subagent-Vorschläge mit „Approve / Reject / Edit"
* `/admin/cleanup/authors/{id}` — manuelle Bearbeitung (Name, Aliase, Institute)
* `/admin/cleanup/institutions/{id}` — analog, plus DE/EN-Pflege
* `/admin/cleanup/merge` — manueller Merge zweier Records (IDs eingeben)
* `/admin/cleanup/unmerge/{alias_id}` — Alias abspalten: neuer `autoren`-Record, dieser Alias zeigt darauf, `paper_autoren`-Verknüpfungen werden anhand des Aliases im `papers.autoren_text` rekonstruiert (manueller Review bei mehrdeutigen Treffern)

---

## 8. Risiken & Mitigation

| Risiko | Mitigation |
|---|---|
| Auto-Merge fügt zwei verschiedene Personen zusammen (z. B. zwei `M. Müller`) | Auto-Merge nur bei **exakter** Normalisierung. Unscharfe Cluster gehen durch Subagent + Review-Queue. |
| Subagent halluziniert „merge" | Konfidenz-Threshold ≥ 0,9. Stichproben-Review der ersten 50 LLM-merged Fälle vor Roll-out. |
| FTS-Index out of sync nach Merge | FTS basiert auf `papers.autoren_text` (unverändert) — kein Re-Index nötig. |
| Schema-Migration auf Production hängt | Migration zuerst auf lokaler Kopie testen; Production-Migration in Wartungsfenster, Backup direkt davor. |
| Performance: `autor_aliase`-JOIN macht Suggest langsam | Index auf `alias_norm` + `LIMIT 5`. Vorab `EXPLAIN QUERY PLAN`. |
| ORCID/ROR kommen später | Spalten schon nullable angelegt, keine spätere Schema-Änderung nötig. |
| Unmerge nach Auto-Merge fehlerhaft | Beim Auto-Merge wird `autor_id_redirects` gefüllt → Audit-Trail. Aliase tragen ihren Original-`alias_text`, daraus + `papers.autoren_text` lassen sich Papers rekonstruieren. |

---

## 9. Implementierungs-Reihenfolge

1. **Pre-Flight:** Backup-Pull, Spec-Approval, Test-Script auf Kopie
2. **Migration M-007:** Schema (Phase 1), in `db.php::runMigrations()`
3. **Cleanup-CLI Phase 2:** `bin/cleanup_phase2.php` — regelbasierter Auto-Merge
4. **Suggest/Search-Refactor:** auf `autor_aliase`/`institut_aliase` umstellen
5. **Admin-UI Basics:** Dashboard + Queue-Anzeige
6. **Cleanup-CLI Phase 3:** Subagent-Pipeline (parallel, Konkurrenz-Limit)
7. **Cleanup-CLI Phase 4:** Affiliations, DE/EN-Pflege
8. **Cleanup-CLI Phase 5:** `autoren.affiliation`-Spalte droppen, finale Checks
9. **Admin-UI Full:** Merge/Unmerge-Tools, manuelle Edits

---

## 10. Erfolgs-Kriterien

* `autoren`-Records reduziert von 5.184 auf ~3.500–4.000
* `institutionen`-Records ~300–500 statt 1.974 distinct Strings
* Suche nach `Pruss` und `Pruß` liefert identisches Ergebnis (1 Person, 51 Papers)
* Suche nach `ITO` liefert alle Stuttgart-ITO-Autoren in einem Block
* Top-50 Institute haben `name_en` und `kuerzel` gepflegt
* Keine `*`/`†`/`‡`/`§`/`#`/`^` mehr in `autoren.nachname`/`autoren.vorname` oder `autor_aliase.alias_text`
* Unmerge eines Aliases stellt Original-Zustand wieder her

---

## Anhang A — Beispiel C. Pruß

**Vorher (5 separate Records, 51 Papers verteilt):**
```
id=50    "C." "Pruss"      affiliation="Institut für Technische Optik, Uni Stuttgart"  28 papers
id=1466  "C." "Pruß"       affiliation="Institut für Technische Optik, Uni Stuttgart"  17 papers
id=7524  "Ch." "Pruß"      affiliation="Institut für Technische Optik, Uni Stuttgart"   2 papers
id=11383 "C." "Pruß*"      affiliation=""                                                3 papers
id=12520 "C." "Pruß**"     affiliation=""                                                1 paper
```

**Nachher (1 Record, 51 Papers, 4 Aliase, 1 Institut):**
```
autoren:
  id=50, vorname="C.", nachname="Pruß", orcid_id=NULL

autor_aliase (Auto-Dedup via UNIQUE(autor_id, alias_norm)):
  (50, "C. Pruss",  "cpruss")   ← Erste Einfügung gewinnt
  (50, "Ch. Pruß",  "chpruss")
  ↑ "C. Pruß"  → norm "cpruss"  → bereits vorhanden, INSERT OR IGNORE schluckt es
  ↑ "C. Pruß*" → strip → "C. Pruß" → norm "cpruss"  → ebenfalls dedupliziert
  ↑ "C. Pruß**" → analog → dedupliziert
  Aliase werden ohnehin nicht öffentlich angezeigt; die Wahl des alias_text bei
  Normalisierungs-Konflikt ist daher irrelevant.

institutionen:
  id=42, name_de="Institut für Technische Optik (ITO), Universität Stuttgart",
         name_en="Institute of Applied Optics (ITO), University of Stuttgart",
         kuerzel="ITO", universitaet="Universität Stuttgart", ort="Stuttgart"

institut_aliase:
  (42, "Institut für Technische Optik, Universität Stuttgart",   "institutfurtechnischeoptikunistuttgart")
  (42, "ITO, Universität Stuttgart",                              "itounistuttgart")
  ... (16 Varianten)

autor_institutionen:
  (50, 42, ist_aktuell=1)

paper_autoren: 51 Zeilen, alle autor_id=50 (vorher auf 5 IDs verteilt)

autor_id_redirects:
  1466  → 50
  7524  → 50
  11383 → 50
  12520 → 50
```

Frontend zeigt:
* `/autor/50`        → „Pruß, C." · ITO Stuttgart · 51 Papers
* `/autor/1466`      → 301-Redirect auf `/autor/50`
* Suche „Ch. Pruss"  → matcht Alias `chpruss` → zeigt „Pruß, C." mit 51 Papers
* Suche „pruss"      → matcht Aliase `cpruss` UND `chpruss` → zeigt „Pruß, C." mit 51 Papers (deduped via GROUP BY)

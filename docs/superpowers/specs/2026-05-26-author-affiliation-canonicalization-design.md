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
* Keine Synonyme, keine Aliase, kein Tippfehler-Mapping

**Constraint:** Die PDFs sind veröffentlicht und unveränderlich. Die Display-Strings *in den PDFs* (z. B. `C. Pruß*`) müssen erhalten bleiben — Kanonisierung darf nur die DB-Schicht betreffen.

---

## 2. Ziele

1. **Eine kanonische Identität pro Person.** Suche und Profil führen alle Papers einer Person zusammen, unabhängig von Schreibvariante im PDF.
2. **Eine kanonische Institution pro Institut.** Suche nach „ITO Stuttgart", „Institut für Technische Optik" oder „Universität Stuttgart" liefert dieselbe Institution mit allen zugehörigen Autoren.
3. **DE/EN-Lokalisierung der Display-Strings.** Institute haben Namen in beiden Sprachen; das UI rendert nach Seitensprache.
4. **PDF-Treue.** PDFs werden nicht angefasst; `papers.autoren_text`/`papers.affiliationen` bleiben als „PDF-Wahrheit" erhalten.
5. **Bessere Suche als Nebenprodukt.** Alias-Index ermöglicht Match auf Schreibvarianten und Tippfehler (Ch. Pruss → C. Pruß), ohne dass User das wissen müssen.
6. **Reversibilität.** Jeder Merge ist via Soft-Link rückgängig zu machen. Keine harten Delete-Operationen ohne Backup.

---

## 3. Nicht-Ziele

* Volle ORCID-/ROR-Integration. (Optional Feld `ror_id` / `orcid_id` für die Zukunft, aber nicht für diese Iteration zu pflegen.)
* Co-Autoren-Graph-basierte Disambiguierung wie OpenAlex. (Subagents nutzen wir nur für Cluster-Bewertung, kein Graph-Algorithmus.)
* Zeitstrahl-Affiliation („von 2010 bis 2015 an PTB"). Wir speichern „aktuelles Institut" + „frühere Institute" als flache Liste, weil wir keine zuverlässigen Wechseldaten haben.
* Änderungen an Paper-Metadaten oder PDFs selbst.

---

## 4. Architektur — drei Schichten

```
┌──────────────────────────────────────────────────────────────┐
│  Layer 3: PDF-Wahrheit (read-only)                          │
│  papers.autoren_text, papers.affiliationen                  │
│  ─ bleibt unverändert                                        │
└──────────────────────────────────────────────────────────────┘
                          │
                          │ (paper_autoren existiert schon)
                          ▼
┌──────────────────────────────────────────────────────────────┐
│  Layer 1: Canonical Authors                                  │
│  autoren (bereinigt) + autor_aliase                         │
│  ─ pro Person 1 aktiver Record                              │
│  ─ alle früheren Schreibvarianten als Aliase                │
└──────────────────────────────────────────────────────────────┘
                          │
                          │ (autor_institutionen N:M)
                          ▼
┌──────────────────────────────────────────────────────────────┐
│  Layer 2: Canonical Institutions                            │
│  institutionen + institut_aliase                            │
│  ─ DE/EN Display, Kürzel, Universität, Ort                  │
└──────────────────────────────────────────────────────────────┘
```

### 4.1 Schema (additiv zu Schema-Version 6)

```sql
-- Layer 1: Canonical Authors
ALTER TABLE autoren ADD COLUMN display_de   TEXT NOT NULL DEFAULT '';
ALTER TABLE autoren ADD COLUMN display_en   TEXT NOT NULL DEFAULT '';
ALTER TABLE autoren ADD COLUMN merged_into  INTEGER REFERENCES autoren(id);
ALTER TABLE autoren ADD COLUMN orcid_id     TEXT;  -- optional, NULL für jetzt
ALTER TABLE autoren ADD COLUMN canonical    INTEGER NOT NULL DEFAULT 1;

CREATE INDEX idx_autoren_merged_into ON autoren(merged_into);
CREATE INDEX idx_autoren_canonical   ON autoren(canonical);

CREATE TABLE autor_aliase (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    autor_id     INTEGER NOT NULL REFERENCES autoren(id),
    alias_text   TEXT NOT NULL,            -- z.B. "Ch. Pruß", "C. Pruss*"
    alias_norm   TEXT NOT NULL,            -- normalisiert: "chpruss" (lowercase, ohne *, ß→ss, ohne Punkte/Spaces)
    source       TEXT NOT NULL CHECK (source IN ('pdf','merge','manual')),
    created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_autor_aliase_norm  ON autor_aliase(alias_norm);
CREATE INDEX idx_autor_aliase_autor ON autor_aliase(autor_id);

-- Layer 2: Institutions
CREATE TABLE institutionen (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name_de         TEXT NOT NULL,
    name_en         TEXT NOT NULL DEFAULT '',  -- Fallback DE
    kuerzel         TEXT,                       -- "ITO", "PTB", "LZH"
    universitaet    TEXT,                       -- "Universität Stuttgart"
    ort             TEXT,
    land            TEXT DEFAULT 'DE',
    ror_id          TEXT,                       -- optional, NULL für jetzt
    merged_into     INTEGER REFERENCES institutionen(id),
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_institutionen_kuerzel ON institutionen(kuerzel);
CREATE INDEX idx_institutionen_merged_into ON institutionen(merged_into);

CREATE TABLE institut_aliase (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    institut_id     INTEGER NOT NULL REFERENCES institutionen(id),
    alias_text      TEXT NOT NULL,             -- Original-PDF-String
    alias_norm      TEXT NOT NULL,             -- normalisiert für Match
    source          TEXT NOT NULL CHECK (source IN ('pdf','merge','manual'))
);

CREATE INDEX idx_institut_aliase_norm  ON institut_aliase(alias_norm);
CREATE INDEX idx_institut_aliase_inst  ON institut_aliase(institut_id);

-- Verknüpfung Autor ↔ Institut (N:M, flache Liste mit "aktuell"-Flag)
CREATE TABLE autor_institutionen (
    autor_id        INTEGER NOT NULL REFERENCES autoren(id),
    institut_id     INTEGER NOT NULL REFERENCES institutionen(id),
    ist_aktuell     INTEGER NOT NULL DEFAULT 0,  -- 1 = vom letzten Paper (Heuristik)
    last_seen       INTEGER,                      -- höchste tagung_nummer mit dieser Verknüpfung
    first_seen      INTEGER,                      -- niedrigste tagung_nummer
    PRIMARY KEY (autor_id, institut_id)
);

CREATE INDEX idx_autor_inst_aktuell ON autor_institutionen(autor_id, ist_aktuell);

PRAGMA user_version = 7;
```

### 4.2 Datenfluss

**Lese-Pfad (Suggest + Suche):**
1. User-Query → normalisiert (lowercase, `ß→ss`, Diakritika weg, Punkte/Spaces weg)
2. Match gegen `autor_aliase.alias_norm` (Substring/LIKE) und `institut_aliase.alias_norm`
3. Resolve via `autor_aliase.autor_id` → `autoren` (nur `canonical=1` UND `merged_into IS NULL`)
4. Display: `display_de` oder `display_en` je nach `$_SESSION['lang']` (selbe Quelle wie bestehender DE/EN-Switch); Fallback DE bei leerem EN.
5. Affiliation im Suggest: `JOIN autor_institutionen WHERE ist_aktuell=1 LIMIT 1` → `institutionen.name_de/en` (gleiche Locale-Quelle)

**Schreib-Pfad (neue PDF-Imports):**
1. Parser extrahiert `(autor_string, affiliation_string)` aus PDF (wie bisher)
2. `autor_string` wird normalisiert → Lookup in `autor_aliase.alias_norm`
   * Treffer → bestehender `autor_id`
   * Kein Treffer → neuer `autoren`-Record + Initial-Alias = PDF-String
3. Analog für Affiliation gegen `institut_aliase`
4. `paper_autoren` und `autor_institutionen` werden upgesert (`last_seen` ggf. aktualisiert)

---

## 5. Migration (in vier Phasen, inkrementell und reversibel)

> **Pre-Flight:** Backup der Production-DB liegt unter `database/backups/prod_YYYY-MM-DD.db` (User zieht via scp). Migrations-Script läuft auf einer **Kopie**, das Ergebnis wird verglichen, dann erst über die Live-DB gespielt.

### Phase 1 — Schema + Initial-Aliase (verlustfrei)

* Schema-Migration in `runMigrations()`, `DB_SCHEMA_VERSION = 7`.
* Für jeden existierenden `autoren`-Record: Initial-Display generieren (`display_de = "{nachname_clean}, {vorname_clean}"`, Sterne entfernt), Initial-Alias = aktueller PDF-String anlegen.
* Für jeden distinct `(papers.affiliationen)`-String pro Paper: Initial-`institutionen`-Record + Alias anlegen.
* `autor_institutionen` füllen aus den existierenden `paper_autoren`-Verknüpfungen mit `last_seen = MAX(papers.tagung_nummer)`, `first_seen = MIN(...)`, `ist_aktuell = 1` für den Record mit höchstem `last_seen` pro Autor.

**Ergebnis:** Schema steht, jede Person hat noch genau ihren bisherigen Record + 1 Alias. Funktional kein Unterschied zur alten DB. Reversibel durch Schema-Rollback (Backup einspielen).

### Phase 2 — Quick-Win Auto-Merge (regelbasiert, hohe Konfidenz)

Auto-merge ohne Review für Cluster, deren normalisierte Form **exakt** übereinstimmt:

* Stern-/Sonderzeichen-Stripping (`*`, `**`, `†`, `‡`, `§`, `#`, `^`)
* Whitespace-Trim, Punkt-Normalisierung
* Beispiel: `C. Franke`, `C. Franke*`, `C. Franke* **`, `C. Franke** *** ****` → 1 Record

**Cluster-Identifikation:**
```sql
SELECT key, GROUP_CONCAT(id) AS ids, COUNT(*) AS n
FROM (SELECT id, normalize(vorname) || '|' || normalize(nachname) AS key FROM autoren)
GROUP BY key HAVING n > 1
```

**Merge-Operation:**
1. Wähle „Anker"-Record (höchste `tagung_nummer` aus angehängten Papers → aktuellster)
2. Anker behält `canonical=1`, alle anderen bekommen `canonical=0`, `merged_into=<anker_id>`
3. `paper_autoren` und `autor_institutionen` werden auf den Anker umgehängt (`UPDATE ... SET autor_id = anker_id WHERE autor_id IN duplicates`); `autor_institutionen`-Duplikate werden via `ON CONFLICT` zusammengeführt (`MAX(last_seen)`, `MIN(first_seen)`)
4. `ist_aktuell` wird neu berechnet: genau eine Verknüpfung pro Autor mit `ist_aktuell=1`, nämlich die mit dem höchsten `last_seen`
5. Bestehende Aliase der Duplikate werden auf den Anker umgehängt
6. Display wird aus Anker neu generiert

**Erwartung:** ~407 Cluster → 488 Records entfernt (laut Analyse), 0 manuelle Reviews.

### Phase 3 — Subagent-gestützter Merge für unscharfe Cluster

Für Cluster, die regelbasiert nicht eindeutig sind:
* ß/ss-Konflikte (378 Gruppen): `Pruss` vs `Pruß` — fast immer gleich, aber Edge Cases
* Initialen-Varianten: `C.` vs `Ch.` vs `Chr.` — oft, aber nicht immer gleich
* Affiliations-Cluster mit Levenshtein-Distanz < threshold

**Pipeline:**
1. Heuristik baut Cluster-Vorschläge mit Konfidenz-Score:
   * Match auf normalisiertem Namen (ohne ß/ss-Unterschied)
   * Überschneidung in Affiliations (mindestens ein Token gemeinsam, z. B. „Stuttgart")
   * Co-Autoren-Schnittmenge (gemeinsame Autoren in Papers)
   * Zeitliche Überlappung (Tagungs-Nummern)
2. Pro Cluster wird ein **Sonnet-Subagent** mit Kontext gestartet:
   * Alle Schreibvarianten + jeweils 2–3 Paper-Titel + Affiliation-Strings
   * Frage: „Ist das wahrscheinlich dieselbe Person? Antwort: yes / no / unsure + Begründung."
3. Bei `yes` mit Konfidenz ≥ X: Auto-Merge (Phase-2-Logik)
4. Bei `no`/`unsure`: in `merge_review_queue` (neue Tabelle, nur für Admin-UI)

**Subagent-Prompt-Template** (Kurzversion):
```
Du beurteilst, ob zwei oder mehr Autoren-Schreibvarianten dieselbe Person sind.

Kandidaten:
  [1] "Ch. Pruß" — 2 Papers, Affiliation: "Institut für Technische Optik, Universität Stuttgart"
      Paper-Titel: "Calibration of CGH-based asphere measurement", "..."
  [2] "C. Pruss" — 28 Papers, Affiliation: "Institut für Technische Optik, Universität Stuttgart"
      Paper-Titel: "...", "..."

Frage: Sind das dieselbe Person? Antworte mit JSON:
  {"verdict": "yes"|"no"|"unsure", "confidence": 0.0-1.0, "reason": "..."}

Konservative Regel: bei Zweifel "unsure".
```

**Wichtig:** Subagents parallel dispatchen (siehe `dispatching-parallel-agents`), aber gebatcht mit Konkurrenz-Limit ~10 — wir reden über hunderte Cluster. Verbrauch kontrollieren.

### Phase 4 — Affiliations-Konsolidierung (analog)

Gleicher Mechanismus für `institutionen`:
1. Token-basiertes Clustering: `{kuerzel, universitaet, ort}` extrahieren
2. Auto-merge bei exakter Token-Übereinstimmung
3. Subagent für unscharfe Fälle (z. B. „Carl Zeiss AG" vs „Carl Zeiss SMT GmbH" — DIFFERENT)
4. Manuelle Pflege von `name_en` für die Top-N Institute (geschätzt 50–100 decken 80 % der Papers)

---

## 6. Suggest/Search-Anpassungen

### 6.1 Normalisierungs-Helper (`public/helpers.php`)

```php
function normalizeForAliasMatch(string $s): string {
    // lower, ß→ss, Diakritika→ASCII, Sterne/Sonderzeichen/Spaces/Punkte weg
    $s = mb_strtolower($s);
    $s = str_replace(['ß'], ['ss'], $s);
    $s = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
    $s = preg_replace('/[\*\†\‡\§\#\^\.\s,]+/u', '', $s);
    return $s;
}
```

### 6.2 Suggest-Endpoint (`public/suggest.php`)

* Author-Query: gegen `autor_aliase.alias_norm` joinen statt direkt gegen `autoren.nachname/vorname`
* `GROUP BY autoren.id`, nur `canonical=1` und `merged_into IS NULL`
* Display: `display_de`/`display_en` je nach Locale
* Affiliation im Treffer: aus `autor_institutionen WHERE ist_aktuell=1` → `institutionen.name_de/en`

### 6.3 Search-Page (`public/templates/pages/suche.php`)

* Autor-Filter analog
* Institutions-Filter `:inst` matcht jetzt gegen `institut_aliase.alias_norm` + `institutionen.name_de/en` + `kuerzel`
* FTS-Index `papers_fts` bleibt wie er ist (PDF-Wahrheit), aber **zusätzlich** wird bei Autor-/Institut-Filter über die kanonischen IDs gejoint — d. h. wer „Pruß" sucht, bekommt auch Papers von `Ch. Pruss`-Aliasen.

### 6.4 Autor-Profilseite (`/autor/{id}`)

* Falls `merged_into IS NOT NULL`: 301-Redirect auf den kanonischen Record
* Anzeige aller Aliase als „auch bekannt als" (nur intern in Admin sichtbar, nicht öffentlich — laut Entscheidung „nur kanonische Form, Match unsichtbar")
* Aktuelles Institut: `ist_aktuell=1`
* Frühere Institute: alle anderen, sortiert nach `last_seen DESC`

---

## 7. Admin-Tools

Neue Routes unter `/admin/cleanup/`:

* `/admin/cleanup/dashboard` — Übersicht: wie viele Cluster gemerged, wie viele in Review-Queue
* `/admin/cleanup/queue` — Merge-Vorschläge der Subagents mit „Approve / Reject / Edit"
* `/admin/cleanup/authors/{id}` — manuelle Autoren-Bearbeitung (Display, Aliase, Institute)
* `/admin/cleanup/institutions/{id}` — analog für Institute, plus DE/EN-Pflege
* `/admin/cleanup/merge` — manueller Merge zweier Records (Drag & Drop oder ID-Eingabe)
* `/admin/cleanup/unmerge/{id}` — Rückgängig-Operation (`merged_into = NULL`, `canonical = 1`, Verknüpfungen zurück)

---

## 8. Risiken & Mitigation

| Risiko | Mitigation |
|---|---|
| Auto-Merge fügt zwei verschiedene Personen zusammen (z. B. zwei `M. Müller`) | Auto-Merge nur bei **exakter** Normalisierung. Unscharfe Cluster gehen durch Subagent + Review-Queue. Soft-Merge ist reversibel. |
| Subagent halluziniert „yes" | Konfidenz-Threshold ≥ 0,9 für Auto. Stichproben-Review der ersten 50 auto-merged-by-LLM Fälle vor Roll-out. |
| FTS-Index out of sync nach Merge | FTS basiert auf `papers.autoren_text` (PDF-Wahrheit, unverändert) — kein Re-Index nötig. |
| Schema-Migration auf Production hängt | Migration zuerst auf lokaler Kopie testen; Production-Migration in Wartungsfenster, Backup direkt davor. |
| Performance: `autor_aliase`-JOIN macht Suggest langsam | Index auf `alias_norm` + `LIMIT` im Suggest (bereits da, max 5 Authors). Vorab Profiling mit `EXPLAIN QUERY PLAN`. |
| ORCID kommt später → Schema-Änderung | `orcid_id`/`ror_id` jetzt schon nullable angelegt, Pflege später. |

---

## 9. Implementierungs-Reihenfolge

1. **Pre-Flight:** Backup-Pull, Spec-Approval, kleines Migrations-Test-Script auf Kopie
2. **Migration M-007:** Schema (Phase 1), in `db.php::runMigrations()`
3. **Cleanup-Script Phase 2:** Auto-Merge (regelbasiert), als `bin/cleanup_phase2.php` CLI
4. **Suggest/Search-Refactor:** auf `autor_aliase`/`institut_aliase` umstellen, alte LIKE-Pfade entfernen
5. **Admin-UI Basics:** Dashboard + Queue-Anzeige (ohne Subagent-Output erstmal)
6. **Cleanup-Script Phase 3:** Subagent-Pipeline als CLI mit Konkurrenz-Limit
7. **Cleanup-Script Phase 4:** Affiliations, DE/EN-Pflege für Top-100
8. **Admin-UI Full:** Merge/Unmerge-Tools, manuelle Edits

---

## 10. Erfolgs-Kriterien

* Anzahl `autoren WHERE canonical=1 AND merged_into IS NULL` reduziert sich von 5.184 auf ~3.500–4.000 (Schätzung)
* Suche nach `Pruss` und `Pruß` liefert identisches Ergebnis
* Suche nach `ITO` liefert alle Stuttgart-ITO-Autoren in einem Block
* Top-50 Institute haben `name_en` gepflegt
* Keine harten Deletes in `autoren`; jede Merge-Operation in `merged_into` rückgängig machbar

---

## Anhang A — Beispiele aus der aktuellen DB

```
C. Pruß cluster (5 Records, 51 Papers):
  id=50    "C. Pruss"     Affiliation: "Institut für Technische Optik..."  28 Papers
  id=1466  "C. Pruß"      Affiliation: "Institut für Technische Optik..."  17 Papers
  id=7524  "Ch. Pruß"     Affiliation: "Institut für Technische Optik..."   2 Papers
  id=11383 "C. Pruß*"     Affiliation: ""                                   3 Papers
  id=12520 "C. Pruß**"    Affiliation: ""                                   1 Paper

→ Nach Merge:
  id=50 (canonical)
    display_de = "Pruß, C."
    display_en = "Pruß, C."
    Aliase:    "C. Pruss", "C. Pruß", "Ch. Pruß", "C. Pruß*", "C. Pruß**"
    Aktuell:   Institut für Technische Optik, Universität Stuttgart (ITO)
```

```
ITO Stuttgart cluster (16+ Schreibweisen):
  "Institut für Technische Optik, Universität Stuttgart"            69 Records
  "Institut für Technische Optik (ITO), Universität Stuttgart"       9
  "Institut für technische Optik, Universität Stuttgart"             6
  "Institut für Technische Optik, Universität Stuttgart, Pfaffen..." 4
  ...

→ Nach Merge:
  id=N
    name_de:       "Institut für Technische Optik (ITO), Universität Stuttgart"
    name_en:       "Institute of Applied Optics (ITO), University of Stuttgart"
    kuerzel:       "ITO"
    universitaet:  "Universität Stuttgart"
    ort:           "Stuttgart"
    Aliase:        alle 16+ Schreibweisen
```

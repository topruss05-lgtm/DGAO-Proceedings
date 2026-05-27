# Vorname-Vervollständigung & zeitliche Affiliation-Modellierung — Design

**Datum:** 2026-05-27
**Status:** Entwurf zur Review
**Vorgänger-Spec:** [2026-05-26-author-affiliation-canonicalization-design.md](2026-05-26-author-affiliation-canonicalization-design.md) (3-Schichten-Modell, abgeschlossen)

## Ziel

Zwei verzahnte Verbesserungen der bereinigten DGaO-Datenbank, **parallel** umgesetzt:

- **Strang A — Vornamen:** Initialen (`M. Wende`) zu vollen Vornamen (`Marco Wende`) vervollständigen — einheitliche Darstellung, bessere Suche. Anspruch: **nahe 100 % korrekt, 0 Falsch-Einträge** (lieber leer lassen als raten).
- **Strang B — Zeitliche/mehrfache Affiliations:** Affiliations mit Jahres-Information und mehreren (historischen + parallelen) Zugehörigkeiten modellieren, statt einer einzigen statischen pro Autor.

## Kernprinzip: 0 Fehler vor Vollständigkeit

Jeder geschriebene Wert braucht eine belegbare Quelle. Unsichere Fälle bleiben leer und werden für manuelle/LLM-Nachbearbeitung markiert — **kein Raten**. Jeder Wert trägt eine `quelle`-Kennzeichnung für Nachvollziehbarkeit.

## Datenquellen (nach Priorität)

Beide Stränge schöpfen aus denselben vier Quellen, in dieser Reihenfolge:

| Prio | Quelle | Liefert | Abdeckung | Determinismus |
|------|--------|---------|-----------|---------------|
| 1 | **Paper-PDF** (`public/download/<t>/<file>.pdf`, 1478 St.) | voller Vorname **und** Autor→Affil-Marker (`*`/`**`) | ~70-85 % (Encoding-abhängig) | hoch, wenn lesbar |
| 2 | **OpenAlex API** (kostenlos, `mailto`) | Vorname, ORCID, Affil-Historie mit Jahren, `raw_affiliation_strings` | ~60-70 % (etablierte Forscher) | hoch bei eindeutigem Match |
| 3 | **Single-Affil-Anker** (DB-intern) | Affiliation eines Autors aus seinen Solo-Affil-Papers | deckt Multi-Affil-Lücken | hoch |
| 4 | **LLM** (Sonnet-Subagent / Opus) | Urteil + Web-Recherche für harten Rest | Restmenge | mittel, nur ≥ Konfidenzschwelle |

### Belegte Fakten zur Datenlage

- **Vornamen:** 4481 Autoren mit Initial-Vorname; 1603 mit ≥ 2 Papers.
- **PDF:** 1478 Papers mit PDF. Lesbarkeit variiert pro Datei (nicht pro Jahr). Lesbare PDFs enthalten volle Vornamen + Superscript-Marker (verifiziert an 118-b1/2017).
- **Affil-Verknüpfungen:** 6778 (59 %) aus Single-Affil-Papers (deterministisch), 4750 (41 %) aus Multi-Affil-Papers.
- **Multi-Affil-Marker:** in `papers.autoren_text` nur für 2024-2025 erhalten (Stern). 2004-2023: Superscript-Ziffern beim Import verloren → **nur aus PDF rekonstruierbar**.
- **OpenAlex:** liefert `affiliations[]` mit `years` (historisch + parallel), `last_known_institutions`, `display_name_alternatives`, ORCID, `raw_affiliation_strings` aus Works.

## Strang A — Vollständige Namens-Auflösung

Pro Autor wird ermittelt: kanonischer **Vorname** (vollständigster verifizierter Rufname), **Nachname**, **ORCID**, und **alle Schreibvarianten als Aliase**.

### Namensformen in der DB (Stand 2026-05-27)

- 4221 Autoren mit einer Initiale (`C.`)
- 266 mit mehreren Initialen (`C. A.` — Mittelinitialen, z. B. `H. J. Tiziani`)
- 28 mit vollem Vornamen
- **ORCID: 0 von 4515 befüllt** → wird in dieser Phase befüllt

### Behandlung von Mittelinitialen (`H. J. Tiziani`)

- `vorname` = Rufname (`Hans`) → Anzeige "Hans Tiziani"
- Mittelname/-initiale (`J.` / `Joachim`) landet in den **Aliasen**, nicht im Hauptfeld
- alle Kombinationen als Aliase → Suche findet den Autor unter jeder Schreibweise

### Auflösungs-Pipeline pro Autor (gestuft, Abbruch beim ersten sicheren Treffer)

1. **PDF-Extraktion:** In den Paper-PDFs des Autors via Positions-Brücke (siehe Strang B) den vollen Namen lesen (`Hans J. Tiziani`). Mehrere Papers als Mehrfachbeleg, bei Konsens → `quelle='pdf'`.
2. **OpenAlex:** Author-Search + affiliation-gefilterte Suche (Pipeline unten). Eindeutiger Kandidat → Vorname + **ORCID** + alle `display_name_alternatives` übernehmen (`quelle='openalex'`).
3. **ORCID:** `personal-details` für offiziellen given/family name als Verifikation.
4. **LLM-Richter:** Für Verpasste die strukturierte OpenAlex-Evidenz (Kandidaten, Affils, ORCID, raw_strings) einem Sonnet-Subagent vorlegen → Urteil mit Konfidenz. Nur Konfidenz ≥ 0.9 schreiben.
5. **Unauflösbar:** Vorname bleibt Initiale, in Review-Queue. **Kein Raten** (`C.` wird nie zu `Christian`, ohne Beleg für genau diesen Autor).

### Alias-Generierung

Pro Autor alle plausiblen Schreibweisen als `autor_aliase`: voller Name, Initial-Formen, "Nachname, Vorname", mit/ohne Mittelinitiale — plus alle real beobachteten aus PDF/OpenAlex. Macht die Suche robust.

### ORCID-basierte & Vollname-basierte Merges

Nach der Namens-Auflösung können bisher getrennte Duplikate zusammengeführt werden:

1. **Gleiche ORCID → sicher mergen** (deterministisch, 0 Fehler). Löst genau die Fälle, die bisher als `C. Müller` / `Christian Müller` / `C. A. Müller` getrennt standen.
2. **Ohne ORCID:** gleicher voller Vorname + Nachname + Affiliation-Überlappung → Merge-Kandidat. Bei Eindeutigkeit mergen, sonst Review-Queue.

Merge nutzt die bestehende `mergeAuthorCluster`-Logik (PK-Konflikt-Deduplizierung).

### OpenAlex-Auflösungs-Pipeline (implementiert in `bin/openalex_test.py`, Hit 9/15 = 60 %, 0 Fehler)

1. DGaO-Affil → OpenAlex Institution-ID(s) (`search=`, bereinigt von Klammern/Kommas; Akronym-Hints wie ITO→Stuttgart).
2. `display_name.search:<Nachname>,affiliations.institution.id:<id>` — präzise, findet auch wenig-publizierende Autoren (löste Marco Wende).
3. Fallback Nachname-Suche + Token-/`raw_affiliation_strings`-Match gegen **alle** `affiliations[]` (historisch + parallel), DE/EN-Präfix-Heuristik.
4. Vorname-Extraktion aus `display_name` + `display_name_alternatives` (all-caps-Kürzel ausgeschlossen); ORCID-`personal-details` als Fallback für identifizierte Personen ohne Vorname in OA.
5. Eindeutigkeit: Akzeptanz nur bei Vornamen-Konsens unter starken Kandidaten.

### Vorname-Schreibregeln

- Schreiben nach `autoren.vorname` nur bei Konfidenz ≥ 0.9.
- Audit-Eintrag in `autor_vorname_audit` (autor_id, alter/neuer Vorname, quelle, confidence, reason).
- Diakritik aus Beleg übernehmen (André, nicht Andre).
- Mehrteilige Vornamen erlaubt (Fortunato Tito, Daniele Eugenio).

## Strang B — Affiliation pro (Paper, Autor)

### Datenmodell

Source of Truth ist die Affiliation eines Autors **in einem konkreten Paper** — nicht statisch pro Autor. Neue Tabelle:

```sql
CREATE TABLE paper_autor_institutionen (
    paper_id   TEXT    NOT NULL REFERENCES papers(id) ON DELETE CASCADE,
    autor_id   INTEGER NOT NULL REFERENCES autoren(id) ON DELETE CASCADE,
    institut_id INTEGER NOT NULL REFERENCES institutionen(id) ON DELETE CASCADE,
    quelle     TEXT,   -- 'pdf'|'single_affil'|'anker'|'openalex'
    PRIMARY KEY (paper_id, autor_id, institut_id)
);
```

`PRIMARY KEY (paper_id, autor_id, institut_id)` erlaubt **mehrere Institute pro Autor pro Paper** — deckt parallele Affiliations ab (realer Fall 2024: `J. Stollenwerk* ***` → TOS RWTH **und** Fraunhofer ILT). Jahre kommen automatisch über `papers → tagungen.jahr`; kein `jahr_von/jahr_bis` nötig.

`autor_institutionen` wird damit **abgeleitet** (Cache/View). Die `jahr_von/jahr_bis`-Idee aus früheren Entwürfen entfällt.

### Positions-Brücke (löst Henne-Ei beim Parsen)

Die Autor→Paper-Zuordnung existiert bereits in `paper_autoren` (`position`). Beim PDF-Parsen wird **nicht** über Namens-Alias gesucht, sondern über die Position gematcht:

```
paper_autoren: Paper P, position 3 → autor_id 850 (kanonisch)
PDF P:         3. Name "Klaus Mantel**" → Marker ** = Max Planck
  ⇒ autor_id 850 hatte in P: Vorname "Klaus", Affil = Max Planck
```

**Sicherheits-Check:** Nachname an PDF-Position N muss == Nachname an `paper_autoren`-Position N. Bei Mismatch (Reihenfolge/Anzahl weicht ab) → Eintrag überspringen, nicht raten. Alias wird nur als Nebenprodukt gelernt, nie zum Finden benutzt.

### Affil-String → kanonisches Institut

Affil-Text aus Abstract/PDF → über `institut_aliase` (normalisiert) auf kanonische `institutionen`. Neue Schreibweise → als `institut_alias` ergänzen. Kein Match → Fuzzy/ROR oder Review (betrifft Institute, die bereits weitgehend kanonisiert sind: 984 Stück).

### Befüllung (gestuft, deterministisch zuerst)

1. **Single-Affil-Papers (6778 Verknüpfungen):** alle Autoren → die eine Affiliation, `quelle='single_affil'`.
2. **Multi-Affil-Papers mit lesbarem PDF:** Marker (`*`/`**`, Ziffern via Layout) → exakte Zuordnung über Positions-Brücke, `quelle='pdf'`.
3. **Single-Affil-Anker:** Multi-Affil-Autoren, deren Affiliation aus ihren Solo-Papers bekannt ist, `quelle='anker'`.
4. **OpenAlex authorships:** wo DGaO-Paper in OpenAlex, `quelle='openalex'`.
5. **Rest:** keine Zeile (ehrlich leer) — kein Raten.

### Lücken-Prinzip

**Nicht über Lücken interpolieren.** Jede Zeile ist an ein konkretes Paper (mit Jahr) gebunden. Wo Zuordnung unsicher → keine Zeile. Für ein Proceedings-Archiv ist die Publikations-Affiliation die maßgebliche Information.

### Ableitung für die Anwendung

- **Alle Affiliations eines Autors:** `DISTINCT institut` über seine `paper_autor_institutionen` + `MIN/MAX(tagungen.jahr)`.
- **Aktuelle Affiliation:** Institut aus dem neuesten Paper.
- **Affil zu einem Paper:** direkt.
- Frontend/Queries, die `LIMIT 1` annehmen, müssen auf Mehrfach-Darstellung umgestellt werden.

## Entschieden

- **Multi-Affil-Tiefe:** PDF-Parsing der Marker als Stufe 1 (deterministisch, löst zugleich Strang A), dann Anker + OpenAlex. Rest bleibt leer (kein Raten).
- **Affil-Modell:** `paper_autor_institutionen` statt `jahr_von/jahr_bis`.

## Komponenten / Dateien

| Datei | Verantwortung |
|-------|---------------|
| `bin/pdf_extract.py` (neu) | Paper-PDF → (Position, voller Name, Affil-Marker-Zuordnung); Lesbarkeits-Check |
| `bin/openalex_resolve.py` (aus `openalex_test.py`) | OpenAlex: Vorname + alle Namensvarianten + ORCID + Affil-Historie |
| `bin/openalex_evidence.py` (vorhanden) | strukturierte Evidenz für LLM-Richter |
| `bin/fill_vornamen.py` (neu) | Strang-A: Namens-Auflösung (PDF→OpenAlex→ORCID→LLM), schreibt `vorname`/`orcid_id` + Aliase + Audit |
| `bin/merge_by_orcid.py` (neu) | ORCID- & Vollname-basierte Merges via `mergeAuthorCluster` |
| `bin/fill_affiliations.py` (neu) | Strang-B: `paper_autor_institutionen` befüllen (PDF/Single/Anker/OpenAlex) |
| `public/db.php` | Schema-Migration v9 (`paper_autor_institutionen`) |
| Frontend-Templates | Mehrfach-Affil-Darstellung statt LIMIT 1 |

## Testbarkeit

- 20-Autoren-Ground-Truth (`autor_vorname_groundtruth`, Sonnet-verifiziert) als Vorname-Benchmark — Ziel ≥ 95 % Hit, 0 Fehler.
- Affil-Befüllung: Stichprobe gegen bekannte Fälle + Konsistenzprüfung (keine Jahre außerhalb Tagungsspanne, keine Interpolation über Lücken).

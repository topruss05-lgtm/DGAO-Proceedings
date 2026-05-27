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

## Strang A — Vorname-Pipeline

Pro Autor mit Initial-Vorname, gestuft (Abbruch beim ersten sicheren Treffer):

1. **PDF-Extraktion:** In den Paper-PDFs des Autors nach `<Initial>. <Nachname>` → vollem `<Vorname> <Nachname>` suchen. Mehrere Papers als Mehrfachbeleg. Bei Konsens → übernehmen (`quelle='pdf'`).
2. **OpenAlex:** Author-Search + affiliation-gefilterte Suche (siehe Pipeline unten). Eindeutiger Kandidat mit Vorname → übernehmen (`quelle='openalex'`), ORCID gleich mit speichern.
3. **LLM-Richter:** Für Verpasste die strukturierte OpenAlex-Evidenz (Kandidaten, Affils, ORCID, raw_strings) einem Sonnet-Subagent vorlegen → Urteil mit Konfidenz. Nur Konfidenz ≥ 0.9 schreiben.
4. **Unauflösbar:** leer lassen, in Review-Queue.

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

## Strang B — Zeitliche/mehrfache Affiliations

### Schema-Änderung (additiv, bricht nichts)

`autor_institutionen` wird um Zeit- und Quell-Information erweitert:

```sql
ALTER TABLE autor_institutionen ADD COLUMN jahr_von  INTEGER;   -- NULL erlaubt
ALTER TABLE autor_institutionen ADD COLUMN jahr_bis  INTEGER;   -- NULL erlaubt
ALTER TABLE autor_institutionen ADD COLUMN quelle    TEXT;      -- 'dgao_paper'|'openalex'|'orcid'|'anker'
-- ist_aktuell bleibt (rückwärtskompatibel)
-- PRIMARY KEY (autor_id, institut_id) bleibt: mehrere Institute pro Autor möglich
```

Mehrere parallele Affiliations = mehrere Zeilen mit überlappenden `[jahr_von, jahr_bis]`. Mehrere `ist_aktuell=1` sind zulässig.

### Befüllung (gestuft, deterministisch zuerst)

1. **Single-Affil-Papers:** Alle Autoren des Papers → dessen eine Affiliation, `jahr_von=jahr_bis=Tagungsjahr`, `quelle='dgao_paper'`. Über mehrere Papers zu `MIN/MAX`-Spanne aggregiert.
2. **Multi-Affil-Papers mit PDF:** PDF parsen, Superscript-Marker (`*`/`**`/Ziffern via Layout) → exakte Autor→Affil-Zuordnung, `quelle='dgao_paper'`.
3. **Single-Affil-Anker:** Multi-Affil-Autoren, deren Affiliation aus Solo-Papers bekannt ist → übernehmen, `quelle='anker'`.
4. **OpenAlex:** `affiliations[]` mit `years` für gematchte Autoren als Ergänzung/Lückenfüller, `quelle='openalex'`.
5. **Rest:** Autor→alle-Affils-des-Papers (unscharf), explizit `quelle='unscharf'` markiert — oder leer, je nach finaler Entscheidung Multi-Affil-Tiefe.

### Lücken-Prinzip

**Nicht über Lücken interpolieren.** Jede Affiliation ist an konkret belegte Jahre gebunden (DGaO-Paper-Jahr oder OpenAlex-`years`). Zwischen Publikationen wird keine Zugehörigkeit angenommen. Für ein Proceedings-Archiv ist die Publikations-Affiliation die maßgebliche Information; OpenAlex füllt kontinuierliche Spannen wo verfügbar.

### Ableitung für die Anwendung

- **Aktuelle Affiliation:** höchstes `jahr_bis` (bzw. `ist_aktuell=1`).
- **Historie:** alle Zeilen nach `jahr_von` sortiert.
- **Parallele:** mehrere Zeilen mit überlappenden Jahren.
- Frontend/Queries, die `LIMIT 1` annehmen, müssen auf Mehrfach-Darstellung umgestellt werden.

## Offene Entscheidung

- **Multi-Affil-Tiefe:** Wie weit bei den ~4740 verlorenen Zuordnungen gehen (Anker+OpenAlex / + PDF-Parsing / + LLM). Vom User zu bestätigen. PDF-Parsing der Marker ist deterministisch und löst zugleich Strang A — daher hohe Priorität.

## Komponenten / Dateien

| Datei | Verantwortung |
|-------|---------------|
| `bin/pdf_extract.py` (neu) | Paper-PDF → (Autor, voller Vorname, Affil-Marker-Zuordnung); Lesbarkeits-Check |
| `bin/openalex_resolve.py` (aus `openalex_test.py`) | OpenAlex-Auflösung Vorname + Affil-Historie |
| `bin/openalex_evidence.py` (vorhanden) | strukturierte Evidenz für LLM-Richter |
| `bin/fill_vornamen.py` (neu) | Strang-A-Orchestrierung (PDF→OpenAlex→LLM), schreibt `autoren.vorname` + Audit |
| `bin/fill_affiliations.py` (neu) | Strang-B-Orchestrierung, Schema-Migration + Befüllung |
| `public/db.php` | Schema-Migration v9 (autor_institutionen-Spalten) |
| Frontend-Templates | Mehrfach-Affil-Darstellung statt LIMIT 1 |

## Testbarkeit

- 20-Autoren-Ground-Truth (`autor_vorname_groundtruth`, Sonnet-verifiziert) als Vorname-Benchmark — Ziel ≥ 95 % Hit, 0 Fehler.
- Affil-Befüllung: Stichprobe gegen bekannte Fälle + Konsistenzprüfung (keine Jahre außerhalb Tagungsspanne, keine Interpolation über Lücken).

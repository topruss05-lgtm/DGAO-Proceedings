CREATE TABLE tagungen (
      nummer INTEGER PRIMARY KEY,
      jahr INTEGER NOT NULL,
      ort TEXT,
      datum_von TEXT,
      datum_bis TEXT,
      vorlage_phase_aktiv INTEGER NOT NULL DEFAULT 0,
      einreichungsfrist TEXT
    );
CREATE TABLE papers (
      id TEXT PRIMARY KEY,
      tagung_nummer INTEGER NOT NULL REFERENCES tagungen(nummer),
      code TEXT NOT NULL,
      typ TEXT NOT NULL,
      titel TEXT NOT NULL,
      autoren_text TEXT NOT NULL,
      hauptautor TEXT,
      abstract_text TEXT,
      zeit TEXT,
      raum TEXT,
      datum TEXT,
      affiliationen TEXT,
      kontakt_email TEXT,
      pdf_dateiname TEXT,
      hat_pdf INTEGER DEFAULT 0,
      alte_abstract_id INTEGER,
      session_id INTEGER REFERENCES sessions(id)
    );
CREATE INDEX idx_papers_session ON papers(session_id);
CREATE TABLE paper_autoren (
      paper_id TEXT REFERENCES papers(id),
      autor_id INTEGER REFERENCES autoren(id),
      position INTEGER NOT NULL,
      ist_hauptautor INTEGER DEFAULT 0,
      PRIMARY KEY (paper_id, autor_id)
    );
CREATE TABLE keywords (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      keyword TEXT UNIQUE NOT NULL
    );
CREATE TABLE paper_keywords (
      paper_id TEXT REFERENCES papers(id),
      keyword_id INTEGER REFERENCES keywords(id),
      PRIMARY KEY (paper_id, keyword_id)
    );
CREATE INDEX idx_papers_tagung ON papers(tagung_nummer);
CREATE INDEX idx_papers_code ON papers(code);
CREATE INDEX idx_paper_autoren_autor ON paper_autoren(autor_id);
CREATE INDEX idx_paper_keywords_keyword ON paper_keywords(keyword_id);
CREATE VIRTUAL TABLE papers_fts USING fts5(
      titel, autoren_text, abstract_text, content='papers', content_rowid='rowid'
    )
/* papers_fts(titel,autoren_text,abstract_text) */;
CREATE TABLE IF NOT EXISTS 'papers_fts_data'(id INTEGER PRIMARY KEY, block BLOB);
CREATE TABLE IF NOT EXISTS 'papers_fts_idx'(segid, term, pgno, PRIMARY KEY(segid, term)) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS 'papers_fts_docsize'(id INTEGER PRIMARY KEY, sz BLOB);
CREATE TABLE IF NOT EXISTS 'papers_fts_config'(k PRIMARY KEY, v) WITHOUT ROWID;
CREATE TABLE autoren (
    id INTEGER PRIMARY KEY,
    vorname TEXT NOT NULL DEFAULT '',
    nachname TEXT NOT NULL,
    orcid_id TEXT
);
CREATE INDEX idx_autoren_nachname ON autoren(nachname);
CREATE TABLE submissions (
    token TEXT PRIMARY KEY,
    paper_id TEXT NOT NULL REFERENCES papers(id),
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','expired')),
    uploader_email TEXT NOT NULL,
    filename_original TEXT,
    filename_stored TEXT,
    file_size INTEGER,
    requested_at TEXT NOT NULL DEFAULT (datetime('now')),
    uploaded_at TEXT,
    decided_at TEXT,
    decided_by TEXT,
    reviewer_note TEXT,
    expires_at TEXT NOT NULL
);
CREATE INDEX idx_submissions_paper ON submissions(paper_id);
CREATE INDEX idx_submissions_status ON submissions(status);

CREATE TABLE admin_login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    ts INTEGER NOT NULL
);
CREATE INDEX idx_admin_login_attempts_ip_ts ON admin_login_attempts(ip, ts);

-- Themen-Sessions aus den Tagungs-Booklets (Programmübersicht).
-- Eine Tagung hat 0..N Sessions. Sessions ohne Papers (z.B. Eröffnung,
-- Pausen, Postersession-Slots ohne Codes) werden hier NICHT gespeichert,
-- sondern nur Sessions, denen mind. 1 Paper zugeordnet ist.
CREATE TABLE sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tagung_nummer INTEGER NOT NULL REFERENCES tagungen(nummer),
    titel TEXT NOT NULL,
    saal TEXT,              -- z.B. 'A', 'B', 'C', oder NULL fuer raumlose
    sortorder INTEGER NOT NULL,
    datum TEXT,             -- 'YYYY-MM-DD' oder NULL
    zeit_von TEXT,          -- 'HH:MM' oder NULL
    zeit_bis TEXT           -- 'HH:MM' oder NULL
);
CREATE INDEX idx_sessions_tagung ON sessions(tagung_nummer, sortorder);

-- papers.session_id ist optional. NULL = noch keine Session-Zuordnung
-- (z.B. fuer Tagungen ohne Booklet-Import). Frontend faellt dann auf
-- die alte Code-Buchstaben-Gruppierung zurueck.
-- ALTER TABLE wird durch runMigrations() ausgefuehrt.

-- Hybrid-News: Auto-generierte (z.B. bei vorlage_phase_aktiv-Wechsel) +
-- manuell vom Admin gepflegte Items. Idempotenz fuer Auto via
-- UNIQUE(source, trigger_key, tagung_nummer): mehrfaches Speichern in
-- tagung_edit erzeugt kein Duplikat (UPSERT).
CREATE TABLE news (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    source          TEXT NOT NULL CHECK (source IN ('auto','manual')),
    trigger_key     TEXT,            -- z.B. 'submission_open' (NULL bei manual)
    tagung_nummer   INTEGER REFERENCES tagungen(nummer) ON DELETE CASCADE,
    display_date    TEXT NOT NULL,   -- 'YYYY-MM-DD' (Admin-overridable)
    title_de        TEXT NOT NULL,
    title_en        TEXT NOT NULL,
    body_de         TEXT NOT NULL DEFAULT '',
    body_en         TEXT NOT NULL DEFAULT '',
    link_url        TEXT,            -- z.B. /einreichen oder /archiv/127
    is_active       INTEGER NOT NULL DEFAULT 1,
    sort_weight     INTEGER NOT NULL DEFAULT 0,  -- >0 = gepinnt
    manual_override INTEGER NOT NULL DEFAULT 0,  -- 1: Admin-Edit, auto-UPSERT skip
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX idx_news_auto_unique
    ON news(source, trigger_key, tagung_nummer)
    WHERE source = 'auto';
CREATE INDEX idx_news_active_date ON news(is_active, display_date DESC);

-- Autoren-Alias-System: Normalisierte Suchaliase pro Autor.
-- Ermoeglicht Merge-Erkennung und fuzzy Autorsuche.
CREATE TABLE autor_aliase (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    autor_id     INTEGER NOT NULL REFERENCES autoren(id) ON DELETE CASCADE,
    alias_text   TEXT NOT NULL,
    alias_norm   TEXT NOT NULL,
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (autor_id, alias_norm)
);
CREATE INDEX idx_autor_aliase_norm  ON autor_aliase(alias_norm);
CREATE INDEX idx_autor_aliase_autor ON autor_aliase(autor_id);

-- Institutionen-Stammdaten.
CREATE TABLE institutionen (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name_de         TEXT NOT NULL,
    name_en         TEXT NOT NULL DEFAULT '',
    kuerzel         TEXT,
    universitaet    TEXT,
    ort             TEXT,
    land            TEXT DEFAULT 'DE',
    ror_id          TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_institutionen_kuerzel ON institutionen(kuerzel);

-- Aliase fuer Institutionen (Schreibvarianten, Kuerzel).
CREATE TABLE institut_aliase (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    institut_id     INTEGER NOT NULL REFERENCES institutionen(id) ON DELETE CASCADE,
    alias_text      TEXT NOT NULL,
    alias_norm      TEXT NOT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (institut_id, alias_norm)
);
CREATE INDEX idx_institut_aliase_norm ON institut_aliase(alias_norm);
CREATE INDEX idx_institut_aliase_inst ON institut_aliase(institut_id);

-- N:M-Verknuepfung Autor ↔ Institution.
CREATE TABLE autor_institutionen (
    autor_id        INTEGER NOT NULL REFERENCES autoren(id) ON DELETE CASCADE,
    institut_id     INTEGER NOT NULL REFERENCES institutionen(id) ON DELETE CASCADE,
    ist_aktuell     INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (autor_id, institut_id)
);
CREATE INDEX idx_autor_inst_aktuell ON autor_institutionen(autor_id, ist_aktuell);

-- Redirect-Tabelle fuer zusammengefuehrte Autoren-IDs.
CREATE TABLE autor_id_redirects (
    alte_id   INTEGER PRIMARY KEY,
    neue_id   INTEGER NOT NULL REFERENCES autoren(id) ON DELETE CASCADE,
    merged_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Aktueller Schema-Stand. Muss mit DB_SCHEMA_VERSION in public/db.php
-- synchron sein. Bei neuem Deploy spielt bootstrapDb() dieses Schema
-- inkl. user_version → runMigrations() greift dann fast-path.
PRAGMA user_version = 8;

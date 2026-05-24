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
      alte_abstract_id INTEGER
    );
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
CREATE TABLE IF NOT EXISTS "autoren" (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            vorname TEXT NOT NULL DEFAULT '',
            nachname TEXT NOT NULL, affiliation TEXT NOT NULL DEFAULT '',
            UNIQUE(nachname, vorname)
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

-- Aktueller Schema-Stand. Muss mit DB_SCHEMA_VERSION in public/db.php
-- synchron sein. Bei neuem Deploy spielt bootstrapDb() dieses Schema
-- inkl. user_version → runMigrations() greift dann fast-path.
PRAGMA user_version = 4;

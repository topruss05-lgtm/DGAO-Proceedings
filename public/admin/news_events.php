<?php

declare(strict_types=1);

/**
 * News-Auto-Trigger.
 *
 * Wird aus den Admin-CRUD-Pages aufgerufen (z.B. tagung_edit), nachdem die
 * Tagungs-Aenderungen erfolgreich gespeichert wurden. Vergleicht alten und
 * neuen Tagungs-State und erzeugt/aktualisiert/deaktiviert News-Items.
 *
 * Idempotenz: das UNIQUE(source,trigger_key,tagung_nummer)-Constraint
 * auf news garantiert, dass wiederholtes Speichern kein Duplikat erzeugt.
 * Wir nutzen ON CONFLICT DO UPDATE (UPSERT) — bestehende Auto-Items
 * werden refresht, neue eingefuegt.
 *
 * Templates (Titel/Body in DE+EN) sind hier hardcoded, weil sie sich
 * praktisch nie aendern. Aenderungen brauchen einen Code-Deploy, was
 * Sinn macht (Wording-Konsistenz).
 */

/**
 * UPSERT eines Auto-News-Items.
 */
function newsUpsertAuto(string $triggerKey, int $tagungNummer, array $data): void
{
    $db = getDbAdmin();
    // SQLite-Spezifik: ON CONFLICT auf partial-unique-Index braucht die
    // exakt gleiche WHERE-Klausel wie der Index-DDL. Unser Index ist
    // CREATE UNIQUE INDEX ... WHERE source = 'auto'.
    $stmt = $db->prepare("
        INSERT INTO news (source, trigger_key, tagung_nummer, display_date,
                          title_de, title_en, body_de, body_en, link_url, is_active)
        VALUES ('auto', :k, :t, :d, :td, :te, :bd, :be, :l, 1)
        ON CONFLICT(source, trigger_key, tagung_nummer) WHERE source = 'auto' DO UPDATE SET
            display_date = excluded.display_date,
            title_de     = excluded.title_de,
            title_en     = excluded.title_en,
            body_de      = excluded.body_de,
            body_en      = excluded.body_en,
            link_url     = excluded.link_url,
            is_active    = 1,
            updated_at   = datetime('now')
    ");
    $stmt->execute([
        ':k'  => $triggerKey,
        ':t'  => $tagungNummer,
        ':d'  => $data['display_date'],
        ':td' => $data['title_de'],
        ':te' => $data['title_en'],
        ':bd' => $data['body_de']  ?? '',
        ':be' => $data['body_en']  ?? '',
        ':l'  => $data['link_url'] ?? null,
    ]);
}

/**
 * Deaktiviert ein Auto-Item (is_active=0). Wird nicht geloescht — Historie
 * bleibt im Admin sichtbar und kann manuell wieder aktiviert werden.
 */
function newsDeactivateAuto(string $triggerKey, int $tagungNummer): void
{
    $db = getDbAdmin();
    $db->prepare("
        UPDATE news
        SET is_active = 0, updated_at = datetime('now')
        WHERE source = 'auto' AND trigger_key = ? AND tagung_nummer = ?
    ")->execute([$triggerKey, $tagungNummer]);
}

/**
 * Wird aufgerufen, nachdem eine Tagung im Admin gespeichert wurde.
 * $old kann NULL sein (neue Tagung).
 */
function newsOnTagungSaved(?array $old, array $new): void
{
    $tagung = (int) $new['nummer'];
    $jahr   = (int) ($new['jahr'] ?? 0);
    $ort    = (string) ($new['ort'] ?? '');

    $oldPhase = (int) ($old['vorlage_phase_aktiv'] ?? 0);
    $newPhase = (int) ($new['vorlage_phase_aktiv'] ?? 0);
    $oldFrist = (string) ($old['einreichungsfrist'] ?? '');
    $newFrist = (string) ($new['einreichungsfrist'] ?? '');

    $today = date('Y-m-d');

    // --- Manuskripteinreichung offen (0 -> 1) ---
    if ($oldPhase === 0 && $newPhase === 1) {
        newsUpsertAuto('submission_open', $tagung, [
            'display_date' => $today,
            'title_de' => "Manuskripteinreichung für die {$tagung}. Jahrestagung offen",
            'title_en' => "Submission open for the {$tagung}. Annual Conference",
            'body_de'  => "Die Manuskript-Vorlagen für die {$tagung}. DGaO-Jahrestagung"
                        . ($ort !== '' ? " ({$ort}, {$jahr})" : " ({$jahr})")
                        . " stehen bereit. Bitte das fertige PDF an die DGaO senden.",
            'body_en'  => "The manuscript templates for the {$tagung}. DGaO Annual Conference"
                        . ($ort !== '' ? " ({$ort}, {$jahr})" : " ({$jahr})")
                        . " are available. Submit your final PDF to the DGaO.",
            'link_url' => '/einreichen',
        ]);
        // submission_closed der gleichen Tagung deaktivieren falls vorhanden
        newsDeactivateAuto('submission_closed', $tagung);
    }

    // --- Beitragsanmeldung geschlossen (1 -> 0) ---
    if ($oldPhase === 1 && $newPhase === 0) {
        newsDeactivateAuto('submission_open', $tagung);
        newsDeactivateAuto('deadline_set', $tagung);
        newsUpsertAuto('submission_closed', $tagung, [
            'display_date' => $today,
            'title_de' => "Beitragsanmeldung der {$tagung}. Jahrestagung geschlossen",
            'title_en' => "Submissions for the {$tagung}. Annual Conference closed",
            'body_de'  => "Die Frist zur Einreichung von Manuskripten für die {$tagung}. DGaO-Jahrestagung"
                        . ($ort !== '' ? " in {$ort}" : '')
                        . " ist abgelaufen. Die Beiträge erscheinen in Kürze in den Proceedings.",
            'body_en'  => "The submission deadline for the {$tagung}. DGaO Annual Conference"
                        . ($ort !== '' ? " in {$ort}" : '')
                        . " has passed. Contributions will appear in the proceedings shortly.",
            'link_url' => '/archiv/' . $tagung,
        ]);
    }

    // --- Einreichungsfrist gesetzt/geaendert (nur wenn Phase offen) ---
    if ($newPhase === 1 && $newFrist !== '' && $newFrist !== $oldFrist) {
        $fristNice = formatDateLong($newFrist);
        newsUpsertAuto('deadline_set', $tagung, [
            'display_date' => $today,
            'title_de' => "Einreichungsfrist für die {$tagung}. Jahrestagung: {$fristNice}",
            'title_en' => "Submission deadline for the {$tagung}. Annual Conference: {$fristNice}",
            'body_de'  => "Manuskripte für die {$tagung}. DGaO-Jahrestagung können bis {$fristNice} eingereicht werden.",
            'body_en'  => "Manuscripts for the {$tagung}. DGaO Annual Conference may be submitted until {$fristNice}.",
            'link_url' => '/einreichen',
        ]);
    }

    // --- Einreichungsfrist entfernt (alt gesetzt, neu leer) ---
    if ($oldFrist !== '' && $newFrist === '') {
        newsDeactivateAuto('deadline_set', $tagung);
    }
}

/**
 * Wird aufgerufen, nachdem ein Booklet-Import (PDF-basiert) Papers
 * fuer eine Tagung angelegt hat. Erzeugt "Proceedings online"-News.
 *
 * $tagung: tagung-Row inkl. nummer, jahr, ort
 * $paperCount: Anzahl der gerade importierten/verlinkten Papers
 */
function newsOnTagungProceedingsOnline(array $tagung, int $paperCount): void
{
    if ($paperCount < 1) return;
    $nr   = (int) $tagung['nummer'];
    $jahr = (int) ($tagung['jahr'] ?? 0);
    $ort  = (string) ($tagung['ort'] ?? '');

    newsUpsertAuto('proceedings_online', $nr, [
        'display_date' => date('Y-m-d'),
        'title_de' => "Proceedings der {$nr}. Jahrestagung" . ($ort !== '' ? " {$ort}" : '') . " online",
        'title_en' => "Proceedings of the {$nr}. Annual Conference" . ($ort !== '' ? " in {$ort}" : '') . " online",
        'body_de'  => "Alle Beiträge der {$nr}. DGaO-Jahrestagung"
                    . ($ort !== '' ? " ({$ort}, {$jahr})" : " ({$jahr})")
                    . " sind jetzt frei zugänglich.",
        'body_en'  => "All contributions of the {$nr}. DGaO Annual Conference"
                    . ($ort !== '' ? " ({$ort}, {$jahr})" : " ({$jahr})")
                    . " are now freely accessible.",
        'link_url' => '/archiv/' . $nr,
    ]);
}

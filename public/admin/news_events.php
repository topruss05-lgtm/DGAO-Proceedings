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
    $datumVon = (string) ($new['datum_von'] ?? '');
    $datumBis = (string) ($new['datum_bis'] ?? '');

    $today = date('Y-m-d');

    // --- Save the date / Tagung läuft (datum_von gesetzt + im Fenster) ---
    // Auto-Trigger 'tagung_dates' für Tagungen, die noch nicht stattgefunden
    // haben oder gerade laufen / vor maximal 60 Tagen waren. Damit ist die
    // aktuelle Tagung auch dann als News auf der Home sichtbar, wenn
    // submission_open schon vorbei ist oder noch nicht angefangen hat.
    // Alte Tagungen (Vergangenheit > 60 Tage) lösen das NICHT aus, damit
    // ein Bulk-Edit alter Tagungen keine News-Flut produziert.
    if ($datumVon !== '' && $ort !== '' && $jahr > 0) {
        $sixtyDaysAgo = date('Y-m-d', strtotime('-60 days'));
        $isCurrentOrFuture = $datumVon >= $sixtyDaysAgo;

        if ($isCurrentOrFuture) {
            $dateRange = formatDateLong($datumVon);
            if ($datumBis !== '' && $datumBis !== $datumVon) {
                $dateRange .= ' – ' . formatDateLong($datumBis);
            }

            $isPast = $datumBis !== ''
                ? ($datumBis < $today)
                : ($datumVon < $today);
            $isRunning = $datumVon <= $today &&
                         ($datumBis === '' || $datumBis >= $today);

            if ($isRunning) {
                $titleDe = "{$tagung}. Jahrestagung der DGaO läuft: {$ort}, {$dateRange}";
                $titleEn = "{$tagung}. Annual Conference in progress: {$ort}, {$dateRange}";
            } elseif ($isPast) {
                $titleDe = "{$tagung}. Jahrestagung der DGaO abgeschlossen: {$ort}, {$dateRange}";
                $titleEn = "{$tagung}. Annual Conference concluded: {$ort}, {$dateRange}";
            } else {
                $titleDe = "Save the date — {$tagung}. Jahrestagung: {$ort}, {$dateRange}";
                $titleEn = "Save the date — {$tagung}. Annual Conference: {$ort}, {$dateRange}";
            }

            // Link-Logik: laufende und kommende Tagungen verweisen extern
            // auf die DGaO-Tagungsseite (wo Programm, Anmeldung, Hotels etc.
            // gepflegt sind). Erst wenn die Tagung vorbei ist und die
            // Proceedings im Archiv liegen, dann auf /archiv/<N>.
            $linkUrl = $isPast
                ? ('/archiv/' . $tagung)
                : 'https://www.dgao.de/jahrestagung/';

            newsUpsertAuto('tagung_dates', $tagung, [
                'display_date' => $today,
                'title_de' => $titleDe,
                'title_en' => $titleEn,
                'body_de'  => "Die {$tagung}. DGaO-Jahrestagung findet vom {$dateRange} in {$ort} statt.",
                'body_en'  => "The {$tagung}. DGaO Annual Conference takes place {$dateRange} in {$ort}.",
                'link_url' => $linkUrl,
            ]);
        } else {
            // Tagung liegt > 60 Tage zurück → ggf. existierendes Item deaktivieren
            newsDeactivateAuto('tagung_dates', $tagung);
        }
    } elseif ($datumVon === '') {
        // Datum wurde entfernt → Item deaktivieren
        newsDeactivateAuto('tagung_dates', $tagung);
    }

    // --- Manuskripteinreichung offen (mit Frist im Body, sofern gesetzt) ---
    // Wir triggern IDEMPOTENT bei jedem Save mit newPhase=1 (nicht nur 0->1),
    // damit ein Admin auch durch erneutes Speichern einer bereits offenen
    // Tagung die fehlende News nachholen kann. UPSERT mit unique-Index
    // verhindert Duplikate; vorhandene werden nur refresht und reaktiviert.
    //
    // Die Frist (einreichungsfrist) wird direkt in den submission_open-Body
    // hineingeschrieben — KEIN separater deadline_set-Eintrag mehr, weil
    // beide den gleichen Call-to-Action (/einreichen) hatten und auf Home
    // doppelt erschienen.
    if ($newPhase === 1) {
        $fristNice = $newFrist !== '' ? formatDateLong($newFrist) : '';
        $fristDe   = $fristNice !== '' ? " Einreichungsfrist: {$fristNice}." : '';
        $fristEn   = $fristNice !== '' ? " Submission deadline: {$fristNice}." : '';

        newsUpsertAuto('submission_open', $tagung, [
            'display_date' => $today,
            'title_de' => "Manuskripteinreichung für die {$tagung}. Jahrestagung offen",
            'title_en' => "Submission open for the {$tagung}. Annual Conference",
            'body_de'  => "Die Manuskript-Vorlagen für die {$tagung}. DGaO-Jahrestagung"
                        . ($ort !== '' ? " ({$ort}, {$jahr})" : " ({$jahr})")
                        . " stehen bereit.{$fristDe} Bitte das fertige PDF an die DGaO senden.",
            'body_en'  => "The manuscript templates for the {$tagung}. DGaO Annual Conference"
                        . ($ort !== '' ? " ({$ort}, {$jahr})" : " ({$jahr})")
                        . " are available.{$fristEn} Submit your final PDF to the DGaO.",
            'link_url' => '/einreichen',
        ]);
        // submission_closed der gleichen Tagung deaktivieren falls vorhanden
        newsDeactivateAuto('submission_closed', $tagung);
        // Alte deadline_set-Items (vor der Konsolidierung) deaktivieren —
        // jede Tagung hat jetzt nur noch das submission_open-Item.
        newsDeactivateAuto('deadline_set', $tagung);
    }

    // --- Beitragsanmeldung geschlossen (1 -> 0, echte Transition!) ---
    // Nur bei Transition, damit eine Tagung die schon immer phase=0 hatte
    // (alte Tagungen, Neuanlage als 0) nicht versehentlich eine
    // "Geschlossen"-News bekommt.
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

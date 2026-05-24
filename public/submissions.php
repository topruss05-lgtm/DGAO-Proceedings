<?php

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

/**
 * Submission-Workflow: Manuskripte gehen per E-Mail an sekretariat@dgao.de;
 * der Admin spielt sie per /admin/submissions ins System ein. Token wird
 * intern für die Datei-Zuordnung verwendet, Author bekommt keinen Magic-Link.
 */

const SUBMISSION_TOKEN_TTL_DAYS = 30;
const SUBMISSION_MAX_FILESIZE   = 30 * 1024 * 1024; // 30 MB
const SUBMISSION_UPLOAD_DIR     = __DIR__ . '/data/uploads/pending';
const SUBMISSION_FINAL_DIR      = __DIR__ . '/download'; // /public/download/{tagung}/

/**
 * Lädt eine Submission anhand Token. Liefert null wenn nicht existiert oder abgelaufen.
 * Setzt Status auf 'expired', wenn expires_at überschritten.
 */
function loadSubmission(string $token): ?array
{
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;

    $db = getDbAdmin();
    $stmt = $db->prepare(
        'SELECT s.*, p.code, p.titel, p.tagung_nummer, p.hauptautor
         FROM submissions s
         JOIN papers p ON p.id = s.paper_id
         WHERE s.token = ?'
    );
    $stmt->execute([$token]);
    $sub = $stmt->fetch();
    if (!$sub) return null;

    // Check expiry
    if ($sub['status'] === 'pending' && strtotime($sub['expires_at']) < time()) {
        $db->prepare('UPDATE submissions SET status = ? WHERE token = ?')->execute(['expired', $token]);
        $sub['status'] = 'expired';
    }
    return $sub;
}

/**
 * Speichert hochgeladene PDF-Datei in Pending-Folder.
 * Validiert Größe + Magic-Bytes. Returnt Result-Array.
 */
function storeSubmissionUpload(array $submission, array $fileInfo): array
{
    if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload fehlgeschlagen.'];
    }
    if ($fileInfo['size'] <= 0) {
        return ['ok' => false, 'error' => 'Datei ist leer.'];
    }
    if ($fileInfo['size'] > SUBMISSION_MAX_FILESIZE) {
        return ['ok' => false, 'error' => 'Datei zu groß (max. 30 MB).'];
    }

    // Magic-Bytes prüfen
    $fh = fopen($fileInfo['tmp_name'], 'rb');
    if ($fh === false) return ['ok' => false, 'error' => 'Datei konnte nicht gelesen werden.'];
    $magic = fread($fh, 4);
    fclose($fh);
    if ($magic !== '%PDF') {
        return ['ok' => false, 'error' => 'Datei ist keine gültige PDF.'];
    }

    if (!is_dir(SUBMISSION_UPLOAD_DIR)) {
        if (!mkdir(SUBMISSION_UPLOAD_DIR, 0755, true)) {
            return ['ok' => false, 'error' => 'Upload-Verzeichnis konnte nicht erstellt werden.'];
        }
    }

    $storedName = $submission['token'] . '.pdf';
    $destination = SUBMISSION_UPLOAD_DIR . '/' . $storedName;
    if (!move_uploaded_file($fileInfo['tmp_name'], $destination)) {
        // Auch direktes copy() für Test-Szenarien (CLI / Magic-Bytes-Pre-Check verschiebt Datei)
        if (!@copy($fileInfo['tmp_name'], $destination)) {
            return ['ok' => false, 'error' => 'Datei konnte nicht gespeichert werden.'];
        }
    }
    @chmod($destination, 0644);

    // DB updaten
    $db = getDbAdmin();
    $orig = basename($fileInfo['name']);
    $orig = preg_replace('/[^A-Za-z0-9._\-]+/', '_', $orig);
    $db->prepare(
        'UPDATE submissions
         SET filename_original = ?, filename_stored = ?, file_size = ?, uploaded_at = datetime("now")
         WHERE token = ?'
    )->execute([$orig, $storedName, $fileInfo['size'], $submission['token']]);

    return ['ok' => true, 'stored' => $storedName];
}

/**
 * Admin-Aktion: Submission freigeben → PDF in finalen Pfad bewegen, papers.hat_pdf=1 setzen.
 */
function approveSubmission(string $token, string $reviewer = 'admin', string $note = ''): array
{
    $sub = loadSubmission($token);
    if (!$sub) return ['ok' => false, 'error' => 'Submission nicht gefunden.'];
    if (empty($sub['filename_stored'])) {
        return ['ok' => false, 'error' => 'Keine Datei vorhanden — Author hat noch nicht hochgeladen.'];
    }

    $db = getDbAdmin();

    // Finalen Pfad bestimmen: /public/download/{tagung}/{tagung}_{code}.pdf
    $tagungDir = SUBMISSION_FINAL_DIR . '/' . (int)$sub['tagung_nummer'];
    if (!is_dir($tagungDir)) {
        if (!mkdir($tagungDir, 0755, true)) {
            return ['ok' => false, 'error' => 'Tagungs-Verzeichnis konnte nicht erstellt werden.'];
        }
    }
    $finalName = $sub['tagung_nummer'] . '_' . strtolower($sub['code']) . '.pdf';
    $finalPath = $tagungDir . '/' . $finalName;

    $sourcePath = SUBMISSION_UPLOAD_DIR . '/' . $sub['filename_stored'];
    if (!is_file($sourcePath)) {
        return ['ok' => false, 'error' => 'Quell-PDF in Pending fehlt.'];
    }

    if (!@rename($sourcePath, $finalPath)) {
        if (!@copy($sourcePath, $finalPath)) {
            return ['ok' => false, 'error' => 'Konnte PDF nicht in finalen Pfad bewegen.'];
        }
        @unlink($sourcePath);
    }
    @chmod($finalPath, 0644);

    $db->beginTransaction();
    try {
        $db->prepare(
            'UPDATE submissions SET status = ?, decided_at = datetime("now"), decided_by = ?, reviewer_note = ?
             WHERE token = ?'
        )->execute(['approved', $reviewer, $note, $token]);

        $db->prepare(
            'UPDATE papers SET hat_pdf = 1, pdf_dateiname = ? WHERE id = ?'
        )->execute([$finalName, $sub['paper_id']]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('approveSubmission error: ' . $e);
        return ['ok' => false, 'error' => 'DB-Fehler — Details im Server-Log.'];
    }

    return ['ok' => true, 'final_path' => $finalPath, 'final_name' => $finalName];
}

/** Admin-Aktion: Submission ablehnen, Datei löschen, Author per Mail informieren. */
function rejectSubmission(string $token, string $reviewer, string $note): array
{
    $sub = loadSubmission($token);
    if (!$sub) return ['ok' => false, 'error' => 'Submission nicht gefunden.'];

    $db = getDbAdmin();
    $db->prepare(
        'UPDATE submissions SET status = ?, decided_at = datetime("now"), decided_by = ?, reviewer_note = ?
         WHERE token = ?'
    )->execute(['rejected', $reviewer, $note, $token]);

    if (!empty($sub['filename_stored'])) {
        @unlink(SUBMISSION_UPLOAD_DIR . '/' . $sub['filename_stored']);
    }

    if (!empty($sub['uploader_email'])) {
        $body = <<<EOT
Sehr geehrte Damen und Herren,

Ihre Einreichung zum DGaO-Proceedings konnten wir leider nicht annehmen.

  Beitrag: {$sub['code']} — {$sub['titel']}

Begründung der Tagungsgeschäftsführung:
{$note}

Sie können eine korrigierte Version gerne erneut per E-Mail an
sekretariat@dgao.de senden.

Mit freundlichen Grüßen
Tagungsgeschäftsführung der DGaO
EOT;
        sendMail($sub['uploader_email'], '[DGaO] Einreichung ' . $sub['code'] . ' — Rückmeldung', $body);
    }

    return ['ok' => true];
}

/**
 * Admin-Upload: Manuskript-PDF zuordnen, das per Mail eingegangen ist.
 * Erstellt eine Submission-Zeile im Status 'pending' mit der Datei im
 * Pending-Folder. Admin bestätigt anschließend wie gewohnt mit
 * approveSubmission(); dabei wandert die Datei in den finalen Pfad.
 *
 * @param string $paperId       z. B. "127-a1"
 * @param string $uploaderEmail E-Mail aus dem Absender (für ggf. Rückmeldung)
 * @param array  $fileInfo      $_FILES['…']-Eintrag mit tmp_name, name, size, error
 * @return array{ok:bool, token?:string, error?:string}
 */
function adminCreateSubmissionFromMail(string $paperId, string $uploaderEmail, array $fileInfo): array
{
    $db = getDbAdmin();
    // Paper muss existieren
    $stmt = $db->prepare('SELECT id, code, kontakt_email FROM papers WHERE id = ?');
    $stmt->execute([$paperId]);
    $paper = $stmt->fetch();
    if (!$paper) return ['ok' => false, 'error' => 'Beitrag nicht gefunden: ' . $paperId];

    // Email-Default: papers.kontakt_email, falls Admin nichts eingegeben hat
    $email = strtolower(trim($uploaderEmail));
    if ($email === '' && !empty($paper['kontakt_email'])) {
        $email = strtolower(trim($paper['kontakt_email']));
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = 'sekretariat@dgao.de'; // Fallback — Admin hat per Hand eingespielt
    }

    $token = bin2hex(random_bytes(32));
    $expiresAt = (new DateTimeImmutable('+' . SUBMISSION_TOKEN_TTL_DAYS . ' days'))->format('Y-m-d H:i:s');

    $db->prepare(
        'INSERT INTO submissions (token, paper_id, status, uploader_email, expires_at)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$token, $paperId, 'pending', $email, $expiresAt]);

    $sub = loadSubmission($token);
    $res = storeSubmissionUpload($sub, $fileInfo);
    if (!$res['ok']) {
        // Rollback: Submission-Zeile löschen
        $db->prepare('DELETE FROM submissions WHERE token = ?')->execute([$token]);
        return ['ok' => false, 'error' => $res['error']];
    }
    return ['ok' => true, 'token' => $token];
}

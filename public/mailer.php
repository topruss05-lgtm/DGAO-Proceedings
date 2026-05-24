<?php

declare(strict_types=1);

/**
 * Schmaler Mail-Helper. Nutzt PHP mail() wenn ein MTA verfügbar ist,
 * fällt auf eine Log-Datei zurück (data/mail_outbox.log), wenn nicht.
 *
 * In Production sollte der Admin auf der Server-Ebene einen MTA / sendmail
 * konfigurieren. Für Dev/Selbsthosting reicht der Log-Fallback.
 */
function sendMail(string $to, string $subject, string $body, ?string $from = null): bool
{
    $from = $from ?? (defined('MAIL_FROM') ? MAIL_FROM : 'no-reply@dgao-proceedings.de');

    // CRLF-Injection-Schutz: Header-Felder duerfen kein \r\n enthalten,
    // sonst kann ein Aufrufer (z.B. via DB-Daten) zusaetzliche Header
    // einschleusen. mail() filtert das selbst nicht zuverlaessig.
    foreach (['to' => $to, 'subject' => $subject, 'from' => $from] as $name => $field) {
        if (preg_match('/[\r\n]/', $field)) {
            error_log("sendMail: CRLF in {$name} blockiert");
            return false;
        }
    }

    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('sendMail: ungueltige E-Mail-Adresse');
        return false;
    }

    $headers = [
        'From: DGaO-Proceedings <' . $from . '>',
        'Reply-To: ' . $from,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'MIME-Version: 1.0',
        'X-Mailer: DGaO-Proceedings',
    ];

    // mail() liefert false wenn lokal kein MTA da ist — Fehler nicht propagieren.
    $sent = false;
    if (function_exists('mail')) {
        $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
    }

    // Always log (audit trail + dev fallback)
    $logFile = __DIR__ . '/data/mail_outbox.log';
    $logEntry = sprintf(
        "[%s] sent=%s\nTo: %s\nFrom: %s\nSubject: %s\n\n%s\n\n%s\n",
        date('c'),
        $sent ? 'true' : 'false (logged only)',
        $to,
        $from,
        $subject,
        $body,
        str_repeat('-', 70)
    );
    if (@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
        error_log('sendMail: konnte mail_outbox.log nicht schreiben');
    }

    return $sent;
}

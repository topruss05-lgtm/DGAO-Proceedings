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

    $headers = [
        'From: DGaO-Proceedings <' . $from . '>',
        'Reply-To: ' . $from,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'MIME-Version: 1.0',
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
    @file_put_contents($logFile, $logEntry, FILE_APPEND);

    return $sent;
}

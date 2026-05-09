<?php
require_once __DIR__ . '/../../submissions.php';
require_once __DIR__ . '/../../mailer.php';

$pageTitle = 'Manuskript einreichen — ' . SITE_NAME;
$canonicalUrl = BASE_URL . '/einreichen';

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code  = trim($_POST['code']  ?? '');
    $email = trim($_POST['email'] ?? '');

    $result = createSubmissionRequest($code, $email);

    // Generic Antwort — keine User-Enumeration
    if ($result !== null) {
        $link = BASE_URL . '/einreichen/' . $result['token'];
        $paper = $result['paper'];
        $expires = $result['expires_at'];
        $body = <<<EOT
Hallo,

du hast einen Upload-Link für dein DGaO-Proceedings-Manuskript angefordert.

  Beitrag: {$paper['code']} — {$paper['titel']}
  Tagung:  {$paper['tagung_nummer']}. Jahrestagung der DGaO

Klicke auf den folgenden Link, um deine PDF-Datei hochzuladen:

  {$link}

Der Link ist gültig bis: {$expires}

Solltest du diese Mail nicht angefordert haben, kannst du sie ignorieren —
ohne Klick auf den Link passiert nichts.

Bei Fragen: dgao-sekretariat@dgao.de

—
Tagungsgeschäftsführung der DGaO
EOT;
        sendMail($email, '[DGaO] Upload-Link für Beitrag ' . $paper['code'], $body);
    }

    $flash = [
        'type' => 'info',
        'message' => 'Wenn die angegebene E-Mail-Adresse bei diesem Beitrag hinterlegt ist, '
                   . 'haben wir gerade einen Upload-Link an diese Adresse gesendet. '
                   . 'Bitte prüfe dein Postfach (auch Spam-Ordner).',
    ];
}
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/">Start</a></li>
        <li class="breadcrumb-item active">Manuskript einreichen</li>
    </ol>
</nav>

<h1 class="h4 mb-3"><i class="bi bi-cloud-upload"></i> Manuskript einreichen</h1>

<p class="text-muted">
    Reiche dein zweiseitiges Manuskript-PDF zu deinem Vortrag oder Poster ein.
    Das System gleicht Vortragscode und E-Mail-Adresse ab und sendet dir einen
    sicheren Upload-Link an die Adresse, die im Tagungsband hinterlegt ist.
</p>

<?php if ($flash): ?>
<div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="card" style="max-width: 520px;">
    <div class="card-body">
        <form method="post" action="/einreichen">
            <div class="mb-3">
                <label for="code" class="form-label">Vortragscode</label>
                <input type="text" class="form-control" id="code" name="code"
                       placeholder="z. B. A12, H1, P5" required maxlength="6"
                       value="<?= e($_POST['code'] ?? '') ?>">
                <div class="form-text">Der Code aus dem Tagungsband (z. B. „A12").</div>
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">Hinterlegte E-Mail-Adresse</label>
                <input type="email" class="form-control" id="email" name="email"
                       placeholder="vorname.nachname@example.com" required
                       value="<?= e($_POST['email'] ?? '') ?>">
                <div class="form-text">Die Adresse, die im Tagungsband bei deinem Beitrag steht.</div>
            </div>

            <button type="submit" class="btn btn-accent">
                <i class="bi bi-envelope-arrow-up"></i> Upload-Link anfordern
            </button>
        </form>
    </div>
</div>

<div class="mt-4 text-muted small">
    <strong>Hinweis:</strong> Aus Datenschutzgründen erhältst du dieselbe Antwort,
    egal ob die Daten passen — so kann niemand fremde E-Mail-Adressen testen.
    Der Link ist 30 Tage gültig.
</div>

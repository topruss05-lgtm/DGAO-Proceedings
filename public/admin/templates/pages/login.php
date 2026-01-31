<div class="login-card">
    <h1 class="login-title">DGaO Admin</h1>

    <?php if (!empty($loginError)): ?>
    <div class="alert alert-danger"><?= e($loginError) ?></div>
    <?php endif; ?>

    <form method="post" action="/admin/login">
        <?= csrfField() ?>
        <div class="mb-3">
            <label for="user" class="form-label">Benutzer</label>
            <input type="text" class="form-control" id="user" name="user" required autofocus>
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">Passwort</label>
            <input type="password" class="form-control" id="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Anmelden</button>
    </form>
</div>

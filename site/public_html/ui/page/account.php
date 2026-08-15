<?php
$csrfToken = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
?>

<div class="container py-5" style="max-width:900px">

    <?php html_notification_print(); ?>

    <div class="mb-4">
        <h1 class="h4 mb-1"><i class="bi bi-person-circle me-2" aria-hidden="true"></i>Minha conta</h1>
        <p class="mb-0" style="font-size:0.85rem;color:var(--text-muted)">Seus dados de acesso.</p>
    </div>

    <div class="card mb-4">
        <div class="card-body p-4">
            <h6 class="fw-semibold mb-3" style="font-size:0.875rem;">
                <i class="bi bi-person me-2" style="color:var(--accent)" aria-hidden="true"></i>Dados da conta
            </h6>
            <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['account_url'], ENT_QUOTES, 'UTF-8'); ?>" style="max-width:32rem;">
                <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">

                <div class="mb-3">
                    <label for="account-name" class="form-label">Nome</label>
                    <input type="text" id="account-name" name="name" class="form-control" required autocomplete="name"
                           value="<?php echo htmlspecialchars($account['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="mb-3">
                    <label for="account-login" class="form-label">Login</label>
                    <input type="text" id="account-login" class="form-control" disabled
                           value="<?php echo htmlspecialchars($account['login'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <p class="form-text">O login não pode ser alterado.</p>
                </div>

                <div class="mb-3">
                    <label for="account-mail" class="form-label">E-mail</label>
                    <input type="email" id="account-mail" class="form-control" disabled
                           value="<?php echo htmlspecialchars($account['mail'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <p class="form-text">Para trocar o e-mail, peça a um administrador.</p>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-sm btn-accent">Salvar alterações</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-4">
            <h6 class="fw-semibold mb-3" style="font-size:0.875rem;">
                <i class="bi bi-key me-2" style="color:var(--accent)" aria-hidden="true"></i>Senha
            </h6>
            <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['account_password_url'], ENT_QUOTES, 'UTF-8'); ?>"
                  style="max-width:32rem;" x-data="{ pwd: '', confirm: '' }">
                <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">

                <div class="mb-3">
                    <label for="current-password" class="form-label">Senha atual</label>
                    <input type="password" id="current-password" name="current_password" class="form-control"
                           required autocomplete="current-password">
                </div>

                <div class="mb-3">
                    <label for="new-password" class="form-label">Nova senha</label>
                    <input type="password" id="new-password" name="password" class="form-control"
                           required minlength="6" autocomplete="new-password" x-model="pwd">
                    <p class="form-text">Mínimo de 6 caracteres.</p>
                </div>

                <div class="mb-3">
                    <label for="confirm-password" class="form-label">Confirme a nova senha</label>
                    <input type="password" id="confirm-password" name="password_confirm" class="form-control"
                           required autocomplete="new-password" x-model="confirm">
                    <p class="invalid-feedback d-block" x-show="confirm !== '' && pwd !== confirm">
                        As senhas não conferem.
                    </p>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-sm btn-accent"
                            :disabled="pwd === '' || pwd !== confirm">Alterar senha</button>
                </div>
            </form>
        </div>
    </div>

</div>

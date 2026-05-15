<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">

            <?php html_notification_print(); ?>

            <div class="text-center mb-4">
                <h4 class="fw-bold mb-1">Recuperar senha</h4>
                <p class="small" style="color: var(--app-text-muted);">Informe seu e-mail cadastrado para receber o link de redefinição</p>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="POST" action="<?php echo $GLOBALS['forgot_password_url']; ?>">
                        <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-4">
                            <label for="mail" class="form-label">E-mail</label>
                            <input type="email"
                                   class="form-control"
                                   id="mail"
                                   name="mail"
                                   autocomplete="email"
                                   required>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            Enviar link de redefinição
                        </button>

                    </form>
                </div>

                <div class="card-footer text-center py-3">
                    <small style="color: var(--app-text-muted);">
                        Lembrou a senha?
                        <a href="<?php echo $GLOBALS['login_url']; ?>" class="text-decoration-none">Fazer login</a>
                    </small>
                </div>
            </div>

        </div>
    </div>
</div>

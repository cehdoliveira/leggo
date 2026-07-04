    </main>

    <footer class="ss-footer">
        <div class="container">
            <div class="ss-footer-inner">
                <div class="ss-footer-brand">
                    <span class="brand-logo brand-logo-sm" aria-hidden="true"><?php readfile(__DIR__ . '/../../assets/img/logo.svg'); ?></span>
                    <span><?php echo htmlspecialchars(constant('cTitle')); ?> &copy; <?php echo date('Y'); ?></span>
                </div>
                <div class="ss-footer-links">
                    <a href="<?php echo $GLOBALS['terms_url']; ?>">Termos de Uso</a>
                    <a href="<?php echo $GLOBALS['privacy_url']; ?>">Política de Privacidade</a>
                    <?php if (!auth_controller::check_login()): ?>
                        <a href="<?php echo $GLOBALS['login_url']; ?>">Entrar</a>
                        <a href="<?php echo $GLOBALS['register_url']; ?>">Criar Conta</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </footer>

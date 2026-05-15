    </main>

    <footer class="ss-footer">
        <div class="container">
            <div class="ss-footer-inner">
                <div class="ss-footer-brand">
                    <img src="<?php printf('%s%s', constant('cFrontend'), 'assets/img/logo.png'); ?>"
                        width="22" height="22" alt="Logo" style="object-fit:contain">
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

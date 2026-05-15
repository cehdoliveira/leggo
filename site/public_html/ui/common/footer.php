    </main>

    <footer class="ss-footer">
        <div class="container">
            <div class="ss-footer-inner">
                <div class="ss-footer-brand">
                    <img src="<?php printf('%s%s', constant('cFrontend'), 'assets/img/logo.png'); ?>"
                        width="22" height="22" alt="GarimpAções" style="object-fit:contain">
                    <span>GarimpAções &copy; <?php echo date('Y'); ?></span>
                </div>
                <div class="ss-footer-links">
                    <a href="<?php echo $GLOBALS['ranking_guide_url']; ?>">Como Ler o Ranking</a>
                    <a href="<?php echo $GLOBALS['terms_url']; ?>">Termos de Uso</a>
                    <a href="<?php echo $GLOBALS['privacy_url']; ?>">Política de Privacidade</a>
                    <?php if (!auth_controller::check_login()): ?>
                        <a href="<?php echo $GLOBALS['login_url']; ?>">Entrar</a>
                        <a href="<?php echo $GLOBALS['register_url']; ?>">Criar Conta</a>
                    <?php endif; ?>
                </div>
            </div>
            <p class="ss-footer-disclaimer">
                Dados de mercado obtidos e consolidados por meio de ferramentas terceiras especializadas.<br>
                As informações exibidas têm caráter informativo e não constituem recomendação de investimento.
            </p>
            <!-- <p class="ss-footer-disclaimer" style="margin-top:0.35rem; font-size:0.78rem; opacity:0.9;">
                Carlos Oliveira | CPF: 022.749.701-57 | Contato: leggo@gmail.com
            </p> -->
        </div>
    </footer>

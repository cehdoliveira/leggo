</head>

<body>
    <header>
        <nav class="ss-navbar">
            <div class="container ss-navbar-inner">
                <a class="ss-brand" href="<?php echo $GLOBALS['home_url']; ?>">
                    <img src="<?php printf('%s%s', constant('cFrontend'), 'assets/img/logo.png'); ?>" width="28" height="28" alt="Logo" style="object-fit:contain">
                    <span><?php echo htmlspecialchars(constant('cTitle')); ?></span>
                </a>

                <?php if (auth_controller::check_login()): ?>
                    <?php
                    $userName = htmlspecialchars($_SESSION[constant("cAppKey")]["credential"]["name"] ?? '', ENT_QUOTES, 'UTF-8');
                    ?>
                    <div class="ss-navbar-actions">
                        <span class="d-none d-sm-inline" style="font-size:0.78rem;color:var(--text-muted);">
                            <?php echo $userName; ?>
                        </span>
                        <button type="button" class="btn-theme-toggle" data-theme-toggle title="Alternar tema" aria-label="Alternar tema">
                            <i class="bi bi-sun" aria-hidden="true"></i>
                        </button>
                        <a class="btn btn-ghost btn-sm" href="<?php echo $GLOBALS['logout_url']; ?>">
                            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Sair</span>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="ss-navbar-actions">
                        <button type="button" class="btn-theme-toggle" data-theme-toggle title="Alternar tema" aria-label="Alternar tema">
                            <i class="bi bi-sun" aria-hidden="true"></i>
                        </button>
                        <a class="btn btn-ghost btn-sm" href="<?php echo $GLOBALS['login_url']; ?>">
                            <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Entrar</span>
                        </a>
                        <a class="btn btn-accent btn-sm" href="<?php echo $GLOBALS['register_url']; ?>">
                            Criar Conta
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <main id="mainContent" class="flex-shrink-0">

</head>

<body>
    <header>
        <nav class="navbar leggo-navbar">
            <div class="container-fluid px-4 d-flex align-items-center justify-content-between">
                <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo $GLOBALS['home_url']; ?>">
                    <img src="<?php printf('%s%s', constant('cFrontend'), 'assets/img/logo.png'); ?>" width="28" height="28" alt="GarimpAções" style="object-fit:contain">
                    <span class="brand-name">GarimpAções Gerenciador</span>
                </a>
                <?php if (auth_controller::check_login()) { ?>
                    <div class="d-flex align-items-center gap-2">
                        <a class="btn btn-sm btn-outline-secondary"
                            href="<?php echo defined('SITE_URL') ? htmlspecialchars(constant('SITE_URL')) : '/'; ?>"
                            target="_blank"
                            rel="noopener"
                            style="font-size:0.8rem; padding:0.3rem 0.75rem; border-color:var(--border); color:var(--text-muted);">
                            <i class="bi bi-arrow-up-right-square me-1"></i>Ver Ranking Público
                        </a>
                        <a class="btn btn-sm btn-outline-danger"
                            href="<?php echo $GLOBALS['logout_url']; ?>"
                            style="font-size:0.8rem; padding:0.3rem 0.75rem;">
                            <i class="bi bi-box-arrow-right me-1"></i>Sair
                        </a>
                    </div>
                <?php } else { ?>
                    <a class="btn btn-sm btn-outline-secondary"
                        href="<?php echo $GLOBALS['login_url']; ?>"
                        style="font-size:0.8rem; color:var(--text-muted); border-color:var(--border);">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Entrar
                    </a>
                <?php } ?>
            </div>
        </nav>
    </header>

    <main id="mainContent" class="flex-shrink-0">

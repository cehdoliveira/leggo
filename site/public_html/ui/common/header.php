</head>

<body>
    <header>
        <nav class="ss-navbar">
            <div class="container ss-navbar-inner">
                <a class="ss-brand" href="<?php echo $GLOBALS['home_url']; ?>">
                    <img src="<?php printf('%s%s', constant('cFrontend'), 'assets/img/logo.png'); ?>" width="28" height="28" alt="GarimpAções" style="object-fit:contain">
                    <span>GarimpAções</span>
                </a>

                <?php if (auth_controller::check_login()): ?>
                    <?php
                    $userName = htmlspecialchars($_SESSION[constant("cAppKey")]["credential"]["name"] ?? '', ENT_QUOTES, 'UTF-8');
                    $userId = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);
                    $headerSub = $userId > 0 ? check_subscription($userId) : ['valid' => false];
                    $headerSubPlan = $headerSub['subscription']['plan'] ?? null;
                    $headerSubBadgeClass = match ($headerSub['subscription']['status'] ?? '') {
                        'trial' => 'subscription-badge--trial',
                        'active' => 'subscription-badge--active',
                        default => 'subscription-badge--expired',
                    };
                    $headerSubExpiry = $headerSub['subscription']['expires_at'] ?? null;
                    $headerSubDaysLeft = ($headerSubExpiry !== null && $headerSubPlan !== 'lifetime')
                        ? max(0, (int)ceil((strtotime($headerSubExpiry) - time()) / 86400))
                        : null;
                    ?>
                    <div class="ss-navbar-actions">
                        <span class="d-none d-sm-inline" style="font-size:0.78rem;color:var(--text-muted);">
                            <?php echo $userName; ?>
                        </span>
                        <?php if ($headerSubPlan): ?>
                            <a href="<?php echo $GLOBALS['plans_url']; ?>" class="subscription-badge <?php echo $headerSubBadgeClass; ?>" style="text-decoration:none;font-size:0.7rem;" title="<?php echo $headerSubDaysLeft !== null ? 'Expira em ' . $headerSubDaysLeft . ' dia(s)' : 'Acesso vitalício'; ?>">
                                <?php echo match ($headerSubPlan) {
                                    'trial' => 'Trial',
                                    'monthly' => 'Mensal',
                                    'annual' => 'Anual',
                                    'lifetime' => 'Vitalício',
                                    default => ''
                                }; ?>
                                <?php if ($headerSubDaysLeft !== null): ?>
                                    <span style="opacity:0.75;margin-left:0.25rem;">• <?php echo $headerSubDaysLeft > 0 ? 'até ' . date('d/m', strtotime($headerSubExpiry)) : 'expira hoje'; ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>
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

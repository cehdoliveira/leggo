<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin");
?>

<div class="manager-layout">

    <!-- Sidebar -->
    <nav class="manager-sidebar">
        <div class="manager-sidebar-inner">
            <div class="nav-section-label">Menu</div>
            <ul class="nav flex-column gap-1">
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['home_url']; ?>" class="nav-link active">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['users_url']; ?>" class="nav-link">
                        <i class="bi bi-people"></i> Usuários
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['quarterly_users_url']; ?>" class="nav-link">
                        <i class="bi bi-graph-up-arrow"></i> Acesso Trimestral
                    </a>
                </li>
            </ul>

            <div class="nav-section-label">Conta</div>
            <ul class="nav flex-column gap-1">
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['logout_url']; ?>" class="nav-link text-danger">
                        <i class="bi bi-box-arrow-right"></i> Sair
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Conteúdo principal -->
    <main class="manager-content">

        <?php html_notification_print(); ?>

        <!-- Cabeçalho -->
        <div class="page-header">
            <h1><i class="bi bi-speedometer2 me-2"></i>Dashboard</h1>
            <p>Olá, <?php echo $userName; ?>. Bem-vindo ao painel administrativo.</p>
        </div>

        <!-- Cards de resumo (placeholder) -->
        <div class="row g-3 mb-4">
            <?php
            $stats = [
                ["bi-people",       "bg-primary", "Usuários",   "—"],
                ["bi-file-earmark", "bg-success", "Registros",  "—"],
                ["bi-clock",        "bg-warning", "Pendentes",  "—"],
                ["bi-check2-all",   "bg-info",    "Processados", "—"],
            ];
            foreach ($stats as [$icon, $bg, $label, $value]):
                $color = str_replace('bg-', '', $bg);
            ?>
                <div class="col-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon <?php echo $bg; ?> bg-opacity-10 text-<?php echo $color; ?>">
                                <i class="bi <?php echo $icon; ?>"></i>
                            </div>
                            <div>
                                <div class="stat-label"><?php echo $label; ?></div>
                                <div class="stat-value"><?php echo $value; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Painel de conteúdo (placeholder) -->
        <div class="row g-3">
            <div class="col-12">
                <div class="content-panel">
                    <div class="content-panel-header">
                        <i class="bi bi-table"></i> Área de conteúdo
                    </div>
                    <div class="content-panel-body">
                        <p class="text-muted small mb-0">
                            Substitua este bloco pelas funcionalidades administrativas da sua aplicação.
                            Adicione listagens, formulários e ações conforme necessário.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

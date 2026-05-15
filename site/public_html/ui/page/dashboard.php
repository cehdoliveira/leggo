<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Usuário");
?>

<div class="container dashboard-wrapper">

    <?php html_notification_print(); ?>

    <!-- Cabeçalho da página -->
    <div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1>Olá, <?php echo $userName; ?></h1>
            <p>Bem-vindo à sua área. <?php echo htmlspecialchars(constant("cTitle")); ?></p>
        </div>
        <a href="<?php echo $GLOBALS['logout_url']; ?>" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-box-arrow-right me-1"></i> Sair
        </a>
    </div>

    <!-- Cards de resumo (placeholder) -->
    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ["bi-person-check", "bg-primary",   "Usuários",    "—"],
            ["bi-bar-chart",    "bg-success",   "Atividades",  "—"],
            ["bi-clock-history","bg-warning",   "Pendentes",   "—"],
            ["bi-check-circle", "bg-info",      "Concluídos",  "—"],
        ];
        foreach ($stats as [$icon, $bg, $label, $value]): ?>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon <?php echo $bg; ?> bg-opacity-10 text-<?php echo str_replace('bg-', '', $bg); ?>">
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
        <div class="col-lg-8">
            <div class="content-panel">
                <div class="content-panel-header">
                    <i class="bi bi-list-ul"></i> Conteúdo principal
                </div>
                <div class="content-panel-body">
                    <p class="text-muted small mb-0">
                        Substitua este bloco pelo conteúdo real da sua aplicação.
                        Este é um painel template de área logada.
                    </p>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="content-panel">
                <div class="content-panel-header">
                    <i class="bi bi-info-circle"></i> Informações
                </div>
                <div class="content-panel-body">
                    <ul class="list-unstyled small mb-0" style="color: var(--app-text-muted);">
                        <li class="mb-2"><strong>Login:</strong> <?php echo htmlspecialchars($credential["login"] ?? "—"); ?></li>
                        <li class="mb-2"><strong>E-mail:</strong> <?php echo htmlspecialchars($credential["mail"]  ?? "—"); ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

</div>

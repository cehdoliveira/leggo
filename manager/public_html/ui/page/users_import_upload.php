<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');
$csrfToken  = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
?>

<div class="manager-layout">

    <!-- Sidebar -->
    <nav class="manager-sidebar">
        <div class="manager-sidebar-inner">
            <div class="nav-section-label">Menu</div>
            <ul class="nav flex-column gap-1">
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['home_url']; ?>" class="nav-link">
                        <i class="bi bi-people" aria-hidden="true"></i> Usuários
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['usersimports_url']; ?>" class="nav-link active">
                        <i class="bi bi-file-earmark-arrow-up" aria-hidden="true"></i> Importar Usuários
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['emails_url']; ?>" class="nav-link">
                        <i class="bi bi-envelope" aria-hidden="true"></i> E-mails
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['profiles_url']; ?>" class="nav-link">
                        <i class="bi bi-person-badge" aria-hidden="true"></i> Perfis
                    </a>
                </li>
            </ul>

            <div class="nav-section-label">Conta</div>
            <ul class="nav flex-column gap-1">
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['logout_url']; ?>" class="nav-link" style="color: #ef4444;">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sair
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
            <div>
                <h1><i class="bi bi-upload me-2" aria-hidden="true"></i>Importar Usuários</h1>
                <p>Olá, <?php echo $userName; ?>. Envie um CSV com as colunas <code>name</code> e <code>mail</code> (separador <code>;</code>).</p>
            </div>
        </div>

        <!-- Formulário -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-file-earmark-arrow-up" aria-hidden="true"></i> Enviar arquivo
            </div>
            <div class="content-panel-body" style="padding:1.25rem;max-width:32rem;">
                <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['usersimports_url'] . '/novo', ENT_QUOTES, 'UTF-8'); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">

                    <div class="mb-3">
                        <label for="import-arquivo" class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Arquivo CSV</label>
                        <input type="file" id="import-arquivo" name="arquivo" class="form-control" accept=".csv,text/csv" required>
                        <div class="form-text">
                            Colunas obrigatórias: <code>name</code>, <code>mail</code>. Até 200 linhas de dados.
                            <a href="<?php echo htmlspecialchars(set_url($GLOBALS['usersimports_url'], ['baixar_modelo' => '1']), ENT_QUOTES, 'UTF-8'); ?>">Baixar modelo</a>.
                        </div>
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <a href="<?php echo htmlspecialchars($GLOBALS['usersimports_url'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-sm btn-primary">Enviar</button>
                    </div>
                </form>
            </div>
        </div>

    </main>
</div>

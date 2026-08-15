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
                <h1><i class="bi bi-file-earmark-arrow-up me-2" aria-hidden="true"></i>Importar Usuários</h1>
                <p>Olá, <?php echo $userName; ?>. Histórico de importações de usuários por CSV.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo htmlspecialchars(set_url($GLOBALS['usersimports_url'], ['baixar_modelo' => '1']), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-secondary">
                    <i class="bi bi-download me-1" aria-hidden="true"></i>Baixar modelo
                </a>
                <a href="<?php echo htmlspecialchars($GLOBALS['usersimports_url'] . '/novo', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-upload me-1" aria-hidden="true"></i>Importar CSV
                </a>
            </div>
        </div>

        <!-- Tabela de imports -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-table" aria-hidden="true"></i> Importações
            </div>
            <div class="content-panel-body p-0">
                <?php if (empty($imports)): ?>
                    <div class="p-4 text-center" style="color: var(--text-muted); font-size: 0.85rem;">
                        Nenhuma importação registrada.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Arquivo</th>
                                    <th>Criado em</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($imports as $i): ?>
                                    <tr>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo (int)$i['idx']; ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($i['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo time_ago($i['created_at'] ?? null); ?></td>
                                        <td style="font-size:0.82rem;">
                                            <?php if (!empty($i['imported_at'])): ?>
                                                <span class="badge text-bg-success">Aplicado</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">Rascunho</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo htmlspecialchars($GLOBALS['usersimports_url'] . '/' . (int)$i['idx'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary">Ver</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (($totalPages ?? 0) > 1): ?>
                <div class="content-panel-footer d-flex justify-content-center p-3">
                    <nav aria-label="Paginação de importações">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['usersimports_url'], ['sr' => (max(1, $page - 1) - 1) * $paginate]), ENT_QUOTES, 'UTF-8'); ?>">Anterior</a>
                            </li>
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item<?php echo $p === $page ? ' active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['usersimports_url'], ['sr' => ($p - 1) * $paginate]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item<?php echo $page >= $totalPages ? ' disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['usersimports_url'], ['sr' => (min($totalPages, $page + 1) - 1) * $paginate]), ENT_QUOTES, 'UTF-8'); ?>">Próximo</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

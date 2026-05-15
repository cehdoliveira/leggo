<div class="manager-layout">

    <!-- Sidebar -->
    <nav class="manager-sidebar">
        <div class="manager-sidebar-inner">
            <div class="nav-section-label">Menu</div>
            <ul class="nav flex-column gap-1">
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['home_url']; ?>" class="nav-link">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['users_url']; ?>" class="nav-link">
                        <i class="bi bi-people"></i> Usuários
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['quarterly_users_url']; ?>" class="nav-link active">
                        <i class="bi bi-graph-up-arrow"></i> Acesso Trimestral
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['payments_url']; ?>" class="nav-link">
                        <i class="bi bi-credit-card"></i> Pagamentos
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
        <div class="page-header d-flex align-items-start justify-content-between gap-3 flex-wrap">
            <div>
                <h1><i class="bi bi-graph-up-arrow me-2"></i>Acesso Trimestral</h1>
                <p><?php echo count($quarterlyUsers); ?> usuário(s) com acesso ao Acompanhamento Trimestral.</p>
            </div>
            <a href="<?php echo $GLOBALS['quarterly_users_url']; ?>?action=grant_form" class="btn btn-accent btn-sm">
                <i class="bi bi-person-plus me-1" aria-hidden="true"></i>
                Conceder acesso
            </a>
        </div>

        <!-- Formulário manual de grant (query param) -->
        <?php if (isset($_GET['action']) && $_GET['action'] === 'grant_form'): ?>
        <div class="content-panel mb-4">
            <div class="content-panel-header">
                <i class="bi bi-person-plus"></i> Conceder acesso por ID de usuário
            </div>
            <div class="content-panel-body">
                <form method="POST" action="<?php echo $GLOBALS['quarterly_access_url']; ?>" class="d-flex gap-2 align-items-end">
                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="grant">
                    <div>
                        <label for="grant_user_id" class="form-label small">ID do usuário</label>
                        <input type="number" id="grant_user_id" name="user_id" class="form-control form-control-sm" min="1" required style="width:140px;" aria-label="ID do usuário">
                    </div>
                    <button type="submit" class="btn btn-accent btn-sm">Conceder</button>
                    <a href="<?php echo $GLOBALS['quarterly_users_url']; ?>" class="btn btn-ghost btn-sm">Cancelar</a>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tabela de usuários -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-table"></i> Usuários com acesso
            </div>
            <div class="content-panel-body p-0">
                <?php if (empty($quarterlyUsers)): ?>
                <div class="text-center py-5 px-4">
                    <i class="bi bi-calendar3" style="font-size:2rem;color:var(--text-muted);" aria-hidden="true"></i>
                    <p class="mt-3 mb-0" style="color:var(--text-muted);">Nenhum usuário com acesso ao Acompanhamento Trimestral.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0" aria-label="Usuários com acesso ao Acompanhamento Trimestral">
                        <thead>
                            <tr>
                                <th scope="col">ID</th>
                                <th scope="col">Nome</th>
                                <th scope="col">E-mail</th>
                                <th scope="col">Acesso concedido em</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quarterlyUsers as $qu): ?>
                            <tr>
                                <td><?php echo (int)$qu['idx']; ?></td>
                                <td><?php echo htmlspecialchars($qu['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($qu['mail'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($qu['access_granted_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <form method="POST" action="<?php echo $GLOBALS['quarterly_access_url']; ?>"
                                          onsubmit="return confirm('Revogar acesso de <?php echo htmlspecialchars(addslashes($qu['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>?');">
                                        <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$qu['idx']; ?>">
                                        <input type="hidden" name="action" value="revoke">
                                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger,#ef4444);" aria-label="Revogar acesso de <?php echo htmlspecialchars($qu['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="bi bi-person-dash" aria-hidden="true"></i>
                                            Revogar
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');
$adminIdx   = (int)($credential["idx"] ?? 0);
$csrfToken  = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
$page       = (int)floor($offset / $paginate) + 1;
?>

<div class="manager-layout" x-data="dashboardController()">

    <!-- Sidebar -->
    <nav class="manager-sidebar">
        <div class="manager-sidebar-inner">
            <div class="nav-section-label">Menu</div>
            <ul class="nav flex-column gap-1">
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['home_url']; ?>" class="nav-link active">
                        <i class="bi bi-people" aria-hidden="true"></i> Usuários
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
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h1><i class="bi bi-people me-2" aria-hidden="true"></i>Gerenciar Usuários</h1>
                    <p>Olá, <?php echo $userName; ?>. Gerencie os usuários cadastrados no sistema.</p>
                </div>
                <div class="d-flex gap-2">
                    <form method="POST" action="<?php echo $GLOBALS['users_url']; ?>" style="display:inline;">
                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="export-csv">
                        <button type="submit" class="btn btn-outline-secondary btn-sm" style="white-space:nowrap;">
                            <i class="bi bi-download me-1" aria-hidden="true"></i> Exportar CSV
                        </button>
                    </form>
                    <a href="<?php echo htmlspecialchars(set_url($form['pattern']['new'], ['done' => $form['done']]), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary" style="white-space:nowrap;">
                        <i class="bi bi-person-plus me-1" aria-hidden="true"></i> Novo Usuário
                    </a>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <?php
            $stats = [
                ["bi-people-fill",  "bg-primary", "Total",    $total_users],
                ["bi-person-check", "bg-success", "Ativos",   $enabled_users],
                ["bi-person-dash",  "bg-warning", "Inativos", $active_users - $enabled_users],
                ["bi-person-x",     "bg-danger",  "Removidos", $removed_users],
            ];
            foreach ($stats as [$icon, $bg, $label, $value]):
                $color = str_replace('bg-', '', $bg);
            ?>
                <div class="col-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon <?php echo $bg; ?> bg-opacity-10 text-<?php echo $color; ?>">
                                <i class="bi <?php echo $icon; ?>" aria-hidden="true"></i>
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

        <!-- Busca -->
        <div class="content-panel">
            <div class="content-panel-body" style="padding:1rem 1.25rem;">
                <form method="GET" action="<?php echo htmlspecialchars($form['pattern']['search'], ENT_QUOTES, 'UTF-8'); ?>" class="d-flex flex-wrap gap-2">
                    <input type="text" name="filter_name" class="form-control form-control-sm" style="max-width:16rem;"
                           placeholder="Buscar por nome ou e-mail" aria-label="Buscar por nome ou e-mail" autocomplete="off"
                           value="<?php echo htmlspecialchars($done['filter_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                    <select name="filter_enabled" class="form-select form-select-sm" style="max-width:10rem;" aria-label="Filtrar por situação">
                        <option value="">Situação: Todos</option>
                        <option value="yes"<?php echo ($done['filter_enabled'] ?? '') === 'yes' ? ' selected' : ''; ?>>Situação: Ativo</option>
                        <option value="no"<?php echo ($done['filter_enabled'] ?? '') === 'no' ? ' selected' : ''; ?>>Situação: Inativo</option>
                    </select>

                    <select name="filter_profile" class="form-select form-select-sm" style="max-width:12rem;" aria-label="Filtrar por perfil">
                        <option value="">Perfil: Todos</option>
                        <?php foreach ($availableProfiles as $profileIdx => $profileName): ?>
                            <option value="<?php echo (int)$profileIdx; ?>"<?php echo (int)($done['filter_profile'] ?? 0) === (int)$profileIdx ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($profileName ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="btn btn-sm btn-primary">Filtrar</button>
                </form>
            </div>
        </div>

        <!-- Tabela de usuários -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-table" aria-hidden="true"></i> Usuários Cadastrados
            </div>
            <div class="content-panel-body p-0">
                <?php if (empty($users)): ?>
                    <div class="p-4 text-center" style="color: var(--text-muted); font-size: 0.85rem;">
                        Nenhum usuário cadastrado.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>
                                        <a href="<?php echo htmlspecialchars(set_url($GLOBALS['users_url'], ['ordenation' => $ordenation['name'][0]] + $done), ENT_QUOTES, 'UTF-8'); ?>"
                                           class="text-decoration-none">
                                            Nome <i class="<?php echo $ordenation['name'][1]; ?>" aria-hidden="true"></i>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="<?php echo htmlspecialchars(set_url($GLOBALS['users_url'], ['ordenation' => $ordenation['mail'][0]] + $done), ENT_QUOTES, 'UTF-8'); ?>"
                                           class="text-decoration-none">
                                            E-mail <i class="<?php echo $ordenation['mail'][1]; ?>" aria-hidden="true"></i>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="<?php echo htmlspecialchars(set_url($GLOBALS['users_url'], ['ordenation' => $ordenation['login'][0]] + $done), ENT_QUOTES, 'UTF-8'); ?>"
                                           class="text-decoration-none">
                                            Login <i class="<?php echo $ordenation['login'][1]; ?>" aria-hidden="true"></i>
                                        </a>
                                    </th>
                                    <th>Perfis</th>
                                    <th>Status</th>
                                    <th>Verificado</th>
                                    <th>
                                        <a href="<?php echo htmlspecialchars(set_url($GLOBALS['users_url'], ['ordenation' => $ordenation['last_login'][0]] + $done), ENT_QUOTES, 'UTF-8'); ?>"
                                           class="text-decoration-none">
                                            Último login <i class="<?php echo $ordenation['last_login'][1]; ?>" aria-hidden="true"></i>
                                        </a>
                                    </th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u):
                                    $isEnabled  = ($u['enabled'] ?? 'yes') === 'yes';
                                    $isVerified = !empty($u['email_verified_at']);
                                    $lastLogin  = time_ago($u['last_login'] ?? null);
                                    $userIdx    = (int)$u['idx'];
                                    $isSelf     = $userIdx === $adminIdx;
                                    $jsName     = htmlspecialchars(json_encode($u['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $editUrl    = set_url(sprintf($form['pattern']['action'], rawurlencode((string)$u['slug'])), ['done' => $form['done']]);
                                    $removeUrl  = sprintf($form['pattern']['remove'], rawurlencode((string)$u['slug']));
                                ?>
                                    <tr>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo $userIdx; ?></td>
                                        <td><?php echo htmlspecialchars($u['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($u['mail'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($u['login'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php foreach (($u['profiles_attach'] ?? []) as $prof): ?>
                                                <span class="user-badge badge-active"><?php echo htmlspecialchars($prof['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td>
                                            <?php if ($isEnabled): ?>
                                                <span class="user-badge badge-active">Ativo</span>
                                            <?php else: ?>
                                                <span class="user-badge badge-inactive">Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isVerified): ?>
                                                <i class="bi bi-check-circle-fill" style="color:#4ade80" title="Verificado" aria-label="E-mail verificado"></i>
                                            <?php else: ?>
                                                <i class="bi bi-clock" style="color:var(--text-muted)" title="Pendente" aria-label="Aguardando verificação"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo $lastLogin; ?></td>
                                        <td>
                                            <?php if (!$isSelf): ?>
                                                <div class="d-flex gap-1">

                                                    <!-- Editar -->
                                                    <a href="<?php echo htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-action-edit" title="Editar usuário" aria-label="Editar usuário">
                                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                                    </a>

                                                    <!-- Ativar / Inativar -->
                                                    <form method="POST" action="<?php echo $GLOBALS['users_url']; ?>"
                                                        @submit.prevent="confirmToggle($event.target, <?php echo $jsName; ?>, '<?php echo $isEnabled ? 'inativar' : 'ativar'; ?>')">
                                                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <input type="hidden" name="idx"         value="<?php echo $userIdx; ?>">
                                                        <input type="hidden" name="action"      value="<?php echo $isEnabled ? 'inativar' : 'ativar'; ?>">
                                                        <button type="submit" class="btn btn-sm btn-action-toggle"
                                                            title="<?php echo $isEnabled ? 'Inativar usuário' : 'Ativar usuário'; ?>">
                                                            <i class="bi <?php echo $isEnabled ? 'bi-person-dash' : 'bi-person-check'; ?>" aria-hidden="true"></i>
                                                        </button>
                                                    </form>

                                                    <!-- Reset de senha -->
                                                    <form method="POST" action="<?php echo $GLOBALS['users_url']; ?>"
                                                        @submit.prevent="confirmResetPassword($event.target, <?php echo $jsName; ?>)">
                                                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <input type="hidden" name="idx"         value="<?php echo $userIdx; ?>">
                                                        <input type="hidden" name="action"      value="reset-senha">
                                                        <button type="submit" class="btn btn-sm btn-action-reset" title="Enviar reset de senha" aria-label="Enviar reset de senha">
                                                            <i class="bi bi-envelope-arrow-up" aria-hidden="true"></i>
                                                        </button>
                                                    </form>

                                                    <!-- Remover -->
                                                    <form method="POST" action="<?php echo htmlspecialchars($removeUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                                        @submit.prevent="confirmRemove($event.target, <?php echo $jsName; ?>)">
                                                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <input type="hidden" name="done" value="<?php echo htmlspecialchars($form['done'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <button type="submit" class="btn btn-sm btn-action-remove" title="Remover usuário">
                                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                                        </button>
                                                    </form>

                                                </div>
                                            <?php else: ?>
                                                <span style="font-size:0.72rem;color:var(--text-muted);">Você</span>
                                            <?php endif; ?>
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
                    <nav aria-label="Paginação de usuários">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['users_url'], ['sr' => (max(1, $page - 1) - 1) * $paginate] + $done), ENT_QUOTES, 'UTF-8'); ?>">Anterior</a>
                            </li>
                            <?php
                            $windowStart = max(1, min($page - 3, $totalPages - 6));
                            $windowEnd   = min($totalPages, $windowStart + 6);
                            for ($p = $windowStart; $p <= $windowEnd; $p++):
                            ?>
                                <li class="page-item<?php echo $p === $page ? ' active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['users_url'], ['sr' => ($p - 1) * $paginate] + $done), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item<?php echo $page >= $totalPages ? ' disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['users_url'], ['sr' => (min($totalPages, $page + 1) - 1) * $paginate] + $done), ENT_QUOTES, 'UTF-8'); ?>">Próximo</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

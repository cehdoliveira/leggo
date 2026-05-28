<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');
$adminIdx   = (int)($credential["idx"] ?? 0);
$csrfToken  = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
?>

<div class="manager-layout" x-data="dashboardController()" x-init="init()">

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
                <a href="<?php echo $GLOBALS['register_url']; ?>" class="btn btn-primary" style="white-space:nowrap;">
                    <i class="bi bi-person-plus me-1" aria-hidden="true"></i> Novo Usuário
                </a>
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

        <!-- Tabela de usuários -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-table" aria-hidden="true"></i> Usuários Cadastrados
            </div>
            <div class="content-panel-body p-0">
                <?php if (empty($users)): ?>
                    <div class="p-4 text-center" style="color: var(--app-text-muted); font-size: 0.85rem;">
                        Nenhum usuário cadastrado.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Nome</th>
                                    <th>E-mail</th>
                                    <th>Login</th>
                                    <th>Status</th>
                                    <th>Verificado</th>
                                    <th>Último login</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u):
                                    $isRemoved  = ($u['active'] ?? 'yes') === 'no';
                                    $isEnabled  = ($u['enabled'] ?? 'yes') === 'yes';
                                    $isVerified = !empty($u['email_verified_at']);
                                    $lastLogin  = !empty($u['last_login'])
                                        ? date('d/m/Y H:i', strtotime($u['last_login']))
                                        : '—';
                                    $userIdx    = (int)$u['idx'];
                                    $isSelf     = $userIdx === $adminIdx;
                                    $jsName     = htmlspecialchars(json_encode($u['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $jsMail     = htmlspecialchars(json_encode($u['mail'] ?? ''), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <tr<?php echo $isRemoved ? ' style="opacity:.4"' : ''; ?>>
                                        <td style="font-size:0.78rem;color:var(--app-text-muted);"><?php echo $userIdx; ?></td>
                                        <td><?php echo htmlspecialchars($u['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($u['mail'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($u['login'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if ($isRemoved): ?>
                                                <span class="user-badge badge-removed">Removido</span>
                                            <?php elseif ($isEnabled): ?>
                                                <span class="user-badge badge-active">Ativo</span>
                                            <?php else: ?>
                                                <span class="user-badge badge-inactive">Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isVerified): ?>
                                                <i class="bi bi-check-circle-fill" style="color:#4ade80" title="Verificado" aria-label="E-mail verificado"></i>
                                            <?php else: ?>
                                                <i class="bi bi-clock" style="color:var(--app-text-muted)" title="Pendente" aria-label="Aguardando verificação"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:0.78rem;color:var(--app-text-muted);"><?php echo $lastLogin; ?></td>
                                        <td>
                                            <?php if (!$isRemoved && !$isSelf): ?>
                                                <div class="d-flex gap-1">

                                                    <!-- Editar -->
                                                    <button type="button" class="btn btn-sm btn-action-edit"
                                                        @click="openEdit(<?php echo $userIdx; ?>, <?php echo $jsName; ?>, <?php echo $jsMail; ?>)"
                                                        title="Editar usuário">
                                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                                    </button>

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

                                                    <!-- Remover -->
                                                    <form method="POST" action="<?php echo $GLOBALS['users_url']; ?>"
                                                        @submit.prevent="confirmRemove($event.target, <?php echo $jsName; ?>)">
                                                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <input type="hidden" name="idx"         value="<?php echo $userIdx; ?>">
                                                        <input type="hidden" name="action"      value="remover">
                                                        <button type="submit" class="btn btn-sm btn-action-remove" title="Remover usuário">
                                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                                        </button>
                                                    </form>

                                                </div>
                                            <?php elseif ($isSelf && !$isRemoved): ?>
                                                <span style="font-size:0.72rem;color:var(--app-text-muted);">Você</span>
                                            <?php else: ?>
                                                <span style="font-size:0.72rem;color:var(--app-text-muted);">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Modal de edição -->
        <div id="editUserModal" class="modal fade" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="background:var(--app-surface);border:1px solid var(--app-border);border-radius:0.5rem;">
                    <form method="POST" action="<?php echo $GLOBALS['users_url']; ?>">
                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="editar">
                        <input type="hidden" name="idx" :value="editData.idx">

                        <div class="modal-header" style="border-color:var(--app-border);padding:1rem 1.25rem 0.75rem;">
                            <h5 class="modal-title" id="editUserModalLabel"
                                style="font-size:0.9rem;font-weight:700;color:var(--app-text);">
                                <i class="bi bi-pencil me-2" style="color:var(--app-primary)" aria-hidden="true"></i>Editar Usuário
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>

                        <div class="modal-body" style="padding:1.25rem;">
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--app-text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Nome</label>
                                <input type="text" name="name" class="form-control" x-model="editData.name" required autocomplete="off">
                            </div>
                            <div class="mb-0">
                                <label class="form-label" style="font-size:0.8rem;color:var(--app-text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">E-mail</label>
                                <input type="email" name="mail" class="form-control" x-model="editData.mail" required autocomplete="off">
                            </div>
                        </div>

                        <div class="modal-footer" style="border-color:var(--app-border);padding:0.75rem 1.25rem;justify-content:space-between;">
                            <button type="button" class="btn btn-sm btn-action-reset"
                                @click="confirmResetPassword(editData.idx, editData.name)">
                                <i class="bi bi-envelope-arrow-up me-1" aria-hidden="true"></i>Enviar reset de senha
                            </button>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-sm btn-primary">Salvar</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Form oculto para reset de senha -->
        <form id="resetPasswordForm" method="POST" action="<?php echo $GLOBALS['users_url']; ?>" style="display:none;">
            <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="action" value="reset-senha">
            <input type="hidden" name="idx" id="resetPasswordIdx" value="">
        </form>

    </main>
</div>

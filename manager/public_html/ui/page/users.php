<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin");

$planLabels  = ['trial' => 'Trial', 'monthly' => 'Mensal', 'annual' => 'Anual', 'lifetime' => 'Vitalício'];
$statusLabels = ['trial' => 'Trial', 'active' => 'Ativa', 'expired' => 'Expirada', 'cancelled' => 'Cancelada'];
$statusColors = ['trial' => 'warning', 'active' => 'success', 'expired' => 'danger', 'cancelled' => 'secondary'];
?>

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
                    <a href="<?php echo $GLOBALS['users_url']; ?>" class="nav-link active">
                        <i class="bi bi-people"></i> Usuários
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['quarterly_users_url']; ?>" class="nav-link">
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
        <div class="page-header">
            <h1><i class="bi bi-people me-2"></i>Usuários</h1>
            <p>Gerenciamento de usuários e assinaturas.</p>
        </div>

        <!-- Métricas SaaS -->
        <?php if (!empty($metrics)): ?>
            <div class="row g-3 mb-4">
                <?php
                $metricCards = [
                    ['label' => 'Usuários Totais',    'value' => (int)($metrics['total_users'] ?? 0),     'icon' => 'bi-people',            'color' => 'var(--accent)'],
                    ['label' => 'Novos este Mês',      'value' => (int)($metrics['new_this_month'] ?? 0),  'icon' => 'bi-person-plus',       'color' => 'var(--success,#2ea043)'],
                    ['label' => 'Em Trial',            'value' => (int)($metrics['total_trial'] ?? 0),     'icon' => 'bi-hourglass-split',   'color' => 'var(--warning,#d29922)'],
                    ['label' => 'Assinantes Ativos',  'value' => (int)($metrics['total_paying'] ?? 0),    'icon' => 'bi-credit-card',       'color' => 'var(--success,#2ea043)'],
                    ['label' => 'Expirados',           'value' => (int)($metrics['total_expired'] ?? 0),   'icon' => 'bi-x-circle',          'color' => 'var(--error,#da3633)'],
                ];
                ?>
                <?php foreach ($metricCards as $mc): ?>
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="card h-100 text-center" style="background:var(--surface);border-color:var(--border);padding:1rem 0.5rem">
                            <i class="bi <?php echo $mc['icon']; ?>" style="font-size:1.6rem;color:<?php echo $mc['color']; ?>"></i>
                            <div class="fw-bold mt-1" style="font-size:1.5rem;color:var(--text-heading)"><?php echo $mc['value']; ?></div>
                            <div class="small text-muted"><?php echo htmlspecialchars($mc['label']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Card Comunicações -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card h-100 text-center"
                        style="background:var(--surface);border-color:var(--border);padding:1rem 0.5rem;cursor:pointer"
                        data-bs-toggle="modal" data-bs-target="#modal-comunicacoes"
                        title="Abrir central de comunicações">
                        <i class="bi bi-megaphone" style="font-size:1.6rem;color:var(--accent)"></i>
                        <div class="fw-bold mt-1" style="font-size:1.5rem;color:var(--text-heading)">
                            <i class="bi bi-arrow-right-circle" style="font-size:1.2rem"></i>
                        </div>
                        <div class="small text-muted">Comunicações</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Modal Comunicações -->
        <div class="modal fade" id="modal-comunicacoes" tabindex="-1" aria-labelledby="modal-comunicacoes-label" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="background:var(--surface);border-color:var(--border)">
                    <div class="modal-header" style="border-color:var(--border)">
                        <h5 class="modal-title" id="modal-comunicacoes-label" style="color:var(--text-heading)">
                            <i class="bi bi-megaphone me-2"></i>Comunicações
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <form method="POST"
                        action="<?php echo htmlspecialchars($GLOBALS['send_communication_url'], ENT_QUOTES, 'UTF-8'); ?>"
                        id="form-comunicacao">
                        <div class="modal-body">
                            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                            <div class="mb-3">
                                <label class="form-label small" for="communication_type">Tipo de Comunicação</label>
                                <select name="communication_type" id="communication_type" class="form-select form-select-sm"
                                    onchange="comunicacaoToggle(this.value)">
                                    <option value="">— Selecione —</option>
                                    <option value="ranking_notification">Reenviar Notificação de Ranking</option>
                                </select>
                            </div>

                            <!-- Painel: ranking_notification -->
                            <div id="painel-ranking_notification" class="comunicacao-painel" style="display:none">
                                <div class="mb-2">
                                    <label class="form-label small" for="session_id">Ranking</label>
                                    <select name="session_id" id="session_id" class="form-select form-select-sm">
                                        <option value="">— Selecione o ranking —</option>
                                        <?php foreach ($successSessions as $s):
                                            $notifLabel = ($s['ranking_notification_sent'] ?? 'no') === 'yes' ? ' ✓' : '';
                                        ?>
                                            <option value="<?php echo (int)$s['idx']; ?>">
                                                <?php echo htmlspecialchars($s['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                                — <?php echo htmlspecialchars(date('d/m/Y', strtotime($s['created_at'] ?? 'now')), ENT_QUOTES, 'UTF-8'); ?>
                                                <?php echo htmlspecialchars($notifLabel, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text small text-muted mt-1">✓ = notificação já enviada anteriormente</div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer" style="border-color:var(--border)">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" id="btn-comunicacao" class="btn btn-sm btn-primary" disabled>
                                <i class="bi bi-send me-1"></i>Enviar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card mb-4" style="background:var(--surface); border-color:var(--border);">
            <div class="card-body">
                <form method="GET" action="<?php echo $GLOBALS['users_url']; ?>" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small">Buscar</label>
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Nome ou email..."
                            value="<?php echo htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Plano</label>
                        <select name="plan" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            <?php foreach ($planLabels as $k => $v): ?>
                                <option value="<?php echo $k; ?>" <?php echo ($_GET['plan'] ?? '') === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            <?php foreach ($statusLabels as $k => $v): ?>
                                <option value="<?php echo $k; ?>" <?php echo ($_GET['status'] ?? '') === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                            <i class="bi bi-search me-1"></i>Filtrar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabela de usuários -->
        <div class="card" style="background:var(--surface); border-color:var(--border);">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0" style="background:transparent;">
                        <thead>
                            <tr>
                                <th style="width:40px;">#</th>
                                <th>Nome</th>
                                <th>Email</th>
                                <th>Plano</th>
                                <th>Status</th>
                                <th>Expira em</th>
                                <th>Cadastro</th>
                                <th style="width:130px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">Nenhum usuário encontrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td><?php echo (int)$u['idx']; ?></td>
                                        <td><?php echo htmlspecialchars($u['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($u['mail'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if ($u['sub_plan']): ?>
                                                <span class="badge bg-<?php echo $statusColors[$u['sub_status']] ?? 'secondary'; ?> bg-opacity-25 text-<?php echo $statusColors[$u['sub_status']] ?? 'secondary'; ?>">
                                                    <?php echo $planLabels[$u['sub_plan']] ?? $u['sub_plan']; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($u['sub_status']): ?>
                                                <span class="badge bg-<?php echo $statusColors[$u['sub_status']] ?? 'secondary'; ?>">
                                                    <?php echo $statusLabels[$u['sub_status']] ?? $u['sub_status']; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            if ($u['sub_plan'] === 'lifetime') {
                                                echo '<span class="text-success">∞</span>';
                                            } elseif (!empty($u['expires_at'])) {
                                                echo date('d/m/Y', strtotime($u['expires_at']));
                                            } else {
                                                echo '<span class="text-muted">—</span>';
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo !empty($u['user_created_at']) ? date('d/m/Y', strtotime($u['user_created_at'])) : '—'; ?></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button type="button" class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editSubModal<?php echo (int)$u['idx']; ?>"
                                                    title="Editar assinatura">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-warning"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#resetPwdModal<?php echo (int)$u['idx']; ?>"
                                                    title="Enviar link de redefinição de senha">
                                                    <i class="bi bi-key"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#deactivateModal<?php echo (int)$u['idx']; ?>"
                                                    title="Desativar usuário">
                                                    <i class="bi bi-person-x"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Modal reset de senha -->
                                    <div class="modal fade" id="resetPwdModal<?php echo (int)$u['idx']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-sm">
                                            <div class="modal-content" style="background:var(--surface); border-color:var(--border);">
                                                <form method="POST" action="<?php echo $GLOBALS['reset_user_password_url']; ?>">
                                                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['idx']; ?>">
                                                    <div class="modal-header" style="border-color:var(--border);">
                                                        <h5 class="modal-title">Resetar senha</h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p class="small mb-0">Enviar link de redefinição de senha para <strong><?php echo htmlspecialchars($u['mail'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>?</p>
                                                    </div>
                                                    <div class="modal-footer" style="border-color:var(--border);">
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                        <button type="submit" class="btn btn-sm btn-warning">Enviar</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Modal desativar usuário -->
                                    <div class="modal fade" id="deactivateModal<?php echo (int)$u['idx']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-sm">
                                            <div class="modal-content" style="background:var(--surface); border-color:var(--border);">
                                                <form method="POST" action="<?php echo $GLOBALS['deactivate_user_url']; ?>">
                                                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['idx']; ?>">
                                                    <div class="modal-header" style="border-color:var(--border);">
                                                        <h5 class="modal-title text-danger">Desativar usuário</h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p class="small mb-0">Desativar <strong><?php echo htmlspecialchars($u['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>? O acesso será bloqueado. Nenhum dado será excluído.</p>
                                                    </div>
                                                    <div class="modal-footer" style="border-color:var(--border);">
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                        <button type="submit" class="btn btn-sm btn-danger">Desativar</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Modal edição assinatura -->
                                    <div class="modal fade" id="editSubModal<?php echo (int)$u['idx']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content" style="background:var(--surface); border-color:var(--border);">
                                                <form method="POST" action="<?php echo $GLOBALS['update_subscription_url']; ?>">
                                                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['idx']; ?>">
                                                    <div class="modal-header" style="border-color:var(--border);">
                                                        <h5 class="modal-title">Assinatura — <?php echo htmlspecialchars($u['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Plano</label>
                                                            <select name="plan" class="form-select">
                                                                <?php foreach ($planLabels as $k => $v): ?>
                                                                    <option value="<?php echo $k; ?>" <?php echo ($u['sub_plan'] ?? '') === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Status</label>
                                                            <select name="status" class="form-select">
                                                                <?php foreach ($statusLabels as $k => $v): ?>
                                                                    <option value="<?php echo $k; ?>" <?php echo ($u['sub_status'] ?? '') === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Expira em</label>
                                                            <input type="date" name="expires_at" class="form-control"
                                                                value="<?php echo !empty($u['expires_at']) ? date('Y-m-d', strtotime($u['expires_at'])) : ''; ?>">
                                                            <small class="text-muted">Deixe vazio para vitalício.</small>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer" style="border-color:var(--border);">
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                        <button type="submit" class="btn btn-sm btn-primary">Salvar</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-3 text-muted small">
            <?php echo count($users); ?> usuário(s) encontrado(s)
        </div>

    </main>
</div>

<script>
    function comunicacaoToggle(type) {
        document.querySelectorAll('.comunicacao-painel').forEach(function(p) {
            p.style.display = 'none';
        });
        var btn = document.getElementById('btn-comunicacao');
        btn.disabled = true;
        if (!type) return;
        var painel = document.getElementById('painel-' + type);
        if (painel) {
            painel.style.display = 'block';
            if (type === 'ranking_notification') {
                var sel = document.getElementById('session_id');
                btn.disabled = !sel || !sel.value;
                if (sel) sel.onchange = function() { btn.disabled = !this.value; };
            } else {
                btn.disabled = false;
            }
        } else {
            btn.disabled = false;
        }
    }

    // Reseta o formulário ao fechar o modal
    document.getElementById('modal-comunicacoes').addEventListener('hidden.bs.modal', function() {
        document.getElementById('communication_type').value = '';
        document.querySelectorAll('.comunicacao-painel').forEach(function(p) { p.style.display = 'none'; });
        document.getElementById('btn-comunicacao').disabled = true;
    });
</script>

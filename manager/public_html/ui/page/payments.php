<?php
$planLabels = [
    'monthly'            => 'Mensal',
    'annual'             => 'Anual',
    'lifetime'           => 'Vitalício',
    'quarterly_tracking' => 'Acomp. Trimestral',
    'trial'              => 'Trial',
];
$statusLabels = [
    'pending'   => 'Pendente',
    'confirmed' => 'Confirmado',
    'expired'   => 'Expirado',
    'failed'    => 'Falhou',
    'refunded'  => 'Estornado',
];
$statusColors = [
    'pending'   => 'warning',
    'confirmed' => 'success',
    'expired'   => 'secondary',
    'failed'    => 'danger',
    'refunded'  => 'danger',
];
$typeLabels = ['subscription' => 'Assinatura', 'feature' => 'Feature'];
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
                    <a href="<?php echo $GLOBALS['users_url']; ?>" class="nav-link">
                        <i class="bi bi-people"></i> Usuários
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['quarterly_users_url']; ?>" class="nav-link">
                        <i class="bi bi-graph-up-arrow"></i> Acesso Trimestral
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['payments_url']; ?>" class="nav-link active">
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

        <div class="page-header">
            <h1><i class="bi bi-credit-card me-2"></i>Pagamentos</h1>
            <p>Consulta de pagamentos e verificação de comprovantes de reembolso.</p>
        </div>

        <!-- Filtros -->
        <div class="card mb-4" style="background:var(--surface); border-color:var(--border);">
            <div class="card-body">
                <form method="GET" action="<?php echo $GLOBALS['payments_url']; ?>" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small">Código de comprovante (GARIM-...)</label>
                        <input type="text" name="token" class="form-control form-control-sm"
                               placeholder="GARIM-XXXX-XXXX-XXXX ou parte do código"
                               value="<?php echo htmlspecialchars($_GET['token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Referência (PAY-...)</label>
                        <input type="text" name="ref" class="form-control form-control-sm"
                               placeholder="PAY-..."
                               value="<?php echo htmlspecialchars($_GET['ref'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            <?php foreach ($statusLabels as $k => $v): ?>
                                <option value="<?php echo $k; ?>" <?php echo ($_GET['status'] ?? '') === $k ? 'selected' : ''; ?>>
                                    <?php echo $v; ?>
                                </option>
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

        <!-- Tabela -->
        <div class="card" style="background:var(--surface); border-color:var(--border);">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0" style="background:transparent;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Comprovante</th>
                                <th>Referência</th>
                                <th>Usuário</th>
                                <th>Plano</th>
                                <th>Valor</th>
                                <th>Status</th>
                                <th>Tipo</th>
                                <th>Pago em</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">Nenhum pagamento encontrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($payments as $p): ?>
                                    <tr>
                                        <td><?php echo (int)$p['idx']; ?></td>
                                        <td>
                                            <?php if (!empty($p['receipt_token'])): ?>
                                                <code style="font-size:0.78rem;color:var(--accent,#00d4aa);">
                                                    <?php echo htmlspecialchars($p['receipt_token'], ENT_QUOTES, 'UTF-8'); ?>
                                                </code>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="text-muted small">
                                                <?php echo htmlspecialchars($p['payment_ref'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($p['user_mail'])): ?>
                                                <div><?php echo htmlspecialchars($p['user_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="text-muted small"><?php echo htmlspecialchars($p['user_mail'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($planLabels[$p['plan']] ?? $p['plan'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if (!empty($p['amount'])): ?>
                                                R$ <?php echo number_format((float)$p['amount'], 2, ',', '.'); ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $st = $p['status'] ?? '';
                                            $color = $statusColors[$st] ?? 'secondary';
                                            $label = $statusLabels[$st] ?? $st;
                                            ?>
                                            <span class="badge bg-<?php echo $color; ?>">
                                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-muted small">
                                                <?php echo htmlspecialchars($typeLabels[$p['payment_type']] ?? $p['payment_type'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($p['paid_at'])): ?>
                                                <span class="small"><?php echo date('d/m/Y H:i', strtotime($p['paid_at'])); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($payments) === 200): ?>
                    <div class="px-3 py-2 text-muted small border-top" style="border-color:var(--border)!important;">
                        Exibindo os 200 registros mais recentes. Use os filtros para refinar a busca.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </main>

</div>

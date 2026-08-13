<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');
$page       = (int)floor($offset / $paginate) + 1;
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
                    <a href="<?php echo $GLOBALS['emails_url']; ?>" class="nav-link active">
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
                <h1><i class="bi bi-envelope me-2" aria-hidden="true"></i>E-mails Enviados</h1>
                <p>Olá, <?php echo $userName; ?>. Histórico de e-mails registrados pelo sistema (cadastro, redefinição de senha).</p>
            </div>
        </div>

        <!-- Busca -->
        <div class="content-panel">
            <div class="content-panel-body" style="padding:1rem 1.25rem;">
                <form method="GET" action="<?php echo htmlspecialchars($form['pattern']['search'], ENT_QUOTES, 'UTF-8'); ?>" class="d-flex flex-wrap gap-2">
                    <input type="text" name="filter_mail" class="form-control form-control-sm" style="max-width:16rem;"
                           placeholder="Buscar por destinatário" aria-label="Buscar por destinatário" autocomplete="off"
                           value="<?php echo htmlspecialchars($done['filter_mail'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                    <input type="text" name="filter_subject" class="form-control form-control-sm" style="max-width:16rem;"
                           placeholder="Buscar por assunto" aria-label="Buscar por assunto" autocomplete="off"
                           value="<?php echo htmlspecialchars($done['filter_subject'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                    <button type="submit" class="btn btn-sm btn-primary">Filtrar</button>
                </form>
            </div>
        </div>

        <!-- Tabela de e-mails -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-table" aria-hidden="true"></i> Mensagens Registradas
            </div>
            <div class="content-panel-body p-0">
                <?php if (empty($emails)): ?>
                    <div class="p-4 text-center" style="color: var(--text-muted); font-size: 0.85rem;">
                        Nenhum e-mail registrado.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>
                                        <a href="<?php echo htmlspecialchars(set_url($GLOBALS['emails_url'], ['ordenation' => $ordenation['to_mail'][0]] + $done), ENT_QUOTES, 'UTF-8'); ?>"
                                           class="text-decoration-none">
                                            Destinatário <i class="<?php echo $ordenation['to_mail'][1]; ?>" aria-hidden="true"></i>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="<?php echo htmlspecialchars(set_url($GLOBALS['emails_url'], ['ordenation' => $ordenation['subject'][0]] + $done), ENT_QUOTES, 'UTF-8'); ?>"
                                           class="text-decoration-none">
                                            Assunto <i class="<?php echo $ordenation['subject'][1]; ?>" aria-hidden="true"></i>
                                        </a>
                                    </th>
                                    <th>Corpo</th>
                                    <th>
                                        <a href="<?php echo htmlspecialchars(set_url($GLOBALS['emails_url'], ['ordenation' => $ordenation['sent_at'][0]] + $done), ENT_QUOTES, 'UTF-8'); ?>"
                                           class="text-decoration-none">
                                            Enviado <i class="<?php echo $ordenation['sent_at'][1]; ?>" aria-hidden="true"></i>
                                        </a>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($emails as $e): ?>
                                    <tr>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo (int)$e['idx']; ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($e['to_mail'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($e['subject'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo htmlspecialchars(str_limit($e['body'] ?? '', 120), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo time_ago($e['sent_at'] ?? null); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (($totalPages ?? 0) > 1): ?>
                <div class="content-panel-footer d-flex justify-content-center p-3">
                    <nav aria-label="Paginação de e-mails">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['emails_url'], ['sr' => (max(1, $page - 1) - 1) * $paginate] + $done), ENT_QUOTES, 'UTF-8'); ?>">Anterior</a>
                            </li>
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item<?php echo $p === $page ? ' active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['emails_url'], ['sr' => ($p - 1) * $paginate] + $done), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item<?php echo $page >= $totalPages ? ' disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['emails_url'], ['sr' => (min($totalPages, $page + 1) - 1) * $paginate] + $done), ENT_QUOTES, 'UTF-8'); ?>">Próximo</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

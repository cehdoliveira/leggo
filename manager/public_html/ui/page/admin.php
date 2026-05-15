<?php
// Variables injected by admin_controller::dashboard():
// $allSessions  — array of screener_sessions rows, newest first
// $lastSuccess  — most recent SUCESSO session row, or null
// $filterLogs   — screener_filter_logs rows for $lastSuccess, ordered by idx ASC
// $scrapingLogs — screener_scraping_logs rows for $lastSuccess, ordered by idx ASC
?>
<div class="manager-layout">

    <!-- Sidebar -->
    <nav class="manager-sidebar">
        <div class="manager-sidebar-inner">
            <div class="nav-section-label">Rankings</div>
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
            <h1><i class="bi bi-speedometer2 me-2"></i>Dashboard</h1>
            <p>Screener de ações — faça upload da planilha e analise o ranking.</p>
        </div>

        <!-- Linha 1: Upload + Histórico -->
        <div class="row g-3">
            <div class="col-xl-3 col-lg-4">
                <div class="sc-card h-100">
                    <div class="sc-card-title">📂 Upload de Planilha</div>
                    <form method="POST"
                        action="<?php echo htmlspecialchars($GLOBALS['process_url'], ENT_QUOTES, 'UTF-8'); ?>"
                        enctype="multipart/form-data"
                        id="form-upload">
                        <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="sc-file-group">
                            <input type="file"
                                name="planilha"
                                id="planilha"
                                accept=".xlsx"
                                required
                                class="sc-file-input">
                            <p class="sc-hint">Apenas .xlsx · Máximo 10MB</p>
                        </div>
                        <button type="submit" class="sc-btn-accent sc-btn-full">
                            ⚙️ Processar Planilha
                        </button>
                    </form>
                </div>
            </div>
            <div class="col-xl-9 col-lg-8">
                <div class="sc-card h-100">
                    <div class="sc-card-title">📋 Histórico de Rankings</div>
                    <?php if (empty($allSessions)): ?>
                        <p class="sc-text-muted">Nenhum ranking processado ainda.</p>
                    <?php else: ?>
                        <div class="sc-table-wrap">
                            <table class="sc-table">
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>Data</th>
                                        <th>Arquivo</th>
                                        <th>Linhas</th>
                                        <th>Ranking</th>
                                        <th>Status</th>
                                        <th>Ação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allSessions as $s): ?>
                                        <?php
                                        $badgeClass = match ($s['status'] ?? '') {
                                            'SUCESSO'     => 'sc-badge-success',
                                            'PROCESSANDO' => 'sc-badge-warning',
                                            default       => 'sc-badge-error',
                                        };
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($s['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                            <td class="sc-nowrap"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($s['created_at'] ?? 'now')), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="sc-ellipsis" title="<?php echo htmlspecialchars($s['file_original_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars($s['file_original_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td><?php echo (int)($s['total_lines'] ?? 0); ?></td>
                                            <td><?php echo (int)($s['total_ranking'] ?? 0); ?></td>
                                            <td>
                                                <span class="sc-badge <?php echo $badgeClass; ?>">
                                                    <?php echo htmlspecialchars($s['status'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <form method="POST"
                                                    action="<?php echo htmlspecialchars($GLOBALS['delete_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    class="form-delete"
                                                    data-name="<?php echo htmlspecialchars($s['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="session_id" value="<?php echo (int)$s['idx']; ?>">
                                                    <button type="submit" class="sc-btn-danger-sm">🗑 Remover</button>
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
        </div>

        <?php if ($lastSuccess): ?>
            <!-- Linha 2: Log de Filtros + Log de Scraping -->
            <div class="row g-3 mt-0">
                <div class="col-lg-6">
                    <div class="sc-card h-100">
                        <div class="sc-card-title">
                            🔍 Log de Filtros — <?php echo htmlspecialchars($lastSuccess['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <?php
                        $logsByCode = [];
                        foreach ($filterLogs as $log) {
                            $logsByCode[$log['filter_code']] = $log;
                        }
                        $displayFilters = ['F1', 'F2', 'F3', 'F4', 'F6'];
                        $hasLogs = false;
                        foreach ($displayFilters as $code):
                            $log = $logsByCode[$code] ?? null;
                            if (!$log) continue;
                            $hasLogs = true;
                            $removed = json_decode($log['removed_tickers'] ?? '[]', true);
                            if (!is_array($removed)) $removed = [];
                            $diff = (int)$log['count_before'] - (int)$log['count_after'];
                        ?>
                            <div class="sc-filter-block">
                                <div class="sc-filter-header">
                                    <span class="sc-filter-name"><?php echo htmlspecialchars($log['filter_name'] ?? $code, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="sc-filter-count">
                                        <?php echo (int)$log['count_before']; ?> → <?php echo (int)$log['count_after']; ?>
                                        <?php if ($diff > 0): ?>
                                            <span class="sc-removed-count">(−<?php echo $diff; ?>)</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if (!empty($removed)): ?>
                                    <div class="sc-filter-tickers">
                                        <?php echo htmlspecialchars(implode(', ', $removed), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php else: ?>
                                    <p class="sc-filter-none">Nenhum removido</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$hasLogs): ?>
                            <p class="sc-text-muted">Nenhum log de filtro disponível.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="sc-card h-100">
                        <div class="sc-card-title">
                            🌐 Log de Scraping — <?php echo htmlspecialchars($lastSuccess['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <?php if (empty($scrapingLogs)): ?>
                            <p class="sc-text-muted">Scraping não executado (ScraperService não configurado).</p>
                        <?php else: ?>
                            <p class="sc-hint-scraping">Verde = mantido · Vermelho = removido por irregularidade</p>
                            <div class="sc-scraping-grid">
                                <?php foreach ($scrapingLogs as $slog): ?>
                                    <?php $isRemoved = !empty($slog['is_removed']) && (int)$slog['is_removed'] === 1; ?>
                                    <div class="sc-scraping-item <?php echo $isRemoved ? 'sc-removed' : 'sc-kept'; ?>">
                                        <div class="sc-ticker"><?php echo htmlspecialchars($slog['ticker'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="sc-status-text" title="<?php echo htmlspecialchars($slog['status_text'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($slog['status_text'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </main>
</div><!-- /.manager-layout -->

<!-- Processing overlay — shown while XLSX is being processed -->
<div id="sc-overlay" class="sc-overlay">
    <div class="sc-overlay-content">
        <div class="sc-spinner"></div>
        <p>Processando planilha... pode levar vários minutos.</p>
    </div>
</div>

<script>
    (function() {
        var form = document.getElementById('form-upload');
        var overlay = document.getElementById('sc-overlay');

        if (form && overlay) {
            form.addEventListener('submit', function() {
                overlay.style.display = 'flex';
            });
        }

        document.querySelectorAll('.form-delete').forEach(function(f) {
            f.addEventListener('submit', function(e) {
                e.preventDefault();
                var name = this.dataset.name || 'este ranking';
                if (confirm('Remover ' + name + '? Esta ação é irreversível.')) {
                    this.submit();
                }
            });
        });
    }());

</script>

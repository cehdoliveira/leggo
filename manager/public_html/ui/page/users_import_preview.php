<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');
$csrfToken  = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
$isApplied  = !empty($draft['imported_at']);
?>

<div class="manager-layout">

    <?php $sidebarActive = 'importar'; include(constant("cRootServer") . "ui/common/sidebar.php"); ?>

    <!-- Conteúdo principal -->
    <main class="manager-content">

        <?php html_notification_print(); ?>

        <!-- Cabeçalho -->
        <div class="page-header">
            <div>
                <h1><i class="bi bi-eye me-2" aria-hidden="true"></i>Preview do Import</h1>
                <p>Olá, <?php echo $userName; ?>. Arquivo: <?php echo htmlspecialchars($draft['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>

        <!-- Contadores -->
        <div class="content-panel">
            <div class="content-panel-body" style="padding:1rem 1.25rem;">
                <div class="d-flex flex-wrap gap-3">
                    <span class="badge text-bg-secondary">Total: <?php echo (int)$totalRows; ?></span>
                    <span class="badge text-bg-success">Criar: <?php echo count($criarRows); ?></span>
                    <span class="badge text-bg-info">Atualizar: <?php echo count($atualizarRows); ?></span>
                    <span class="badge text-bg-danger">Erro: <?php echo count($erroRows); ?></span>
                </div>
                <?php if ($isApplied): ?>
                    <div class="mt-2" style="font-size:0.85rem;color:var(--text-muted);">
                        Este import já foi aplicado em <?php echo htmlspecialchars((string)$draft['imported_at'], ENT_QUOTES, 'UTF-8'); ?>.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Linhas com erro -->
        <?php if (!empty($erroRows)): ?>
            <div class="content-panel">
                <div class="content-panel-header">
                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Linhas com erro
                </div>
                <div class="content-panel-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Linha</th>
                                    <th>Nome</th>
                                    <th>E-mail</th>
                                    <th>Motivo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($erroRows as $r): ?>
                                    <tr>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo (int)$r['row']; ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($r['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($r['mail'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;color:var(--text-muted);"><?php echo htmlspecialchars($r['motivo'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Linhas a criar/atualizar -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-table" aria-hidden="true"></i> Linhas válidas
            </div>
            <div class="content-panel-body p-0">
                <?php if (empty($criarRows) && empty($atualizarRows)): ?>
                    <div class="p-4 text-center" style="color: var(--text-muted); font-size: 0.85rem;">
                        Nenhuma linha válida neste arquivo.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Linha</th>
                                    <th>Nome</th>
                                    <th>E-mail</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_merge($criarRows, $atualizarRows) as $r): ?>
                                    <tr>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo (int)$r['row']; ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($r['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($r['mail'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;">
                                            <?php if ($r['status'] === 'criar'): ?>
                                                <span class="badge text-bg-success">Criar</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-info">Atualizar</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!$isApplied): ?>
                <div class="content-panel-footer d-flex gap-2 justify-content-end p-3">
                    <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['usersimports_url'] . '/' . (int)$draft['idx'] . '/remover', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                        <button type="submit" class="btn btn-sm btn-secondary">Descartar rascunho</button>
                    </form>
                    <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['usersimports_url'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="confirmar">
                        <input type="hidden" name="idx" value="<?php echo (int)$draft['idx']; ?>">
                        <button type="submit" class="btn btn-sm btn-primary" <?php echo empty($criarRows) && empty($atualizarRows) ? 'disabled' : ''; ?>>Confirmar import</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

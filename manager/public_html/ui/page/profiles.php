<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');
$csrfToken  = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
$page       = (int)floor($offset / $paginate) + 1;
?>

<div class="manager-layout" x-data="profilesController()">

    <?php $sidebarActive = 'perfis'; include(constant("cRootServer") . "ui/common/sidebar.php"); ?>

    <!-- Conteúdo principal -->
    <main class="manager-content">

        <?php html_notification_print(); ?>

        <!-- Cabeçalho -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h1><i class="bi bi-person-badge me-2" aria-hidden="true"></i>Gerenciar Perfis</h1>
                    <p>Olá, <?php echo $userName; ?>. Gerencie os perfis de acesso cadastrados no sistema.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?php echo htmlspecialchars(set_url($form['pattern']['new'], ['done' => $form['done']]), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary" style="white-space:nowrap;">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Novo Perfil
                    </a>
                </div>
            </div>
        </div>

        <!-- Busca -->
        <div class="content-panel">
            <div class="content-panel-body" style="padding:1rem 1.25rem;">
                <form method="GET" action="<?php echo htmlspecialchars($form['pattern']['search'], ENT_QUOTES, 'UTF-8'); ?>" class="d-flex flex-wrap gap-2">
                    <input type="text" name="filter_name" class="form-control form-control-sm" style="max-width:16rem;"
                           placeholder="Buscar por nome" aria-label="Buscar por nome" autocomplete="off"
                           value="<?php echo htmlspecialchars($done['filter_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                    <select name="filter_adm" class="form-select form-select-sm" style="max-width:10rem;" aria-label="Filtrar por admin">
                        <option value="">Admin: Todos</option>
                        <option value="yes"<?php echo ($done['filter_adm'] ?? '') === 'yes' ? ' selected' : ''; ?>>Admin: Sim</option>
                        <option value="no"<?php echo ($done['filter_adm'] ?? '') === 'no' ? ' selected' : ''; ?>>Admin: Não</option>
                    </select>

                    <select name="filter_parent" class="form-select form-select-sm" style="max-width:12rem;" aria-label="Filtrar por perfil pai">
                        <option value="">Perfil pai: Todos</option>
                        <?php foreach ($availableParents as $parentIdx => $parentName): ?>
                            <option value="<?php echo (int)$parentIdx; ?>"<?php echo (int)($done['filter_parent'] ?? 0) === (int)$parentIdx ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($parentName ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="btn btn-sm btn-primary">Filtrar</button>
                </form>
            </div>
        </div>

        <!-- Tabela de perfis -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-table" aria-hidden="true"></i> Perfis Cadastrados
            </div>
            <div class="content-panel-body p-0">
                <?php if (empty($profiles)): ?>
                    <div class="p-4 text-center" style="color: var(--text-muted); font-size: 0.85rem;">
                        Nenhum perfil cadastrado.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>
                                        <a href="<?php echo htmlspecialchars(set_url($GLOBALS['profiles_url'], ['ordenation' => $ordenation['name'][0]] + $done), ENT_QUOTES, 'UTF-8'); ?>"
                                           class="text-decoration-none">
                                            Nome <i class="<?php echo $ordenation['name'][1]; ?>" aria-hidden="true"></i>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="<?php echo htmlspecialchars(set_url($GLOBALS['profiles_url'], ['ordenation' => $ordenation['slug'][0]] + $done), ENT_QUOTES, 'UTF-8'); ?>"
                                           class="text-decoration-none">
                                            Slug <i class="<?php echo $ordenation['slug'][1]; ?>" aria-hidden="true"></i>
                                        </a>
                                    </th>
                                    <th>Admin</th>
                                    <th>Protegido</th>
                                    <th>Perfil pai</th>
                                    <th>Capacidades</th>
                                    <th>
                                        <a href="<?php echo htmlspecialchars(set_url($GLOBALS['profiles_url'], ['ordenation' => $ordenation['created_at'][0]] + $done), ENT_QUOTES, 'UTF-8'); ?>"
                                           class="text-decoration-none">
                                            Criado em <i class="<?php echo $ordenation['created_at'][1]; ?>" aria-hidden="true"></i>
                                        </a>
                                    </th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profiles as $p):
                                    $profileIdx  = (int)$p['idx'];
                                    $isAdm       = ($p['adm'] ?? 'no') === 'yes';
                                    $isEditabled = ($p['editabled'] ?? 'yes') === 'yes';
                                    $parentIdx   = (int)($p['parent'] ?? 0);
                                    $parentName  = $availableParents[$parentIdx] ?? '—';
                                    $editUrl     = set_url(sprintf($form['pattern']['action'], rawurlencode((string)$p['slug'])), ['done' => $form['done']]);
                                    $removeUrl   = sprintf($GLOBALS['removeprofile_url'], rawurlencode((string)$p['slug']));
                                    $jsName      = htmlspecialchars(json_encode($p['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <tr>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo $profileIdx; ?></td>
                                        <td><?php echo htmlspecialchars($p['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($p['slug'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if ($isAdm): ?>
                                                <span class="user-badge badge-active">Sim</span>
                                            <?php else: ?>
                                                <span class="user-badge badge-inactive">Não</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isEditabled): ?>
                                                <span class="user-badge badge-active">Editável</span>
                                            <?php else: ?>
                                                <span class="user-badge badge-removed">Protegido</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:0.82rem;color:var(--text-muted);"><?php echo htmlspecialchars($parentName, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;color:var(--text-muted);">
                                            <?php
                                            $capCount = count($p['capabilities_attach'] ?? []);
                                            echo $isAdm ? 'todas' : (string)$capCount;
                                            ?>
                                        </td>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo htmlspecialchars((string)($p['created_at'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if ($isEditabled): ?>
                                                <div class="d-flex gap-1">

                                                    <!-- Editar -->
                                                    <a href="<?php echo htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-action-edit" title="Editar perfil">
                                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                                    </a>

                                                    <!-- Remover -->
                                                    <form method="POST" action="<?php echo htmlspecialchars($removeUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                                        @submit.prevent="confirmRemove($event.target, <?php echo $jsName; ?>)">
                                                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <input type="hidden" name="done" value="<?php echo htmlspecialchars($form['done'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <button type="submit" class="btn btn-sm btn-action-remove" title="Remover perfil">
                                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                                        </button>
                                                    </form>

                                                </div>
                                            <?php else: ?>
                                                <span style="font-size:0.72rem;color:var(--text-muted);">—</span>
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
                    <nav aria-label="Paginação de perfis">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['profiles_url'], ['sr' => (max(1, $page - 1) - 1) * $paginate] + $done), ENT_QUOTES, 'UTF-8'); ?>">Anterior</a>
                            </li>
                            <?php
                            $windowStart = max(1, min($page - 3, $totalPages - 6));
                            $windowEnd   = min($totalPages, $windowStart + 6);
                            for ($p = $windowStart; $p <= $windowEnd; $p++):
                            ?>
                                <li class="page-item<?php echo $p === $page ? ' active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['profiles_url'], ['sr' => ($p - 1) * $paginate] + $done), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item<?php echo $page >= $totalPages ? ' disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['profiles_url'], ['sr' => (min($totalPages, $page + 1) - 1) * $paginate] + $done), ENT_QUOTES, 'UTF-8'); ?>">Próximo</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

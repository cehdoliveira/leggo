<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');
$csrfToken  = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
?>

<div class="manager-layout" x-data="profilesController()" x-init="init()">

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
                    <a href="<?php echo $GLOBALS['emails_url']; ?>" class="nav-link">
                        <i class="bi bi-envelope" aria-hidden="true"></i> E-mails
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo $GLOBALS['profiles_url']; ?>" class="nav-link active">
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
                    <h1><i class="bi bi-person-badge me-2" aria-hidden="true"></i>Gerenciar Perfis</h1>
                    <p>Olá, <?php echo $userName; ?>. Gerencie os perfis de acesso cadastrados no sistema.</p>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" style="white-space:nowrap;" @click="openCreate()">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Novo Perfil
                    </button>
                </div>
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
                                    <th>Nome</th>
                                    <th>Slug</th>
                                    <th>Admin</th>
                                    <th>Protegido</th>
                                    <th>Perfil pai</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profiles as $p):
                                    $profileIdx  = (int)$p['idx'];
                                    $isAdm       = ($p['adm'] ?? 'no') === 'yes';
                                    $isEditabled = ($p['editabled'] ?? 'yes') === 'yes';
                                    $parentIdx   = (int)($p['parent'] ?? 0);
                                    $parentName  = '—';
                                    foreach ($availableParents as $ap) {
                                        if ((int)$ap['idx'] === $parentIdx) {
                                            $parentName = $ap['name'];
                                            break;
                                        }
                                    }
                                    $jsName   = htmlspecialchars(json_encode($p['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $jsSlug   = htmlspecialchars(json_encode($p['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $jsParent = (int)($p['parent'] ?? 0);
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
                                        <td>
                                            <?php if ($isEditabled): ?>
                                                <div class="d-flex gap-1">

                                                    <!-- Editar -->
                                                    <button type="button" class="btn btn-sm btn-action-edit"
                                                        @click="openEdit(<?php echo $profileIdx; ?>, <?php echo $jsName; ?>, <?php echo $jsSlug; ?>, <?php echo $jsParent; ?>)"
                                                        title="Editar perfil">
                                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                                    </button>

                                                    <!-- Remover -->
                                                    <form method="POST" action="<?php echo $GLOBALS['profiles_url']; ?>"
                                                        @submit.prevent="confirmRemove($event.target, <?php echo $jsName; ?>)">
                                                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <input type="hidden" name="idx"         value="<?php echo $profileIdx; ?>">
                                                        <input type="hidden" name="action"      value="remover">
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
                                <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['profiles_url'], ['page' => max(1, $page - 1)]), ENT_QUOTES, 'UTF-8'); ?>">Anterior</a>
                            </li>
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item<?php echo $p === $page ? ' active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['profiles_url'], ['page' => $p]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item<?php echo $page >= $totalPages ? ' disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars(set_url($GLOBALS['profiles_url'], ['page' => min($totalPages, $page + 1)]), ENT_QUOTES, 'UTF-8'); ?>">Próximo</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>

        <!-- Modal de criação -->
        <div id="createProfileModal" class="modal fade" tabindex="-1" aria-labelledby="createProfileModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;">
                    <form method="POST" action="<?php echo $GLOBALS['profiles_url']; ?>">
                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="criar">

                        <div class="modal-header" style="border-color:var(--border);padding:1rem 1.25rem 0.75rem;">
                            <h5 class="modal-title" id="createProfileModalLabel"
                                style="font-size:0.9rem;font-weight:700;color:var(--text);">
                                <i class="bi bi-plus-lg me-2" style="color:var(--accent)" aria-hidden="true"></i>Novo Perfil
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>

                        <div class="modal-body" style="padding:1.25rem;">
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Nome</label>
                                <input type="text" name="name" class="form-control" required autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Slug</label>
                                <input type="text" name="slug" class="form-control" required autocomplete="off">
                            </div>
                            <div class="mb-0">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Perfil pai</label>
                                <select name="parent" class="form-select">
                                    <option value="0">Nenhum (raiz)</option>
                                    <?php foreach ($availableParents as $ap): ?>
                                        <option value="<?php echo (int)$ap['idx']; ?>"><?php echo htmlspecialchars($ap['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="modal-footer" style="border-color:var(--border);padding:0.75rem 1.25rem;justify-content:end;">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-sm btn-primary">Criar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal de edição -->
        <div id="editProfileModal" class="modal fade" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;">
                    <form method="POST" action="<?php echo $GLOBALS['profiles_url']; ?>">
                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="editar">
                        <input type="hidden" name="idx" :value="editData.idx">

                        <div class="modal-header" style="border-color:var(--border);padding:1rem 1.25rem 0.75rem;">
                            <h5 class="modal-title" id="editProfileModalLabel"
                                style="font-size:0.9rem;font-weight:700;color:var(--text);">
                                <i class="bi bi-pencil me-2" style="color:var(--accent)" aria-hidden="true"></i>Editar Perfil
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>

                        <div class="modal-body" style="padding:1.25rem;">
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Nome</label>
                                <input type="text" name="name" class="form-control" x-model="editData.name" required autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Slug</label>
                                <input type="text" name="slug" class="form-control" x-model="editData.slug" required autocomplete="off">
                            </div>
                            <div class="mb-0">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Perfil pai</label>
                                <select name="parent" class="form-select" x-model="editData.parent">
                                    <option value="0">Nenhum (raiz)</option>
                                    <?php foreach ($availableParents as $ap): ?>
                                        <option value="<?php echo (int)$ap['idx']; ?>" :disabled="editData.idx === <?php echo (int)$ap['idx']; ?>"><?php echo htmlspecialchars($ap['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="modal-footer" style="border-color:var(--border);padding:0.75rem 1.25rem;justify-content:end;">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-sm btn-primary">Salvar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </main>
</div>

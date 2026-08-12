<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');
$csrfToken  = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
$currentIdx = (int)($data['idx'] ?? 0);
$cancelUrl  = ($form['done'] ?? '') !== '' ? rawurldecode($form['done']) : $GLOBALS['profiles_url'];
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
            <div>
                <h1><i class="bi bi-person-badge me-2" aria-hidden="true"></i><?php echo htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
                <p>Olá, <?php echo $userName; ?>.</p>
            </div>
        </div>

        <!-- Formulário -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-person-badge" aria-hidden="true"></i> <?php echo htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="content-panel-body" style="padding:1.25rem;max-width:32rem;">
                <form method="POST" action="<?php echo htmlspecialchars($form['url'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="done" value="<?php echo htmlspecialchars($form['done'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="mb-3">
                        <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Nome</label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($data['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="off">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Slug</label>
                        <input type="text" name="slug" class="form-control" value="<?php echo htmlspecialchars($data['slug'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="off">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Perfil pai</label>
                        <select name="parent" class="form-select">
                            <option value="0">Nenhum (raiz)</option>
                            <?php foreach ($availableParents as $parentIdx => $parentName): ?>
                                <option value="<?php echo (int)$parentIdx; ?>"
                                    <?php echo ((int)($data['parent'] ?? 0) === (int)$parentIdx) ? ' selected' : ''; ?>
                                    <?php echo ($currentIdx > 0 && (int)$parentIdx === $currentIdx) ? ' disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($parentName ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Admin</label>
                        <div>
                            <?php if (($data['adm'] ?? 'no') === 'yes'): ?>
                                <span class="user-badge badge-active">Sim</span>
                            <?php else: ?>
                                <span class="user-badge badge-inactive">Não</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <a href="<?php echo htmlspecialchars($cancelUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-sm btn-primary">Salvar</button>
                    </div>
                </form>
            </div>
        </div>

    </main>
</div>

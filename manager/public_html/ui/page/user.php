<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');
$csrfToken  = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
$cancelUrl  = $form['cancelUrl'] ?? $GLOBALS['users_url'];
?>

<div class="manager-layout">

    <?php $sidebarActive = 'usuarios'; include(constant("cRootServer") . "ui/common/sidebar.php"); ?>

    <!-- Conteúdo principal -->
    <main class="manager-content">

        <?php html_notification_print(); ?>

        <!-- Cabeçalho -->
        <div class="page-header">
            <div>
                <h1><i class="bi bi-people me-2" aria-hidden="true"></i><?php echo htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
                <p>Olá, <?php echo $userName; ?>.</p>
            </div>
        </div>

        <!-- Formulário -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-people" aria-hidden="true"></i> <?php echo htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="content-panel-body" style="padding:1.25rem;max-width:32rem;">
                <form method="POST" action="<?php echo htmlspecialchars($form['url'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="done" value="<?php echo htmlspecialchars($form['done'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="mb-3">
                        <label for="user-name" class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Nome</label>
                        <input type="text" id="user-name" name="name" class="form-control" value="<?php echo htmlspecialchars($data['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="off">
                    </div>

                    <div class="mb-3">
                        <label for="user-mail" class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">E-mail</label>
                        <input type="email" id="user-mail" name="mail" class="form-control" value="<?php echo htmlspecialchars($data['mail'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="off">
                    </div>

                    <div class="mb-3">
                        <label for="user-login" class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Login</label>
                        <input type="text" id="user-login" name="login" class="form-control" value="<?php echo htmlspecialchars($data['login'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                    </div>

                    <div class="mb-3">
                        <label for="user-phone" class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Telefone</label>
                        <input type="text" id="user-phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($data['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                    </div>

                    <fieldset class="mb-3">
                        <legend class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Perfis</legend>
                        <div class="d-flex flex-column gap-1">
                            <?php foreach ($availableProfiles as $profileIdx => $profileName): ?>
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="profiles_id[]" value="<?php echo (int)$profileIdx; ?>"
                                        <?php echo in_array((int)$profileIdx, $currentProfileIds, true) ? 'checked' : ''; ?>>
                                    <span class="form-check-label"><?php echo htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8'); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <div class="d-flex gap-2 justify-content-end">
                        <a href="<?php echo htmlspecialchars($cancelUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-sm btn-primary">Salvar</button>
                    </div>
                </form>
            </div>
        </div>

    </main>
</div>

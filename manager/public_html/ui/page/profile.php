<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');
$csrfToken  = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
$currentIdx = (int)($data['idx'] ?? 0);
$cancelUrl  = $form['cancelUrl'] ?? $GLOBALS['profiles_url'];
?>

<div class="manager-layout">

    <?php $sidebarActive = 'perfis'; include(constant("cRootServer") . "ui/common/sidebar.php"); ?>

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
                        <label for="profile-name" class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Nome</label>
                        <input type="text" id="profile-name" name="name" class="form-control" value="<?php echo htmlspecialchars($data['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="off">
                    </div>

                    <div class="mb-3">
                        <label for="profile-slug" class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Slug</label>
                        <input type="text" id="profile-slug" name="slug" class="form-control" value="<?php echo htmlspecialchars($data['slug'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="off">
                    </div>

                    <div class="mb-3">
                        <label for="profile-parent" class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Perfil pai</label>
                        <select id="profile-parent" name="parent" class="form-select">
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

                    <?php
                    $isAdmProfile = ($data['adm'] ?? 'no') === 'yes';
                    $isProtected  = ($data['editabled'] ?? 'yes') === 'no';

                    // Agrupa por recurso (prefixo do slug antes do primeiro '.') so
                    // que a lista continue legivel quando o vocabulario crescer.
                    $capabilityGroups = [];
                    foreach ($availableCapabilities as $capIdx => $capLabel) {
                        $capSlug   = explode(' — ', $capLabel, 2)[0];
                        $capDomain = explode('.', $capSlug, 2)[0];
                        $capabilityGroups[$capDomain][$capIdx] = $capLabel;
                    }
                    ?>
                    <fieldset class="mb-3" style="border:0;padding:0;margin-bottom:1rem;" x-data="{ q: '' }">
                        <legend class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;width:auto;float:none;">
                            Capacidades
                        </legend>

                        <?php if ($isAdmProfile): ?>
                            <p class="form-text mb-0">
                                Este é um perfil administrador: ele já tem todas as capacidades, marcadas ou não.
                            </p>
                        <?php endif; ?>

                        <?php if ($isProtected): ?>
                            <p class="form-text mb-0">
                                Perfil protegido. As capacidades não podem ser alteradas por aqui.
                            </p>
                        <?php else: ?>
                            <input type="text" class="form-control form-control-sm mb-2" placeholder="Filtrar capacidades"
                                   aria-label="Filtrar capacidades" x-model="q" autocomplete="off">

                            <div class="capabilities-box">
                                <?php foreach ($capabilityGroups as $capDomain => $capItems): ?>
                                    <?php $groupSearchText = htmlspecialchars(strtolower(implode(' ', $capItems)), ENT_QUOTES, 'UTF-8'); ?>
                                    <div data-search="<?php echo $groupSearchText; ?>" x-show="q === '' || $el.dataset.search.includes(q.toLowerCase())">
                                        <div style="font-size:0.72rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.03em;margin-top:0.5rem;">
                                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $capDomain)), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <?php foreach ($capItems as $capIdx => $capLabel): ?>
                                            <?php $checked = in_array((int)$capIdx, $selectedCapabilities, true); ?>
                                            <div class="form-check" data-search="<?php echo htmlspecialchars(strtolower($capLabel), ENT_QUOTES, 'UTF-8'); ?>" x-show="q === '' || $el.dataset.search.includes(q.toLowerCase())">
                                                <input class="form-check-input" type="checkbox"
                                                       name="capabilities_id[]" value="<?php echo (int)$capIdx; ?>"
                                                       id="cap-<?php echo (int)$capIdx; ?>"<?php echo $checked ? ' checked' : ''; ?>>
                                                <label class="form-check-label" for="cap-<?php echo (int)$capIdx; ?>">
                                                    <?php echo htmlspecialchars($capLabel, ENT_QUOTES, 'UTF-8'); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <input type="hidden" name="capabilities_sent" value="1">
                            <p class="form-text">
                                Desmarcar tudo remove todas as capacidades deste perfil.
                            </p>
                        <?php endif; ?>
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

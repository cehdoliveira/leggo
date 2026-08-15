<?php
/**
 * Menu lateral do manager. Incluído por todos os views que usam .manager-layout.
 *
 * Contrato: o view define $sidebarActive antes do include, com uma das chaves
 * de $sidebarItems abaixo. Item sem a capacidade exigida não é renderizado —
 * a checagem usa auth_controller::can() (modo log), não has() (estrita).
 * Motivo: profiles_capabilities só é seedada pro perfil admin (migrations/012);
 * usar has() aqui esconderia o menu inteiro de qualquer perfil custom até a
 * tela de gestão de capacidades (plano 029) existir. Reavaliar quando 029 ou
 * 022 (can() bloqueante) entrarem.
 */
$sidebarActive = $sidebarActive ?? '';

$sidebarItems = [
    ['key' => 'usuarios',  'url' => $GLOBALS['home_url'],          'icon' => 'bi-people',        'label' => 'Usuários',           'cap' => 'usuarios.ler'],
    ['key' => 'importar',  'url' => $GLOBALS['usersimports_url'],  'icon' => 'bi-upload',        'label' => 'Importar usuários',  'cap' => 'usuarios.ler'],
    ['key' => 'emails',    'url' => $GLOBALS['emails_url'],        'icon' => 'bi-envelope',      'label' => 'E-mails',            'cap' => 'emails.ler'],
    ['key' => 'perfis',    'url' => $GLOBALS['profiles_url'],      'icon' => 'bi-person-badge',  'label' => 'Perfis',             'cap' => 'perfis.ler'],
];
?>
<nav class="manager-sidebar">
    <div class="manager-sidebar-inner">
        <div class="nav-section-label">Menu</div>
        <ul class="nav flex-column gap-1">
            <?php foreach ($sidebarItems as $item): ?>
                <?php if (!auth_controller::can($item['cap'])) { continue; } ?>
                <?php $isActive = $sidebarActive === $item['key']; ?>
                <li class="nav-item">
                    <a href="<?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>"
                       class="nav-link<?php echo $isActive ? ' active' : ''; ?>"
                       <?php echo $isActive ? 'aria-current="page"' : ''; ?>>
                        <i class="bi <?php echo $item['icon']; ?>" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="nav-section-label">Conta</div>
        <ul class="nav flex-column gap-1">
            <li class="nav-item">
                <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['logout_url'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="nav-link nav-link-logout">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sair
                    </button>
                </form>
            </li>
        </ul>
    </div>
</nav>

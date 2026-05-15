<?php
// home.php — landing pública (mode 1) ou área do usuário (mode 2)
// Variáveis de site_controller::home(): $isLoggedIn, $userId
?>

<?php if ($isLoggedIn): ?>
    <!-- ===================== MODE 2 — ÁREA DO USUÁRIO ===================== -->
    <div class="container py-5" style="max-width:900px">

        <?php html_notification_print(); ?>

        <!-- Saudação -->
        <div class="welcome-banner mb-4">
            <div>
                <strong>Bem-vindo, <?php echo htmlspecialchars($_SESSION[constant("cAppKey")]["credential"]["name"] ?? '', ENT_QUOTES, 'UTF-8'); ?>!</strong>
                <p class="mb-0 mt-1" style="font-size:0.85rem;color:var(--text-muted)">
                    Você está na área restrita de <?php echo htmlspecialchars(constant('cTitle')); ?>.
                </p>
            </div>
            <a href="<?php echo $GLOBALS['logout_url']; ?>" class="btn btn-ghost btn-sm">
                <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Sair
            </a>
        </div>

        <!-- Cards de conta e placeholder -->
        <div class="row g-3 mb-4">
            <div class="col-md-5">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="fw-semibold mb-3" style="font-size:0.875rem;">
                            <i class="bi bi-person-circle me-2" style="color:var(--accent)" aria-hidden="true"></i>Minha Conta
                        </h6>
                        <?php $cred = $_SESSION[constant("cAppKey")]["credential"] ?? []; ?>
                        <ul class="list-unstyled mb-0" style="font-size:0.85rem;">
                            <li class="mb-2">
                                <span style="color:var(--text-muted)">Nome</span>
                                <div class="fw-medium"><?php echo htmlspecialchars($cred['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
                            </li>
                            <li class="mb-2">
                                <span style="color:var(--text-muted)">E-mail</span>
                                <div class="fw-medium"><?php echo htmlspecialchars($cred['mail'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
                            </li>
                            <li>
                                <span style="color:var(--text-muted)">Login</span>
                                <div class="fw-medium"><?php echo htmlspecialchars($cred['login'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-md-7">
                <div class="card h-100">
                    <div class="card-body text-center d-flex flex-column align-items-center justify-content-center" style="min-height:160px">
                        <i class="bi bi-grid-3x3-gap" style="font-size:2rem;color:var(--accent);margin-bottom:0.75rem" aria-hidden="true"></i>
                        <h6 class="fw-semibold mb-2">Área de Conteúdo</h6>
                        <p style="font-size:0.83rem;color:var(--text-muted);max-width:320px;margin:0 0 1rem">
                            Adicione aqui o conteúdo da área logada — listagens, formulários e funcionalidades da sua aplicação.
                        </p>
                        <span style="background:var(--accent-dim);color:var(--accent);border:1px solid var(--border-accent);border-radius:6px;font-size:0.72rem;font-weight:600;padding:0.2rem 0.6rem;">
                            <i class="bi bi-code-slash me-1" aria-hidden="true"></i>Whitelabel Template
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Painel principal placeholder -->
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:40px;height:40px;border-radius:10px;background:var(--accent-dim);display:flex;align-items:center;justify-content:center;color:var(--accent);font-size:1.2rem;flex-shrink:0;">
                        <i class="bi bi-layers" aria-hidden="true"></i>
                    </div>
                    <div>
                        <h6 class="fw-semibold mb-0">Painel Principal</h6>
                        <p class="mb-0" style="font-size:0.8rem;color:var(--text-muted)">Substitua com o conteúdo real da aplicação</p>
                    </div>
                </div>
                <div style="background:var(--surface-2);border:1px dashed var(--border-accent);border-radius:10px;padding:2.5rem;text-align:center;">
                    <p class="mb-0" style="font-size:0.85rem;color:var(--text-muted)">
                        <i class="bi bi-arrow-up-circle me-1" style="color:var(--accent)" aria-hidden="true"></i>
                        Adicione tabelas, gráficos, formulários ou qualquer funcionalidade aqui.
                    </p>
                </div>
            </div>
        </div>

    </div>

<?php else: ?>
    <!-- ===================== MODE 1 — LANDING PAGE ===================== -->

    <!-- HERO -->
    <section class="hero-section animate-fadein animate-pending" id="top">
        <div class="hero-bg-decoration" aria-hidden="true">
            <div class="hero-circle hero-circle-1"></div>
            <div class="hero-circle hero-circle-2"></div>
        </div>
        <div class="container hero-content">
            <h1 class="hero-heading">
                Construa o que importa<br>com <em><?php echo htmlspecialchars(constant('cTitle')); ?></em>
            </h1>
            <p class="hero-subtitle">
                Framework PHP pronto para produção. Autenticação, painel administrativo, e-mail assíncrono e infraestrutura moderna — tudo incluído.
            </p>
            <div class="hero-cta-wrap">
                <a class="btn btn-accent btn-lg" href="<?php echo $GLOBALS['register_url']; ?>">
                    Criar Conta
                </a>
                <a class="btn btn-ghost btn-lg" href="<?php echo $GLOBALS['login_url']; ?>">
                    <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Entrar
                </a>
            </div>
            <div class="hero-indicators">
                <div class="hero-indicator">
                    <i class="bi bi-shield-check" aria-hidden="true"></i>
                    <span>Auth completo</span>
                </div>
                <div class="hero-indicator">
                    <i class="bi bi-envelope-check" aria-hidden="true"></i>
                    <span>E-mail via Kafka</span>
                </div>
                <div class="hero-indicator">
                    <i class="bi bi-speedometer2" aria-hidden="true"></i>
                    <span>Painel admin</span>
                </div>
                <div class="hero-indicator">
                    <i class="bi bi-box" aria-hidden="true"></i>
                    <span>Docker ready</span>
                </div>
            </div>
        </div>
    </section>

    <!-- O QUE ESTÁ INCLUÍDO -->
    <section class="how-section py-5 animate-fadein animate-pending">
        <div class="container" style="max-width:1100px">
            <h2 class="section-title text-center mb-2">O que está incluído</h2>
            <p class="section-subtitle text-center">Tudo pronto para você começar a construir.</p>
            <div class="row g-4">
                <?php
                $features = [
                    ['bi-shield-lock',       'Autenticação Completa',
                        'Login, cadastro, verificação de e-mail, reset de senha e bloqueio por tentativas excessivas com Redis.'],
                    ['bi-person-badge',      'Perfis e Permissões',
                        'Sistema de perfis com papéis (admin/usuário), guards de rota e verificação de e-mail obrigatória.'],
                    ['bi-envelope-arrow-up', 'E-mail Assíncrono',
                        'Envio via Kafka + PHPMailer em background. Kafka down = fallback silencioso. O fluxo nunca trava.'],
                    ['bi-hdd-stack',         'Infraestrutura Pronta',
                        'Docker com Nginx + PHP-FPM 8.4, MySQL 8, Redis 7 e Kafka. Migrations automáticas a cada 5 minutos.'],
                ];
                foreach ($features as [$icon, $title, $desc]):
                ?>
                    <div class="col-sm-6 col-lg-3">
                        <div class="how-card">
                            <div class="how-card-icon">
                                <i class="bi <?php echo $icon; ?>" style="font-size:2rem;color:var(--accent)" aria-hidden="true"></i>
                            </div>
                            <h6 class="fw-semibold mb-2"><?php echo htmlspecialchars($title); ?></h6>
                            <p class="mb-0" style="font-size:0.88rem;color:var(--text-muted)"><?php echo htmlspecialchars($desc); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ESTRUTURA DO FRAMEWORK -->
    <section class="py-5 animate-fadein animate-pending">
        <div class="container" style="max-width:860px">
            <h2 class="section-title text-center mb-2">Estrutura do Framework</h2>
            <p class="text-center mb-5" style="font-size:0.92rem;color:var(--text-muted)">
                Dois ambientes separados que compartilham models, lib e infraestrutura.
            </p>
            <div class="row g-3">
                <?php
                $blocks = [
                    ['bi-window',   'Site',    'Público + área logada. Cadastro, login, verificação de e-mail e reset de senha. Frontend voltado ao usuário final.'],
                    ['bi-sliders',  'Manager', 'Painel administrativo restrito. Gestão de usuários com ativação, inativação e remoção (soft-delete).'],
                    ['bi-database', 'Shared',  'Models, lib e migrações compartilhados. DOLModel (ORM), localPDO (PDO wrapper), Dispatcher (rotas).'],
                    ['bi-gear',     'Config',  'kernel.php por ambiente (nunca versionado). Redis, Kafka, SMTP, DB e URLs configuráveis por env.'],
                ];
                foreach ($blocks as [$icon, $title, $desc]):
                ?>
                    <div class="col-sm-6">
                        <div class="d-flex gap-3 align-items-start p-3"
                             style="background:var(--surface);border:1px solid var(--border);border-radius:10px;height:100%">
                            <div style="font-size:1.4rem;flex-shrink:0;line-height:1.2;color:var(--accent)">
                                <i class="bi <?php echo $icon; ?>" aria-hidden="true"></i>
                            </div>
                            <div>
                                <h6 class="fw-semibold mb-1" style="font-size:0.9rem"><?php echo htmlspecialchars($title); ?></h6>
                                <p class="mb-0" style="font-size:0.83rem;color:var(--text-muted)"><?php echo htmlspecialchars($desc); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- CTA FINAL -->
    <section class="py-5 animate-fadein animate-pending">
        <div class="container" style="max-width:680px">
            <div class="cta-section text-center">
                <h2 class="fw-bold mb-3" style="font-size:1.6rem">Pronto para começar?</h2>
                <p class="mb-4" style="color:var(--text-muted)">
                    Crie sua conta e explore a área restrita. O painel administrativo fica em
                    <code style="font-size:0.85rem;background:var(--surface-2);padding:0.1rem 0.4rem;border-radius:4px;color:var(--accent)">manager.leggo.local</code>.
                </p>
                <a class="btn btn-accent btn-lg mb-3" href="<?php echo $GLOBALS['register_url']; ?>">
                    Criar Conta Gratuitamente
                </a>
                <p style="font-size:0.82rem;color:var(--text-muted)">
                    Já tem conta? <a href="<?php echo $GLOBALS['login_url']; ?>" style="color:var(--accent)">Entrar</a>
                </p>
            </div>
        </div>
    </section>

<?php endif; ?>

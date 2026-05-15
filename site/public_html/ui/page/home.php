<?php
// home.php — Mode 1 (landing) or Mode 2 (authenticated)
// Variables provided by site_controller::home():
//   $isLoggedIn, $session, $results, $sessions, $prevSession,
//   $persistence, $comparativo, $isAdmin, $dbError, $showWelcome

function _fmt_volume(float $v): string
{
    return 'R$ ' . number_format($v, 0, ',', '.');
}
function _fmt_decimal(?float $v, int $dec = 2): string
{
    if ($v === null) return '—';
    return number_format($v, $dec, ',', '.');
}
function _passivo_pl($passivo, $pl): ?float
{
    $p = (float)$passivo;
    $l = (float)$pl;
    return ($l > 0) ? round($p / $l, 2) : null;
}
function _row_class(int $pos): string
{
    if ($pos <= 20) return 'row-green';
    return 'row-amber';
}
function _situacao_class(string $s): string
{
    $u = strtoupper($s);
    if ($u === 'FASE OPERACIONAL' || $u === 'REGULAR') return 'operacional';
    if ($u === 'REMOVIDA' || $u === 'INDISPONÍVEL') return 'indisponivel';
    return 'irregular';
}
?>

<?php if ($isLoggedIn): ?>
    <!-- Warning banner de expiração próxima -->
    <?php if (
        !empty($subscriptionInfo) &&
        !$subscriptionExpired &&
        $subscriptionInfo['expires_at'] !== null &&
        $subscriptionInfo['plan'] !== 'lifetime'
    ):
        $daysLeft = max(0, (int)ceil((strtotime($subscriptionInfo['expires_at']) - time()) / 86400));
        if ($daysLeft <= 3):
    ?>
            <div class="warning-banner" x-data="{ dismissed: false }" x-show="!dismissed" x-cloak>
                <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                <?php if ($daysLeft <= 1): ?>
                    Sua assinatura expira <strong>amanhã</strong>.
                <?php else: ?>
                    Sua assinatura expira em <strong><?php echo $daysLeft; ?> dias</strong>.
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($GLOBALS['plans_url'], ENT_QUOTES, 'UTF-8'); ?>" style="color: var(--accent); text-decoration: underline; margin-left: 0.5rem;">
                    Renovar agora
                </a>
                <button class="warning-banner__close" @click="dismissed = true" aria-label="Fechar aviso">
                    <i class="bi bi-x" aria-hidden="true"></i>
                </button>
            </div>
    <?php endif;
    endif; ?>

    <!-- ===================== MODE 2 — ÁREA DO USUÁRIO ===================== -->
    <div class="container py-4" style="max-width:1200px">

        <!-- Overlay de expiração -->
        <?php if ($subscriptionExpired): ?>
            <div class="expired-overlay">
                <div class="expired-overlay__modal">
                    <i class="bi bi-lock-fill" style="font-size: 2.5rem; color: var(--warning); margin-bottom: 1rem; display: block;"></i>
                    <h4 class="fw-bold mb-2">Seu período de acesso expirou</h4>
                    <p class="small mb-4" style="color: var(--text-muted);">
                        Para continuar acessando o ranking completo, painel de movimentação e grid de persistência, escolha um plano de assinatura.
                    </p>
                    <div class="d-flex gap-2 justify-content-center">
                        <a href="<?php echo htmlspecialchars($GLOBALS['plans_url'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-accent">
                            Ver planos <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                        </a>
                        <a href="<?php echo htmlspecialchars($GLOBALS['logout_url'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-ghost">
                            Sair
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($dbError): ?>
            <div class="error-state text-center py-5">
                <div style="font-size:2.5rem">⚠️</div>
                <h4 class="mt-3">Erro ao carregar dados</h4>
                <p class="text-muted">Não foi possível conectar ao banco de dados.</p>
            </div>

        <?php elseif (!$session): ?>
            <div class="empty-state text-center py-5">
                <div style="font-size:2.5rem">📊</div>
                <h4 class="mt-3">Nenhum dado disponível</h4>
                <p class="text-muted">Ainda não há um ranking processado. Volte em breve para ver as melhores ações.</p>
            </div>

        <?php else: ?>

            <?php if ($showWelcome): ?>
                <div id="welcome-banner" class="welcome-banner mb-4 animate-fadein animate-pending">
                    <div class="d-flex align-items-start gap-3">
                        <span style="font-size:1.5rem">👋</span>
                        <div class="flex-grow-1">
                            <strong>Bem-vindo ao GarimpAções!</strong>
                            <p class="mb-0 mt-1" style="font-size:0.88rem;color:var(--text-muted)">
                                Você está vendo o ranking completo das 20 melhores ações filtradas pelo algoritmo.
                                Use a navegação abaixo para explorar rankings anteriores e acompanhe as mudanças mês a mês.
                            </p>
                        </div>
                        <button id="welcome-dismiss" class="btn btn-ghost btn-sm" aria-label="Fechar" style="flex-shrink:0">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (
                !$subscriptionExpired &&
                !empty($subscriptionInfo) &&
                in_array($subscriptionInfo['plan'] ?? '', ['trial', 'monthly', 'annual'], true) &&
                !empty($subscriptionInfo['expires_at'])
            ):
                $trialDaysLeft = max(0, (int)ceil((strtotime($subscriptionInfo['expires_at']) - time()) / 86400));
                $trialPlanName = match ($subscriptionInfo['plan'] ?? '') {
                    'trial'   => 'Trial',
                    'monthly' => 'Mensal',
                    'annual'  => 'Anual',
                    default   => 'Atual',
                };
                $trialExpDate = date('d/m/Y', strtotime($subscriptionInfo['expires_at']));
            ?>
                <div x-data="{ open: !sessionStorage.getItem('plan_popup_dismissed_<?php echo date('Ymd', strtotime($subscriptionInfo['expires_at'])); ?>') }"
                    x-show="open"
                    x-cloak
                    class="welcome-banner mb-4 animate-fadein animate-pending"
                    style="border-left:3px solid var(--accent);">
                    <div class="d-flex align-items-start gap-3">
                        <span style="font-size:1.4rem">⏳</span>
                        <div class="flex-grow-1">
                            <strong>Plano <?php echo htmlspecialchars($trialPlanName, ENT_QUOTES, 'UTF-8'); ?> ativo</strong>
                            <p class="mb-0 mt-1" style="font-size:0.88rem;color:var(--text-muted)">
                                <?php if ($trialDaysLeft <= 1): ?>
                                    Seu plano expira <strong>hoje</strong>. Assine agora para manter o acesso.
                                <?php else: ?>
                                    Seu plano expira em <strong><?php echo $trialDaysLeft; ?> dias</strong> (<?php echo $trialExpDate; ?>). Assine antes que termine.
                                <?php endif; ?>
                            </p>
                        </div>
                        <a href="<?php echo htmlspecialchars($GLOBALS['plans_url'], ENT_QUOTES, 'UTF-8'); ?>"
                            class="btn btn-accent btn-sm" style="flex-shrink:0">
                            Ver planos
                        </a>
                        <button class="btn btn-ghost btn-sm"
                            @click="open = false; sessionStorage.setItem('plan_popup_dismissed_<?php echo date('Ymd', strtotime($subscriptionInfo['expires_at'])); ?>', '1')"
                            aria-label="Fechar lembrete">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <?php html_notification_print(); ?>

            <!-- Session navigation -->
            <div class="session-nav mb-3">
                <?php
                $currentIdx = (int)$session['idx'];
                $prevIdx    = null;
                $nextIdx    = null;
                $currentPos = -1;
                foreach ($sessions as $i => $s) {
                    if ((int)$s['idx'] === $currentIdx) {
                        $currentPos = $i;
                        break;
                    }
                }
                if ($currentPos > 0)                       $nextIdx = (int)$sessions[$currentPos - 1]['idx'];
                if ($currentPos < count($sessions) - 1)    $prevIdx = (int)$sessions[$currentPos + 1]['idx'];
                ?>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($prevIdx): ?>
                        <a class="btn btn-ghost btn-sm" href="?session_id=<?php echo $prevIdx; ?>" title="Ranking anterior" aria-label="Ranking anterior">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <button class="btn btn-ghost btn-sm" disabled aria-disabled="true"><i class="bi bi-chevron-left"></i></button>
                    <?php endif; ?>

                    <select class="form-select form-select-sm session-select" onchange="window.location.href='?session_id='+this.value">
                        <?php foreach ($sessions as $s): ?>
                            <?php
                            $sId   = (int)$s['idx'];
                            $sName = htmlspecialchars($s['name'] ?? '', ENT_QUOTES, 'UTF-8');
                            $sDate = $s['created_at'] ? date('d/m/Y', strtotime($s['created_at'])) : '';
                            $sel   = $sId === $currentIdx ? ' selected' : '';
                            ?>
                            <option value="<?php echo $sId; ?>" <?php echo $sel; ?>><?php echo $sName; ?> — <?php echo $sDate; ?></option>
                        <?php endforeach; ?>
                    </select>

                    <?php if ($nextIdx): ?>
                        <a class="btn btn-ghost btn-sm" href="?session_id=<?php echo $nextIdx; ?>" title="Próximo ranking" aria-label="Próximo ranking">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <button class="btn btn-ghost btn-sm" disabled aria-disabled="true"><i class="bi bi-chevron-right"></i></button>
                    <?php endif; ?>
                </div>

                <span class="session-nav-meta">
                    <?php echo htmlspecialchars($session['total_ranking'] ?? count($results), ENT_QUOTES, 'UTF-8'); ?> ações analisadas
                    → <?php echo count($results); ?> no ranking
                </span>
                <?php if (defined('INVESTIDOR10_URL') && constant('INVESTIDOR10_URL') !== ''): ?>
                    <a href="<?php echo htmlspecialchars(constant('INVESTIDOR10_URL'), ENT_QUOTES, 'UTF-8'); ?>"
                        target="_blank" rel="noopener"
                        class="btn btn-ghost btn-sm" style="font-size:0.75rem;">
                        <i class="bi bi-graph-up-arrow me-1"></i>Carteira no Investidor10
                        <span class="text-muted ms-1" style="font-size:0.68rem;">(D-7)</span>
                    </a>
                <?php endif; ?>
            </div>


            <!-- Acompanhamento Trimestral -->
            <?php if (!$subscriptionExpired): ?>
                <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3 px-3 py-2"
                    style="background:var(--surface);border:1px solid var(--border);border-radius:10px;">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-graph-up-arrow" style="color:var(--accent);font-size:1.1rem;" aria-hidden="true"></i>
                        <div>
                            <strong style="font-size:0.9rem;">Acompanhamento Trimestral</strong>
                            <span class="d-none d-sm-inline" style="font-size:0.82rem;color:var(--text-muted);margin-left:0.5rem;">
                                — registre aportes e dividendos, veja sua evolução trimestre a trimestre
                            </span>
                        </div>
                    </div>
                    <?php if ($hasQuarterlyAccess): ?>
                        <a href="<?php echo htmlspecialchars($GLOBALS['quarterly_tracking_url'], ENT_QUOTES, 'UTF-8'); ?>"
                            class="btn btn-accent btn-sm flex-shrink-0">
                            Acessar <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                        </a>
                    <?php else:
                        $isAnnualOrLifetime = in_array($subscriptionInfo['plan'] ?? '', ['annual', 'lifetime'], true);
                    ?>
                        <a href="<?php echo htmlspecialchars($GLOBALS['quarterly_tracking_url'], ENT_QUOTES, 'UTF-8'); ?>"
                            class="btn btn-outline-accent btn-sm flex-shrink-0">
                            <?php echo $isAnnualOrLifetime ? 'Ativar acesso' : 'Ver — R$ 49,90 único'; ?>
                            <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Legend -->
            <div class="rank-legend mb-3">
                <span class="rank-legend-item"><span class="rank-legend-swatch" style="background:var(--success);opacity:.35"></span>Sugestão de compra 1 – 20</span>
                <span class="rank-legend-item"><span class="rank-legend-swatch" style="background:var(--warning);opacity:.3"></span>Zona de Análise e Monitoramento 21 – 30</span>
                <span class="rank-legend-item" style="margin-left:auto;font-size:0.9rem;">Exemplo de divisão: 5% por ação</span>
            </div>

            <!-- Ranking table -->
            <div class="ranking-table-wrap mb-5 animate-fadein animate-pending">
                <table class="ranking-table">
                    <thead>
                        <tr>
                            <th class="rank-col">#</th>
                            <th>Ação</th>
                            <th>Empresa</th>
                            <th class="text-end">EV/EBIT</th>
                            <th class="text-end">Passivo/PL</th>
                            <th class="text-end">ROTanC</th>
                            <th class="text-end">Margem EBIT</th>
                            <th class="text-end">Volume Diário</th>
                            <th>Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $row):
                            $pos  = (int)$row['rank_position'];
                            $ppl  = isset($row['passivo']) && $row['passivo'] !== '' && $row['passivo'] !== null ? (float)$row['passivo'] : null;
                            $rotanc = isset($row['rotanc']) ? _fmt_decimal((float)$row['rotanc']) . '%' : '—';
                            $margebit = isset($row['margem_ebit']) ? _fmt_decimal((float)$row['margem_ebit']) . '%' : '—';
                            $vol  = isset($row['volume']) ? _fmt_volume((float)$row['volume']) : '—';
                            $ev   = isset($row['ev_ebit']) ? _fmt_decimal((float)$row['ev_ebit']) : '—';
                            $sit  = $row['situacao'] ?? 'INDISPONÍVEL';
                            $sitClass = _situacao_class($sit);
                            $rowClass = _row_class($pos);
                        ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td class="rank-col"><?php echo $pos; ?></td>
                                <td><span class="badge-ticker"><?php echo htmlspecialchars($row['ticker'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo htmlspecialchars($row['empresa'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-end"><?php echo $ev; ?></td>
                                <td class="text-end"><?php echo $ppl !== null ? _fmt_decimal($ppl) : '—'; ?></td>
                                <td class="text-end"><?php echo $rotanc; ?></td>
                                <td class="text-end"><?php echo $margebit; ?></td>
                                <td class="text-end"><?php echo $vol; ?></td>
                                <td><span class="badge-situacao <?php echo $sitClass; ?>"><?php echo htmlspecialchars($sit, ENT_QUOTES, 'UTF-8'); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($isAdmin): ?>
                <!-- Admin: remove session -->
                <div class="mb-5 text-end">
                    <form method="POST" action="<?php echo $GLOBALS['home_url']; ?>remover-ranking"
                        onsubmit="return confirm('Remover este ranking permanentemente?')">
                        <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="session_id" value="<?php echo $currentIdx; ?>">
                        <button type="submit" class="btn btn-sm" style="color:var(--error);border:1px solid var(--error);background:transparent;">
                            <i class="bi bi-trash" aria-hidden="true"></i> Remover este ranking
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($prevSession): ?>
                <!-- Comparativo -->
                <section class="mb-5 animate-fadein animate-pending">
                    <h5 class="fw-semibold mb-1">🔄 Comparativo</h5>
                    <p style="font-size:0.85rem;color:var(--text-muted)" class="mb-3">
                        vs. <?php echo htmlspecialchars($prevSession['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="comp-card saidas">
                                <div class="comp-card-title">⬇️ Saíram do Ranking (<?php echo count($comparativo['saidas']); ?>)</div>
                                <?php if (empty($comparativo['saidas'])): ?>
                                    <p class="mb-0" style="font-size:0.85rem;color:var(--text-muted)">Nenhuma ação saiu.</p>
                                    <?php else: foreach ($comparativo['saidas'] as $s): ?>
                                        <div class="comp-row">
                                            <span class="badge-ticker"><?php echo htmlspecialchars($s['ticker'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="comp-empresa"><?php echo htmlspecialchars($s['empresa'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="comp-pos">#<?php echo (int)$s['prev_position']; ?></span>
                                        </div>
                                <?php endforeach;
                                endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="comp-card entradas">
                                <div class="comp-card-title">⬆️ Entraram no Ranking (<?php echo count($comparativo['entradas']); ?>)</div>
                                <?php if (empty($comparativo['entradas'])): ?>
                                    <p class="mb-0" style="font-size:0.85rem;color:var(--text-muted)">Nenhuma ação nova.</p>
                                    <?php else: foreach ($comparativo['entradas'] as $e): ?>
                                        <div class="comp-row">
                                            <span class="badge-ticker"><?php echo htmlspecialchars($e['ticker'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="comp-empresa"><?php echo htmlspecialchars($e['empresa'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="comp-pos">#<?php echo (int)$e['curr_position']; ?></span>
                                        </div>
                                <?php endforeach;
                                endif; ?>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($persistence)): ?>
                <!-- Persistência -->
                <section class="mb-5 animate-fadein animate-pending">
                    <h5 class="fw-semibold mb-1">📌 Persistência no Top 20</h5>
                    <p style="font-size:0.85rem;color:var(--text-muted)" class="mb-3">Ações que aparecem repetidamente nos rankings</p>
                    <div class="persistence-grid">
                        <?php foreach ($persistence as $p):
                            $total = (int)($p['total'] ?? 1);
                            $cnt   = (int)($p['cnt'] ?? 0);
                            $pct   = $total > 0 ? min(100, round($cnt / $total * 100)) : 0;
                            $names = htmlspecialchars($p['session_names'] ?? '', ENT_QUOTES, 'UTF-8');
                        ?>
                            <div class="persist-card" title="Apareceu em: <?php echo $names; ?>">
                                <div class="persist-count"><?php echo $cnt; ?></div>
                                <div class="mb-1">
                                    <span class="badge-ticker"><?php echo htmlspecialchars($p['ticker'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <div class="persist-empresa"><?php echo htmlspecialchars($p['empresa'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="persist-bar-wrap mt-2">
                                    <div class="persist-bar" style="width:<?php echo $pct; ?>%"></div>
                                </div>
                                <div class="persist-label"><?php echo $cnt; ?>/<?php echo $total; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

        <?php endif; // end !$session && !$dbError
        ?>
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
                As <em>20 melhores ações</em> da bolsa brasileira,<br>
                filtradas para você
            </h1>
            <p class="hero-subtitle">
                Nosso algoritmo analisa centenas de ações, aplica 7 filtros rigorosos de valor e qualidade,
                e entrega um ranking mensal das ações mais baratas e saudáveis do mercado.
            </p>
            <div class="hero-cta-wrap">
                <a class="btn btn-accent btn-lg hero-cta-primary" href="<?php echo $GLOBALS['register_url']; ?>">
                    Teste Grátis por 30 Dias
                </a>
                <a class="btn btn-outline-accent btn-lg" href="#preview">
                    Ver Prévia do Ranking ↓
                </a>
            </div>
            <div class="text-center mb-5">
                <a class="btn btn-ghost" href="<?php echo $GLOBALS['ranking_guide_url']; ?>"
                    style="font-size:1rem;">
                    <i class="bi bi-mortarboard me-1" aria-hidden="true"></i>Como Ler o Ranking?
                </a>
            </div>
            <div class="hero-indicators">
                <div class="hero-indicator">
                    <i class="bi bi-funnel" aria-hidden="true"></i>
                    <span>7 filtros aplicados</span>
                </div>
                <div class="hero-indicator">
                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                    <span>Atualização mensal</span>
                </div>
                <div class="hero-indicator">
                    <i class="bi bi-shield-check" aria-hidden="true"></i>
                    <span>Verificação de situação</span>
                </div>
            </div>
        </div>
    </section>

    <!-- PREVIEW RANKING -->
    <section class="py-5 animate-fadein animate-pending" id="preview">
        <div class="container" style="max-width:1100px">
            <h2 class="section-title text-center mb-2">Ranking Atual</h2>
            <p class="text-center mb-4" style="font-size:0.95rem;color:var(--text-muted)">
                Veja uma prévia das 5 primeiras ações selecionadas neste mês. Crie sua conta para ver o ranking completo.
            </p>

            <?php if ($dbError): ?>
                <div class="empty-state text-center py-4">
                    <div style="font-size:2rem">⚠️</div>
                    <p class="mt-2">Erro ao carregar dados. Tente mais tarde.</p>
                </div>
            <?php elseif (!$session || empty($results)): ?>
                <div class="empty-state text-center py-4">
                    <div style="font-size:2rem">📊</div>
                    <p class="mt-2">Nenhum ranking disponível no momento. Volte em breve.</p>
                </div>
            <?php else: ?>

                <div class="preview-overlay-wrap mb-4">
                    <div class="ranking-table-wrap ranking-table-preview">
                        <table class="ranking-table">
                            <thead>
                                <tr>
                                    <th class="rank-col">#</th>
                                    <th>Ação</th>
                                    <th>Empresa</th>
                                    <th class="text-end">EV/EBIT</th>
                                    <th class="text-end">Margem EBIT</th>
                                    <th class="text-end">Volume Diário</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $row):
                                    $pos      = (int)$row['rank_position'];
                                    $visible  = !empty($row['_visible']);
                                    $rowClass = _row_class($pos) . (!$visible ? ' row-blurred' : '');
                                ?>
                                    <tr class="<?php echo $rowClass; ?>">
                                        <td class="rank-col"><?php echo $pos; ?></td>
                                        <td><span class="badge-ticker"><?php echo htmlspecialchars($row['ticker'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td><?php echo htmlspecialchars($row['empresa'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-end"><?php echo isset($row['ev_ebit']) ? _fmt_decimal((float)$row['ev_ebit']) : '—'; ?></td>
                                        <td class="text-end"><?php echo isset($row['margem_ebit']) ? _fmt_decimal((float)$row['margem_ebit']) . '%' : '—'; ?></td>
                                        <td class="text-end"><?php echo isset($row['volume']) ? _fmt_volume((float)$row['volume']) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="preview-overlay-cta">
                        <i class="bi bi-lock-fill" style="font-size:1.4rem;color:var(--accent)" aria-hidden="true"></i>
                        <p class="fw-semibold mb-0" style="font-size:0.95rem;color:var(--text-heading)">
                            15 ações estão ocultas nesta prévia
                        </p>
                        <a class="btn btn-accent" href="<?php echo $GLOBALS['register_url']; ?>">
                            Criar Conta Grátis para Ver Tudo
                        </a>
                    </div>
                </div>

            <?php endif; ?>
        </div>
    </section>

    <?php if (defined('INVESTIDOR10_URL') && constant('INVESTIDOR10_URL') !== ''): ?>
        <!-- INVESTIDOR10 -->
        <section class="py-3 animate-fadein animate-pending" style="border-top:1px solid var(--border);border-bottom:1px solid var(--border)">
            <div class="container" style="max-width:1100px">
                <div class="d-flex flex-wrap align-items-center justify-content-center gap-3 py-2">
                    <div class="text-center">
                        <strong style="font-size:0.92rem">Acompanhe a carteira de forma independente</strong>
                        <p class="mb-0" style="font-size:0.82rem;color:var(--text-muted)">Resultados publicados no Investidor10 com atraso de 7 dias (D-7) para validação pública da metodologia.</p>
                    </div>
                    <a href="<?php echo htmlspecialchars(constant('INVESTIDOR10_URL'), ENT_QUOTES, 'UTF-8'); ?>"
                        target="_blank" rel="noopener"
                        class="btn btn-outline-accent btn-sm flex-shrink-0">
                        <i class="bi bi-graph-up-arrow me-1" aria-hidden="true"></i>Ver Carteira no Investidor10
                    </a>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- COMO FUNCIONA -->
    <section class="how-section py-5 animate-fadein animate-pending">
        <div class="container" style="max-width:1100px">
            <h2 class="section-title text-center mb-5">Como funciona</h2>
            <div class="row g-4">
                <?php
                $steps = [
                    ['📊', 'Upload da dados',         'Recebemos os dados de mercado atualizados com indicadores fundamentalistas de todas as ações da B3.'],
                    ['🔍', '7 filtros rigorosos',        'Liquidez mínima, rentabilidade, endividamento, retorno sobre capital, valuation, deduplicação e verificação de situação jurídica.'],
                    ['🌐', 'Validação em tempo real',  'Cada ação é verificada automaticamente para eliminar empresas em recuperação judicial, falência ou qualquer situação irregular.'],
                    ['🏆', 'Ranking Top 30',             'As 30 ações que sobrevivem a todos os filtros, ordenadas da mais barata para a mais cara pelo indicador EV/EBIT.'],
                ];
                foreach ($steps as [$icon, $title, $desc]):
                ?>
                    <div class="col-sm-6 col-lg-3">
                        <div class="how-card">
                            <div class="how-card-icon"><?php echo $icon; ?></div>
                            <h6 class="fw-semibold mb-2"><?php echo htmlspecialchars($title); ?></h6>
                            <p class="mb-0" style="font-size:0.88rem;color:var(--text-muted)"><?php echo htmlspecialchars($desc); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- METODOLOGIA -->
    <section class="py-5 animate-fadein animate-pending">
        <div class="container" style="max-width:860px">
            <h2 class="section-title text-center mb-2">Metodologia</h2>
            <p class="text-center mb-5" style="font-size:0.92rem;color:var(--text-muted)">
                A seleção combina qualidade operacional, solidez financeira, liquidez e avaliação relativa.
                Os filtros são aplicados em sequência — a ordem importa e é parte do diferencial proprietário.
            </p>
            <div class="row g-3">
                <?php
                $pillars = [
                    ['🏦', 'Qualidade operacional',  'Somente empresas com lucro operacional real e consistente. Margem operacional negativa é critério eliminatório.'],
                    ['📊', 'Solidez financeira',     'Estrutura de capital saudável. Alavancagem excessiva compromete a resiliência da empresa e é critério eliminatório.'],
                    ['💧', 'Liquidez de mercado',    'Volume mínimo de negociação que permite comprar e vender posições sem impacto relevante no preço.'],
                    ['⚖️', 'Avaliação relativa',      'Ordenação pelo preço em relação à geração de caixa operacional — as mais baratas ficam no topo do ranking.'],
                    ['🔁', 'Retorno sobre capital',  'Retorno mínimo exigido sobre o capital investido, separando empresas eficientes das ineficientes.'],
                    ['✅', 'Verificação regulatória', 'Situação jurídica e operacional de cada empresa verificada automaticamente antes do ranking final.'],
                ];
                foreach ($pillars as [$icon, $title, $desc]):
                ?>
                    <div class="col-sm-6">
                        <div class="d-flex gap-3 align-items-start p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:10px;height:100%">
                            <div style="font-size:1.4rem;flex-shrink:0;line-height:1.2"><?php echo $icon; ?></div>
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

    <!-- PLANOS -->
    <section class="py-5 animate-fadein animate-pending" id="planos" style="background:var(--surface-alt,var(--surface))">
        <div class="container" style="max-width:1100px">
            <h2 class="section-title text-center mb-2">Planos de Assinatura</h2>
            <p class="text-center mb-5" style="font-size:0.92rem;color:var(--text-muted)">
                Comece com 30 dias gratuitos. Pagamentos via Pix, sem recorrência automática.
            </p>
            <?php
            $prices = defined('SUBSCRIPTION_PRICES') ? constant('SUBSCRIPTION_PRICES') : [];
            $isAnnualLocked = (
                $isLoggedIn
                &&
                !$subscriptionExpired
                && !empty($subscriptionInfo)
                && ($subscriptionInfo['plan'] ?? '') === 'annual'
                && ($subscriptionInfo['status'] ?? '') === 'active'
                && !empty($subscriptionInfo['expires_at'])
                && strtotime($subscriptionInfo['expires_at']) > time()
            );
            $landingPlans = [
                [
                    'key'      => 'trial',
                    'name'     => 'Trial',
                    'price'    => 'Grátis',
                    'period'   => '30 dias',
                    'note'     => 'Sem cartão de crédito',
                    'features' => ['Acesso completo por 30 dias', 'Ranking Top-30', 'Painel de movimentação', 'Grid de persistência'],
                    'best'     => false,
                    'cta'      => $isLoggedIn ? 'Ver detalhes' : 'Começar Grátis',
                    'url'      => $isLoggedIn ? $GLOBALS['plans_url'] : $GLOBALS['register_url'],
                    'outline'  => true,
                    'disabled' => false,
                    'badge'    => null,
                ],
                [
                    'key'      => 'monthly',
                    'name'     => 'Mensal',
                    'price'    => 'R$ ' . number_format($prices['monthly'] ?? 9.90, 2, ',', '.'),
                    'period'   => '/mês',
                    'note'     => $isAnnualLocked ? 'Indisponível enquanto o Anual estiver vigente' : 'Renovação manual via Pix',
                    'features' => ['Tudo do Trial', 'Acesso mensal renovável', 'Suporte por email'],
                    'best'     => false,
                    'cta'      => $isAnnualLocked ? 'Indisponível' : 'Assinar Mensal',
                    'url'      => $isLoggedIn ? $GLOBALS['plans_url'] : ($GLOBALS['register_url'] . '?plano=monthly'),
                    'outline'  => false,
                    'disabled' => $isAnnualLocked,
                    'badge'    => $isAnnualLocked ? 'Bloqueado' : null,
                ],
                [
                    'key'      => 'annual',
                    'name'     => 'Anual',
                    'price'    => 'R$ ' . number_format($prices['annual'] ?? 97.00, 2, ',', '.'),
                    'period'   => '/ano',
                    'note'     => $isAnnualLocked ? 'Seu plano atual permanece ativo até o fim da vigência' : 'Equivale a R$&nbsp;8,08/mês',
                    'features' => ['Tudo do Mensal', '12 meses de acesso', 'Economia de 32%'],
                    'best'     => true,
                    'cta'      => $isAnnualLocked ? 'Plano atual' : 'Assinar Anual',
                    'url'      => $isLoggedIn ? $GLOBALS['plans_url'] : ($GLOBALS['register_url'] . '?plano=annual'),
                    'outline'  => false,
                    'disabled' => $isAnnualLocked,
                    'badge'    => $isAnnualLocked ? 'Plano atual' : 'Melhor custo-benefício',
                ],
                [
                    'key'      => 'lifetime',
                    'name'     => 'Vitalício',
                    'price'    => 'R$ ' . number_format($prices['lifetime'] ?? 197.00, 2, ',', '.'),
                    'period'   => 'único',
                    'note'     => $isAnnualLocked ? 'Único upgrade liberado durante a vigência do Anual' : 'Acesso permanente',
                    'features' => ['Tudo do Anual', 'Sem renovação', 'Acesso para sempre'],
                    'best'     => false,
                    'cta'      => $isAnnualLocked ? 'Fazer upgrade' : 'Acesso Vitalício',
                    'url'      => $isLoggedIn ? $GLOBALS['plans_url'] : ($GLOBALS['register_url'] . '?plano=lifetime'),
                    'outline'  => false,
                    'disabled' => false,
                    'badge'    => null,
                ],
            ];
            ?>
            <?php if ($isAnnualLocked): ?>
                <div class="card border-warning mb-4" style="background: rgba(255, 193, 7, 0.05);">
                    <div class="card-body p-3">
                        <h6 class="fw-semibold mb-2 text-warning">
                            <i class="bi bi-lock-fill me-2"></i>Bloqueio de Downgrade Ativo
                        </h6>
                        <p class="small mb-0" style="color: var(--text-muted);">
                            Seu plano <strong>Anual</strong> está ativo até <strong><?php echo date('d/m/Y', strtotime($subscriptionInfo['expires_at'])); ?></strong>.
                            Até o fim da vigência, os planos <strong>Mensal</strong> e <strong>Anual</strong> ficam travados nesta área. O único upgrade liberado é o <strong>Vitalício</strong>.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
            <div class="row g-4 justify-content-center">
                <?php foreach ($landingPlans as $lp): ?>
                    <div class="col-sm-6 col-lg-3 d-flex">
                        <div class="pricing-card <?php echo $lp['best'] ? 'pricing-card--recommended' : ''; ?> <?php echo !empty($lp['disabled']) ? 'pricing-card--disabled opacity-50 position-relative' : ''; ?>" style="width:100%">
                            <?php if (!empty($lp['badge'])): ?>
                                <span class="pricing-card__badge"><?php echo htmlspecialchars($lp['badge'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php elseif ($lp['best']): ?>
                                <span class="pricing-card__badge">Melhor custo-benefício</span>
                            <?php endif; ?>
                            <div class="pricing-card__name"><?php echo htmlspecialchars($lp['name']); ?></div>
                            <div class="pricing-card__price"><?php echo htmlspecialchars($lp['price']); ?></div>
                            <div class="pricing-card__period"><?php echo $lp['period']; ?></div>
                            <ul class="pricing-card__features mt-3 mb-3">
                                <?php foreach ($lp['features'] as $feat): ?>
                                    <li><i class="bi bi-check2" aria-hidden="true"></i><?php echo htmlspecialchars($feat); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <div style="margin-top:auto;width:100%">
                                <?php if (!empty($lp['disabled'])): ?>
                                    <span class="btn btn-secondary w-100 disabled" aria-disabled="true" style="pointer-events:none;min-height:44px;display:inline-flex;align-items:center;justify-content:center;">
                                        <?php echo htmlspecialchars($lp['cta'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php else: ?>
                                    <a href="<?php echo htmlspecialchars($lp['url'], ENT_QUOTES, 'UTF-8'); ?>"
                                        class="btn <?php echo $lp['outline'] ? 'btn-outline-accent' : 'btn-accent'; ?> w-100">
                                        <?php echo htmlspecialchars($lp['cta']); ?>
                                    </a>
                                <?php endif; ?>
                                <p class="mt-2 mb-0" style="font-size:0.75rem;color:var(--text-muted);text-align:center"><?php echo $lp['note']; ?></p>
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
                <h2 class="fw-bold mb-3" style="font-size:1.6rem"><?php echo $isAnnualLocked ? 'Upgrade liberado: Vitalício' : 'Acesse o ranking completo'; ?></h2>
                <p class="mb-4" style="color:var(--text-muted)">
                    <?php if ($isAnnualLocked): ?>
                        Seu plano Anual segue ativo, mas se quiser encerrar as renovações futuras, faça agora o upgrade para o plano Vitalício.
                    <?php elseif (!$isLoggedIn): ?>
                        Crie sua conta e veja as 20 melhores ações, comparativo entre meses, e quais ações são presença constante no ranking.
                    <?php else: ?>
                        Veja as 20 melhores ações, comparativo entre meses, e quais ações são presença constante no ranking.
                    <?php endif; ?>
                </p>
                <a class="btn btn-accent btn-lg hero-cta-primary mb-3" href="<?php echo htmlspecialchars($isLoggedIn ? $GLOBALS['plans_url'] : $GLOBALS['register_url'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo $isAnnualLocked ? 'Ver upgrade para Vitalício' : ($isLoggedIn ? 'Ver planos' : 'Criar Conta Grátis'); ?>
                </a>
                <p style="font-size:0.82rem;color:var(--text-muted)">
                    <?php echo $isAnnualLocked ? 'Mensal e Anual ficam bloqueados até o fim da vigência atual.' : (!$isLoggedIn ? 'Sem cartão de crédito. Sem spam.' : 'Pagamentos via Pix, sem recorrência automática.'); ?>
                </p>
            </div>
        </div>
    </section>

<?php endif; // isLoggedIn
?>

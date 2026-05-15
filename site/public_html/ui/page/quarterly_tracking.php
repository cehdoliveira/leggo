<?php
// Extrair contexto do período
$year            = $periodCtx['year'];
$quarter         = $periodCtx['quarter'];
$month           = $periodCtx['month'];
$monthInQuarter  = $periodCtx['month_in_quarter'];
$nextUpdateDate  = $periodCtx['next_update_date'];
$currentEntry    = $periodCtx['current_entry'];

$quarterLabels   = [1 => '1º', 2 => '2º', 3 => '3º', 4 => '4º'];
$monthNames      = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio',    6 => 'Junho',     7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
];

// Classificar resultados por faixa
$principal    = [];
$monitoramento = [];
$fora          = [];
if ($rankingData && !empty($rankingData['results'])) {
    foreach ($rankingData['results'] as $r) {
        $pos = (int)$r['rank_position'];
        if ($pos <= 20)      $principal[]     = $r;
        elseif ($pos <= 30)  $monitoramento[] = $r;
        else                 $fora[]          = $r;
    }
}

$hasRanking       = !empty($rankingData['session']);
$nextUpdateTs     = strtotime($nextUpdateDate);
$daysToUpdate     = (int)ceil(($nextUpdateTs - time()) / 86400);
$showCountdown    = $daysToUpdate <= 7 && $daysToUpdate >= 0;
?>

<div class="container py-4">

    <div class="mb-3">
        <a href="<?php echo htmlspecialchars($GLOBALS['home_url'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-ghost btn-sm">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Voltar
        </a>
    </div>

    <!-- Aviso institucional colapsável via localStorage -->
    <div x-data="{ expanded: !localStorage.getItem('qt_disclaimer_seen') }" class="mb-4">
        <template x-if="!expanded">
            <div class="small p-2 rounded d-flex align-items-center gap-2"
                 style="background:var(--surface-2);color:var(--text-muted);">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>Conteúdo informativo —
                    <button type="button" class="btn btn-link p-0 small" style="color:var(--text-muted);vertical-align:baseline;"
                            @click="expanded=true" aria-label="Ver aviso completo">ver aviso completo</button>
                </span>
            </div>
        </template>
        <template x-if="expanded">
            <div class="p-3 rounded d-flex align-items-start gap-2"
                 style="background:var(--surface-2);border-left:4px solid var(--accent-dim);" role="note">
                <div class="flex-grow-1">
                    <p class="small mb-0">
                        Conteúdo informativo e educacional. As classificações, faixas e simulações exibidas representam critérios objetivos do ranking e exemplos ilustrativos, sem caráter de recomendação, consultoria, análise personalizada, promessa de resultado ou ordem de investimento.
                    </p>
                </div>
                <button type="button" class="btn btn-link btn-sm p-0 flex-shrink-0"
                        style="color:var(--text-muted);"
                        @click="expanded=false; localStorage.setItem('qt_disclaimer_seen','1')"
                        aria-label="Fechar aviso">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>
        </template>
    </div>

    <!-- Cabeçalho -->
    <div class="mb-4">
        <h1 class="fw-bold mb-1" style="font-size:1.6rem;">Acompanhamento Trimestral</h1>
        <p style="color:var(--text-muted);">
            <?php echo $quarterLabels[$quarter]; ?> Trimestre de <?php echo $year; ?>
            &middot; Mês <?php echo $monthInQuarter; ?> de 3
            &middot; Atualização mensal
        </p>
    </div>

    <!-- 4 Cards de status -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card h-100 p-3 text-center" style="background:var(--surface-2);border:1px solid var(--border);">
                <p class="small mb-1" style="color:var(--text-muted);">Período atual</p>
                <p class="fw-bold mb-0"><?php echo $quarterLabels[$quarter]; ?>T <?php echo $year; ?> &middot; M<?php echo $monthInQuarter; ?></p>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 p-3 text-center" style="background:var(--surface-2);border:1px solid var(--border);">
                <p class="small mb-1" style="color:var(--text-muted);">Faixa Principal</p>
                <p class="fw-bold mb-0" style="color:var(--success);">
                    <?php echo count($principal); ?> ativos
                </p>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 p-3 text-center" style="background:var(--surface-2);border:1px solid var(--border);">
                <p class="small mb-1" style="color:var(--text-muted);">Aporte registrado</p>
                <?php if ($currentEntry && $currentEntry['contributed_amount'] !== null): ?>
                    <p class="fw-bold mb-0" style="color:var(--accent);">
                        R$ <?php echo number_format((float)$currentEntry['contributed_amount'], 2, ',', '.'); ?>
                    </p>
                <?php else: ?>
                    <p class="fw-bold mb-0 small" style="color:var(--text-muted);">
                        — <a href="#form-registro" style="color:var(--text-muted);font-size:0.75rem;">Registrar</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 p-3 text-center" style="background:var(--surface-2);border:1px solid var(--border);">
                <p class="small mb-1" style="color:var(--text-muted);">Próxima atualização</p>
                <p class="fw-bold mb-0" style="font-size:0.9rem;">
                    <?php echo date('d/m/Y', $nextUpdateTs); ?>
                    <?php if ($showCountdown): ?>
                        <span class="d-block small" style="color:var(--warning);"><?php echo $daysToUpdate; ?> dia(s)</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Painel principal: faixas do período -->
    <div class="mb-4">
        <h2 class="h6 fw-semibold mb-3">Leitura do ranking — <?php echo $monthNames[$month]; ?> de <?php echo $year; ?></h2>

        <?php if (!$hasRanking): ?>
            <div class="card p-4 text-center" style="background:var(--surface-2);border:1px solid var(--border);">
                <i class="bi bi-calendar-x" style="font-size:2rem;color:var(--text-muted);" aria-hidden="true"></i>
                <p class="mt-3 mb-0" style="color:var(--text-muted);">
                    Ainda não há dados de ranking para este período. A atualização ocorre mensalmente.
                </p>
            </div>
        <?php else: ?>
            <!-- Desktop: 3 colunas | Mobile: Alpine.js tabs -->
            <div class="d-none d-lg-block">
                <div class="row g-3">
                    <!-- Faixa Principal -->
                    <div class="col-lg-4">
                        <div class="card h-100" style="background:var(--surface-2);border:1px solid var(--border);border-top:3px solid var(--success);">
                            <div class="card-header py-2 px-3" style="background:transparent;border-bottom:1px solid var(--border);">
                                <span class="fw-semibold small" style="color:var(--success);">Faixa Principal</span>
                                <span class="float-end small" style="color:var(--text-muted);">pos. 1–20</span>
                            </div>
                            <div class="card-body p-0">
                                <?php foreach ($principal as $r): ?>
                                <div class="d-flex align-items-center justify-content-between px-3 py-2" style="border-bottom:1px solid var(--border);">
                                    <span class="small" style="color:var(--text-muted);width:28px;"><?php echo (int)$r['rank_position']; ?></span>
                                    <span class="small flex-grow-1 fw-medium"><?php echo htmlspecialchars($r['ticker'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="small" style="color:var(--text-muted);"><?php echo htmlspecialchars(mb_strimwidth($r['empresa'] ?? '', 0, 18, '…'), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <?php endforeach; ?>
                                <?php if (empty($principal)): ?>
                                <p class="text-center small p-3 mb-0" style="color:var(--text-muted);">Sem dados</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Faixa de Monitoramento -->
                    <div class="col-lg-4">
                        <div class="card h-100" style="background:var(--surface-2);border:1px solid var(--border);border-top:3px solid var(--warning);">
                            <div class="card-header py-2 px-3" style="background:transparent;border-bottom:1px solid var(--border);">
                                <span class="fw-semibold small" style="color:var(--warning);">Faixa de Monitoramento</span>
                                <span class="float-end small" style="color:var(--text-muted);">pos. 21–30</span>
                            </div>
                            <div class="card-body p-0">
                                <?php foreach ($monitoramento as $r): ?>
                                <div class="d-flex align-items-center justify-content-between px-3 py-2" style="border-bottom:1px solid var(--border);">
                                    <span class="small" style="color:var(--text-muted);width:28px;"><?php echo (int)$r['rank_position']; ?></span>
                                    <span class="small flex-grow-1 fw-medium"><?php echo htmlspecialchars($r['ticker'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="small" style="color:var(--text-muted);"><?php echo htmlspecialchars(mb_strimwidth($r['empresa'] ?? '', 0, 18, '…'), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <?php endforeach; ?>
                                <?php if (empty($monitoramento)): ?>
                                <p class="text-center small p-3 mb-0" style="color:var(--text-muted);">Sem dados</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Fora da Faixa -->
                    <div class="col-lg-4">
                        <div class="card h-100" style="background:var(--surface-2);border:1px solid var(--border);border-top:3px solid var(--text-muted);">
                            <div class="card-header py-2 px-3" style="background:transparent;border-bottom:1px solid var(--border);">
                                <span class="fw-semibold small" style="color:var(--text-muted);">Fora da Faixa Principal</span>
                                <span class="float-end small" style="color:var(--text-muted);">pos. 31+</span>
                            </div>
                            <div class="card-body p-0">
                                <?php foreach (array_slice($fora, 0, 15) as $r): ?>
                                <div class="d-flex align-items-center justify-content-between px-3 py-2" style="border-bottom:1px solid var(--border);">
                                    <span class="small" style="color:var(--text-muted);width:28px;"><?php echo (int)$r['rank_position']; ?></span>
                                    <span class="small flex-grow-1 fw-medium"><?php echo htmlspecialchars($r['ticker'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="small" style="color:var(--text-muted);"><?php echo htmlspecialchars(mb_strimwidth($r['empresa'] ?? '', 0, 18, '…'), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <?php endforeach; ?>
                                <?php if (empty($fora)): ?>
                                <p class="text-center small p-3 mb-0" style="color:var(--text-muted);">Sem dados</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mobile: tabs Alpine.js -->
            <div class="d-lg-none" x-data="{ activeTab: 'principal' }">
                <div class="d-flex gap-2 mb-3" role="tablist">
                    <button type="button" class="btn btn-sm" role="tab"
                            :class="activeTab === 'principal' ? 'btn-accent' : 'btn-outline-secondary'"
                            @click="activeTab = 'principal'"
                            :aria-selected="activeTab === 'principal'"
                            aria-controls="tab-principal">
                        Faixa Principal
                    </button>
                    <button type="button" class="btn btn-sm" role="tab"
                            :class="activeTab === 'monitoramento' ? 'btn-accent' : 'btn-outline-secondary'"
                            @click="activeTab = 'monitoramento'"
                            :aria-selected="activeTab === 'monitoramento'"
                            aria-controls="tab-monitoramento">
                        Monitoramento
                    </button>
                    <button type="button" class="btn btn-sm" role="tab"
                            :class="activeTab === 'fora' ? 'btn-accent' : 'btn-outline-secondary'"
                            @click="activeTab = 'fora'"
                            :aria-selected="activeTab === 'fora'"
                            aria-controls="tab-fora">
                        Fora
                    </button>
                </div>

                <div id="tab-principal" role="tabpanel" x-show="activeTab === 'principal'">
                    <div class="card" style="background:var(--surface-2);border:1px solid var(--border);border-top:3px solid var(--success);">
                        <?php foreach ($principal as $r): ?>
                        <div class="d-flex align-items-center justify-content-between px-3 py-2" style="border-bottom:1px solid var(--border);">
                            <span class="small" style="color:var(--text-muted);width:28px;"><?php echo (int)$r['rank_position']; ?></span>
                            <span class="small flex-grow-1 fw-medium"><?php echo htmlspecialchars($r['ticker'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($principal)): ?><p class="text-center small p-3 mb-0" style="color:var(--text-muted);">Sem dados</p><?php endif; ?>
                    </div>
                </div>

                <div id="tab-monitoramento" role="tabpanel" x-show="activeTab === 'monitoramento'">
                    <div class="card" style="background:var(--surface-2);border:1px solid var(--border);border-top:3px solid var(--warning);">
                        <?php foreach ($monitoramento as $r): ?>
                        <div class="d-flex align-items-center justify-content-between px-3 py-2" style="border-bottom:1px solid var(--border);">
                            <span class="small" style="color:var(--text-muted);width:28px;"><?php echo (int)$r['rank_position']; ?></span>
                            <span class="small flex-grow-1 fw-medium"><?php echo htmlspecialchars($r['ticker'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($monitoramento)): ?><p class="text-center small p-3 mb-0" style="color:var(--text-muted);">Sem dados</p><?php endif; ?>
                    </div>
                </div>

                <div id="tab-fora" role="tabpanel" x-show="activeTab === 'fora'">
                    <div class="card" style="background:var(--surface-2);border:1px solid var(--border);border-top:3px solid var(--text-muted);">
                        <?php foreach (array_slice($fora, 0, 15) as $r): ?>
                        <div class="d-flex align-items-center justify-content-between px-3 py-2" style="border-bottom:1px solid var(--border);">
                            <span class="small" style="color:var(--text-muted);width:28px;"><?php echo (int)$r['rank_position']; ?></span>
                            <span class="small flex-grow-1 fw-medium"><?php echo htmlspecialchars($r['ticker'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($fora)): ?><p class="text-center small p-3 mb-0" style="color:var(--text-muted);">Sem dados</p><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Simulação ilustrativa (premium — exibe se aporte registrado + ranking disponível) -->
    <?php if ($currentEntry && $currentEntry['contributed_amount'] !== null && (float)$currentEntry['contributed_amount'] > 0 && !empty($principal)): ?>
    <div class="mb-4">
        <h2 class="h6 fw-semibold mb-2">Simulação ilustrativa</h2>
        <p class="small mb-3" style="color:var(--text-muted);">
            Distribuição matemática igualitária do aporte informado de
            R$ <?php echo number_format((float)$currentEntry['contributed_amount'], 2, ',', '.'); ?> entre 20 posições.
        </p>
        <div class="table-responsive">
            <table class="table table-sm table-hover" aria-label="Simulação de distribuição do aporte">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Ativo</th>
                        <th scope="col">Valor por posição</th>
                        <th scope="col" class="d-none d-sm-table-cell">% do total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $aporte = (float)$currentEntry['contributed_amount'];
                    $perPos = $aporte / 20;
                    foreach (array_slice($principal, 0, 20) as $i => $r):
                    ?>
                    <tr>
                        <td><?php echo (int)$r['rank_position']; ?></td>
                        <td><?php echo htmlspecialchars($r['ticker'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>R$ <?php echo number_format($perPos, 2, ',', '.'); ?></td>
                        <td class="d-none d-sm-table-cell">5,00%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small" style="color:var(--text-muted);">Simulação ilustrativa — distribuição matemática igualitária do aporte informado.</p>
    </div>
    <?php endif; ?>

    <!-- Registro manual do período -->
    <div id="form-registro" class="mb-4"
         x-data="{ saved: <?php echo $currentEntry ? 'true' : 'false'; ?>, editing: false }">
        <h2 class="h6 fw-semibold mb-3">
            Registro do período — <?php echo $monthNames[$month]; ?> de <?php echo $year; ?>
        </h2>

        <?php html_notification_print(); ?>

        <template x-if="saved && !editing">
            <div class="card p-3" style="background:var(--surface-2);border:1px solid var(--border);">
                <div class="d-flex align-items-center justify-content-between">
                    <span>
                        <i class="bi bi-check-circle me-2" style="color:var(--success);" aria-hidden="true"></i>
                        <span class="small fw-medium">Registro salvo</span>
                    </span>
                    <button type="button" class="btn btn-link btn-sm p-0 small" @click="editing = true">
                        Editar
                    </button>
                </div>
                <?php if ($currentEntry): ?>
                <div class="mt-2 small" style="color:var(--text-muted);">
                    <?php if ($currentEntry['contributed_amount'] !== null): ?>
                    Aporte: R$ <?php echo number_format((float)$currentEntry['contributed_amount'], 2, ',', '.'); ?>
                    <?php endif; ?>
                    <?php if ($currentEntry['dividends_amount'] !== null): ?>
                    &middot; Dividendos: R$ <?php echo number_format((float)$currentEntry['dividends_amount'], 2, ',', '.'); ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </template>

        <template x-if="!saved || editing">
            <form method="POST" action="<?php echo $GLOBALS['quarterly_tracking_url']; ?>/registro">
                <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="contributed_amount" class="form-label small fw-medium">
                            Aporte informado no período (R$)
                            <span class="fw-normal" style="color:var(--text-muted);">— opcional</span>
                        </label>
                        <input type="text"
                               id="contributed_amount"
                               name="contributed_amount"
                               class="form-control"
                               inputmode="decimal"
                               placeholder="0,00"
                               value="<?php echo $currentEntry ? htmlspecialchars(number_format((float)($currentEntry['contributed_amount'] ?? 0), 2, ',', '.'), ENT_QUOTES, 'UTF-8') : ''; ?>"
                               autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label for="dividends_amount" class="form-label small fw-medium">
                            Dividendos / proventos informados (R$)
                            <span class="fw-normal" style="color:var(--text-muted);">— opcional</span>
                        </label>
                        <input type="text"
                               id="dividends_amount"
                               name="dividends_amount"
                               class="form-control"
                               inputmode="decimal"
                               placeholder="0,00"
                               value="<?php echo $currentEntry ? htmlspecialchars(number_format((float)($currentEntry['dividends_amount'] ?? 0), 2, ',', '.'), ENT_QUOTES, 'UTF-8') : ''; ?>"
                               autocomplete="off">
                    </div>
                    <div class="col-12" x-data="{ notes: <?php echo htmlspecialchars(json_encode($currentEntry['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> }">
                        <label for="notes" class="form-label small fw-medium">
                            Observações
                            <span class="fw-normal" style="color:var(--text-muted);">— opcional, até 500 caracteres</span>
                        </label>
                        <textarea id="notes"
                                  name="notes"
                                  class="form-control"
                                  rows="3"
                                  maxlength="500"
                                  x-model="notes"
                                  placeholder="Observações sobre o período..."><?php echo htmlspecialchars($currentEntry['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <div class="text-end small mt-1" style="color:var(--text-muted);">
                            <span x-text="notes.length"></span>/500
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <?php if ($currentEntry): ?>
                    <button type="button" class="btn btn-ghost btn-sm" @click="editing = false">
                        Cancelar
                    </button>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-accent" aria-label="Salvar registro do período">
                        <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                        Salvar registro
                    </button>
                </div>
            </form>
        </template>
    </div>

    <!-- Histórico dos últimos 12 meses -->
    <?php if (!empty($history)): ?>
    <div class="mb-4">
        <h2 class="h6 fw-semibold mb-3">Histórico</h2>

        <!-- Desktop -->
        <div class="d-none d-md-block">
            <div class="table-responsive">
                <table class="table table-sm table-hover" aria-label="Histórico de registros trimestrais">
                    <thead>
                        <tr>
                            <th scope="col">Período</th>
                            <th scope="col">Trimestre</th>
                            <th scope="col">Aporte</th>
                            <th scope="col">Dividendos</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody x-data="{ open: null }">
                        <?php foreach ($history as $hIdx => $h):
                            $hMonth   = (int)$h['period_month'];
                            $hYear    = (int)$h['period_year'];
                            $hQuarter = (int)$h['period_quarter'];
                            $hasNotes = !empty($h['notes']);
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars(($monthNames[$hMonth] ?? '') . ' ' . $hYear, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $quarterLabels[$hQuarter] ?? '?'; ?>T <?php echo $hYear; ?></td>
                            <td>
                                <?php echo $h['contributed_amount'] !== null
                                    ? 'R$ ' . number_format((float)$h['contributed_amount'], 2, ',', '.')
                                    : '<span style="color:var(--text-muted);">—</span>'; ?>
                            </td>
                            <td>
                                <?php echo $h['dividends_amount'] !== null
                                    ? 'R$ ' . number_format((float)$h['dividends_amount'], 2, ',', '.')
                                    : '<span style="color:var(--text-muted);">—</span>'; ?>
                            </td>
                            <td>
                                <?php if ($hasNotes): ?>
                                <button type="button" class="btn btn-link btn-sm p-0 small"
                                        @click="open = (open === <?php echo $hIdx; ?>) ? null : <?php echo $hIdx; ?>"
                                        :aria-expanded="open === <?php echo $hIdx; ?>">
                                    <span x-text="open === <?php echo $hIdx; ?> ? 'Fechar' : 'Ver obs.'"></span>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($hasNotes): ?>
                        <tr x-show="open === <?php echo $hIdx; ?>" style="display:none;">
                            <td colspan="5" class="small" style="color:var(--text-muted);background:var(--surface-2);">
                                <?php echo htmlspecialchars($h['notes'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Mobile: cards -->
        <div class="d-md-none d-flex flex-column gap-2">
            <?php foreach ($history as $h):
                $hMonth   = (int)$h['period_month'];
                $hYear    = (int)$h['period_year'];
                $hQuarter = (int)$h['period_quarter'];
            ?>
            <div class="card p-3" style="background:var(--surface-2);border:1px solid var(--border);">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="fw-semibold small mb-1">
                            <?php echo htmlspecialchars(($monthNames[$hMonth] ?? '') . ' ' . $hYear, ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <p class="small mb-0" style="color:var(--text-muted);">
                            <?php echo $quarterLabels[$hQuarter] ?? '?'; ?>T <?php echo $hYear; ?>
                        </p>
                    </div>
                    <div class="text-end">
                        <?php if ($h['contributed_amount'] !== null): ?>
                        <p class="small mb-0">Aporte: R$ <?php echo number_format((float)$h['contributed_amount'], 2, ',', '.'); ?></p>
                        <?php endif; ?>
                        <?php if ($h['dividends_amount'] !== null): ?>
                        <p class="small mb-0" style="color:var(--text-muted);">Divid.: R$ <?php echo number_format((float)$h['dividends_amount'], 2, ',', '.'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($h['notes'])): ?>
                <div class="mt-2 small" style="color:var(--text-muted);">
                    <?php echo htmlspecialchars($h['notes'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

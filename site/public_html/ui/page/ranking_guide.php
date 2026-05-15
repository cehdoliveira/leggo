<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">

            <?php html_notification_print(); ?>

            <!-- Seção 1: Abertura didática -->
            <div class="mb-4">
                <h1 class="fw-bold mb-2" style="font-size:2rem;">Como ler o Ranking do GarimpAções</h1>
                <p class="lead" style="color:var(--text-muted);">
                    Entenda o que as posições, faixas e mudanças do ranking significam — sem linguagem de investimento.
                </p>

                <div class="mt-3 p-3 rounded" style="background:var(--surface-2);border-left:4px solid var(--accent-dim);" role="note">
                    <p class="mb-0 small">
                        Conteúdo informativo e educacional. As classificações, faixas e simulações exibidas representam critérios objetivos do ranking e exemplos ilustrativos, sem caráter de recomendação, consultoria, análise personalizada, promessa de resultado ou ordem de investimento.
                    </p>
                </div>
            </div>

            <hr style="border-color:var(--border);" class="my-4">

            <!-- Seção 2: Simulação ilustrativa -->
            <div class="mb-5" x-data="rankingGuideSimulator()">
                <h2 class="h5 fw-semibold mb-1">Simulação ilustrativa de distribuição</h2>
                <p class="small mb-3" style="color:var(--text-muted);">
                    Digite um valor para visualizar como ele seria dividido matematicamente entre 20 posições. Este é apenas um exemplo de divisão igualitária.
                </p>

                <label for="simInput" class="form-label small fw-medium">Valor total (R$)</label>
                <input
                    type="text"
                    id="simInput"
                    class="form-control mb-2"
                    inputmode="decimal"
                    placeholder="Ex: 5.000,00"
                    x-model="rawInput"
                    @input.debounce.300ms="calculate()"
                    autocomplete="off"
                    aria-describedby="simHint"
                    style="max-width:260px;">

                <div id="simHint" class="mb-3">
                    <template x-if="showPartialNote">
                        <p class="small" style="color:var(--warning);">
                            Para valores menores que R$ 2.000, a composição pode ocorrer de forma parcial. Este é apenas um exemplo de divisão matemática.
                        </p>
                    </template>
                </div>

                <template x-if="showTable">
                    <div>
                        <p class="small fw-medium mb-2">Simulação ilustrativa — exemplo de divisão matemática igualitária</p>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover" aria-label="Simulação de distribuição entre 20 posições">
                                <thead>
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col" class="d-none d-sm-table-cell">Ativo</th>
                                        <th scope="col">Valor por posição</th>
                                        <th scope="col" class="d-none d-sm-table-cell">% do total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(row, i) in tableRows" :key="i">
                                        <tr>
                                            <td x-text="i + 1"></td>
                                            <td class="d-none d-sm-table-cell" x-text="'Ativo ' + (i + 1)"></td>
                                            <td x-text="formatBRL(row.value)"></td>
                                            <td class="d-none d-sm-table-cell" x-text="row.pct + '%'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>
            </div>

            <hr style="border-color:var(--border);" class="my-4">

            <!-- Seção 3: Passo a passo visual -->
            <div class="mb-5">
                <h2 class="h5 fw-semibold mb-3">Como usar o ranking — passo a passo</h2>
                <div class="d-flex flex-column gap-3">
                    <?php
                    $steps = [
                        ['icon' => 'bi-search',           'text' => 'Acesse o ranking no GarimpAções'],
                        ['icon' => 'bi-list-ol',          'text' => 'Observe as posições e os ativos listados'],
                        ['icon' => 'bi-bar-chart-steps',  'text' => 'Entenda as faixas de classificação'],
                        ['icon' => 'bi-calendar-check',   'text' => 'Acompanhe as mudanças mês a mês'],
                        ['icon' => 'bi-arrow-left-right', 'text' => 'Compare a composição entre períodos diferentes'],
                    ];
                    foreach ($steps as $i => $step):
                    ?>
                        <div class="d-flex align-items-center gap-3 p-3 rounded" style="background:var(--surface-2);">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                style="width:40px;height:40px;background:var(--accent-dim);color:var(--accent);">
                                <i class="bi <?php echo $step['icon']; ?>" aria-hidden="true"></i>
                            </div>
                            <div>
                                <span class="fw-medium" style="color:var(--text-muted);font-size:0.75rem;">Passo <?php echo $i + 1; ?></span>
                                <p class="mb-0 fw-semibold"><?php echo htmlspecialchars($step['text'], ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <hr style="border-color:var(--border);" class="my-4">

            <!-- Seção 4: Explicação das faixas -->
            <div class="mb-5">
                <h2 class="h5 fw-semibold mb-3">As faixas de classificação</h2>
                <div class="d-flex flex-column gap-3">
                    <div class="p-3 rounded" style="background:var(--surface-2);border-left:4px solid var(--success);">
                        <span class="fw-semibold" style="color:var(--success);">Faixa Principal</span>
                        <span class="small ms-2" style="color:var(--text-muted);">(posições 1–20)</span>
                        <p class="mb-0 mt-1 small">Ativos nas primeiras 20 posições do ranking neste período.</p>
                    </div>
                    <div class="p-3 rounded" style="background:var(--surface-2);border-left:4px solid var(--warning);">
                        <span class="fw-semibold" style="color:var(--warning);">Faixa de Monitoramento</span>
                        <span class="small ms-2" style="color:var(--text-muted);">(posições 21–30)</span>
                        <p class="mb-0 mt-1 small">Ativos entre as posições 21 e 30.</p>
                    </div>
                    <div class="p-3 rounded" style="background:var(--surface-2);border-left:4px solid var(--text-muted);">
                        <span class="fw-semibold" style="color:var(--text-muted);">Fora da Faixa Principal</span>
                        <span class="small ms-2" style="color:var(--text-muted);">(acima de 30)</span>
                        <p class="mb-0 mt-1 small">Ativos além da posição 30.</p>
                    </div>
                </div>
            </div>

            <hr style="border-color:var(--border);" class="my-4">

            <!-- Seção 5: CTA condicional -->
            <div class="text-center py-4">
                <?php if (!$isLoggedIn): ?>
                    <a href="<?php echo $GLOBALS['login_url']; ?>?next=/" class="btn btn-accent btn-lg" aria-label="Acessar o Ranking">
                        <i class="bi bi-arrow-right-circle me-2" aria-hidden="true"></i>
                        Acessar o Ranking
                    </a>
                <?php elseif (!$hasSubscription): ?>
                    <a href="<?php echo $GLOBALS['plans_url']; ?>" class="btn btn-accent btn-lg" aria-label="Ver o Ranking Completo">
                        <i class="bi bi-unlock me-2" aria-hidden="true"></i>
                        Ver o Ranking Completo
                    </a>
                <?php else: ?>
                    <a href="<?php echo $GLOBALS['home_url']; ?>" class="btn btn-accent btn-lg" aria-label="Ver o Ranking Atual">
                        <i class="bi bi-bar-chart me-2" aria-hidden="true"></i>
                        Ver o Ranking Atual
                    </a>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<script>
    function rankingGuideSimulator() {
        return {
            rawInput: '',
            showTable: false,
            showPartialNote: false,
            tableRows: [],

            calculate() {
                const raw = this.rawInput.trim();
                if (!raw) {
                    this.showTable = false;
                    this.showPartialNote = false;
                    return;
                }
                // Parse BR decimal: 1.234,56 → 1234.56
                const normalized = raw.replace(/\./g, '').replace(',', '.');
                const value = parseFloat(normalized);

                if (isNaN(value) || value <= 0) {
                    this.showTable = false;
                    this.showPartialNote = false;
                    return;
                }

                const perPosition = value / 20;
                const pct = '5,00';
                this.tableRows = Array.from({
                    length: 20
                }, () => ({
                    value: perPosition,
                    pct
                }));
                this.showTable = true;
                this.showPartialNote = value < 2000;
            },

            formatBRL(value) {
                return 'R$ ' + value.toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }
        };
    }
</script>

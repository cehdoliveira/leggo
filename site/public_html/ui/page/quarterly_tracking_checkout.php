<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">

            <?php html_notification_print(); ?>

            <?php if ($pending): ?>
                <!-- QR Code pendente -->
                <div class="text-center mb-4">
                    <h1 class="h4 fw-bold mb-1">Pagamento via Pix</h1>
                    <p class="small" style="color:var(--text-muted);">
                        Acompanhamento Trimestral — R$ 49,90
                    </p>
                </div>

                <div class="card shadow-sm mx-auto" style="max-width:420px;"
                     x-data="quarterlyCheckoutController()" x-init="startPolling()">
                    <div class="card-body p-4 text-center">
                        <?php if (!empty($pending['qr_image_url'])): ?>
                            <div class="mb-3">
                                <img src="<?php echo htmlspecialchars($pending['qr_image_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                     alt="QR Code Pix"
                                     style="max-width:260px;width:100%;"
                                     class="mx-auto d-block">
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($pending['qr_code'])): ?>
                            <div class="mb-3">
                                <label class="form-label small" style="color:var(--text-muted);">Código Pix (Copia e Cola)</label>
                                <div class="input-group">
                                    <input type="text"
                                           class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($pending['qr_code'], ENT_QUOTES, 'UTF-8'); ?>"
                                           readonly
                                           id="qtPixCode"
                                           aria-label="Código Pix copia e cola">
                                    <button class="btn btn-accent btn-sm"
                                            type="button"
                                            @click="copyPixCode()"
                                            :title="copied ? 'Copiado!' : 'Copiar código'"
                                            :aria-label="copied ? 'Código copiado' : 'Copiar código Pix'">
                                        <i class="bi" :class="copied ? 'bi-check-lg' : 'bi-clipboard'" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3" aria-live="polite">
                            <template x-if="paymentStatus === 'pending'">
                                <div>
                                    <span class="pulsing-dot"></span>
                                    <span class="small" style="color:var(--text-muted);">Aguardando pagamento...</span>
                                    <div class="small mt-2" style="color:var(--text-muted);">
                                        Tempo restante: <span x-text="timeRemaining"></span>
                                    </div>
                                </div>
                            </template>
                            <template x-if="paymentStatus === 'confirmed'">
                                <div class="text-center">
                                    <i class="bi bi-check-circle-fill" style="color:var(--success);font-size:2rem;" aria-hidden="true"></i>
                                    <p class="mt-2 fw-semibold">Pagamento confirmado!</p>
                                    <p class="small" style="color:var(--text-muted);">Redirecionando...</p>
                                </div>
                            </template>
                            <template x-if="paymentStatus === 'expired'">
                                <div class="text-center">
                                    <i class="bi bi-clock-history" style="color:var(--warning);font-size:2rem;" aria-hidden="true"></i>
                                    <p class="mt-2 fw-semibold">QR Code expirado</p>
                                    <a href="<?php echo $GLOBALS['quarterly_checkout_url']; ?>" class="btn btn-accent btn-sm mt-2">
                                        Gerar novo Pix
                                    </a>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Página de checkout -->
                <div class="text-center mb-4">
                    <div class="mb-3">
                        <span class="d-inline-flex align-items-center justify-content-center rounded-circle"
                              style="width:64px;height:64px;background:var(--accent-dim);">
                            <i class="bi bi-calendar3" style="font-size:1.75rem;color:var(--accent);" aria-hidden="true"></i>
                        </span>
                    </div>
                    <h1 class="h4 fw-bold mb-1">Acompanhamento Trimestral</h1>
                    <p style="color:var(--text-muted);">Acesso permanente por pagamento único</p>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h2 class="h6 fw-semibold mb-3">O que você acessa:</h2>
                        <ul class="list-unstyled mb-0">
                            <?php
                            $features = [
                                ['icon' => 'bi-bar-chart-steps',  'text' => 'Histórico de classificação por faixas (trimestral)'],
                                ['icon' => 'bi-calendar-check',   'text' => 'Registro mensal de aportes e dividendos informados'],
                                ['icon' => 'bi-arrow-left-right', 'text' => 'Comparativo de mudanças entre períodos'],
                                ['icon' => 'bi-list-ul',          'text' => 'Nomes reais dos ativos na simulação ilustrativa'],
                                ['icon' => 'bi-infinity',         'text' => 'Acesso permanente — sem renovação'],
                            ];
                            foreach ($features as $f):
                            ?>
                            <li class="d-flex align-items-start gap-2 mb-2">
                                <i class="bi <?php echo $f['icon']; ?> mt-1 flex-shrink-0" style="color:var(--accent);" aria-hidden="true"></i>
                                <span class="small"><?php echo htmlspecialchars($f['text'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <div class="card shadow-sm mb-4" style="border:1px solid var(--accent-dim);">
                    <div class="card-body p-4 text-center">
                        <p class="small mb-1" style="color:var(--text-muted);">Pagamento único</p>
                        <p class="display-6 fw-bold mb-1" style="color:var(--accent);">R$ 49,90</p>
                        <p class="small" style="color:var(--text-muted);">via Pix — acesso liberado em instantes</p>
                    </div>
                </div>

                <div class="mb-4 p-3 rounded" style="background:var(--surface-2);border-left:4px solid var(--accent-dim);" role="note">
                    <p class="small mb-0">
                        Conteúdo informativo e educacional. As classificações, faixas e simulações exibidas representam critérios objetivos do ranking e exemplos ilustrativos, sem caráter de recomendação, consultoria, análise personalizada, promessa de resultado ou ordem de investimento.
                    </p>
                </div>

                <form method="POST" action="<?php echo $GLOBALS['quarterly_checkout_url']; ?>">
                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="btn btn-accent btn-lg w-100" aria-label="Gerar QR Code Pix para Acompanhamento Trimestral">
                        <i class="bi bi-qr-code me-2" aria-hidden="true"></i>
                        Gerar QR Code Pix
                    </button>
                </form>

                <p class="text-center mt-3 small" style="color:var(--text-muted);">
                    <a href="<?php echo $GLOBALS['ranking_guide_url']; ?>" style="color:var(--text-muted);">
                        Como funciona o ranking?
                    </a>
                </p>

            <?php endif; ?>

        </div>
    </div>
</div>

<script>
function quarterlyCheckoutController() {
    return {
        paymentStatus: 'pending',
        timeRemaining: '20:00',
        copied: false,
        pollInterval: null,
        startTs: Date.now(),
        totalMs: 20 * 60 * 1000,

        startPolling() {
            this.tick();
            this.pollInterval = setInterval(() => this.tick(), 5000);
        },

        async tick() {
            const elapsed = Date.now() - this.startTs;
            if (elapsed >= this.totalMs) {
                this.paymentStatus = 'expired';
                clearInterval(this.pollInterval);
                return;
            }

            const remaining = this.totalMs - elapsed;
            const mins = String(Math.floor(remaining / 60000)).padStart(2, '0');
            const secs = String(Math.floor((remaining % 60000) / 1000)).padStart(2, '0');
            this.timeRemaining = `${mins}:${secs}`;

            try {
                const res = await fetch('<?php echo $GLOBALS['quarterly_status_url']; ?>');
                const data = await res.json();
                if (data.status === 'confirmed') {
                    this.paymentStatus = 'confirmed';
                    clearInterval(this.pollInterval);
                    setTimeout(() => { window.location.href = data.redirect; }, 1500);
                } else if (data.status === 'expired') {
                    this.paymentStatus = 'expired';
                    clearInterval(this.pollInterval);
                }
            } catch (e) { /* network error — keep polling */ }
        },

        copyPixCode() {
            const el = document.getElementById('qtPixCode');
            if (!el) return;
            navigator.clipboard.writeText(el.value).then(() => {
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2000);
            }).catch(() => {
                el.select();
                document.execCommand('copy');
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2000);
            });
        }
    };
}
</script>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">

            <?php html_notification_print(); ?>

            <?php
            $pending = $_SESSION['pending_payment'] ?? null;
            $hasTrial = ($subscriptionInfo && $subscriptionInfo['plan'] === 'trial' && $subscriptionInfo['status'] === 'trial');
            $isExpired = ($subscriptionInfo && $subscriptionInfo['status'] === 'expired');
            $isAnnualLocked = (
                $subscriptionInfo
                && ($subscriptionInfo['plan'] ?? '') === 'annual'
                && ($subscriptionInfo['status'] ?? '') === 'active'
                && !empty($subscriptionInfo['expires_at'])
                && strtotime($subscriptionInfo['expires_at']) > time()
            );
            ?>

            <?php if ($pending): ?>
                <!-- ===== QR CODE PIX ===== -->
                <div class="text-center mb-4">
                    <h4 class="fw-bold mb-1">Pagamento via Pix</h4>
                    <p class="small" style="color: var(--text-muted);">
                        Plano <?php echo htmlspecialchars($pending['plan_label'], ENT_QUOTES, 'UTF-8'); ?>
                        — R$ <?php echo number_format($pending['amount'], 2, ',', '.'); ?>
                    </p>
                    <?php if ($pending['original_price'] < $pending['amount']): ?>
                        <p class="small" style="color: var(--text-muted);">
                            R$ <?php echo number_format($pending['original_price'], 2, ',', '.'); ?>
                            + R$ <?php echo number_format($pending['amount'] - $pending['original_price'], 2, ',', '.'); ?> taxa de processamento
                        </p>
                    <?php endif; ?>
                </div>

                <div class="card shadow-sm mx-auto" style="max-width: 400px;">
                    <div class="card-body p-4 text-center" x-data="plansController()" x-init="startPolling()">
                        <?php if (!empty($pending['qr_image_url'])): ?>
                            <div class="qr-container mb-3">
                                <img src="<?php echo htmlspecialchars($pending['qr_image_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                    alt="QR Code Pix"
                                    style="max-width: 280px; width: 100%;"
                                    class="mx-auto d-block">
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($pending['qr_code'])): ?>
                            <div class="mb-3">
                                <label class="form-label small" style="color: var(--text-muted);">Código Pix (Copia e Cola)</label>
                                <div class="input-group">
                                    <input type="text"
                                        class="form-control form-control-sm"
                                        value="<?php echo htmlspecialchars($pending['qr_code'], ENT_QUOTES, 'UTF-8'); ?>"
                                        readonly
                                        id="pixCode">
                                    <button class="btn btn-accent btn-sm"
                                        type="button"
                                        @click="copyPixCode()"
                                        :title="copied ? 'Copiado!' : 'Copiar código'">
                                        <i class="bi" :class="copied ? 'bi-check-lg' : 'bi-clipboard'" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3" aria-live="polite">
                            <template x-if="paymentStatus === 'pending'">
                                <div>
                                    <span class="pulsing-dot"></span>
                                    <span class="small" style="color: var(--text-muted);">Aguardando pagamento...</span>
                                    <div class="small mt-2" style="color: var(--text-muted);">
                                        Tempo restante: <span x-text="timeRemaining"></span>
                                    </div>
                                </div>
                            </template>
                            <template x-if="paymentStatus === 'confirmed'">
                                <div class="text-center">
                                    <i class="bi bi-check-circle-fill" style="color: var(--success); font-size: 2rem;"></i>
                                    <p class="mt-2 fw-semibold">Pagamento confirmado!</p>
                                    <p class="small" style="color: var(--text-muted);">Redirecionando...</p>
                                </div>
                            </template>
                            <template x-if="paymentStatus === 'expired'">
                                <div class="text-center">
                                    <i class="bi bi-clock-history" style="color: var(--warning); font-size: 2rem;"></i>
                                    <p class="mt-2">Tempo expirado. Gere um novo QR code.</p>
                                    <a href="<?php echo htmlspecialchars($GLOBALS['plans_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                        class="btn btn-accent btn-sm mt-2"
                                        @click="clearPending()">
                                        Tentar novamente
                                    </a>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- ===== SELEÇÃO DE PLANOS ===== -->
                <div class="text-center mb-4">
                    <h4 class="fw-bold mb-1">Planos de Assinatura</h4>
                    <p class="small" style="color: var(--text-muted);">
                        <?php if ($isExpired): ?>
                            Seu período de acesso expirou. Escolha um plano para continuar.
                        <?php elseif ($hasTrial): ?>
                            Você está no período de teste. Assine para garantir acesso contínuo.
                        <?php else: ?>
                            Escolha o plano que melhor se adapta a você.
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Como funciona -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-3">
                        <h6 class="fw-semibold mb-3"><i class="bi bi-info-circle me-1" style="color: var(--accent);"></i> Como funciona o pagamento via Pix</h6>
                        <div class="row g-3 text-center">
                            <div class="col-md-4">
                                <div class="mb-1"><i class="bi bi-1-circle" style="font-size: 1.5rem; color: var(--accent);"></i></div>
                                <p class="small mb-0">Escolha seu plano e clique em "Assinar"</p>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-1"><i class="bi bi-2-circle" style="font-size: 1.5rem; color: var(--accent);"></i></div>
                                <p class="small mb-0">Escaneie o QR code ou copie o código Pix</p>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-1"><i class="bi bi-3-circle" style="font-size: 1.5rem; color: var(--accent);"></i></div>
                                <p class="small mb-0">Pagamento confirmado em segundos!</p>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($isDowngradeBlocked): ?>
                    <div class="card border-warning mb-4" style="background: rgba(255, 193, 7, 0.05);">
                        <div class="card-body p-3">
                            <h6 class="fw-semibold mb-2 text-warning">
                                <i class="bi bi-lock-fill me-2"></i>Bloqueio de Downgrade Ativo
                            </h6>
                            <p class="small mb-0" style="color: var(--text-muted);">
                                Seu plano <strong>Anual</strong> está ativo até <strong><?php echo date('d/m/Y', strtotime($subscriptionInfo['expires_at'])); ?></strong>.
                                Você pode fazer <strong>upgrade</strong> para Vitalício agora, ou fazer downgrade quando seu plano expirar.
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['plans_url'], ENT_QUOTES, 'UTF-8'); ?>"
                    x-data="{ selectedPlan: '<?php echo $isAnnualLocked ? 'lifetime' : 'annual'; ?>' }">
                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="plan" :value="selectedPlan">

                    <div class="row g-3 justify-content-center mb-4" role="radiogroup" aria-label="Planos de assinatura">

                        <!-- Trial (info only) -->
                        <?php if ($hasTrial): ?>
                            <div class="col-sm-6 col-lg-3">
                                <div class="pricing-card pricing-card--current" aria-label="Plano atual: Trial">
                                    <div class="pricing-card__badge pricing-card__badge--current">Atual</div>
                                    <h5 class="pricing-card__name">Trial</h5>
                                    <div class="pricing-card__price">Grátis</div>
                                    <div class="pricing-card__period">30 dias</div>
                                    <ul class="pricing-card__features">
                                        <li><i class="bi bi-check2" aria-hidden="true"></i> Ranking completo</li>
                                        <li><i class="bi bi-check2" aria-hidden="true"></i> Painel de movimentação</li>
                                        <li><i class="bi bi-check2" aria-hidden="true"></i> Grid de persistência</li>
                                    </ul>
                                    <?php if ($subscriptionInfo && $subscriptionInfo['expires_at']): ?>
                                        <p class="small mt-2" style="color: var(--text-muted);">
                                            Expira em <?php echo date('d/m/Y', strtotime($subscriptionInfo['expires_at'])); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Mensal -->
                        <div class="col-sm-6 col-lg-3">
                            <div class="pricing-card <?php echo $isDowngradeBlocked ? 'pricing-card--disabled opacity-50 position-relative' : ''; ?>"
                                <?php if (!$isDowngradeBlocked): ?>
                                :class="{ 'pricing-card--selected': selectedPlan === 'monthly' }"
                                @click="selectedPlan = 'monthly'"
                                role="radio"
                                :aria-checked="selectedPlan === 'monthly'"
                                tabindex="0"
                                @keydown.enter="selectedPlan = 'monthly'"
                                @keydown.space.prevent="selectedPlan = 'monthly'"
                                <?php else: ?>
                                style="cursor: not-allowed; pointer-events: none;"
                                role="radio"
                                aria-checked="false"
                                aria-disabled="true"
                                <?php endif; ?>>
                                <?php if ($isDowngradeBlocked): ?>
                                    <div class="pricing-card__badge" style="background: var(--warning, #ffc107); color: #000;">
                                        <i class="bi bi-lock-fill me-1"></i>Bloqueado
                                    </div>
                                <?php endif; ?>
                                <h5 class="pricing-card__name">Mensal</h5>
                                <div class="pricing-card__price">R$ <?php echo number_format($prices['monthly'], 2, ',', '.'); ?></div>
                                <div class="pricing-card__period">/mês</div>
                                <?php if ($processingFee > 0): ?>
                                    <div class="small" style="color: var(--text-muted);">
                                        + R$ <?php echo number_format($processingFee, 2, ',', '.'); ?> taxa de processamento
                                    </div>
                                <?php endif; ?>
                                <ul class="pricing-card__features">
                                    <li><i class="bi bi-check2" aria-hidden="true"></i> Ranking completo</li>
                                    <li><i class="bi bi-check2" aria-hidden="true"></i> Painel de movimentação</li>
                                    <li><i class="bi bi-check2" aria-hidden="true"></i> Grid de persistência</li>
                                </ul>
                                <?php if ($isDowngradeBlocked): ?>
                                    <p class="small mt-2 mb-0" style="color: var(--warning, #ffc107);">
                                        <i class="bi bi-info-circle me-1"></i>Plano Anual ainda ativo. Apenas Vitalício está liberado até a expiração.
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Anual (recomendado) -->
                        <div class="col-sm-6 col-lg-3">
                            <div class="pricing-card pricing-card--recommended <?php echo $isAnnualLocked ? 'pricing-card--disabled opacity-50 position-relative' : ''; ?>"
                                <?php if (!$isAnnualLocked): ?>
                                :class="{ 'pricing-card--selected': selectedPlan === 'annual' }"
                                @click="selectedPlan = 'annual'"
                                role="radio"
                                :aria-checked="selectedPlan === 'annual'"
                                tabindex="0"
                                @keydown.enter="selectedPlan = 'annual'"
                                @keydown.space.prevent="selectedPlan = 'annual'"
                                <?php else: ?>
                                style="cursor: not-allowed; pointer-events: none;"
                                role="radio"
                                aria-checked="false"
                                aria-disabled="true"
                                <?php endif; ?>>
                                <div class="pricing-card__badge"><?php echo $isAnnualLocked ? 'Plano atual' : 'Melhor custo-benefício'; ?></div>
                                <h5 class="pricing-card__name">Anual</h5>
                                <div class="pricing-card__price">R$ <?php echo number_format($prices['annual'], 2, ',', '.'); ?></div>
                                <div class="pricing-card__period">/ano</div>
                                <div class="small" style="color: var(--accent);">
                                    R$ <?php echo number_format($prices['annual'] / 12, 2, ',', '.'); ?>/mês
                                </div>
                                <ul class="pricing-card__features">
                                    <li><i class="bi bi-check2" aria-hidden="true"></i> Ranking completo</li>
                                    <li><i class="bi bi-check2" aria-hidden="true"></i> Painel de movimentação</li>
                                    <li><i class="bi bi-check2" aria-hidden="true"></i> Grid de persistência</li>
                                    <li><i class="bi bi-star-fill" style="color: var(--accent);" aria-hidden="true"></i> Economia de <?php echo round((1 - $prices['annual'] / ($prices['monthly'] * 12)) * 100); ?>%</li>
                                </ul>
                                <?php if ($isAnnualLocked): ?>
                                    <p class="small mt-2 mb-0" style="color: var(--warning, #ffc107);">
                                        <i class="bi bi-lock-fill me-1"></i>Renovação bloqueada durante a vigência atual.
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Vitalício -->
                        <div class="col-sm-6 col-lg-3">
                            <div class="pricing-card"
                                :class="{ 'pricing-card--selected': selectedPlan === 'lifetime' }"
                                @click="selectedPlan = 'lifetime'"
                                role="radio"
                                :aria-checked="selectedPlan === 'lifetime'"
                                tabindex="0"
                                @keydown.enter="selectedPlan = 'lifetime'"
                                @keydown.space.prevent="selectedPlan = 'lifetime'">
                                <h5 class="pricing-card__name">Vitalício</h5>
                                <div class="pricing-card__price">R$ <?php echo number_format($prices['lifetime'], 2, ',', '.'); ?></div>
                                <div class="pricing-card__period">pagamento único</div>
                                <ul class="pricing-card__features">
                                    <li><i class="bi bi-check2" aria-hidden="true"></i> Ranking completo</li>
                                    <li><i class="bi bi-check2" aria-hidden="true"></i> Painel de movimentação</li>
                                    <li><i class="bi bi-check2" aria-hidden="true"></i> Grid de persistência</li>
                                    <li><i class="bi bi-infinity" style="color: var(--accent);" aria-hidden="true"></i> Acesso permanente</li>
                                </ul>
                            </div>
                        </div>

                    </div>

                    <div class="text-center">
                        <button type="submit" class="btn btn-accent btn-lg px-5" style="min-height: 44px;">
                            <i class="bi bi-qr-code me-1" aria-hidden="true"></i>
                            Assinar via Pix
                        </button>
                    </div>
                </form>
            <?php endif; ?>

        </div>
    </div>
</div>

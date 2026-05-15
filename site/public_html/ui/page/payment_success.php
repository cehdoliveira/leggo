<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-5">

            <div class="text-center mb-4">
                <div class="mb-3">
                    <i class="bi bi-check-circle-fill" style="color: var(--success); font-size: 3rem;"></i>
                </div>
                <h4 class="fw-bold mb-1">Pagamento Confirmado!</h4>
                <p class="small" style="color: var(--text-muted);">
                    Sua assinatura foi ativada com sucesso.
                </p>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <?php if ($subscriptionInfo): ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-2">
                                <span style="color: var(--text-muted);">Plano</span>
                                <span class="fw-semibold">
                                    <?php
                                    $planLabels = ['trial' => 'Trial', 'monthly' => 'Mensal', 'annual' => 'Anual', 'lifetime' => 'Vitalício'];
                                    echo htmlspecialchars($planLabels[$subscriptionInfo['plan']] ?? $subscriptionInfo['plan'], ENT_QUOTES, 'UTF-8');
                                    ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span style="color: var(--text-muted);">Status</span>
                                <span class="subscription-badge subscription-badge--active">Ativo</span>
                            </div>
                            <?php if ($subscriptionInfo['expires_at']): ?>
                                <div class="d-flex justify-content-between">
                                    <span style="color: var(--text-muted);">Válido até</span>
                                    <span class="fw-semibold"><?php echo date('d/m/Y', strtotime($subscriptionInfo['expires_at'])); ?></span>
                                </div>
                            <?php else: ?>
                                <div class="d-flex justify-content-between">
                                    <span style="color: var(--text-muted);">Validade</span>
                                    <span class="fw-semibold" style="color: var(--accent);">Permanente</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <a href="<?php echo htmlspecialchars($GLOBALS['home_url'], ENT_QUOTES, 'UTF-8'); ?>"
                        class="btn btn-accent w-100 mt-3" style="min-height: 44px;">
                        Ir para o Ranking <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

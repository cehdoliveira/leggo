function plansController() {
    return {
        paymentStatus: 'pending',
        copied: false,
        timeRemaining: '',
        _interval: null,
        _timerInterval: null,
        _createdAt: 0,
        _maxDuration: 1200, // 20 minutos (expiração PixGo)

        init() {
            // Pegar created_at do elemento data se disponível
            this._createdAt = Math.floor(Date.now() / 1000);
        },

        startPolling() {
            this._createdAt = Math.floor(Date.now() / 1000);
            this.updateTimer();

            // Timer countdown
            this._timerInterval = setInterval(() => {
                this.updateTimer();
            }, 1000);

            // Polling status a cada 5s
            this._interval = setInterval(() => {
                this.checkPayment();
            }, 5000);
        },

        updateTimer() {
            var elapsed = Math.floor(Date.now() / 1000) - this._createdAt;
            var remaining = this._maxDuration - elapsed;

            if (remaining <= 0) {
                this.paymentStatus = 'expired';
                this.stopPolling();
                return;
            }

            var minutes = Math.floor(remaining / 60);
            var seconds = remaining % 60;
            this.timeRemaining =
                minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
        },

        async checkPayment() {
            try {
                var response = await fetch(
                    window.location.origin + '/p/check',
                    {
                        credentials: 'same-origin',
                    },
                );
                var data = await response.json();

                if (data.status === 'confirmed') {
                    this.paymentStatus = 'confirmed';
                    this.stopPolling();
                    setTimeout(function () {
                        window.location.href =
                            data.redirect || '/assinar/sucesso';
                    }, 1500);
                } else if (data.status === 'expired') {
                    this.paymentStatus = 'expired';
                    this.stopPolling();
                }
            } catch (e) {
                // Silently continue polling
            }
        },

        stopPolling() {
            if (this._interval) {
                clearInterval(this._interval);
                this._interval = null;
            }
            if (this._timerInterval) {
                clearInterval(this._timerInterval);
                this._timerInterval = null;
            }
        },

        copyPixCode() {
            var input = document.getElementById('pixCode');
            if (input) {
                navigator.clipboard
                    .writeText(input.value)
                    .then(() => {
                        this.copied = true;
                        setTimeout(() => {
                            this.copied = false;
                        }, 2000);
                    })
                    .catch(function () {
                        // Fallback
                        input.select();
                        document.execCommand('copy');
                    });
            }
        },

        clearPending() {
            // Limpar sessão via reload
        },
    };
}

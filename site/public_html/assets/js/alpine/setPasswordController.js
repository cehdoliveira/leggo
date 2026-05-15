document.addEventListener('alpine:init', () => {
    Alpine.data('setPasswordController', () => ({
        formData: {
            password: '',
            password_confirm: '',
        },

        errors: {},
        isSubmitting: false,

        validate() {
            this.errors = {};
            let valid = true;

            if (!this.formData.password || this.formData.password.length < 6) {
                this.errors.password = 'Senha deve ter pelo menos 6 caracteres';
                valid = false;
            }

            if (this.formData.password !== this.formData.password_confirm) {
                this.errors.password_confirm = 'As senhas não conferem';
                valid = false;
            }

            return valid;
        },

        handleSubmit(event) {
            if (!this.validate()) {
                const messages = Object.values(this.errors || {});
                const list = messages.map((m) => `<li>${m}</li>`).join('');
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    html: `<div style="text-align:left"><ul style="margin:0;padding-left:1.2rem">${list}</ul></div>`,
                    confirmButtonText: 'Ok',
                }).then(() => {
                    const firstKey = Object.keys(this.errors || {})[0];
                    if (firstKey) {
                        const el = document.getElementById(firstKey) || document.querySelector(`[name="${firstKey}"]`);
                        if (el && typeof el.focus === 'function') el.focus();
                    }
                });
                return;
            }

            if (this.isSubmitting) return;
            this.isSubmitting = true;
            event.target.submit();
        },

        init() {
            this.$nextTick(() => {
                const el = document.getElementById('password');
                if (el) el.focus();
            });
        },
    }));
});

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-7 col-lg-5">

            <?php html_notification_print(); ?>

            <div class="text-center mb-4">
                <h4 class="fw-bold mb-1">Criar conta</h4>
                <p class="small" style="color: var(--app-text-muted);">Preencha os dados abaixo para se cadastrar</p>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="POST"
                        action="<?php echo $GLOBALS['register_url']; ?>"
                        x-data="registerController()"
                        @submit.prevent="handleSubmit($event)"
                        x-init="init()">
                        <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-3">
                            <label for="name" class="form-label">Nome completo</label>
                            <input type="text"
                                class="form-control"
                                :class="{ 'is-invalid': errors.name }"
                                id="name"
                                name="name"
                                x-model="formData.name"
                                autocomplete="name"
                                required>
                            <div class="invalid-feedback" x-text="errors.name"></div>
                        </div>

                        <div class="mb-3">
                            <label for="mail" class="form-label">E-mail</label>
                            <input type="email"
                                class="form-control"
                                :class="{ 'is-invalid': errors.mail }"
                                id="mail"
                                name="mail"
                                x-model="formData.mail"
                                autocomplete="email"
                                required>
                            <div class="invalid-feedback" x-text="errors.mail"></div>
                        </div>

                        <div class="mb-3">
                            <label for="login" class="form-label">Login <small class="fw-normal" style="color: var(--app-text-muted);">(sem espaços)</small></label>
                            <input type="text"
                                class="form-control"
                                :class="{ 'is-invalid': errors.login }"
                                id="login"
                                name="login"
                                x-model="formData.login"
                                autocomplete="username"
                                pattern="[a-zA-Z0-9._-]+"
                                required>
                            <div class="invalid-feedback" x-text="errors.login"></div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100" :disabled="isSubmitting">
                            <span x-show="isSubmitting" class="spinner-border spinner-border-sm me-1" role="status"></span>
                            <span x-text="isSubmitting ? 'Cadastrando...' : 'Criar conta'">Criar conta</span>
                        </button>

                    </form>
                </div>

                <div class="card-footer text-center py-3">
                    <small style="color: var(--app-text-muted);">
                        Já tem conta?
                        <a href="<?php echo $GLOBALS['login_url']; ?>" class="text-decoration-none">Entrar</a>
                    </small>
                </div>
            </div>

        </div>
    </div>
</div>

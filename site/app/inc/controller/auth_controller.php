<?php
class auth_controller
{
    public static function check_login(): bool
    {
        if (!isset($_SESSION[constant("cAppKey")]["credential"]["idx"])) {
            return false;
        } else {
            return true;
        }
    }

    public function logout(): never
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        basic_redir($GLOBALS["login_url"]);
    }

    public function login(array $info): never
    {
        validate_csrf($info["post"]["_csrf_token"] ?? null, $GLOBALS["login_url"]);

        if (empty($info["post"]["login"]) || empty($info["post"]["password"])) {
            $_SESSION["messages_app"]["danger"] = ["Login e/ou Senha são obrigatórios para realizar o login"];
            basic_redir($GLOBALS["login_url"]);
            exit();
        }

        $redis   = $GLOBALS['redis'] ?? null;
        $rateKey = "login_attempts:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (check_and_increment_rate_limit($redis, $rateKey, 5, 60)) {
            $_SESSION["messages_app"]["danger"] = ["Muitas tentativas. Aguarde um momento antes de tentar novamente."];
            basic_redir($GLOBALS["login_url"]);
            exit();
        }

        $users = new users_model();

        $users->set_field([" idx ", " name ", " mail ", " login ", " password "]);
        $users->set_filter(["enabled = 'yes'", "? IN (mail,login)"], [$info["post"]["login"]]);
        $users->set_paginate([1]);
        $users->load_data();
        $users->attach(["profiles"]);

        $user   = $users->data[0] ?? null;
        $userId = $user["idx"] ?? null;

        if ($userId) {
            $authenticated = verify_password_with_migration($user["password"] ?? '', $info["post"]["password"], $userId);
        } else {
            // Always run password_verify to prevent timing-based username enumeration
            password_verify($info["post"]["password"], '$2y$10$invalidhashXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXe');
            $authenticated = false;
        }

        if ($authenticated) {
            session_regenerate_id(true);
            $credential = $user;
            unset($credential["password"]);
            $_SESSION[constant("cAppKey")] = ["credential" => $credential];

            reset_rate_limit($redis, $rateKey);

            $update = new users_model();
            $update->set_filter(["idx = ?"], [(int)$credential["idx"]]);
            $update->populate(["last_login" => date("Y-m-d H:i:s")]);
            $update->save();
        } else {
            $_SESSION["messages_app"]["danger"] = ["Login e/ou Senha informados não conferem"];
        }

        basic_redir($authenticated ? $GLOBALS["area_url"] : $GLOBALS["login_url"]);
        exit();
    }

    public function display_register(array $info): void
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        $alpineControllers = ['register'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/register.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function register(array $info): never
    {
        validate_csrf($info["post"]["_csrf_token"] ?? null, $GLOBALS["register_url"]);

        $required = ["name", "mail", "login"];
        foreach ($required as $r) {
            if (empty($info["post"][$r])) {
                $_SESSION["messages_app"]["danger"] = ["Campo $r é obrigatório"];
                basic_redir($GLOBALS["register_url"]);
                exit();
            }
        }

        $users = new users_model();

        try {
            $users->set_filter([" active = 'yes' ", " ( mail = ? OR login = ? ) "], [$info["post"]["mail"], $info["post"]["login"]]);
            $users->set_paginate([1]);
            $users->load_data();

            if (isset($users->data[0]["idx"])) {
                $_SESSION["messages_app"]["danger"] = ["Já existe um usuário com esse e-mail/login"];
                basic_redir($GLOBALS["register_url"]);
                exit();
            }

            $token = bin2hex(random_bytes(32));

            // Senha temporária desconhecida — usuário vai definir após confirmar email
            $info["post"]["password"]    = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
            $info["post"]["profiles_id"] = constant("DEFAULT_USER_PROFILE_ID");
            $info["post"]["enabled"]               = "no";
            $info["post"]["email_token"]           = $token;
            $info["post"]["email_token_expires_at"] = date("Y-m-d H:i:s", strtotime("+72 hours"));

            $newUser = new users_model();
            $newUser->populate($info["post"]);
            $info["idx"] = $newUser->save();

            if (isset($info["idx"]) && $info["idx"] > 0) {
                $newUser->save_attach($info, ["profiles"]);

                $canonicalBase = (defined('SITE_CANONICAL_URL') && constant('SITE_CANONICAL_URL') !== '')
                    ? rtrim(constant('SITE_CANONICAL_URL'), '/')
                    : rtrim(constant('cFrontend'), '/');
                $verifyLink = $canonicalBase . '/verificar-email/' . $token;
                $subject = "Confirme seu cadastro — " . constant('cTitle');
                ob_start();
                $name = $info["post"]["name"];
                include(constant("cRootServer") . "ui/mail/verify_email.php");
                $body = ob_get_clean();

                $emailSent = false;
                try {
                    if (class_exists("EmailProducer")) {
                        $producer = EmailProducer::getInstance();
                        $emailSent = (bool)$producer->send($info["post"]["mail"], $subject, $body);
                    }
                } catch (Exception $e) {
                    error_log("Erro ao enfileirar email de verificação: " . $e->getMessage());
                }

                try {
                    $msgModel = new messages_model();
                    $msgModel->populate([
                        "to_mail" => $info["post"]["mail"],
                        "subject" => $subject,
                        "body"    => $body,
                        "sent_at" => date("Y-m-d H:i:s"),
                    ]);
                    $msgModel->save();
                } catch (Exception $e) {
                    error_log("Erro ao salvar log de email: " . $e->getMessage());
                }

                if (!$emailSent) {
                    $_SESSION["messages_app"]["danger"] = ["Cadastro realizado, mas não foi possível enviar o email de verificação. Entre em contato com o suporte."];
                    basic_redir($GLOBALS["register_url"]);
                    exit();
                }

                $_SESSION["messages_app"]["success"] = ["Cadastro realizado! Verifique seu e-mail para ativar sua conta."];
                basic_redir($GLOBALS["login_url"]);
                exit();
            } else {
                $_SESSION["messages_app"]["danger"] = ["Falha ao criar usuário. Tente novamente mais tarde."];
                basic_redir($GLOBALS["register_url"]);
                exit();
            }
        } catch (Exception $e) {
            error_log("Erro ao criar usuário: " . $e->getMessage());
            $_SESSION["messages_app"]["danger"] = ["Já existe um usuário com esse e-mail/login ou ocorreu um erro. Tente novamente."];
            basic_redir($GLOBALS["register_url"], rollback: true);
            exit();
        }
    }

    public function verify_email(array $info): never
    {
        $token = $info[1] ?? null;

        if (empty($token)) {
            $_SESSION["messages_app"]["danger"] = ["Link de verificação inválido."];
            basic_redir($GLOBALS["login_url"]);
            exit();
        }

        $users = new users_model();

        $users->set_field([" idx ", " email_verified_at "]);
        $users->set_filter([" active = 'yes' ", " enabled = 'no' ", " email_token = ? ", " email_token_expires_at > NOW() "], [$token]);
        $users->set_paginate([1]);
        $users->load_data();

        $user = $users->data[0] ?? null;

        if (!$user) {
            $users->set_field([" idx ", " email_verified_at "]);
            $users->set_filter([" active = 'yes' ", " enabled = 'no' ", " email_token = ? "], [$token]);
            $users->set_paginate([1]);
            $users->load_data();
            $user = $users->data[0] ?? null;

            if ($user && !empty($user["email_verified_at"])) {
                $_SESSION['pending_set_password_idx'] = (int)$user["idx"];
                $_SESSION["messages_app"]["success"] = ["Este e-mail já foi confirmado. Continue para definir sua senha."];
                basic_redir(sprintf($GLOBALS["set_password_url"], $token));
                exit();
            }

            $_SESSION["messages_app"]["danger"] = ["Link inválido, expirado ou já utilizado."];
            basic_redir($GLOBALS["login_url"]);
            exit();
        }

        $users->set_filter(["idx = ?"], [(int)$user["idx"]]);
        $users->populate(["email_verified_at" => date("Y-m-d H:i:s")]);
        $users->save();
        $_SESSION['pending_set_password_idx'] = (int)$user["idx"];

        $_SESSION["messages_app"]["success"] = ["E-mail confirmado! Agora defina sua senha para ativar sua conta."];
        basic_redir(sprintf($GLOBALS["set_password_url"], $token));
        exit();
    }

    public function display_set_password(array $info): void
    {
        $token      = $info[1] ?? null;  // mantido apenas para compor o action da URL do formulario
        $pendingIdx = $_SESSION['pending_set_password_idx'] ?? null;

        if (empty($pendingIdx)) {
            $_SESSION["messages_app"]["danger"] = ["Sessão expirada. Por favor, verifique seu e-mail novamente."];
            basic_redir($GLOBALS["login_url"]);
            exit();
        }

        $users = new users_model();

        $users->set_field([" idx "]);
        $users->set_filter([" active = 'yes' ", " enabled = 'no' ", " idx = ? "], [$pendingIdx]);
        $users->set_paginate([1]);
        $users->load_data();

        if (!isset($users->data[0]["idx"])) {
            unset($_SESSION['pending_set_password_idx']);
            $_SESSION["messages_app"]["danger"] = ["Link inválido ou já utilizado."];
            basic_redir($GLOBALS["login_url"]);
            exit();
        }

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        $alpineControllers = ['setPassword'];
        $set_password_token = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/set_password.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function set_password(array $info): never
    {
        validate_csrf($info["post"]["_csrf_token"] ?? null, $GLOBALS["login_url"]);

        $token    = $info[1] ?? null;
        $password = $info["post"]["password"] ?? '';
        $confirm  = $info["post"]["password_confirm"] ?? '';

        $pendingIdx = $_SESSION['pending_set_password_idx'] ?? null;

        if (empty($pendingIdx)) {
            $_SESSION["messages_app"]["danger"] = ["Sessão expirada. Por favor, verifique seu e-mail novamente."];
            basic_redir($GLOBALS["login_url"]);
            exit();
        }

        if (empty($password) || strlen($password) < 6) {
            $_SESSION["messages_app"]["danger"] = ["Senha deve ter pelo menos 6 caracteres."];
            basic_redir(sprintf($GLOBALS["set_password_url"], $token));
            exit();
        }

        if ($password !== $confirm) {
            $_SESSION["messages_app"]["danger"] = ["As senhas não conferem."];
            basic_redir(sprintf($GLOBALS["set_password_url"], $token));
            exit();
        }

        $users = new users_model();

        $users->set_field([" idx "]);
        $users->set_filter([" active = 'yes' ", " enabled = 'no' ", " idx = ? "], [$pendingIdx]);
        $users->set_paginate([1]);
        $users->load_data();

        if (!($users->data[0] ?? null)) {
            unset($_SESSION['pending_set_password_idx']);
            $_SESSION["messages_app"]["danger"] = ["Link inválido ou já utilizado."];
            basic_redir($GLOBALS["login_url"]);
            exit();
        }

        $hashedPwd = password_hash($password, PASSWORD_BCRYPT);

        $users->set_filter(["idx = ?"], [$pendingIdx]);
        $users->populate([
            "enabled"            => "yes",
            "email_verified_at"  => date("Y-m-d H:i:s"),
            "password"           => $hashedPwd,
            "email_token"        => null,
        ]);
        $users->save();

        unset($_SESSION['pending_set_password_idx']);

        $_SESSION["messages_app"]["success"] = ["Senha definida! Você já pode fazer login."];
        basic_redir($GLOBALS["login_url"]);
        exit();
    }

    public function display(array $info): void
    {
        if (self::check_login()) {
            basic_redir($GLOBALS["area_url"]);
            return;
        }

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        $alpineControllers = ['login'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/login.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function display_forgot_password(array $info): void
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/forgot_password.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function forgot_password(array $info): never
    {
        validate_csrf($info["post"]["_csrf_token"] ?? null, $GLOBALS["forgot_password_url"]);

        $mail = trim($info["post"]["mail"] ?? '');

        if (empty($mail)) {
            $_SESSION["messages_app"]["danger"] = ["Informe seu e-mail."];
            basic_redir($GLOBALS["forgot_password_url"]);
            exit();
        }

        $redis   = $GLOBALS['redis'] ?? null;
        $rateKey = "forgot_pwd:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (check_and_increment_rate_limit($redis, $rateKey, 3, 300)) {
            $_SESSION["messages_app"]["danger"] = ["Muitas tentativas. Aguarde alguns minutos."];
            basic_redir($GLOBALS["forgot_password_url"]);
            exit();
        }

        $users = new users_model();
        $users->set_field([" idx ", " name ", " mail ", " enabled "]);
        $users->set_filter([" active = 'yes' ", " mail = ? "], [$mail]);
        $users->set_paginate([1]);
        $users->load_data();

        $user = $users->data[0] ?? null;

        if ($user) {
            $userId   = (int)$user['idx'];
            $name     = $user['name'];
            $token   = bin2hex(random_bytes(32));

            if ($user['enabled'] === 'no') {
                // Unverified users: use same 72h window as original registration
                $expires = date("Y-m-d H:i:s", strtotime("+72 hours"));
            } else {
                // Verified users: shorter window for password reset
                $expires = date("Y-m-d H:i:s", strtotime("+2 hours"));
            }

            $users->set_filter(["idx = ?"], [$userId]);
            $users->populate([
                "email_token"           => $token,
                "email_token_expires_at" => $expires,
            ]);
            $users->save();

            $canonicalBase = rtrim(constant('SITE_CANONICAL_URL'), '/');

            if ($user['enabled'] === 'no') {
                $verifyLink = $canonicalBase . '/verificar-email/' . $token;
                $subject    = "Confirme seu cadastro — " . constant('cTitle');
                ob_start();
                include(constant("cRootServer") . "ui/mail/verify_email.php");
                $body = ob_get_clean();
            } else {
                $resetLink = $canonicalBase . '/redefinir-senha/' . $token;
                $subject   = "Redefinição de senha — " . constant('cTitle');
                ob_start();
                include(constant("cRootServer") . "ui/mail/reset_password.php");
                $body = ob_get_clean();
            }

            $emailSent = false;
            try {
                if (class_exists("EmailProducer")) {
                    $producer = EmailProducer::getInstance();
                    $emailSent = (bool)$producer->send($user['mail'], $subject, $body);
                }
            } catch (Exception $e) {
                error_log("Erro ao enfileirar email de recuperação de senha: " . $e->getMessage());
            }

            try {
                $msgModel = new messages_model();
                $msgModel->populate([
                    "to_mail" => $user['mail'],
                    "subject" => $subject,
                    "body"    => $body,
                    "sent_at" => date("Y-m-d H:i:s"),
                ]);
                $msgModel->save();
            } catch (Exception $e) {
                error_log("Erro ao salvar log de email: " . $e->getMessage());
            }

            if (!$emailSent) {
                $_SESSION["messages_app"]["danger"] = ["Não foi possível enviar o email. Tente novamente mais tarde."];
                basic_redir($GLOBALS["forgot_password_url"]);
                exit();
            }
        }

        // Mensagem genérica — não revela se o e-mail existe
        $_SESSION["messages_app"]["success"] = ["Se o e-mail informado estiver cadastrado, você receberá um link em breve."];
        basic_redir($GLOBALS["login_url"]);
        exit();
    }

    public function display_reset_password(array $info): void
    {
        $token      = $info[1] ?? null;
        $pendingIdx = $_SESSION['pending_reset_idx'] ?? null;

        if ($pendingIdx) {
            $users = new users_model();
            $users->set_field([" idx "]);
            $users->set_filter([" active = 'yes' ", " enabled = 'yes' ", " idx = ? "], [$pendingIdx]);
            $users->set_paginate([1]);
            $users->load_data();

            if (empty($users->data[0]["idx"])) {
                unset($_SESSION['pending_reset_idx']);
                $_SESSION["messages_app"]["danger"] = ["Sessão inválida."];
                basic_redir($GLOBALS["login_url"]);
                exit();
            }
        } else {
            if (empty($token)) {
                $_SESSION["messages_app"]["danger"] = ["Link inválido."];
                basic_redir($GLOBALS["login_url"]);
                exit();
            }

            $users      = new users_model();
            $users->set_field([" idx "]);
            $users->set_filter([" active = 'yes' ", " enabled = 'yes' ", " email_token = ? ", " email_token_expires_at > NOW() "], [$token]);
            $users->set_paginate([1]);
            $users->load_data();

            $user = $users->data[0] ?? null;

            if (!$user) {
                $_SESSION["messages_app"]["danger"] = ["Link inválido, expirado ou já utilizado."];
                basic_redir($GLOBALS["login_url"]);
                exit();
            }

            $users->set_filter(["idx = ?"], [(int)$user["idx"]]);
            $users->populate(["email_token" => null]);
            $users->save();
            $_SESSION['pending_reset_idx'] = (int)$user["idx"];
        }

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        $reset_password_token = htmlspecialchars($token ?? '', ENT_QUOTES, 'UTF-8');

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/reset_password.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function reset_password(array $info): never
    {
        validate_csrf($info["post"]["_csrf_token"] ?? null, $GLOBALS["login_url"]);

        $token    = $info[1] ?? null;
        $password = $info["post"]["password"] ?? '';
        $confirm  = $info["post"]["password_confirm"] ?? '';

        $pendingIdx = $_SESSION['pending_reset_idx'] ?? null;

        if (empty($pendingIdx)) {
            $_SESSION["messages_app"]["danger"] = ["Sessão expirada. Solicite um novo link de redefinição."];
            basic_redir($GLOBALS["forgot_password_url"]);
            exit();
        }

        if (empty($password) || strlen($password) < 6) {
            $_SESSION["messages_app"]["danger"] = ["Senha deve ter pelo menos 6 caracteres."];
            basic_redir(sprintf($GLOBALS["reset_password_url"], $token));
            exit();
        }

        if ($password !== $confirm) {
            $_SESSION["messages_app"]["danger"] = ["As senhas não conferem."];
            basic_redir(sprintf($GLOBALS["reset_password_url"], $token));
            exit();
        }

        $users   = new users_model();

        $users->set_field([" idx "]);
        $users->set_filter([" active = 'yes' ", " enabled = 'yes' ", " idx = ? "], [$pendingIdx]);
        $users->set_paginate([1]);
        $users->load_data();

        if (empty($users->data[0]["idx"])) {
            unset($_SESSION['pending_reset_idx']);
            $_SESSION["messages_app"]["danger"] = ["Usuário não encontrado."];
            basic_redir($GLOBALS["login_url"]);
            exit();
        }

        $users->set_filter(["idx = ?"], [$pendingIdx]);
        $users->populate([
            "password"                => password_hash($password, PASSWORD_BCRYPT),
            "email_token_expires_at"  => null,
        ]);
        $users->save();

        unset($_SESSION['pending_reset_idx']);
        session_regenerate_id(true);

        $_SESSION["messages_app"]["success"] = ["Senha redefinida com sucesso! Faça login para continuar."];
        basic_redir($GLOBALS["login_url"]);
        exit();
    }
}

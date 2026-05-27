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

        if ($authenticated && is_array($user)) {
            session_regenerate_id(true);

            $isAdmin = false;
            foreach (($user["profiles_attach"] ?? []) as $profile) {
                if (($profile["adm"] ?? 'no') === 'yes') {
                    $isAdmin = true;
                    break;
                }
            }

            if (!$isAdmin) {
                $_SESSION["messages_app"]["danger"] = ["Acesso não autorizado. Este painel é restrito a administradores."];
                basic_redir($GLOBALS["login_url"]);
                exit();
            }

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

        basic_redir($authenticated ? $GLOBALS["home_url"] : $GLOBALS["login_url"]);
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

        $required = ["name", "mail", "password", "login"];
        foreach ($required as $r) {
            if (empty($info["post"][$r])) {
                $_SESSION["messages_app"]["danger"] = ["Campo $r é obrigatório"];
                basic_redir($GLOBALS["register_url"]);
                exit();
            }
        }

        if (strlen($info["post"]["password"]) < 6) {
            $_SESSION["messages_app"]["danger"] = ["Senha deve ter pelo menos 6 caracteres."];
            basic_redir($GLOBALS["register_url"]);
            exit();
        }

        $users = new users_model();
        $users->set_filter([" active = 'yes' ", " ( mail = ? OR login = ? ) "], [$info["post"]["mail"], $info["post"]["login"]]);
        $users->set_paginate([1]);
        $users->load_data();

        if (isset($users->data[0]["idx"])) {
            $_SESSION["messages_app"]["danger"] = ["Já existe um usuário com esse e-mail/login"];
            basic_redir($GLOBALS["register_url"]);
            exit();
        }

        $info["post"]["password"]    = password_hash($info["post"]["password"], PASSWORD_BCRYPT);
        $info["post"]["profiles_id"] = constant("DEFAULT_USER_PROFILE_ID");

        $newUser = new users_model();
        $newUser->populate($info["post"]);
        $info["idx"] = $newUser->save();

        if (isset($info["idx"]) && $info["idx"] > 0) {
            $newUser->save_attach($info, ["profiles"]);

            try {
                $name      = $info["post"]["name"];
                $login     = $info["post"]["login"];
                $canonicalBase = (defined('MANAGER_CANONICAL_URL') && constant('MANAGER_CANONICAL_URL') !== '')
                    ? rtrim(constant('MANAGER_CANONICAL_URL'), '/')
                    : rtrim(constant('cFrontend'), '/');
                $loginLink = $canonicalBase . '/login';
                $subject   = "Seus dados de acesso — " . constant('cTitle');
                ob_start();
                include(constant("cRootServer") . "ui/mail/new_admin_credentials.php");
                $body = ob_get_clean();

                if (class_exists("EmailProducer")) {
                    $producer = EmailProducer::getInstance();
                    $producer->send($info["post"]["mail"], $subject, $body);
                }

                $msgModel = new messages_model();
                $msgModel->populate([
                    "to_mail" => $info["post"]["mail"],
                    "subject" => $subject,
                    "body"    => $body,
                    "sent_at" => date("Y-m-d H:i:s"),
                ]);
                $msgModel->save();
            } catch (Exception $e) {
                error_log("Erro ao enviar email de cadastro: " . $e->getMessage());
            }

            $_SESSION["messages_app"]["success"] = ["Cadastro realizado com sucesso. Verifique seu e-mail com os dados de acesso."];
            basic_redir($GLOBALS["login_url"]);
            exit();
        } else {
            $_SESSION["messages_app"]["danger"] = ["Falha ao criar usuário. Tente novamente mais tarde."];
            basic_redir($GLOBALS["register_url"]);
            exit();
        }
    }

    public function display(array $info): void
    {
        if (self::check_login()) {
            basic_redir($GLOBALS["home_url"]);
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
}

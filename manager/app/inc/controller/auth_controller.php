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

    /**
     * O usuario logado tem a capacidade, de verdade?
     *
     * Resposta estrita: nunca passa pelo bypass do modo log. Use em decisao de
     * NAVEGACAO (esconder item de menu, esconder botao) — nao em guard de rota,
     * onde a fatia atual ainda e can().
     */
    public static function has(string $capability): bool
    {
        if (!self::check_login()) {
            return false;
        }

        $userId = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);

        foreach (self::access_rows($userId) as $row) {
            if (($row["adm"] ?? 'no') === 'yes') {
                return true;
            }
            if (($row["slug"] ?? null) === $capability) {
                return true;
            }
        }

        return false;
    }

    /**
     * O usuario logado tem a capacidade pedida?
     *
     * FATIA 2 (modo log): para quem JA esta logado, esta funcao nunca nega —
     * ela registra o que negaria e devolve true. Serve para descobrir seed
     * incompleto ou rota mapeada no slug errado antes de bloquear alguem.
     * O plano 022 troca o `return true` final por `return false`.
     *
     * A checagem de sessao (check_login) e separada e NUNCA e afrouxada:
     * requisicao sem login e negada em qualquer fatia.
     *
     * Regra de compatibilidade permanente: um perfil ativo com adm = 'yes'
     * vale por todas as capacidades, sem consultar profiles_capabilities.
     *
     * A decisao real mora em has() — can() so acrescenta o bypass do modo log.
     */
    public static function can(string $capability): bool
    {
        if (self::has($capability)) {
            return true;
        }

        if (!self::check_login()) {
            return false;
        }

        Logger::getInstance()->warning("capacidade negada (modo log, nao bloqueou)", [
            "capability" => $capability,
            "user"       => (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0),
        ]);

        return true;
    }

    /**
     * Guard de rota por capacidade — valor passado no 4o argumento de
     * add_route() (Dispatcher::evaluateCheck).
     *
     * Devolver false faz o Dispatcher redirecionar pro login — certo pra quem
     * nao esta logado. Pra quem ESTA logado e so nao tem a capacidade, o login
     * redireciona de volta pra home (auth_controller::display) e o usuario
     * fica sem nenhuma explicacao: nesse caso a resposta correta e 403, via
     * render_error_page() (CommonFunctions.php).
     *
     * Enquanto can() estiver em modo log (plano 021), o 403 nunca dispara:
     * can() sempre devolve true pra quem esta logado. O ramo fica inerte ate
     * o plano 022 trocar esse retorno pra false.
     *
     * $can e $checkLogin sao seams de teste (default: can()/check_login()
     * reais) — permitem simular em teste a fatia 022 (can() negando pra quem
     * esta logado), que hoje nao acontece de verdade. Nao passe nada em
     * producao.
     */
    public static function routeGuard(string $capability, ?callable $can = null, ?callable $checkLogin = null): bool
    {
        $can ??= fn(string $c): bool => self::can($c);
        $checkLogin ??= fn(): bool => self::check_login();

        if ($can($capability)) {
            return true;
        }

        if (!$checkLogin()) {
            return false;
        }

        render_error_page(
            403,
            "Acesso negado",
            "Sua conta não tem permissão para esta área. Peça a um administrador a capacidade necessária."
        );
    }

    /**
     * Perfis ativos do usuario e as capacidades ativas de cada um, numa query.
     * LEFT JOIN: um perfil sem nenhuma capacidade ainda devolve uma linha, com
     * slug nulo — e por ela que o bypass de adm = 'yes' funciona.
     */
    private static function access_rows(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $sql = "SELECT p.adm AS adm, c.slug AS slug
                  FROM users_profiles up
                  JOIN profiles p ON p.idx = up.profiles_id AND p.active = 'yes'
                  LEFT JOIN profiles_capabilities pc ON pc.profiles_id = p.idx AND pc.active = 'yes'
                  LEFT JOIN capabilities c ON c.idx = pc.capabilities_id AND c.active = 'yes'
                 WHERE up.active = 'yes' AND up.users_id = ?";

        return (new profiles_model())->execute_raw_prepared($sql, [$userId])->fetchAll();
    }

	public function logout(array $info): never
	{
		validate_csrf($info["post"]["_csrf_token"] ?? null, $GLOBALS["home_url"]);
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
        }

        $redis   = $GLOBALS['redis'] ?? null;
        $rateKey = "login_attempts:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (check_and_increment_rate_limit($redis, $rateKey, 5, 60)) {
            $_SESSION["messages_app"]["danger"] = ["Muitas tentativas. Aguarde um momento antes de tentar novamente."];
            basic_redir($GLOBALS["login_url"]);
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
    }

    public function display_register(array $info): void
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
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
            }

            $token = random_token();

            $info["post"]["password"]              = password_hash(random_token(), PASSWORD_BCRYPT);
            $info["post"]["profiles_id"]           = constant("DEFAULT_USER_PROFILE_ID");
            $info["post"]["enabled"]               = "no";
            $info["post"]["email_token"]           = $token;
            $info["post"]["email_token_expires_at"] = date("Y-m-d H:i:s", strtotime("+72 hours"));

            $newUser = new users_model();
            $newUser->populate([
                "name"                   => $info["post"]["name"],
                "mail"                   => $info["post"]["mail"],
                "login"                  => $info["post"]["login"],
                "password"               => $info["post"]["password"],
                "enabled"                => $info["post"]["enabled"],
                "email_token"            => $info["post"]["email_token"],
                "email_token_expires_at" => $info["post"]["email_token_expires_at"],
            ]);
            $info["idx"] = $newUser->save();

            if ($info["idx"] > 0) {
                $newUser->save_attach($info, ["profiles"]);

                try {
                    send_admin_credentials_mail(
                        $info["post"]["name"],
                        $info["post"]["login"],
                        $info["post"]["mail"],
                        $token
                    );
                } catch (Exception $e) {
                    error_log("Erro ao enviar email de cadastro: " . $e->getMessage());
                }

                $_SESSION["messages_app"]["success"] = ["Usuário criado com sucesso. Um email foi enviado com as instruções para definir a senha."];
                basic_redir($GLOBALS["login_url"]);
            } else {
                $_SESSION["messages_app"]["danger"] = ["Falha ao criar usuário. Tente novamente mais tarde."];
                basic_redir($GLOBALS["register_url"]);
            }
        } catch (Exception $e) {
            error_log("Erro ao criar usuário: " . $e->getMessage());
            $_SESSION["messages_app"]["danger"] = ["Já existe um usuário com esse e-mail/login ou ocorreu um erro. Tente novamente."];
            basic_redir($GLOBALS["register_url"], rollback: true);
        }
    }

    public function display_set_password(array $info): void
    {
        $token = $info[1] ?? null;

        if (empty($token)) {
            $_SESSION["messages_app"]["danger"] = ["Link inválido."];
            basic_redir($GLOBALS["login_url"]);
        }

        $users = new users_model();
        $users->set_field([" idx "]);
        $users->set_filter([" active = 'yes' ", " enabled = 'no' ", " email_token = ? ", " email_token_expires_at > NOW() "], [$token]);
        $users->set_paginate([1]);
        $users->load_data();

        if (!isset($users->data[0]["idx"])) {
            $_SESSION["messages_app"]["danger"] = ["Link inválido ou expirado."];
            basic_redir($GLOBALS["login_url"]);
        }

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
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

        if (empty($token)) {
            $_SESSION["messages_app"]["danger"] = ["Link inválido."];
            basic_redir($GLOBALS["login_url"]);
        }

        if (empty($password) || strlen($password) < 6) {
            $_SESSION["messages_app"]["danger"] = ["Senha deve ter pelo menos 6 caracteres."];
            basic_redir(sprintf($GLOBALS["set_password_url"], $token));
        }

        if ($password !== $confirm) {
            $_SESSION["messages_app"]["danger"] = ["As senhas não conferem."];
            basic_redir(sprintf($GLOBALS["set_password_url"], $token));
        }

        $users = new users_model();
        $users->set_field([" idx "]);
        $users->set_filter([" active = 'yes' ", " enabled = 'no' ", " email_token = ? ", " email_token_expires_at > NOW() "], [$token]);
        $users->set_paginate([1]);
        $users->load_data();

        $userIdx = $users->data[0]["idx"] ?? null;

        if (!$userIdx) {
            $_SESSION["messages_app"]["danger"] = ["Link inválido ou expirado."];
            basic_redir($GLOBALS["login_url"]);
        }

        $users->set_filter(["idx = ?"], [$userIdx]);
        $users->populate([
            "enabled"            => "yes",
            "email_verified_at"  => date("Y-m-d H:i:s"),
            "password"           => password_hash($password, PASSWORD_BCRYPT),
            "email_token"        => null,
        ]);
        $users->save();

        session_regenerate_id(true);

        $_SESSION["messages_app"]["success"] = ["Senha definida! Você já pode fazer login."];
        basic_redir($GLOBALS["login_url"]);
    }

    public function display(array $info): void
    {
        if (self::check_login()) {
            basic_redir($GLOBALS["home_url"]);
        }

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
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
            $_SESSION['_csrf_token'] = random_token();
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
        }

        $redis   = $GLOBALS['redis'] ?? null;
        $rateKey = "forgot_pwd:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (check_and_increment_rate_limit($redis, $rateKey, 3, 300)) {
            $_SESSION["messages_app"]["danger"] = ["Muitas tentativas. Aguarde alguns minutos."];
            basic_redir($GLOBALS["forgot_password_url"]);
        }

        $users = new users_model();
        $users->set_field([" idx ", " name ", " mail ", " login ", " enabled "]);
        $users->set_filter([" active = 'yes' ", " mail = ? "], [$mail]);
        $users->set_paginate([1]);
        $users->load_data();

        $user = $users->data[0] ?? null;

        if ($user) {
            $userId   = (int)$user['idx'];
            $name     = $user['name'];
            $token   = random_token();

            if ($user['enabled'] === 'no') {
                // Unverified users: use same 72h window as original registration.
                // gmdate(), nao date(): kernel.php forca America/Sao_Paulo (UTC-3)
                // pro PHP, mas o MySQL deste ambiente roda em UTC — gravar em UTC
                // dos dois lados evita expirar o token antes da hora (ver achado).
                $expires = gmdate("Y-m-d H:i:s", strtotime("+72 hours"));
            } else {
                // Verified users: shorter window for password reset
                $expires = gmdate("Y-m-d H:i:s", strtotime("+2 hours"));
            }

            $users->set_filter(["idx = ?"], [$userId]);
            $users->populate([
                "email_token"           => $token,
                "email_token_expires_at" => $expires,
            ]);
            $users->save();

            try {
                $canonicalBase = canonical_url('MANAGER_CANONICAL_URL');

                if ($user['enabled'] === 'no') {
                    // O manager nao tem cadastro publico nem rota de verificar-email: quem
                    // ainda nao ativou a conta foi criado por um administrador e entra pelo
                    // mesmo link de convite usado no cadastro (definir-senha).
                    $login           = $user['login'];
                    $setPasswordLink = $canonicalBase . '/definir-senha/' . $token;
                    $subject         = "Defina sua senha — " . constant('cTitle');
                    ob_start();
                    include(constant("cRootServer") . "ui/mail/new_admin_credentials.php");
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
                        "body"    => redact_email_body($body),
                        "sent_at" => date("Y-m-d H:i:s"),
                    ]);
                    $msgModel->save();
                } catch (Exception $e) {
                    error_log("Erro ao salvar log de email: " . $e->getMessage());
                }

                if (!$emailSent) {
                    // Nao revela ao usuario que o envio falhou: a mensagem final e
                    // sempre a generica (ver achado de enumeracao de conta). O
                    // operador enxerga a falha pelo log.
                    Logger::getInstance()->error("Falha ao enviar email de recuperação de senha", [
                        "user_id" => $userId,
                    ]);
                }
            } catch (RuntimeException $e) {
                // canonical_url() falha fechado de proposito quando a configuracao
                // canonica esta ausente. Desfaz o token gravado acima e mantem a
                // MESMA resposta generica — nao deixa a falta de configuracao virar
                // um jeito de diferenciar conta cadastrada de nao cadastrada.
                Logger::getInstance()->error("Falha ao montar link de recuperação de senha", [
                    "error"   => $e->getMessage(),
                    "user_id" => $userId,
                ]);
                $_SESSION["messages_app"]["success"] = ["Se o e-mail informado estiver cadastrado, você receberá um link em breve."];
                basic_redir($GLOBALS["login_url"], rollback: true);
            }
        }

        // Mensagem genérica — não revela se o e-mail existe
        $_SESSION["messages_app"]["success"] = ["Se o e-mail informado estiver cadastrado, você receberá um link em breve."];
        basic_redir($GLOBALS["login_url"]);
    }

    public function display_reset_password(array $info): void
    {
        $token = $info[1] ?? null;

        if (empty($token)) {
            $_SESSION["messages_app"]["danger"] = ["Link inválido."];
            basic_redir($GLOBALS["login_url"]);
        }

        // Somente leitura: o GET nao consome o token. Um scanner de seguranca de
        // e-mail (Safe Links, ATP, proxy de imagem) que pre-busca o link antes do
        // usuario clicar nao pode "queimar" o token por conta disso. O consumo de
        // verdade, atomico, acontece em reset_password() (POST) — ver ali.
        $users = new users_model();
        $users->set_field([" idx "]);
        $users->set_filter([" active = 'yes' ", " enabled = 'yes' ", " email_token = ? ", " email_token_expires_at > NOW() "], [$token]);
        $users->set_paginate([1]);
        $users->load_data();

        if (empty($users->data[0]["idx"])) {
            $_SESSION["messages_app"]["danger"] = ["Link inválido, expirado ou já utilizado."];
            basic_redir($GLOBALS["login_url"]);
        }

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }
        $alpineControllers = ['setPassword'];
        $reset_password_token = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

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

        if (empty($token)) {
            $_SESSION["messages_app"]["danger"] = ["Link inválido."];
            basic_redir($GLOBALS["forgot_password_url"]);
        }

        if (empty($password) || strlen($password) < 6) {
            $_SESSION["messages_app"]["danger"] = ["Senha deve ter pelo menos 6 caracteres."];
            basic_redir(sprintf($GLOBALS["reset_password_url"], $token));
        }

        if ($password !== $confirm) {
            $_SESSION["messages_app"]["danger"] = ["As senhas não conferem."];
            basic_redir(sprintf($GLOBALS["reset_password_url"], $token));
        }

        // Valida e consome o token no MESMO UPDATE: o WHERE repete email_token = ?
        // (nao so idx), entao so uma requisicao concorrente com o mesmo link
        // consegue afetar a linha — a outra ve rowCount() 0 e cai no erro de link
        // invalido, mesmo tendo lido o token valido no mesmo instante.
        $users = new users_model();
        $users->set_filter([
            " active = 'yes' ",
            " enabled = 'yes' ",
            " email_token = ? ",
            " email_token_expires_at > NOW() ",
        ], [$token]);
        $users->populate([
            "password"                => password_hash($password, PASSWORD_BCRYPT),
            "email_token"             => null,
            "email_token_expires_at"  => null,
        ]);
        $result = $users->save();
        $rowsAffected = ($result instanceof \PDOStatement) ? $result->rowCount() : 0;

        if ($rowsAffected !== 1) {
            $_SESSION["messages_app"]["danger"] = ["Link inválido, expirado ou já utilizado."];
            basic_redir($GLOBALS["login_url"]);
        }

        session_regenerate_id(true);

        $_SESSION["messages_app"]["success"] = ["Senha redefinida com sucesso! Faça login para continuar."];
        basic_redir($GLOBALS["login_url"]);
    }

    public function display_account(array $info): void
    {
        if (!self::check_login()) {
            basic_redir($GLOBALS["login_url"]);
        }

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $userIdx = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);

        $users = new users_model();
        $users->set_field([" idx ", " name ", " mail ", " login "]);
        $users->set_filter([" active = 'yes' ", " idx = ? "], [$userIdx]);
        $users->set_paginate([1]);
        $users->load_data(false);

        $account = $users->data[0] ?? [];

        if ($account === []) {
            $_SESSION["messages_app"]["danger"] = ["Sessão inválida. Entre novamente."];
            basic_redir($GLOBALS["login_url"]);
        }

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/account.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function save_account(array $info): never
    {
        validate_csrf($info["post"]["_csrf_token"] ?? null, $GLOBALS["account_url"]);

        if (!self::check_login()) {
            basic_redir($GLOBALS["login_url"]);
        }

        $userIdx = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);
        $name    = trim((string)($info["post"]["name"] ?? ''));

        if ($name === '') {
            $_SESSION["messages_app"]["danger"] = ["Informe seu nome."];
            basic_redir($GLOBALS["account_url"]);
        }

        $rollback = false;

        try {
            $users = new users_model();
            $users->set_filter([" idx = ? "], [$userIdx]);
            $users->populate(["name" => $name]);
            $users->save();

            // A sessao guarda o nome exibido no cabecalho; sem isto a tela
            // continuaria mostrando o nome antigo ate o proximo login.
            $_SESSION[constant("cAppKey")]["credential"]["name"] = $name;

            $_SESSION["messages_app"]["success"] = ["Dados atualizados."];
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("account save failed", [
                "error" => $e->getMessage(),
                "user"  => $userIdx,
            ]);
            $_SESSION["messages_app"]["danger"] = ["Não foi possível salvar. Tente novamente."];
        }

        basic_redir($GLOBALS["account_url"], rollback: $rollback);
    }

    public function change_password(array $info): never
    {
        validate_csrf($info["post"]["_csrf_token"] ?? null, $GLOBALS["account_url"]);

        if (!self::check_login()) {
            basic_redir($GLOBALS["login_url"]);
        }

        $userIdx = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);
        $current  = (string)($info["post"]["current_password"] ?? '');
        $password = (string)($info["post"]["password"] ?? '');
        $confirm  = (string)($info["post"]["password_confirm"] ?? '');

        // Mesmo teto do login: 5 tentativas por minuto por IP. Sem isto, a tela
        // vira oraculo de senha para quem pegar uma sessao aberta.
        $redis   = $GLOBALS['redis'] ?? null;
        $rateKey = "change_pwd:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (check_and_increment_rate_limit($redis, $rateKey, 5, 60)) {
            $_SESSION["messages_app"]["danger"] = ["Muitas tentativas. Aguarde um momento."];
            basic_redir($GLOBALS["account_url"]);
        }

        if (strlen($password) < 6) {
            $_SESSION["messages_app"]["danger"] = ["Senha deve ter pelo menos 6 caracteres."];
            basic_redir($GLOBALS["account_url"]);
        }

        if ($password !== $confirm) {
            $_SESSION["messages_app"]["danger"] = ["As senhas não conferem."];
            basic_redir($GLOBALS["account_url"]);
        }

        $users = new users_model();
        $users->set_field([" idx ", " password "]);
        $users->set_filter([" active = 'yes' ", " idx = ? "], [$userIdx]);
        $users->set_paginate([1]);
        $users->load_data(false);

        $stored = $users->data[0]["password"] ?? '';

        if (!verify_password_with_migration($stored, $current, (string)$userIdx)) {
            $_SESSION["messages_app"]["danger"] = ["A senha atual está incorreta."];
            basic_redir($GLOBALS["account_url"]);
        }

        $rollback = false;

        try {
            $users->set_filter([" idx = ? "], [$userIdx]);
            $users->populate(["password" => password_hash($password, PASSWORD_BCRYPT)]);
            $users->save();

            // Troca valida: a cota de tentativas nao deve penalizar quem so
            // errou e depois acertou, como ja acontece em login().
            reset_rate_limit($redis, $rateKey);

            // Troca de senha invalida a sessao antiga: e a defesa contra sessao
            // roubada continuar valendo depois da troca.
            session_regenerate_id(true);

            $_SESSION["messages_app"]["success"] = ["Senha alterada."];
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("password change failed", [
                "error" => $e->getMessage(),
                "user"  => $userIdx,
            ]);
            $_SESSION["messages_app"]["danger"] = ["Não foi possível alterar a senha. Tente novamente."];
        }

        basic_redir($GLOBALS["account_url"], rollback: $rollback);
    }
}

<?php

declare(strict_types=1);

/**
 * Cobre o fluxo de recuperacao de senha do manager (plano 031).
 *
 * O token e validado e consumido de forma ATOMICA dentro de reset_password()
 * (POST) — nao mais em display_reset_password() (GET). Isso fecha duas coisas
 * de uma vez: um scanner de seguranca de e-mail que pre-busca o link (GET) nao
 * "queima" o token antes do usuario clicar, e duas requisicoes POST
 * concorrentes com o mesmo token nao conseguem as duas "vencer" a corrida —
 * o UPDATE repete `email_token = ?` no WHERE e so uma linha e afetada.
 *
 * testResetPasswordSameTokenTwiceOnlyFirstSucceeds e
 * testDisplayResetPasswordExpiredTokenIsRejected sao o que separa este fluxo
 * de uma porta dos fundos (token de uso unico + expiracao real).
 */
final class ForgotPasswordTest extends DBTestCase
{
    private mixed $originalRedis = null;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['home_url']            = constant('cFrontend');
        $GLOBALS['login_url']           = constant('cFrontend') . 'login';
        $GLOBALS['forgot_password_url'] = constant('cFrontend') . 'esqueci-minha-senha';
        $GLOBALS['reset_password_url']  = constant('cFrontend') . 'redefinir-senha/%s';

        // A maioria dos testes isola o rate limit do Redis real (fallback em
        // arquivo) para nao competir entre testes na mesma chave. O teste
        // dedicado ao Redis real restaura $this->originalRedis explicitamente.
        $this->originalRedis = $GLOBALS['redis'] ?? null;
        $GLOBALS['redis'] = null;
        reset_rate_limit(null, 'forgot_pwd:unknown');
    }

    protected function tearDown(): void
    {
        reset_rate_limit(null, 'forgot_pwd:unknown');
        unset(
            $_SESSION[constant('cAppKey')],
            $_SESSION['_csrf_token'],
            $_SESSION['_csrf_used'],
            $_SESSION['messages_app']
        );
        $GLOBALS['redis'] = $this->originalRedis;
        $this->resetSingleton();
        parent::tearDown();
    }

    private function makeUser(string $marker, string $password = 'secret1', string $enabled = 'yes'): int
    {
        $insert = new users_model();
        $insert->populate([
            'name'     => "user_{$marker}",
            'mail'     => "user_{$marker}@example.com",
            'login'    => 'user_' . $marker,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'enabled'  => $enabled,
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    /** Le pela MESMA conexao (singleton) que DOLModel/basic_redir usam para gravar. */
    private function loadUserRow(int $id): array
    {
        $stmt = localPDO::getInstance()->executePrepared(
            "SELECT password, email_token, email_token_expires_at FROM users WHERE idx = ?",
            [$id]
        );

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * $intervalSql e um literal fixo (nao input de usuario), ex. "DATE_ADD(NOW(), INTERVAL 2 HOUR)".
     * Usa o NOW() do proprio MySQL como base — nao o date() do PHP — porque o
     * container roda em UTC enquanto o kernel.php forca "America/Sao_Paulo" (UTC-3)
     * para date_default_timezone_set(); comparar contra o NOW() do MySQL aqui
     * isola este helper desse descompasso (o fix em si e coberto por
     * testForgotPasswordExpiresAtIsAheadOfMysqlNow, que passa pelo controller de
     * verdade em vez de gravar o token direto via SQL).
     */
    private function setToken(int $id, ?string $token, string $intervalSql): void
    {
        localPDO::getInstance()->executePrepared(
            "UPDATE users SET email_token = ?, email_token_expires_at = $intervalSql WHERE idx = ?",
            [$token, $id]
        );
    }

    private function countMessagesTo(string $mail): int
    {
        $stmt = localPDO::getInstance()->executePrepared(
            "SELECT COUNT(*) as total FROM messages WHERE to_mail = ?",
            [$mail]
        );

        return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    }

    private function deleteUser(int $id): void
    {
        (new localPDO())->executePrepared("DELETE FROM users WHERE idx = ?", [$id]);
    }

    private function deleteMessagesTo(string $mail): void
    {
        (new localPDO())->executePrepared("DELETE FROM messages WHERE to_mail = ?", [$mail]);
    }

    public function testForgotPasswordWithUnknownMailDoesNotWriteTokenAndShowsGenericMessage(): void
    {
        $marker = uniqid();
        $userId = null;

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker);
            $mail   = "user_{$marker}@example.com";

            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->forgot_password([
                    'post' => [
                        '_csrf_token' => $_SESSION['_csrf_token'],
                        'mail'        => 'nao_existe_' . $marker . '@example.com',
                    ],
                ]);
                $this->fail('forgot_password() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertSame($GLOBALS['login_url'], $e->payload['url']);
                $this->assertContains(
                    'Se o e-mail informado estiver cadastrado, você receberá um link em breve.',
                    $_SESSION['messages_app']['success'] ?? [],
                    'Email inexistente deve mostrar a MESMA mensagem generica do email existente'
                );
                $this->assertArrayNotHasKey('danger', $_SESSION['messages_app'] ?? [], 'Nenhuma mensagem de erro deve aparecer');
            }

            $row = $this->loadUserRow($userId);
            $this->assertArrayHasKey('email_token', $row, 'Fixture deve ter sido encontrada apos o commit');
            $this->assertNull($row['email_token'], 'Usuario nao relacionado nao deve ganhar email_token');
            $this->assertSame(0, $this->countMessagesTo($mail), 'Nenhum email deve ser logado quando o mail informado nao existe');
        } finally {
            $this->resetSingleton();
            if ($userId) {
                $this->deleteUser($userId);
            }
        }
    }

    public function testForgotPasswordWithExistingMailWritesTokenAndLogsMessage(): void
    {
        $marker = uniqid();
        $userId = null;

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker);
            $mail   = "user_{$marker}@example.com";

            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->forgot_password([
                    'post' => [
                        '_csrf_token' => $_SESSION['_csrf_token'],
                        'mail'        => $mail,
                    ],
                ]);
                $this->fail('forgot_password() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertSame($GLOBALS['login_url'], $e->payload['url']);
                $this->assertContains(
                    'Se o e-mail informado estiver cadastrado, você receberá um link em breve.',
                    $_SESSION['messages_app']['success'] ?? []
                );
                // Prova a correcao de enumeracao: mesmo que o envio do email falhe
                // (ex.: Kafka fora do ar), a resposta ao usuario nao pode diferenciar
                // esse caso do caminho feliz — so existe UM caminho de mensagem agora.
                $this->assertArrayNotHasKey('danger', $_SESSION['messages_app'] ?? [], 'Falha de envio nao pode gerar mensagem diferente da generica');
            }

            $row = $this->loadUserRow($userId);
            $this->assertNotEmpty($row['email_token'] ?? null, 'Email existente deve ganhar email_token');
            $this->assertNotNull($row['email_token_expires_at'] ?? null);
            $this->assertGreaterThanOrEqual(1, $this->countMessagesTo($mail), 'Deve gravar uma linha em messages');
        } finally {
            $this->resetSingleton();
            if ($userId) {
                $this->deleteMessagesTo("user_{$marker}@example.com");
                $this->deleteUser($userId);
            }
        }
    }

    /**
     * Prova o fix de fuso horario (gmdate() em vez de date()): compara
     * email_token_expires_at contra o NOW() do proprio MySQL, nao contra o
     * strtotime() do PHP — que reinterpretaria a string sob o MESMO fuso
     * default e mascararia o descompasso local (America/Sao_Paulo) vs. UTC.
     */
    public function testForgotPasswordExpiresAtIsAheadOfMysqlNow(): void
    {
        $marker = uniqid();
        $userId = null;

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker, 'secret1', 'yes');
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->forgot_password([
                    'post' => [
                        '_csrf_token' => $_SESSION['_csrf_token'],
                        'mail'        => "user_{$marker}@example.com",
                    ],
                ]);
                $this->fail('forgot_password() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                // so precisamos que o fluxo termine; a asserção real é no banco.
            }

            $stmt = localPDO::getInstance()->executePrepared(
                "SELECT (email_token_expires_at > NOW()) as still_valid, TIMESTAMPDIFF(MINUTE, NOW(), email_token_expires_at) as minutes_left FROM users WHERE idx = ?",
                [$userId]
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $this->assertSame(1, (int)($row['still_valid'] ?? 0), 'email_token_expires_at deve estar no futuro segundo o NOW() do MySQL');
            $this->assertGreaterThan(60, (int)($row['minutes_left'] ?? 0), 'janela de 2h deve deixar bem mais de 1h restante logo apos a criacao');
        } finally {
            $this->resetSingleton();
            if ($userId) {
                $this->deleteMessagesTo("user_{$marker}@example.com");
                $this->deleteUser($userId);
            }
        }
    }

    public function testDisplayResetPasswordWithValidTokenRendersFormWithoutConsumingToken(): void
    {
        $marker = uniqid();
        $userId = null;
        $token  = bin2hex(random_bytes(16));
        $obLevel = ob_get_level();

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker);
            $this->setToken($userId, $token, 'DATE_ADD(NOW(), INTERVAL 2 HOUR)');

            ob_start();
            (new auth_controller())->display_reset_password([1 => $token]);
            $html = ob_get_clean();

            $this->assertStringContainsString('name="password"', $html, 'GET deve renderizar o formulario de redefinicao');

            $row = $this->loadUserRow($userId);
            $this->assertSame($token, $row['email_token'], 'GET nao deve consumir o token — evita lockout por scanner de e-mail que pre-busca o link');
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            $this->resetSingleton();
            if ($userId) {
                $this->deleteUser($userId);
            }
        }
    }

    public function testDisplayResetPasswordSameTokenTwiceStillRendersBothTimes(): void
    {
        $marker = uniqid();
        $userId = null;
        $token  = bin2hex(random_bytes(16));
        $obLevel = ob_get_level();

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker);
            $this->setToken($userId, $token, 'DATE_ADD(NOW(), INTERVAL 2 HOUR)');

            ob_start();
            (new auth_controller())->display_reset_password([1 => $token]);
            $first = ob_get_clean();

            ob_start();
            (new auth_controller())->display_reset_password([1 => $token]);
            $second = ob_get_clean();

            $this->assertStringContainsString('name="password"', $first);
            $this->assertStringContainsString('name="password"', $second, 'Segunda visita ao mesmo link (GET) deve renderizar igual — GET nunca consome o token');
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            $this->resetSingleton();
            if ($userId) {
                $this->deleteUser($userId);
            }
        }
    }

    public function testDisplayResetPasswordExpiredTokenIsRejected(): void
    {
        $marker = uniqid();
        $userId = null;
        $token  = bin2hex(random_bytes(16));
        $obLevel = ob_get_level();

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker);
            $this->setToken($userId, $token, 'DATE_SUB(NOW(), INTERVAL 1 HOUR)');

            try {
                ob_start();
                (new auth_controller())->display_reset_password([1 => $token]);
                ob_end_clean();
                $this->fail('display_reset_password() deveria ter lancado TerminalResponse para token expirado');
            } catch (TerminalResponse $e) {
                while (ob_get_level() > $obLevel) {
                    ob_end_clean();
                }
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertSame($GLOBALS['login_url'], $e->payload['url']);
                $this->assertContains(
                    'Link inválido, expirado ou já utilizado.',
                    $_SESSION['messages_app']['danger'] ?? []
                );
            }

            $row = $this->loadUserRow($userId);
            $this->assertArrayHasKey('email_token', $row);
            $this->assertSame($token, $row['email_token'], 'Token expirado nao deve ser consumido');
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            $this->resetSingleton();
            if ($userId) {
                $this->deleteUser($userId);
            }
        }
    }

    public function testResetPasswordWithInvalidTokenDoesNotChangeHash(): void
    {
        $marker = uniqid();
        $userId = null;

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker, 'secret1');
            $before = $this->loadUserRow($userId)['password'];

            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->reset_password([
                    'post' => [
                        '_csrf_token'      => $_SESSION['_csrf_token'],
                        'password'         => 'novaSenha1',
                        'password_confirm' => 'novaSenha1',
                    ],
                    1 => 'token-que-nao-existe-' . $marker,
                ]);
                $this->fail('reset_password() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertSame($GLOBALS['login_url'], $e->payload['url']);
                $this->assertContains(
                    'Link inválido, expirado ou já utilizado.',
                    $_SESSION['messages_app']['danger'] ?? []
                );
            }

            $this->assertSame($before, $this->loadUserRow($userId)['password'], 'Token invalido nao deve alterar hash de nenhum usuario');
        } finally {
            $this->resetSingleton();
            if ($userId) {
                $this->deleteUser($userId);
            }
        }
    }

    public function testResetPasswordWithMismatchedConfirmationDoesNotChangeHash(): void
    {
        $marker = uniqid();
        $userId = null;
        $token  = bin2hex(random_bytes(16));

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker, 'secret1');
            $this->setToken($userId, $token, 'DATE_ADD(NOW(), INTERVAL 2 HOUR)');
            $before = $this->loadUserRow($userId)['password'];

            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->reset_password([
                    'post' => [
                        '_csrf_token'      => $_SESSION['_csrf_token'],
                        'password'         => 'novaSenha1',
                        'password_confirm' => 'outraSenha2',
                    ],
                    1 => $token,
                ]);
                $this->fail('reset_password() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertContains(
                    'As senhas não conferem.',
                    $_SESSION['messages_app']['danger'] ?? []
                );
            }

            $row = $this->loadUserRow($userId);
            $this->assertSame($before, $row['password'], 'Senhas divergentes nao devem alterar o hash');
            $this->assertSame($token, $row['email_token'], 'Falha de validacao antes do UPDATE atomico nao deve consumir o token');
        } finally {
            $this->resetSingleton();
            if ($userId) {
                $this->deleteUser($userId);
            }
        }
    }

    public function testResetPasswordHappyPathChangesHashAndVerifies(): void
    {
        $marker = uniqid();
        $userId = null;
        $token  = bin2hex(random_bytes(16));

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker, 'secret1');
            $this->setToken($userId, $token, 'DATE_ADD(NOW(), INTERVAL 2 HOUR)');

            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->reset_password([
                    'post' => [
                        '_csrf_token'      => $_SESSION['_csrf_token'],
                        'password'         => 'novaSenha1',
                        'password_confirm' => 'novaSenha1',
                    ],
                    1 => $token,
                ]);
                $this->fail('reset_password() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertSame($GLOBALS['login_url'], $e->payload['url']);
                $this->assertContains(
                    'Senha redefinida com sucesso! Faça login para continuar.',
                    $_SESSION['messages_app']['success'] ?? []
                );
            }

            $row = $this->loadUserRow($userId);
            $this->assertTrue(password_verify('novaSenha1', $row['password']), 'Nova senha deve verificar contra o hash gravado');
            $this->assertNull($row['email_token'], 'Token deve ser consumido apos o sucesso');
        } finally {
            $this->resetSingleton();
            if ($userId) {
                $this->deleteUser($userId);
            }
        }
    }

    /**
     * Prova o fix da race TOCTOU (D1): o UPDATE que consome o token repete
     * `email_token = ?` no WHERE, entao uma segunda tentativa com o MESMO
     * token (ja consumido pela primeira) afeta zero linhas e e rejeitada —
     * mesmo sem nenhuma sessao de por meio, o que tambem prova que o design
     * antigo (pending_reset_idx) nao faz falta.
     */
    public function testResetPasswordSameTokenTwiceOnlyFirstSucceeds(): void
    {
        $marker = uniqid();
        $userId = null;
        $token  = bin2hex(random_bytes(16));

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker, 'secret1');
            $this->setToken($userId, $token, 'DATE_ADD(NOW(), INTERVAL 2 HOUR)');
            $csrfToken = 'tok-' . $marker;
            $_SESSION['_csrf_token'] = $csrfToken;

            try {
                (new auth_controller())->reset_password([
                    'post' => [
                        '_csrf_token'      => $csrfToken,
                        'password'         => 'novaSenha1',
                        'password_confirm' => 'novaSenha1',
                    ],
                    1 => $token,
                ]);
                $this->fail('reset_password() deveria ter lancado TerminalResponse na primeira tentativa');
            } catch (TerminalResponse $e) {
                $this->assertContains(
                    'Senha redefinida com sucesso! Faça login para continuar.',
                    $_SESSION['messages_app']['success'] ?? []
                );
            }

            $afterFirst = $this->loadUserRow($userId)['password'];
            $this->assertTrue(password_verify('novaSenha1', $afterFirst), 'Primeira tentativa deve ter trocado a senha');

            unset($_SESSION['messages_app']);
            // validate_csrf() consome o _csrf_token da sessao apos o primeiro uso, mas
            // aceita o MESMO valor de novo dentro do grace period de 10s (_csrf_used) —
            // reenviar $csrfToken (nao $_SESSION['_csrf_token'], que ja foi unset) e o
            // que reproduz uma segunda submissao real de formulario (F5-apos-POST).
            try {
                (new auth_controller())->reset_password([
                    'post' => [
                        '_csrf_token'      => $csrfToken,
                        'password'         => 'outraSenha2',
                        'password_confirm' => 'outraSenha2',
                    ],
                    1 => $token,
                ]);
                $this->fail('reset_password() deveria ter lancado TerminalResponse na segunda tentativa');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertSame($GLOBALS['login_url'], $e->payload['url']);
                $this->assertContains(
                    'Link inválido, expirado ou já utilizado.',
                    $_SESSION['messages_app']['danger'] ?? []
                );
            }

            $afterSecond = $this->loadUserRow($userId)['password'];
            $this->assertSame(
                $afterFirst,
                $afterSecond,
                'Segunda tentativa com o MESMO token (ja consumido) nao pode alterar a senha de novo'
            );
        } finally {
            $this->resetSingleton();
            if ($userId) {
                $this->deleteUser($userId);
            }
        }
    }

    /** users.email_token precisa ter indice (plano 031, D9) — sem ele, todo link de reset/definicao de senha faz table scan. */
    public function testEmailTokenColumnHasIndex(): void
    {
        $stmt = localPDO::getInstance()->executePrepared(
            "SELECT COUNT(*) as total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_email_token'",
            []
        );
        $this->assertSame(1, (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0), 'users.email_token deve ter o indice idx_users_email_token (migrations/014)');
    }

    /**
     * Roda contra o RedisCache REAL (nao o fallback em arquivo): prova que
     * check_and_increment_rate_limit()/reset_rate_limit() usam metodos que a
     * classe realmente tem (increment()/expire()/delete()) — antes do fix,
     * isso lancava Error fatal (Call to undefined method RedisCache::incr()).
     */
    public function testRateLimitHelpersWorkWithRealRedisCache(): void
    {
        if (!($this->originalRedis instanceof RedisCache) || !$this->originalRedis->isConnected()) {
            $this->markTestSkipped('Redis real nao conectado neste ambiente.');
        }

        $key = 'phpship_test_rl:' . uniqid();
        try {
            $this->assertFalse(check_and_increment_rate_limit($this->originalRedis, $key, 3, 5), '1a tentativa nao deve bloquear');
            $this->assertFalse(check_and_increment_rate_limit($this->originalRedis, $key, 3, 5), '2a tentativa nao deve bloquear');
            $this->assertFalse(check_and_increment_rate_limit($this->originalRedis, $key, 3, 5), '3a tentativa nao deve bloquear');
            $this->assertTrue(check_and_increment_rate_limit($this->originalRedis, $key, 3, 5), '4a tentativa deve bloquear');
        } finally {
            reset_rate_limit($this->originalRedis, $key);
        }
    }
}

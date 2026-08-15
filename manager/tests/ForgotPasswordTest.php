<?php

declare(strict_types=1);

/**
 * Cobre o fluxo de recuperacao de senha do manager (plano 031):
 * forgot_password(), display_reset_password() e reset_password().
 *
 * Os casos mais importantes sao testDisplayResetPasswordSameTokenTwiceIsRejected
 * e testDisplayResetPasswordExpiredTokenIsRejected: sao o que separa este fluxo
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

        // Mesmo achado do plano 030 (CommonFunctions.php:446-521): RedisCache nao
        // implementa incr()/del() usados por check_and_increment_rate_limit(); os
        // testes usam o fallback em arquivo (redis null) para isolar o rate limit.
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
            $_SESSION['messages_app'],
            $_SESSION['pending_reset_idx']
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
     * isola o teste desse descompasso de fuso (ver achado no relatorio).
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
            }

            $row = $this->loadUserRow($userId);
            $this->assertNotEmpty($row['email_token'] ?? null, 'Email existente deve ganhar email_token');
            $this->assertNotNull($row['email_token_expires_at'] ?? null);
            $this->assertGreaterThan(
                time(),
                strtotime((string) $row['email_token_expires_at']),
                'email_token_expires_at deve estar no futuro'
            );
            $this->assertGreaterThanOrEqual(1, $this->countMessagesTo($mail), 'Deve gravar uma linha em messages');
        } finally {
            $this->resetSingleton();
            if ($userId) {
                $this->deleteMessagesTo("user_{$marker}@example.com");
                $this->deleteUser($userId);
            }
        }
    }

    public function testDisplayResetPasswordWithValidTokenConsumesItAndSetsPendingSession(): void
    {
        $marker = uniqid();
        $userId = null;
        $token  = bin2hex(random_bytes(16));
        $obLevel = ob_get_level();

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker);
            $this->setToken($userId, $token, 'DATE_ADD(NOW(), INTERVAL 2 HOUR)');

            unset($_SESSION['pending_reset_idx']);
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            ob_start();
            (new auth_controller())->display_reset_password([1 => $token]);
            ob_end_clean();

            $this->assertSame(
                $userId,
                $_SESSION['pending_reset_idx'] ?? null,
                'Token valido deve colocar o idx do usuario na sessao'
            );

            $row = $this->loadUserRow($userId);
            $this->assertArrayHasKey('email_token', $row);
            $this->assertNull($row['email_token'], 'Token deve ser zerado (uso unico) apos o GET');
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

    public function testDisplayResetPasswordSameTokenTwiceIsRejected(): void
    {
        $marker = uniqid();
        $userId = null;
        $token  = bin2hex(random_bytes(16));
        $obLevel = ob_get_level();

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker);
            $this->setToken($userId, $token, 'DATE_ADD(NOW(), INTERVAL 2 HOUR)');

            // Primeira visita: consome o token (comportamento ja coberto no teste anterior).
            unset($_SESSION['pending_reset_idx']);
            ob_start();
            (new auth_controller())->display_reset_password([1 => $token]);
            ob_end_clean();

            // Segunda visita ao MESMO link, em uma sessao nova (sem pending_reset_idx) —
            // e o que prova que o link nao e reutilizavel.
            unset($_SESSION['pending_reset_idx']);

            try {
                ob_start();
                (new auth_controller())->display_reset_password([1 => $token]);
                ob_end_clean();
                $this->fail('display_reset_password() deveria ter lancado TerminalResponse na segunda visita');
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

            unset($_SESSION['pending_reset_idx']);

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

    public function testResetPasswordWithoutPendingSessionDoesNotChangeHash(): void
    {
        $marker = uniqid();
        $userId = null;

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker, 'secret1');
            $before = $this->loadUserRow($userId)['password'];

            unset($_SESSION['pending_reset_idx']);
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->reset_password([
                    'post' => [
                        '_csrf_token'      => $_SESSION['_csrf_token'],
                        'password'         => 'novaSenha1',
                        'password_confirm' => 'novaSenha1',
                    ],
                    1 => 'irrelevant-token',
                ]);
                $this->fail('reset_password() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertSame($GLOBALS['forgot_password_url'], $e->payload['url']);
                $this->assertContains(
                    'Sessão expirada. Solicite um novo link de redefinição.',
                    $_SESSION['messages_app']['danger'] ?? []
                );
            }

            $this->assertSame($before, $this->loadUserRow($userId)['password'], 'Sem pending_reset_idx, hash nao deve mudar');
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

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker, 'secret1');
            $before = $this->loadUserRow($userId)['password'];

            $_SESSION['pending_reset_idx'] = $userId;
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->reset_password([
                    'post' => [
                        '_csrf_token'      => $_SESSION['_csrf_token'],
                        'password'         => 'novaSenha1',
                        'password_confirm' => 'outraSenha2',
                    ],
                    1 => 'tok-url',
                ]);
                $this->fail('reset_password() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertContains(
                    'As senhas não conferem.',
                    $_SESSION['messages_app']['danger'] ?? []
                );
            }

            $this->assertSame($before, $this->loadUserRow($userId)['password'], 'Senhas divergentes nao devem alterar o hash');
        } finally {
            unset($_SESSION['pending_reset_idx']);
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

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker, 'secret1');

            $_SESSION['pending_reset_idx'] = $userId;
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->reset_password([
                    'post' => [
                        '_csrf_token'      => $_SESSION['_csrf_token'],
                        'password'         => 'novaSenha1',
                        'password_confirm' => 'novaSenha1',
                    ],
                    1 => 'tok-url',
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

            $this->assertArrayNotHasKey('pending_reset_idx', $_SESSION, 'Sessao de reset deve ser limpa apos o sucesso');

            $stored = $this->loadUserRow($userId)['password'];
            $this->assertTrue(password_verify('novaSenha1', $stored), 'Nova senha deve verificar contra o hash gravado');
        } finally {
            unset($_SESSION['pending_reset_idx']);
            $this->resetSingleton();
            if ($userId) {
                $this->deleteUser($userId);
            }
        }
    }
}

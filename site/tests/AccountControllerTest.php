<?php

declare(strict_types=1);

/**
 * Cobre display_account()/save_account()/change_password() do plano 030 —
 * "Minha conta". O caso mais importante e testActualPasswordWrongDoesNotChangeHash:
 * e o que impede uma sessao sequestrada de trocar a senha sem saber a atual.
 */
final class AccountControllerTest extends DBTestCase
{
    private function makeUser(string $marker, string $password = 'secret1'): int
    {
        $insert = new users_model();
        $insert->populate([
            'name'     => "user_{$marker}",
            'mail'     => "user_{$marker}@example.com",
            'login'    => 'user_' . $marker,
            'password' => password_hash($password, PASSWORD_BCRYPT),
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    /**
     * Le pelo singleton (localPDO::getInstance()), a MESMA conexao que
     * DOLModel usa para gravar. Uma "before" snapshot lida por uma conexao
     * separada (new localPDO()) nao enxergaria a fixture ainda nao commitada
     * — cada transacao/conexao MySQL so ve seus proprios writes pendentes.
     */
    private function storedPassword(int $userId): string
    {
        $stmt = localPDO::getInstance()->executePrepared("SELECT password FROM users WHERE idx = ?", [$userId]);

        return $stmt->fetch(PDO::FETCH_ASSOC)['password'] ?? '';
    }

    private mixed $originalRedis = null;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['account_url'] = constant('cFrontend') . 'minha-conta';
        $GLOBALS['login_url']   = constant('cFrontend') . 'login';
        // RedisCache (achado do plano 030: CommonFunctions.php:446-521 chama
        // $redis->incr()/->del(), que RedisCache nao implementa — so
        // increment()/delete() — bug pre-existente, fora de escopo aqui) nao
        // e compativel com check_and_increment_rate_limit()/reset_rate_limit().
        // Testes usam o fallback em arquivo (redis null) para isolar o rate
        // limit de change_password() sem depender desse bug.
        $this->originalRedis = $GLOBALS['redis'] ?? null;
        $GLOBALS['redis'] = null;
        reset_rate_limit(null, 'change_pwd:unknown');
    }

    protected function tearDown(): void
    {
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

    public function testSaveAccountWithValidNameUpdatesRowAndSession(): void
    {
        $marker = uniqid();
        $userId = null;

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker);

            $_SESSION[constant('cAppKey')]['credential']['idx']  = $userId;
            $_SESSION[constant('cAppKey')]['credential']['name'] = "user_{$marker}";
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->save_account([
                    'post' => ['_csrf_token' => $_SESSION['_csrf_token'], 'name' => "renamed_{$marker}"],
                ]);
                $this->fail('save_account() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertSame($GLOBALS['account_url'], $e->payload['url']);
            }

            $this->assertSame(
                "renamed_{$marker}",
                $_SESSION[constant('cAppKey')]['credential']['name'],
                'save_account() deve atualizar o nome na sessao na mesma resposta'
            );

            $check = new localPDO();
            $stmt  = $check->executePrepared("SELECT name FROM users WHERE idx = ?", [$userId]);
            $this->assertSame("renamed_{$marker}", $stmt->fetch(PDO::FETCH_ASSOC)['name'] ?? null);
        } finally {
            $this->resetSingleton();
            if ($userId) {
                (new localPDO())->executePrepared("DELETE FROM users WHERE idx = ?", [$userId]);
            }
        }
    }

    public function testSaveAccountWithEmptyNameDoesNotSaveAndRedirectsWithError(): void
    {
        $marker = uniqid();
        $userId = null;

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker);

            $_SESSION[constant('cAppKey')]['credential']['idx'] = $userId;
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->save_account([
                    'post' => ['_csrf_token' => $_SESSION['_csrf_token'], 'name' => '   '],
                ]);
                $this->fail('save_account() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertSame($GLOBALS['account_url'], $e->payload['url']);
                $this->assertContains(
                    'Informe seu nome.',
                    $_SESSION['messages_app']['danger'] ?? [],
                    'Nome vazio deve setar mensagem de danger'
                );
            }

            $check = new localPDO();
            $stmt  = $check->executePrepared("SELECT name FROM users WHERE idx = ?", [$userId]);
            $this->assertSame("user_{$marker}", $stmt->fetch(PDO::FETCH_ASSOC)['name'] ?? null, 'Nome original nao deve ter sido alterado');
        } finally {
            $this->resetSingleton();
            if ($userId) {
                (new localPDO())->executePrepared("DELETE FROM users WHERE idx = ?", [$userId]);
            }
        }
    }

    public function testChangePasswordWithWrongCurrentPasswordDoesNotChangeHash(): void
    {
        $marker = uniqid();
        $userId = null;

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker, 'secret1');
            $before = $this->storedPassword($userId);

            $_SESSION[constant('cAppKey')]['credential']['idx'] = $userId;
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->change_password([
                    'post' => [
                        '_csrf_token'       => $_SESSION['_csrf_token'],
                        'current_password'  => 'senha-errada',
                        'password'          => 'novaSenha1',
                        'password_confirm'  => 'novaSenha1',
                    ],
                ]);
                $this->fail('change_password() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertContains(
                    'A senha atual está incorreta.',
                    $_SESSION['messages_app']['danger'] ?? [],
                    'Senha atual errada deve setar mensagem de danger'
                );
            }

            $this->assertSame($before, $this->storedPassword($userId), 'Hash gravado NAO deve mudar quando a senha atual informada esta errada');
        } finally {
            $this->resetSingleton();
            if ($userId) {
                (new localPDO())->executePrepared("DELETE FROM users WHERE idx = ?", [$userId]);
            }
        }
    }

    public function testChangePasswordWithCorrectCurrentAndValidNewChangesHash(): void
    {
        $marker = uniqid();
        $userId = null;

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker, 'secret1');

            $_SESSION[constant('cAppKey')]['credential']['idx'] = $userId;
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->change_password([
                    'post' => [
                        '_csrf_token'       => $_SESSION['_csrf_token'],
                        'current_password'  => 'secret1',
                        'password'          => 'novaSenha1',
                        'password_confirm'  => 'novaSenha1',
                    ],
                ]);
                $this->fail('change_password() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertContains(
                    'Senha alterada.',
                    $_SESSION['messages_app']['success'] ?? [],
                    'Troca valida deve setar mensagem de success'
                );
            }

            $stored = $this->storedPassword($userId);
            $this->assertTrue(password_verify('novaSenha1', $stored), 'Nova senha deve verificar contra o hash gravado');
        } finally {
            $this->resetSingleton();
            if ($userId) {
                (new localPDO())->executePrepared("DELETE FROM users WHERE idx = ?", [$userId]);
            }
        }
    }

    public function testChangePasswordWithMismatchedConfirmationDoesNotChangeHash(): void
    {
        $marker = uniqid();
        $userId = null;

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker, 'secret1');
            $before = $this->storedPassword($userId);

            $_SESSION[constant('cAppKey')]['credential']['idx'] = $userId;
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->change_password([
                    'post' => [
                        '_csrf_token'       => $_SESSION['_csrf_token'],
                        'current_password'  => 'secret1',
                        'password'          => 'novaSenha1',
                        'password_confirm'  => 'outraSenha2',
                    ],
                ]);
                $this->fail('change_password() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertContains(
                    'As senhas não conferem.',
                    $_SESSION['messages_app']['danger'] ?? []
                );
            }

            $this->assertSame($before, $this->storedPassword($userId), 'Hash gravado NAO deve mudar quando a confirmacao nao bate');
        } finally {
            $this->resetSingleton();
            if ($userId) {
                (new localPDO())->executePrepared("DELETE FROM users WHERE idx = ?", [$userId]);
            }
        }
    }

    public function testChangePasswordWithShortNewPasswordDoesNotChangeHash(): void
    {
        $marker = uniqid();
        $userId = null;

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker, 'secret1');
            $before = $this->storedPassword($userId);

            $_SESSION[constant('cAppKey')]['credential']['idx'] = $userId;
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->change_password([
                    'post' => [
                        '_csrf_token'       => $_SESSION['_csrf_token'],
                        'current_password'  => 'secret1',
                        'password'          => 'abcde',
                        'password_confirm'  => 'abcde',
                    ],
                ]);
                $this->fail('change_password() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertContains(
                    'Senha deve ter pelo menos 6 caracteres.',
                    $_SESSION['messages_app']['danger'] ?? []
                );
            }

            $this->assertSame($before, $this->storedPassword($userId), 'Hash gravado NAO deve mudar quando a nova senha tem menos de 6 caracteres');
        } finally {
            $this->resetSingleton();
            if ($userId) {
                (new localPDO())->executePrepared("DELETE FROM users WHERE idx = ?", [$userId]);
            }
        }
    }

    public function testSaveAccountAndChangePasswordWithoutSessionRedirectToLoginAndWriteNothing(): void
    {
        $marker = uniqid();
        $userId = null;

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker, 'secret1');
            $beforeHash = $this->storedPassword($userId);

            unset($_SESSION[constant('cAppKey')]);
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->save_account([
                    'post' => ['_csrf_token' => $_SESSION['_csrf_token'], 'name' => 'nao deveria salvar'],
                ]);
                $this->fail('save_account() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertSame($GLOBALS['login_url'], $e->payload['url'], 'Sem sessao, save_account() deve redirecionar para o login');
            }

            $_SESSION['_csrf_token'] = 'tok2-' . $marker;
            try {
                (new auth_controller())->change_password([
                    'post' => [
                        '_csrf_token'      => $_SESSION['_csrf_token'],
                        'current_password' => 'secret1',
                        'password'         => 'novaSenha1',
                        'password_confirm' => 'novaSenha1',
                    ],
                ]);
                $this->fail('change_password() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertSame($GLOBALS['login_url'], $e->payload['url'], 'Sem sessao, change_password() deve redirecionar para o login');
            }

            $check = new localPDO();
            $stmt  = $check->executePrepared("SELECT name, password FROM users WHERE idx = ?", [$userId]);
            $row   = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->assertSame("user_{$marker}", $row['name'] ?? null, 'Sem sessao, nome nao deve ter sido gravado');
            $this->assertSame($beforeHash, $row['password'] ?? null, 'Sem sessao, senha nao deve ter sido gravada');
        } finally {
            $this->resetSingleton();
            if ($userId) {
                (new localPDO())->executePrepared("DELETE FROM users WHERE idx = ?", [$userId]);
            }
        }
    }
}

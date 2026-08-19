<?php

declare(strict_types=1);

/**
 * Cobre auth_controller::forgot_password() do site (plano 033, achado de
 * Cobertura de Testes): a migração pra Redis Streams removeu o bloco
 * if (!$emailSent) { danger; basic_redir(...) } — a resposta agora é SEMPRE
 * a mensagem genérica, para não vazar enumeração de conta (mesmo padrão já
 * testado no forgot_password do manager, ver manager/tests/ForgotPasswordTest.php).
 * Sem teste, uma regressão nesse comportamento não seria pega. TESTING=true
 * faz EmailQueue::flushPending() cair em EmailQueue::sent() em vez de tocar
 * Redis/SMTP de verdade.
 */
final class ForgotPasswordTest extends DBTestCase
{
    private mixed $originalRedis = null;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['login_url']           = constant('cFrontend') . 'login';
        $GLOBALS['forgot_password_url'] = constant('cFrontend') . 'esqueci-minha-senha';

        // Isola o rate limit do Redis real (fallback em arquivo) pra nao competir
        // entre testes na mesma chave — mesmo padrao de AccountControllerTest.
        $this->originalRedis = $GLOBALS['redis'] ?? null;
        $GLOBALS['redis'] = null;
        reset_rate_limit(null, 'forgot_pwd:unknown');

        EmailQueue::reset();
    }

    protected function tearDown(): void
    {
        reset_rate_limit(null, 'forgot_pwd:unknown');
        EmailQueue::reset();
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

    private function makeUser(string $marker, string $enabled = 'yes'): int
    {
        $insert = new users_model();
        $insert->populate([
            'name'     => "user_{$marker}",
            'mail'     => "user_{$marker}@example.com",
            'login'    => 'user_' . $marker,
            'password' => password_hash('secret1', PASSWORD_BCRYPT),
            'enabled'  => $enabled,
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    private function loadUserRow(int $id): array
    {
        $stmt = localPDO::getInstance()->executePrepared(
            "SELECT email_token, email_token_expires_at FROM users WHERE idx = ?",
            [$id]
        );

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function deleteUser(int $id): void
    {
        (new localPDO())->executePrepared("DELETE FROM users WHERE idx = ?", [$id]);
    }

    private function deleteMessagesTo(string $mail): void
    {
        (new localPDO())->executePrepared("DELETE FROM messages WHERE to_mail = ?", [$mail]);
    }

    public function testForgotPasswordWithExistingMailWritesTokenDispatchesEmailAndShowsGenericMessage(): void
    {
        $marker = uniqid();
        $userId = null;
        $mail   = "user_{$marker}@example.com";

        $this->resetSingleton();
        try {
            $userId = $this->makeUser($marker, 'yes');
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
                // Mesmo que o despacho do email falhe, a resposta ao usuario nao pode
                // diferenciar esse caso do caminho feliz — so existe UM caminho de mensagem.
                $this->assertArrayNotHasKey('danger', $_SESSION['messages_app'] ?? [], 'Falha de envio nao pode gerar mensagem diferente da generica');
            }

            $row = $this->loadUserRow($userId);
            $this->assertNotEmpty($row['email_token'] ?? null, 'Email existente deve ganhar email_token');
            $this->assertNotNull($row['email_token_expires_at'] ?? null);

            $sent = EmailQueue::sent();
            $this->assertCount(1, $sent, 'Email de redefinicao deve ter sido despachado apos o commit');
            $this->assertSame([$mail], $sent[0]['to']);
        } finally {
            $this->resetSingleton();
            $this->deleteMessagesTo($mail);
            if ($userId) {
                $this->deleteUser($userId);
            }
        }
    }

    public function testForgotPasswordWithUnknownMailShowsSameGenericMessageAndDispatchesNoEmail(): void
    {
        $marker = uniqid();

        $this->resetSingleton();
        try {
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

            $this->assertSame([], EmailQueue::sent(), 'Nenhum email deve ser despachado quando o mail informado nao existe');
        } finally {
            $this->resetSingleton();
        }
    }
}

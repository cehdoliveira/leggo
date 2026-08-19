<?php

declare(strict_types=1);

/**
 * Cobre auth_controller::register() do site (plano 033, achado de Cobertura
 * de Testes): a migração pra Redis Streams removeu o bloco
 * if (!$emailSent) { danger; basic_redir(...) } — o fluxo agora sempre
 * termina em sucesso, independente do resultado do despacho de e-mail.
 * Sem teste, uma regressão nesse comportamento (ex.: alguém reintroduzindo o
 * branch de erro, ou EmailQueue::enqueue() parando de ser chamado) não seria
 * pega. TESTING=true faz EmailQueue::flushPending() cair em
 * EmailQueue::sent() em vez de tocar Redis/SMTP de verdade.
 */
final class RegisterTest extends DBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['register_url'] = constant('cFrontend') . 'cadastro';
        $GLOBALS['login_url']    = constant('cFrontend') . 'login';
        EmailQueue::reset();
    }

    protected function tearDown(): void
    {
        EmailQueue::reset();
        unset(
            $_SESSION[constant('cAppKey')],
            $_SESSION['_csrf_token'],
            $_SESSION['_csrf_used'],
            $_SESSION['messages_app']
        );
        $this->resetSingleton();
        parent::tearDown();
    }

    private function loadUserRow(string $mail): array
    {
        $stmt = localPDO::getInstance()->executePrepared(
            "SELECT idx, enabled, email_token FROM users WHERE mail = ?",
            [$mail]
        );

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function deleteUserByMail(string $mail): void
    {
        (new localPDO())->executePrepared("DELETE FROM users WHERE mail = ?", [$mail]);
    }

    private function deleteMessagesTo(string $mail): void
    {
        (new localPDO())->executePrepared("DELETE FROM messages WHERE to_mail = ?", [$mail]);
    }

    public function testRegisterHappyPathShowsSuccessMessageDispatchesEmailNoDanger(): void
    {
        $marker = uniqid();
        $mail   = "user_{$marker}@example.com";

        $this->resetSingleton();
        try {
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new auth_controller())->register([
                    'post' => [
                        '_csrf_token' => $_SESSION['_csrf_token'],
                        'name'        => "user_{$marker}",
                        'mail'        => $mail,
                        'login'       => 'user_' . $marker,
                    ],
                ]);
                $this->fail('register() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertSame($GLOBALS['login_url'], $e->payload['url']);
                $this->assertContains(
                    'Cadastro realizado! Verifique seu e-mail para ativar sua conta.',
                    $_SESSION['messages_app']['success'] ?? []
                );
                $this->assertArrayNotHasKey('danger', $_SESSION['messages_app'] ?? [], 'Sucesso na criacao nao pode gerar mensagem de erro');
            }

            $row = $this->loadUserRow($mail);
            $this->assertNotEmpty($row, 'Usuario deve ter sido criado');
            $this->assertSame('no', $row['enabled'] ?? null, 'Usuario novo comeca desabilitado ate confirmar o email');
            $this->assertNotEmpty($row['email_token'] ?? null, 'Token de confirmacao deve ter sido gravado');

            // A fila so e despachada DEPOIS do commit (basic_redir); sob TESTING o
            // despacho cai em EmailQueue::sent() em vez de Redis/SMTP real.
            $sent = EmailQueue::sent();
            $this->assertCount(1, $sent, 'Email de confirmacao deve ter sido despachado apos o commit');
            $this->assertSame([$mail], $sent[0]['to']);
        } finally {
            $this->resetSingleton();
            $this->deleteMessagesTo($mail);
            $this->deleteUserByMail($mail);
        }
    }

    public function testRegisterWithDuplicateMailShowsGenericSuccessWithoutCreatingSecondUserOrDispatchingEmail(): void
    {
        $marker = uniqid();
        $mail   = "user_{$marker}@example.com";

        $this->resetSingleton();
        try {
            $existing = new users_model();
            $existing->populate([
                'name'     => "user_{$marker}",
                'mail'     => $mail,
                'login'    => 'user_' . $marker,
                'password' => password_hash('secret1', PASSWORD_BCRYPT),
                'enabled'  => 'yes',
            ]);
            $existing->save();

            $_SESSION['_csrf_token'] = 'tok2-' . $marker;

            try {
                (new auth_controller())->register([
                    'post' => [
                        '_csrf_token' => $_SESSION['_csrf_token'],
                        'name'        => "user_{$marker}_dup",
                        'mail'        => $mail,
                        'login'       => 'user_' . $marker . '_dup',
                    ],
                ]);
                $this->fail('register() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertContains(
                    'Cadastro recebido! Se os dados forem válidos, você receberá um e-mail de confirmação.',
                    $_SESSION['messages_app']['success'] ?? []
                );
                $this->assertArrayNotHasKey('danger', $_SESSION['messages_app'] ?? [], 'Mail duplicado nao pode diferenciar a mensagem (enumeracao)');
            }

            // Nenhum email deve ter sido enfileirado — o fluxo saiu antes do enqueue.
            $this->assertSame([], EmailQueue::sent(), 'Cadastro duplicado nao deve despachar email de confirmacao');
        } finally {
            $this->resetSingleton();
            $this->deleteMessagesTo($mail);
            $this->deleteUserByMail($mail);
        }
    }
}

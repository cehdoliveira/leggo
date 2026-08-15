<?php

declare(strict_types=1);

/**
 * Cobre o plano 026: render_error_page() (CommonFunctions.php) renderiza a
 * tela de erro com o codigo HTTP correto e encerra a resposta lancando
 * TerminalResponse::KIND_ERROR (ver TerminalResponseTest.php para o mecanismo
 * de captura sob PHPUnit).
 *
 * Estende DBTestCase pelo mesmo motivo do TerminalResponseTest: a funcao
 * chama close_request_transaction(), que toca o singleton do localPDO.
 */
final class ErrorPageTest extends DBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSingleton();
        $GLOBALS['home_url']     = '/';
        $GLOBALS['login_url']    = '/login';
        $GLOBALS['register_url'] = '/cadastro';
        $GLOBALS['terms_url']    = '/termos-de-uso';
        $GLOBALS['privacy_url']  = '/politica-de-privacidade';
    }

    protected function tearDown(): void
    {
        $this->resetSingleton();
        parent::tearDown();
    }

    public function testRenderErrorPage404LancaTerminalResponseDeErro(): void
    {
        ob_start();
        try {
            render_error_page(404, 'Página não encontrada', 'O endereço não existe.');
            $this->fail('render_error_page() deveria ter lancado TerminalResponse');
        } catch (TerminalResponse $e) {
            ob_end_clean();
            $this->assertSame(TerminalResponse::KIND_ERROR, $e->kind);
            $this->assertSame(404, $e->payload['code']);
        }
    }

    public function testRenderErrorPage403LancaTerminalResponseDeErro(): void
    {
        ob_start();
        try {
            render_error_page(403, 'Acesso negado', 'Sem permissão para esta área.');
            $this->fail('render_error_page() deveria ter lancado TerminalResponse');
        } catch (TerminalResponse $e) {
            ob_end_clean();
            $this->assertSame(TerminalResponse::KIND_ERROR, $e->kind);
            $this->assertSame(403, $e->payload['code']);
        }
    }

    public function testRenderErrorPageImprimeCodigoETitulo(): void
    {
        ob_start();
        try {
            render_error_page(404, 'Página não encontrada', 'O endereço não existe.');
            $this->fail('render_error_page() deveria ter lancado TerminalResponse');
        } catch (TerminalResponse $e) {
            $body = ob_get_clean();
            $this->assertStringContainsString('404', $body);
            $this->assertStringContainsString('Página não encontrada', $body);
        }
    }

    public function testRenderErrorPageEscapaTitulo(): void
    {
        ob_start();
        try {
            render_error_page(404, '<script>alert(1)</script>', 'msg');
            $this->fail('render_error_page() deveria ter lancado TerminalResponse');
        } catch (TerminalResponse $e) {
            $body = ob_get_clean();
            $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
            $this->assertStringContainsString('&lt;script&gt;', $body);
        }
    }
}

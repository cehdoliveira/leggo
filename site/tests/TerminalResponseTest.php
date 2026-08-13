<?php

declare(strict_types=1);

/**
 * Cobre o mecanismo do plano 009: basic_redir()/json_response()/array_to_csv()
 * lancam TerminalResponse (em vez de exit()) sob a constante TESTING, para que
 * os caminhos terminais dos controllers virem testaveis.
 *
 * Estende DBTestCase porque os tres helpers chamam close_request_transaction(),
 * que toca o singleton do localPDO (ver CommitGateTest.php). O singleton e
 * resetado em setUp/tearDown pelo mesmo motivo do CommitGateTest: sem isso, a
 * transacao commitada por um caso vaza para o proximo caso do mesmo processo.
 */
final class TerminalResponseTest extends DBTestCase
{
    private function resetSingleton(): void
    {
        $prop = new ReflectionProperty(localPDO::class, 'instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSingleton();
    }

    protected function tearDown(): void
    {
        $this->resetSingleton();
        parent::tearDown();
    }

    public function testBasicRedirLancaTerminalResponseDeRedirect(): void
    {
        try {
            basic_redir('/x');
            $this->fail('basic_redir() deveria ter lancado TerminalResponse');
        } catch (TerminalResponse $e) {
            $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
            $this->assertSame('/x', $e->payload['url']);
        }
    }

    public function testBasicRedirComRollbackMarcaPayload(): void
    {
        try {
            basic_redir('/x', rollback: true);
            $this->fail('basic_redir() deveria ter lancado TerminalResponse');
        } catch (TerminalResponse $e) {
            $this->assertTrue($e->payload['rollback']);
        }
    }

    public function testJsonResponseLancaTerminalResponseDeJson(): void
    {
        ob_start();
        try {
            json_response(['a' => 1]);
            $this->fail('json_response() deveria ter lancado TerminalResponse');
        } catch (TerminalResponse $e) {
            $body = ob_get_clean();
            $this->assertSame(TerminalResponse::KIND_JSON, $e->kind);
            $this->assertSame(200, $e->payload['code']);
            // a_walk()/toUtf8() converte todo valor escalar para string antes do
            // json_encode — comportamento pre-existente de json_response(),
            // nao introduzido por este plano. Por isso o corpo sai com "1"
            // (string), nao 1 (numero).
            $this->assertSame('{"a":"1"}', $body);
        }
    }

    public function testJsonResponseComCodigoDeErroMarcaPayload(): void
    {
        ob_start();
        try {
            json_response(['e' => 1], 500);
            $this->fail('json_response() deveria ter lancado TerminalResponse');
        } catch (TerminalResponse $e) {
            ob_get_clean();
            $this->assertSame(500, $e->payload['code']);
        }
    }

    public function testArrayToCsvComDadosVaziosLancaTerminalResponseDeCsv(): void
    {
        ob_start();
        try {
            array_to_csv([]);
            $this->fail('array_to_csv() deveria ter lancado TerminalResponse');
        } catch (TerminalResponse $e) {
            ob_get_clean();
            $this->assertSame(TerminalResponse::KIND_CSV, $e->kind);
            $this->assertSame(0, $e->payload['rows']);
        }
    }

    public function testArrayToCsvComDadosContaLinhasEImprimeCorpo(): void
    {
        ob_start();
        try {
            array_to_csv([['a' => 1], ['a' => 2]], 'x.csv', ['a']);
            $this->fail('array_to_csv() deveria ter lancado TerminalResponse');
        } catch (TerminalResponse $e) {
            $body = ob_get_clean();
            $this->assertSame(2, $e->payload['rows']);
            $this->assertStringContainsString("1\n", $body);
            $this->assertStringContainsString("2\n", $body);
        }
    }

    public function testTerminalResponseEscapaDeCatchException(): void
    {
        $this->expectException(TerminalResponse::class);
        try {
            basic_redir('/x');
        } catch (RuntimeException $e) {
            $this->fail('RuntimeException nao deveria capturar TerminalResponse');
        } catch (Exception $e) {
            $this->fail('Exception nao deveria capturar TerminalResponse');
        }
    }
}

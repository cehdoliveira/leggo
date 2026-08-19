<?php

declare(strict_types=1);

/**
 * Cobre o desenho da fila de e-mail (plano 033): enqueue() so bufferiza em
 * memoria, o despacho para o stream so acontece DEPOIS do commit da
 * transacao global (basic_redir / close_request_transaction), e um rollback
 * descarta o buffer sem nunca despachar.
 *
 * testRollbackDescartaOBuffer e testBasicRedirComRollbackDescarta sao os que
 * fecham o defeito original: enfileirar dentro da transacao, antes do
 * commit, mandava e-mail para uma operacao que podia nunca ter existido.
 *
 * Sob TESTING, EmailQueue::flushPending() nao toca em Redis nem em SMTP —
 * os itens despachados vao para EmailQueue::sent(), que os testes inspecionam.
 */
final class EmailQueueTest extends DBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EmailQueue::reset();
    }

    protected function tearDown(): void
    {
        EmailQueue::reset();
        parent::tearDown();
    }

    public function testEnqueueApenasBufferiza(): void
    {
        EmailQueue::enqueue('user@example.com', 'Assunto', 'Corpo');

        $pending = EmailQueue::pending();
        $this->assertCount(1, $pending, 'enqueue() deve acumular no buffer');
        $this->assertSame(['user@example.com'], $pending[0]['to']);
        $this->assertSame('Assunto', $pending[0]['subject']);
        $this->assertSame('Corpo', $pending[0]['body']);
        $this->assertSame([], EmailQueue::sent(), 'Nada pode ser despachado antes do commit');
    }

    public function testCommitDespachaOBuffer(): void
    {
        EmailQueue::enqueue('user@example.com', 'Assunto', 'Corpo');

        $this->resetSingleton();
        close_request_transaction(200);

        $this->assertSame([], EmailQueue::pending(), 'Buffer deve esvaziar apos o commit');
        $sent = EmailQueue::sent();
        $this->assertCount(1, $sent, 'Item deve ter sido despachado apos o commit');
        $this->assertSame(['user@example.com'], $sent[0]['to']);
    }

    public function testRollbackDescartaOBuffer(): void
    {
        EmailQueue::enqueue('user@example.com', 'Assunto', 'Corpo');

        $this->resetSingleton();
        close_request_transaction(500);

        $this->assertSame([], EmailQueue::pending(), 'Buffer deve esvaziar mesmo em rollback');
        $this->assertSame([], EmailQueue::sent(), 'Rollback nunca pode despachar o buffer — e o defeito que este plano fecha');
    }

    public function testBasicRedirComRollbackDescarta(): void
    {
        EmailQueue::enqueue('user@example.com', 'Assunto', 'Corpo');

        $this->resetSingleton();
        try {
            basic_redir('/qualquer', 302, false, true);
            $this->fail('basic_redir() deveria ter lancado TerminalResponse sob TESTING');
        } catch (TerminalResponse $e) {
            // esperado — basic_redir() encerra a request via terminate_request()
        }

        $this->assertSame([], EmailQueue::sent(), 'basic_redir com rollback=true nao pode despachar o buffer');
    }

    public function testPayloadNormalizaDestinatarioUnicoEmArray(): void
    {
        EmailQueue::enqueue('a@b.c', 'Assunto', 'Corpo');

        $pending = EmailQueue::pending();
        $this->assertSame(['a@b.c'], $pending[0]['to'], 'Destinatario unico (string) deve virar array de um item');
    }
}

<?php

declare(strict_types=1);

/**
 * Cobre close_request_transaction() (plano 002) — o gate de commit das
 * respostas terminais que nao passam por basic_redir(). Sem ele, um save()
 * que responde JSON grava e o __destruct() do localPDO desfaz.
 *
 * O teste zera o singleton do localPDO antes de cada caso: assim a transacao
 * commitada contem apenas a fixture deste teste, e nao as fixtures dos testes
 * anteriores do mesmo processo (que vivem na transacao nunca-commitada do
 * singleton compartilhado).
 */
final class CommitGateTest extends DBTestCase
{
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

    public function testFunctionExists(): void
    {
        $this->assertTrue(
            function_exists('close_request_transaction'),
            'close_request_transaction() deve existir em CommonFunctions.php'
        );
    }

    public function testCommitMakesTheWriteVisibleToAnotherConnection(): void
    {
        $slug = 'commit-gate-' . uniqid();

        $insert = new profiles_model();
        $insert->populate(['name' => 'Commit Gate', 'slug' => $slug, 'editabled' => 'yes']);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id);

        // Conexao independente: ainda nao enxerga a escrita nao-commitada.
        $observer = new localPDO();
        $this->assertSame(0, $this->countBySlug($observer, $slug), 'Antes do gate a escrita nao deve estar visivel');

        close_request_transaction(200);

        $this->assertSame(1, $this->countBySlug($observer, $slug), 'Apos o gate com 2xx a escrita deve estar commitada');

        // Limpeza: a linha esta commitada, o rollback do tearDown nao a alcanca.
        $observer->executePrepared("DELETE FROM profiles WHERE slug = ?", [$slug]);
    }

    public function testErrorCodeDiscardsTheWrite(): void
    {
        $slug = 'commit-gate-erro-' . uniqid();

        $insert = new profiles_model();
        $insert->populate(['name' => 'Commit Gate Erro', 'slug' => $slug, 'editabled' => 'yes']);
        $this->assertGreaterThan(0, (int) $insert->save());

        close_request_transaction(500);

        $observer = new localPDO();
        $this->assertSame(0, $this->countBySlug($observer, $slug), 'Codigo >= 400 deve reverter a escrita');
    }

    private function countBySlug(localPDO $con, string $slug): int
    {
        $stmt = $con->executePrepared("SELECT COUNT(*) AS total FROM profiles WHERE slug = ?", [$slug]);

        return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    }
}

<?php

declare(strict_types=1);

/**
 * Prova que a migration 012 criou o vocabulario de capacidades e vinculou
 * TODAS elas ao perfil 'admin'. O seed usa CROSS JOIN filtrado por
 * profiles.slug = 'admin': se o slug nao bater, o INSERT produz zero linhas
 * sem erro de SQL e ninguem recebe capacidade nenhuma. Este teste e a rede
 * contra esse silencio — fatia 1 de plans/016-DESIGN.md.
 */
final class CapabilitiesSchemaTest extends DBTestCase
{
    private const SLUGS = [
        'usuarios.ler',
        'usuarios.escrever',
        'usuarios.atribuir_perfil',
        'emails.ler',
        'perfis.ler',
        'perfis.escrever',
    ];

    public function testSeedRegistraAsSeisCapacidades(): void
    {
        $rows = $this->con->executePrepared(
            "SELECT slug FROM capabilities WHERE active = 'yes'"
        )->fetchAll();

        $slugs = array_column($rows, 'slug');
        foreach (self::SLUGS as $expected) {
            $this->assertContains($expected, $slugs, "Capacidade {$expected} ausente do seed");
        }
    }

    public function testPerfilAdminRecebeTodasAsCapacidades(): void
    {
        $vinculadas = (int) $this->con->executePrepared(
            "SELECT COUNT(*) AS total
               FROM profiles_capabilities pc
               JOIN profiles p ON p.idx = pc.profiles_id
              WHERE p.slug = ? AND pc.active = 'yes'",
            ['admin']
        )->fetch()['total'];

        $total = (int) $this->con->executePrepared(
            "SELECT COUNT(*) AS total FROM capabilities WHERE active = 'yes'"
        )->fetch()['total'];

        $this->assertGreaterThan(0, $total, 'Nenhuma capacidade cadastrada — seed nao rodou');
        $this->assertSame(
            $total,
            $vinculadas,
            'O perfil admin nao tem todas as capacidades — o CROSS JOIN do seed nao casou o slug'
        );
    }

    /**
     * Reexecuta os INSERTs da propria migration 012 e prova que nao duplicam.
     * O DDL nao entra na reexecucao de proposito: CREATE TABLE dispara COMMIT
     * implicito no MySQL e romperia a transacao de isolamento do DBTestCase.
     * Guarda o INSERT IGNORE e as chaves UNIQUE — se alguem remover qualquer
     * um dos dois, uma reexecucao do runner duplica linha em silencio.
     */
    public function testReexecutarOSeedNaoDuplicaLinha(): void
    {
        $antes = $this->contagens();

        foreach ($this->insertsDaMigration() as $sql) {
            $this->con->executePrepared($sql);
        }

        $this->assertSame($antes, $this->contagens(), 'Reexecutar o seed da 012 duplicou linha');
    }

    /**
     * @return array{capabilities: int, profiles_capabilities: int}
     */
    private function contagens(): array
    {
        return [
            'capabilities' => (int) $this->con->executePrepared(
                'SELECT COUNT(*) AS total FROM capabilities'
            )->fetch()['total'],
            'profiles_capabilities' => (int) $this->con->executePrepared(
                'SELECT COUNT(*) AS total FROM profiles_capabilities'
            )->fetch()['total'],
        ];
    }

    /**
     * Extrai do arquivo real da migration so os statements INSERT, aplicando a
     * mesma remocao de comentarios e o mesmo split por ';' do MigrationRunner.
     *
     * @return list<string>
     */
    private function insertsDaMigration(): array
    {
        $arquivo = __DIR__ . '/../../migrations/012_create_capabilities.sql';
        $sql = file_get_contents($arquivo);
        $this->assertIsString($sql, "Migration nao encontrada em {$arquivo}");

        $sql = (string) preg_replace('/(\/\*[\s\S]*?\*\/)|(--[^\n]*)/m', '', $sql);

        $inserts = array_filter(
            array_map('trim', explode(';', $sql)),
            fn (string $q): bool => stripos($q, 'INSERT') === 0
        );

        $this->assertCount(2, $inserts, 'A 012 deveria ter exatamente 2 statements INSERT');

        return array_values($inserts);
    }
}

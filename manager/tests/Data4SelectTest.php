<?php

declare(strict_types=1);

/**
 * Cobre DOLModel::data4select() (plano 001) — mapa chave=>rotulo, uso
 * invertido para traduzir slug em idx, active='yes' sempre mesclado (mesmo
 * quando o chamador nao inclui), e bind de parametros.
 */
final class Data4SelectTest extends DBTestCase
{
    private function makeProfile(string $name, string $slug): int
    {
        $insert = new profiles_model();
        $insert->populate([
            'name'      => $name,
            'slug'      => $slug,
            'editabled' => 'yes',
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    public function testReturnsKeyLabelMap(): void
    {
        $marker = uniqid();
        $idA = $this->makeProfile("Zeta {$marker}", "zeta-{$marker}");
        $idB = $this->makeProfile("Alfa {$marker}", "alfa-{$marker}");

        $map = (new profiles_model())->data4select(
            "idx",
            [" slug LIKE ? "],
            "name",
            ["%{$marker}"]
        );

        $this->assertSame("Zeta {$marker}", $map[$idA] ?? null);
        $this->assertSame("Alfa {$marker}", $map[$idB] ?? null);

        // Ordenacao alfabetica pelo rotulo: Alfa antes de Zeta.
        $this->assertSame([$idB, $idA], array_keys($map), 'Mapa deve vir ordenado pelo rotulo');
    }

    public function testInvertedUseResolvesSlugToIdx(): void
    {
        $marker = uniqid();
        $id = $this->makeProfile("Perfil {$marker}", "perfil-{$marker}");

        $found = (int) current((new profiles_model())->data4select(
            "name",
            [" slug = ? "],
            "idx",
            ["perfil-{$marker}"]
        ));

        $this->assertSame($id, $found, 'Uso invertido deve devolver o idx do registro do slug');
    }

    public function testUnknownSlugReturnsEmptyMap(): void
    {
        $map = (new profiles_model())->data4select(
            "name",
            [" slug = ? "],
            "idx",
            ['slug-que-nao-existe-' . uniqid()]
        );

        $this->assertSame([], $map);
        $this->assertFalse(current($map), 'current() em mapa vazio devolve false — o chamador deve castear');
    }

    public function testAliasedExpressionBecomesTheColumnName(): void
    {
        $marker = uniqid();
        $id = $this->makeProfile("Nome {$marker}", "slug-{$marker}");

        $map = (new profiles_model())->data4select(
            "idx",
            [" idx = ? "],
            "CONCAT(name, ' (', slug, ')') as label",
            [$id]
        );

        $this->assertSame("Nome {$marker} (slug-{$marker})", $map[$id] ?? null);
    }

    public function testParamsAreBoundNotInterpolated(): void
    {
        $marker = uniqid();
        $this->makeProfile("Injecao {$marker}", "injecao-{$marker}");

        // Se o valor fosse concatenado no SQL, isto quebraria a query ou
        // retornaria linhas indevidas. Bindado, e apenas um slug inexistente.
        $map = (new profiles_model())->data4select(
            "name",
            [" slug = ? "],
            "idx",
            ["' OR 1=1 -- "]
        );

        $this->assertSame([], $map, 'Valor malicioso deve ser tratado como dado, nao como SQL');
    }

    public function testExcludesSoftDeletedRowsEvenWithoutExplicitActiveFilter(): void
    {
        $marker = uniqid();
        $id = $this->makeProfile("Removido {$marker}", "removido-{$marker}");

        $toRemove = new profiles_model();
        $toRemove->set_filter(["idx = ?"], [$id]);
        $toRemove->remove();

        // Filtro do chamador nao menciona active — data4select() deve
        // mesclar 'active = yes' incondicionalmente, igual _list_data().
        $map = (new profiles_model())->data4select(
            "idx",
            [" slug = ? "],
            "name",
            ["removido-{$marker}"]
        );

        $this->assertArrayNotHasKey($id, $map, 'Registro soft-deleted nao pode aparecer no mapa mesmo sem filtro active explicito');
    }
}

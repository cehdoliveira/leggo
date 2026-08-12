<?php

declare(strict_types=1);

/**
 * Cobre o filtro por nome usado por profiles_controller::filter() e a
 * resolucao de slug->idx usada por profiles_controller::idx_by_slug() (via
 * DOLModel::data4select() invertido), alem da allowlist de ordenacao usada
 * por profiles_controller::display() — plano 005.
 */
final class ProfilesFilterTest extends DBTestCase
{
    private function makeProfile(string $name, string $slug): int
    {
        $insert = new profiles_model();
        $insert->populate([
            'name' => $name,
            'slug' => $slug,
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    public function testFilterByNameReturnsOnlyMatchingRows(): void
    {
        $marker = uniqid();
        $this->makeProfile("alice_{$marker}_1", "alice-{$marker}-1");
        $this->makeProfile("alice_{$marker}_2", "alice-{$marker}-2");
        $this->makeProfile("bob_{$marker}", "bob-{$marker}");

        $like = '%' . addcslashes("alice_{$marker}", '\\%_') . '%';

        $model = new profiles_model();
        $model->set_field([' idx ', ' name ']);
        $model->set_filter([" active = 'yes' ", " name LIKE ? "], [$like]);
        $model->set_order([' idx ASC ']);
        $model->load_data(false);

        $this->assertCount(2, $model->data, 'Filtro deve retornar apenas as 2 fixtures de alice');
    }

    public function testFilterEscapesLikeWildcards(): void
    {
        $marker = uniqid();
        $name   = "user_{$marker}";
        $this->makeProfile($name, "user-{$marker}");

        // '%' sozinho, se NAO escapado, vira um curinga que casa com qualquer
        // string. Escapado (addcslashes), deve ser tratado como caractere
        // literal — e nenhuma fixture (sem '%' no nome) deve casar.
        $like = '%' . addcslashes('%', '\\%_') . '%';

        $model = new profiles_model();
        $model->set_field([' idx ', ' name ']);
        $model->set_filter([" active = 'yes' ", " name LIKE ? "], [$like]);
        $model->set_order([' idx ASC ']);
        $model->load_data(false);

        $matched = array_column($model->data, 'name');
        $this->assertNotContains($name, $matched, 'Um "%" literal escapado nao deve casar com um perfil sem "%" no nome');
    }

    public function testData4selectInvertedResolvesSlugToIdx(): void
    {
        $marker = uniqid();
        $slug   = "profile-{$marker}";
        $idx    = $this->makeProfile("Perfil {$marker}", $slug);

        $found = (new profiles_model())->data4select(
            "name",
            [" active = 'yes' ", " slug = ? "],
            "idx",
            [$slug]
        );

        $this->assertSame($idx, (int) current($found));
    }

    public function testData4selectInvertedResolvesUnknownSlugToZero(): void
    {
        $found = (new profiles_model())->data4select(
            "name",
            [" active = 'yes' ", " slug = ? "],
            "idx",
            ['slug-que-nao-existe-' . uniqid()]
        );

        $this->assertSame(0, (int) current($found));
    }

    public function testResolveOrdenationFallsBackToDefaultForDisallowedColumn(): void
    {
        $this->assertSame(['name', 'asc'], resolve_ordenation('password-desc', ['name', 'slug', 'created_at']));
    }
}

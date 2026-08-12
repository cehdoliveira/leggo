<?php

declare(strict_types=1);

/**
 * Caracteriza DOLModel::attach() (plano 004): escrito contra a implementacao
 * por linha e mantido apos a troca para queries em lote. Se algum caso aqui
 * mudar de resultado, a otimizacao alterou comportamento observavel.
 */
final class AttachBatchTest extends DBTestCase
{
    private function makeProfile(string $marker, string $suffix): int
    {
        $profile = new profiles_model();
        $profile->populate([
            'name'      => "Perfil {$suffix} {$marker}",
            'slug'      => "perfil-{$suffix}-{$marker}",
            'editabled' => 'yes',
        ]);
        $id = (int) $profile->save();
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    /** @param array<int> $profileIds */
    private function makeUser(string $marker, string $suffix, array $profileIds): int
    {
        $user = new users_model();
        $user->populate([
            'name'     => "User {$suffix} {$marker}",
            'mail'     => "user-{$suffix}-{$marker}@example.com",
            'login'    => "user-{$suffix}-{$marker}",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        $id = (int) $user->save();
        $this->assertGreaterThan(0, $id);

        if ($profileIds !== []) {
            $user->save_attach(['idx' => $id, 'post' => ['profiles_id' => $profileIds]], ['profiles']);
        }

        return $id;
    }

    public function testAttachGroupsRowsByParent(): void
    {
        $marker = uniqid();
        $pA = $this->makeProfile($marker, 'a');
        $pB = $this->makeProfile($marker, 'b');

        $u1 = $this->makeUser($marker, '1', [$pA, $pB]);
        $u2 = $this->makeUser($marker, '2', [$pB]);
        $u3 = $this->makeUser($marker, '3', []);

        $model = new users_model();
        $model->set_field([' idx ', ' name ']);
        $model->set_filter([" idx IN (?, ?, ?) "], [$u1, $u2, $u3]);
        $model->set_order([' idx ASC ']);
        $model->load_data(false);
        $model->attach(['profiles']);

        $byIdx = array_column($model->data, null, 'idx');

        $this->assertCount(3, $model->data, 'attach() nao pode perder nem duplicar linhas');
        $this->assertCount(2, $byIdx[$u1]['profiles_attach'], 'u1 tem 2 perfis');
        $this->assertCount(1, $byIdx[$u2]['profiles_attach'], 'u2 tem 1 perfil');
        $this->assertSame([], $byIdx[$u3]['profiles_attach'], 'u3 sem vinculo recebe array vazio, nao chave ausente');

        $idsU1 = array_column($byIdx[$u1]['profiles_attach'], 'idx');
        sort($idsU1);
        $expected = [$pA, $pB];
        sort($expected);
        $this->assertSame($expected, array_map('intval', $idsU1));
    }

    public function testAttachPreservesRowOrderAndKeys(): void
    {
        $marker = uniqid();
        $p = $this->makeProfile($marker, 'ord');
        $u1 = $this->makeUser($marker, 'o1', [$p]);
        $u2 = $this->makeUser($marker, 'o2', [$p]);

        $model = new users_model();
        $model->set_field([' idx ']);
        $model->set_filter([" idx IN (?, ?) "], [$u1, $u2]);
        $model->set_order([' idx DESC ']);
        $model->load_data(false);
        $before = array_column($model->data, 'idx');
        $model->attach(['profiles']);
        $after = array_column($model->data, 'idx');

        $this->assertSame($before, $after, 'A ordem das linhas deve ser preservada');
        $this->assertSame([0, 1], array_keys($model->data), 'As chaves do array devem ser preservadas');
    }

    public function testAttachRespectsClassFieldAndOptions(): void
    {
        $marker = uniqid();
        $p  = $this->makeProfile($marker, 'campos');
        $u  = $this->makeUser($marker, 'campos', [$p]);

        $model = new users_model();
        $model->set_field([' idx ']);
        $model->set_filter([" idx = ? "], [$u]);
        $model->load_data(false);
        $model->attach(['profiles'], null, ' and idx > 0 ', [' idx ', ' name ']);

        $attached = $model->data[0]['profiles_attach'][0] ?? [];
        $this->assertSame(['idx', 'name'], array_keys($attached), 'class_field deve limitar as colunas');
    }

    public function testAttachIgnoresInactiveLinks(): void
    {
        $marker = uniqid();
        $p = $this->makeProfile($marker, 'inativo');
        $u = $this->makeUser($marker, 'inativo', [$p]);

        // Desativa o vinculo na tabela de juncao (soft-delete, como o framework faz).
        $model = new users_model();
        $model->execute_raw_prepared(
            "UPDATE users_profiles SET active = 'no' WHERE users_id = ? AND profiles_id = ?",
            [$u, $p]
        );

        $model->set_field([' idx ']);
        $model->set_filter([" idx = ? "], [$u]);
        $model->load_data(false);
        $model->attach(['profiles']);

        $this->assertSame([], $model->data[0]['profiles_attach'], 'Vinculo inativo nao deve aparecer');
    }

    public function testAttachOnEmptyResultSetDoesNothing(): void
    {
        $model = new users_model();
        $model->set_field([' idx ']);
        $model->set_filter([" idx = ? "], [-1]);
        $model->load_data(false);
        $model->attach(['profiles']);

        $this->assertSame([], $model->data);
    }

    public function testAttachWorksWhenClassFieldOmitsIdx(): void
    {
        $marker = uniqid();
        $p = $this->makeProfile($marker, 'semidx');
        $u = $this->makeUser($marker, 'semidx', [$p]);

        $model = new users_model();
        $model->set_field([' idx ']);
        $model->set_filter([" idx = ? "], [$u]);
        $model->load_data(false);
        $model->attach(['profiles'], null, null, [' name ']);

        $this->assertCount(1, $model->data[0]['profiles_attach'], 'Vinculo deve aparecer mesmo sem idx em class_field');
    }
}

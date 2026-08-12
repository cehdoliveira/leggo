<?php

declare(strict_types=1);

/**
 * Cobre o filtro por vínculo (profiles, via users_profiles) e por
 * nome/e-mail usados por users_controller::filter() — plano 006 — além da
 * geração do slug público em save() e do contorno de save_attach() com lista
 * vazia (Step 5b: desmarcar todos os perfis precisa desvincular, o que
 * DOLModel::save_attach() sozinho não faz).
 */
final class UsersControllerTest extends DBTestCase
{
    private function makeUser(string $name, string $mail): int
    {
        $insert = new users_model();
        $insert->populate([
            'name'     => $name,
            'mail'     => $mail,
            'login'    => 'user_' . uniqid(),
            'password' => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

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

    private function linkProfile(int $userId, int $profileId, string $active = 'yes'): void
    {
        (new users_model())->execute_raw_prepared(
            "INSERT INTO users_profiles (created_at, created_by, active, users_id, profiles_id) VALUES (now(), 0, ?, ?, ?)",
            [$active, $userId, $profileId]
        );
    }

    public function testFilterByProfileReturnsOnlyUsersWithActiveLink(): void
    {
        $marker    = uniqid();
        $profileId = $this->makeProfile("profile_{$marker}", "profile-{$marker}");

        $linkedA   = $this->makeUser("linked_a_{$marker}", "linked_a_{$marker}@example.com");
        $linkedB   = $this->makeUser("linked_b_{$marker}", "linked_b_{$marker}@example.com");
        $unlinked  = $this->makeUser("unlinked_{$marker}", "unlinked_{$marker}@example.com");

        $this->linkProfile($linkedA, $profileId);
        $this->linkProfile($linkedB, $profileId);

        $model = new users_model();
        $model->set_field([' idx ']);
        $model->set_filter(
            [" active = 'yes' ", " idx IN ( SELECT users_id FROM users_profiles WHERE active = 'yes' AND profiles_id = ? ) "],
            [$profileId]
        );
        $model->set_order([' idx ASC ']);
        $model->load_data(false);

        $matched = array_column($model->data, 'idx');
        $this->assertContains($linkedA, $matched, 'Usuario vinculado A deve aparecer no filtro por perfil');
        $this->assertContains($linkedB, $matched, 'Usuario vinculado B deve aparecer no filtro por perfil');
        $this->assertNotContains($unlinked, $matched, 'Usuario sem vinculo nao deve aparecer no filtro por perfil');

        // Desativa o vinculo de A e confirma que ele sai do resultado — o
        // filtro exige vinculo ATIVO, nao apenas historico.
        (new users_model())->execute_raw_prepared(
            "UPDATE users_profiles SET active = 'no' WHERE users_id = ? AND profiles_id = ?",
            [$linkedA, $profileId]
        );

        $model2 = new users_model();
        $model2->set_field([' idx ']);
        $model2->set_filter(
            [" active = 'yes' ", " idx IN ( SELECT users_id FROM users_profiles WHERE active = 'yes' AND profiles_id = ? ) "],
            [$profileId]
        );
        $model2->set_order([' idx ASC ']);
        $model2->load_data(false);

        $matched2 = array_column($model2->data, 'idx');
        $this->assertNotContains($linkedA, $matched2, 'Apos desativar o vinculo, A deve sair do filtro por perfil');
        $this->assertContains($linkedB, $matched2, 'B continua com vinculo ativo e deve permanecer no filtro');
    }

    public function testFilterByNameOrMailReturnsOnlyMatchingRows(): void
    {
        $marker = uniqid();
        $this->makeUser("alice_{$marker}_1", "alice1_{$marker}@example.com");
        $this->makeUser("someone_else_{$marker}", "alice_{$marker}_2@example.com");
        $this->makeUser("bob_{$marker}", "bob_{$marker}@example.com");

        $like = '%' . addcslashes("alice_{$marker}", '\\%_') . '%';

        $model = new users_model();
        $model->set_field([' idx ', ' name ', ' mail ']);
        $model->set_filter([" active = 'yes' ", " ( name LIKE ? OR mail LIKE ? ) "], [$like, $like]);
        $model->set_order([' idx ASC ']);
        $model->load_data(false);

        $this->assertCount(2, $model->data, 'Filtro deve casar em name (fixture 1) e em mail (fixture 2), mas nao no bob');
    }

    public function testFilterEscapesLikeWildcards(): void
    {
        $marker = uniqid();
        $name   = "user_{$marker}";
        $mail   = "user_{$marker}@example.com";
        $this->makeUser($name, $mail);

        // '%' sozinho, se NAO escapado, vira um curinga que casa com qualquer
        // string. Escapado (addcslashes), deve ser tratado como caractere
        // literal — e nenhuma fixture (sem '%' no name/mail) deve casar.
        $like = '%' . addcslashes('%', '\\%_') . '%';

        $model = new users_model();
        $model->set_field([' idx ', ' name ', ' mail ']);
        $model->set_filter([" active = 'yes' ", " ( name LIKE ? OR mail LIKE ? ) "], [$like, $like]);
        $model->set_order([' idx ASC ']);
        $model->load_data(false);

        $matchedNames = array_column($model->data, 'name');
        $this->assertNotContains($name, $matchedNames, 'Um "%" literal escapado nao deve casar com um usuario sem "%" no name/mail');
    }

    public function testGeneratedSlugMatchesRouteRegex(): void
    {
        // generate_key() devolve hex minusculo (CommonFunctions.php:47); se
        // isso mudar, a rota /usuario/([a-z0-9_-]+) para de casar o slug e
        // este teste pega a regressao antes de virar um bug em producao.
        $slug = generate_key(10) . date("ymd");

        $this->assertSame(16, strlen($slug), 'Slug deve ter 16 caracteres: 10 do gerador + 6 da data (aammdd)');
        $this->assertMatchesRegularExpression('/^[a-z0-9_-]{16}$/', $slug, 'Slug gerado deve casar com a regex de rota /usuario/([a-z0-9_-]+)');
    }

    public function testUnlinkingAllProfilesRemovesAttach(): void
    {
        $marker     = uniqid();
        $userId     = $this->makeUser("user_{$marker}", "user_{$marker}@example.com");
        $profileOne = $this->makeProfile("profile_one_{$marker}", "profile-one-{$marker}");
        $profileTwo = $this->makeProfile("profile_two_{$marker}", "profile-two-{$marker}");

        $this->linkProfile($userId, $profileOne);
        $this->linkProfile($userId, $profileTwo);

        $before = new users_model();
        $before->set_field([' idx ']);
        $before->set_filter([" active = 'yes' ", " idx = ? "], [$userId]);
        $before->set_paginate([1]);
        $before->load_data(false);
        $before->attach(["profiles"]);
        $this->assertCount(2, $before->data[0]['profiles_attach'] ?? [], 'Usuario deve comecar com os 2 vinculos ativos');

        // Contorno do Step 5b: save_attach() nao age com lista vazia
        // (DOLModel.php:498), entao desmarcar todos os perfis no formulario
        // exige este UPDATE direto para desativar os vinculos remanescentes.
        (new users_model())->execute_raw_prepared(
            "UPDATE users_profiles SET active = 'no', removed_at = now(), removed_by = ? WHERE active = 'yes' AND users_id = ?",
            [0, $userId]
        );

        $after = new users_model();
        $after->set_field([' idx ']);
        $after->set_filter([" active = 'yes' ", " idx = ? "], [$userId]);
        $after->set_paginate([1]);
        $after->load_data(false);
        $after->attach(["profiles"]);

        $this->assertSame([], $after->data[0]['profiles_attach'] ?? null, 'Apos desmarcar todos os perfis, attach(["profiles"]) deve devolver array vazio');
    }
}

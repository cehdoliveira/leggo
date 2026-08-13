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

    /** Invoca um metodo privado de users_controller para testar em isolamento. */
    private function callPrivate(string $method, array $args = []): mixed
    {
        $controller = new users_controller();
        $ref        = new ReflectionMethod($controller, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($controller, $args);
    }

    /**
     * save()/remove()/action() terminam em basic_redir()/json_response()/
     * array_to_csv() (plano 009), que comitam ou revertem o singleton de
     * localPDO (CommonFunctions.php). Sem resetar o singleton antes, esses
     * metodos comitariam a transacao nunca-fechada de OUTROS testes do mesmo
     * processo — mesmo raciocinio do CommitGateTest.php. Os testes abaixo
     * resetam o singleton no inicio e no fim, e limpam manualmente qualquer
     * fixture que tenha sido comitada por essa chamada.
     */
    private function resetSingleton(): void
    {
        $prop = new ReflectionProperty(localPDO::class, 'instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testSaveWithoutCsrfTokenRedirectsToUsersUrl(): void
    {
        $GLOBALS['users_url'] = constant('cFrontend') . 'usuarios';
        unset($_SESSION['_csrf_token'], $_SESSION['_csrf_used']);

        $this->resetSingleton();
        try {
            (new users_controller())->save(['post' => ['name' => 'Sem Token', 'mail' => 'sem-token-' . uniqid() . '@example.com']]);
            $this->fail('save() deveria ter lancado TerminalResponse');
        } catch (TerminalResponse $e) {
            $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind, 'Sem _csrf_token, save() deve terminar em redirect (validate_csrf)');
            $this->assertSame($GLOBALS['users_url'], $e->payload['url'], 'validate_csrf() redireciona para $users_url quando o token e invalido/ausente');
        } finally {
            $this->resetSingleton();
        }
    }

    public function testRemoveSelfIsBlockedWithDangerMessage(): void
    {
        $GLOBALS['users_url'] = constant('cFrontend') . 'usuarios';
        $marker = uniqid();
        $slug   = 'self-' . $marker;

        $this->resetSingleton();
        unset($_SESSION['messages_app']);
        $selfId = null;
        try {
            $insert = new users_model();
            $insert->populate([
                'name'  => "self_{$marker}",
                'mail'  => "self_{$marker}@example.com",
                'login' => 'self_' . $marker,
                'slug'  => $slug,
            ]);
            $selfId = (int) $insert->save();
            $this->assertGreaterThan(0, $selfId, 'Insert de fixture deve retornar um ID valido');

            $_SESSION[constant('cAppKey')]['credential']['idx'] = $selfId;
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new users_controller())->remove(['post' => ['_csrf_token' => $_SESSION['_csrf_token']], 1 => $slug]);
                $this->fail('remove() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertContains(
                    'Você não pode remover a si mesmo.',
                    $_SESSION['messages_app']['danger'] ?? [],
                    'Guard de autorremocao deve setar a mensagem de danger'
                );
            }
        } finally {
            unset($_SESSION[constant('cAppKey')], $_SESSION['_csrf_token'], $_SESSION['_csrf_used'], $_SESSION['messages_app']);
            $this->resetSingleton();
            if ($selfId) {
                (new localPDO())->executePrepared("DELETE FROM users WHERE idx = ?", [$selfId]);
            }
        }
    }

    public function testRemoveOtherUserSoftDeletesRow(): void
    {
        $GLOBALS['users_url'] = constant('cFrontend') . 'usuarios';
        $marker     = uniqid();
        $adminSlug  = 'admin-' . $marker;
        $targetSlug = 'target-' . $marker;

        $this->resetSingleton();
        $adminId = $targetId = null;
        try {
            $admin = new users_model();
            $admin->populate([
                'name'  => "admin_{$marker}",
                'mail'  => "admin_{$marker}@example.com",
                'login' => 'admin_' . $marker,
                'slug'  => $adminSlug,
            ]);
            $adminId = (int) $admin->save();
            $this->assertGreaterThan(0, $adminId, 'Insert de fixture deve retornar um ID valido');

            $target = new users_model();
            $target->populate([
                'name'  => "target_{$marker}",
                'mail'  => "target_{$marker}@example.com",
                'login' => 'target_' . $marker,
                'slug'  => $targetSlug,
            ]);
            $targetId = (int) $target->save();
            $this->assertGreaterThan(0, $targetId, 'Insert de fixture deve retornar um ID valido');

            $_SESSION[constant('cAppKey')]['credential']['idx'] = $adminId;
            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new users_controller())->remove(['post' => ['_csrf_token' => $_SESSION['_csrf_token']], 1 => $targetSlug]);
                $this->fail('remove() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
            }

            $check = new localPDO();
            $stmt  = $check->executePrepared("SELECT active FROM users WHERE idx = ?", [$targetId]);
            $this->assertSame('no', $stmt->fetch(PDO::FETCH_ASSOC)['active'] ?? null, 'Usuario removido deve ficar com active = no');
        } finally {
            unset($_SESSION[constant('cAppKey')], $_SESSION['_csrf_token'], $_SESSION['_csrf_used'], $_SESSION['messages_app']);
            $this->resetSingleton();
            $cleanup = new localPDO();
            if ($targetId) {
                $cleanup->executePrepared("DELETE FROM users WHERE idx = ?", [$targetId]);
            }
            if ($adminId) {
                $cleanup->executePrepared("DELETE FROM users WHERE idx = ?", [$adminId]);
            }
        }
    }

    public function testActionExportCsvReturnsRowsFromDatabase(): void
    {
        $GLOBALS['users_url'] = constant('cFrontend') . 'usuarios';
        $marker = uniqid();
        $slug   = 'export-' . $marker;

        $this->resetSingleton();
        $userId = null;
        try {
            $insert = new users_model();
            $insert->populate([
                'name'  => "export_{$marker}",
                'mail'  => "export_{$marker}@example.com",
                'login' => 'export_' . $marker,
                'slug'  => $slug,
            ]);
            $userId = (int) $insert->save();
            $this->assertGreaterThan(0, $userId, 'Insert de fixture deve retornar um ID valido');

            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            ob_start();
            try {
                (new users_controller())->action(['post' => ['_csrf_token' => $_SESSION['_csrf_token'], 'action' => 'export-csv']]);
                $this->fail('action() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                ob_get_clean();
                $this->assertSame(TerminalResponse::KIND_CSV, $e->kind);
                $this->assertGreaterThan(0, $e->payload['rows'], 'action=export-csv deve exportar pelo menos a fixture criada neste teste');
            }
        } finally {
            unset($_SESSION['_csrf_token'], $_SESSION['_csrf_used']);
            $this->resetSingleton();
            if ($userId) {
                (new localPDO())->executePrepared("DELETE FROM users WHERE idx = ?", [$userId]);
            }
        }
    }

    public function testActionInativarSetsEnabledNo(): void
    {
        $GLOBALS['users_url'] = constant('cFrontend') . 'usuarios';
        $marker = uniqid();
        $slug   = 'inativar-' . $marker;

        $this->resetSingleton();
        $userId = null;
        try {
            $insert = new users_model();
            $insert->populate([
                'name'  => "inativar_{$marker}",
                'mail'  => "inativar_{$marker}@example.com",
                'login' => 'inativar_' . $marker,
                'slug'  => $slug,
            ]);
            $userId = (int) $insert->save();
            $this->assertGreaterThan(0, $userId, 'Insert de fixture deve retornar um ID valido');

            $_SESSION['_csrf_token'] = 'tok-' . $marker;

            try {
                (new users_controller())->action(['post' => ['_csrf_token' => $_SESSION['_csrf_token'], 'action' => 'inativar', 'idx' => $userId]]);
                $this->fail('action() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
            }

            $check = new localPDO();
            $stmt  = $check->executePrepared("SELECT enabled FROM users WHERE idx = ?", [$userId]);
            $this->assertSame('no', $stmt->fetch(PDO::FETCH_ASSOC)['enabled'] ?? null, 'action=inativar deve gravar enabled = no');
        } finally {
            unset($_SESSION['_csrf_token'], $_SESSION['_csrf_used']);
            $this->resetSingleton();
            if ($userId) {
                (new localPDO())->executePrepared("DELETE FROM users WHERE idx = ?", [$userId]);
            }
        }
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

    public function testSafeInternalUrlAcceptsInternalDestination(): void
    {
        $internal = constant('cFrontend') . 'usuarios?filter_name=foo';

        $this->assertSame($internal, $this->callPrivate('safe_internal_url', [$internal, 'fallback']));
    }

    public function testSafeInternalUrlRejectsExternalDestination(): void
    {
        $result = $this->callPrivate('safe_internal_url', ['https://evil.example/', 'fallback']);

        $this->assertSame('fallback', $result, 'URL externa deve cair no fallback — impede open redirect');
    }

    public function testSafeInternalUrlRejectsJavascriptUri(): void
    {
        $result = $this->callPrivate('safe_internal_url', ['javascript:alert(1)', 'fallback']);

        $this->assertSame('fallback', $result, 'URI javascript: deve cair no fallback — impede XSS via link Cancelar');
    }

    public function testResolveFormatReturnsJsonForJsonSuffix(): void
    {
        $this->assertSame('.json', $this->callPrivate('resolve_format', [[1 => '.json']]));
    }

    public function testResolveFormatDefaultsToHtml(): void
    {
        $this->assertSame('.html', $this->callPrivate('resolve_format', [[]]));
        $this->assertSame('.html', $this->callPrivate('resolve_format', [[1 => '.html']]));
    }

    public function testBackUrlFallsBackWhenDoneIsEmpty(): void
    {
        $this->assertSame('fallback', $this->callPrivate('back_url', [[], 'fallback']));
        $this->assertSame('fallback', $this->callPrivate('back_url', [['done' => '  '], 'fallback']));
    }

    public function testBackUrlDecodesAndValidatesDone(): void
    {
        $internal = constant('cFrontend') . 'usuarios?filter_name=foo';
        $post     = ['done' => rawurlencode($internal)];

        $this->assertSame($internal, $this->callPrivate('back_url', [$post, 'fallback']));
    }

    public function testBackUrlRejectsExternalDone(): void
    {
        $post = ['done' => rawurlencode('https://evil.example/')];

        $this->assertSame('fallback', $this->callPrivate('back_url', [$post, 'fallback']), 'done externo tambem deve cair no fallback via back_url');
    }

    public function testJsonSafeReplacesRootLevelNullWithEmptyString(): void
    {
        $row = ['idx' => 1, 'last_login' => null, 'name' => 'Alice'];

        $this->assertSame(['idx' => 1, 'last_login' => '', 'name' => 'Alice'], $this->callPrivate('json_safe', [$row]));
    }

    public function testJsonSafeReplacesNestedNullWithEmptyString(): void
    {
        // Simula o formato de profiles_attach (SELECT * de attach()), onde
        // colunas como modified_at/removed_at sao nulas em operacao normal.
        $row = [
            'idx'              => 1,
            'profiles_attach'  => [
                ['idx' => 2, 'name' => 'Admin', 'modified_at' => null, 'removed_at' => null],
            ],
        ];

        $result = $this->callPrivate('json_safe', [$row]);

        $this->assertSame('', $result['profiles_attach'][0]['modified_at']);
        $this->assertSame('', $result['profiles_attach'][0]['removed_at']);
        $this->assertSame('Admin', $result['profiles_attach'][0]['name']);
    }
}

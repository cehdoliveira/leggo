<?php

declare(strict_types=1);

/**
 * Cobre o filtro por nome usado por profiles_controller::filter() e a
 * resolucao de slug->idx usada por profiles_controller::idx_by_slug() (via
 * DOLModel::data4select() invertido), alem da allowlist de ordenacao usada
 * por profiles_controller::display() — plano 005.
 *
 * Tambem cobre, via reflection (metodos privados), o guard anti open-redirect
 * de safe_internal_url()/back_url() e a resolucao de formato .json/.html de
 * display() — achados do /phpship nesta branch. O caminho save(...,
 * no-redirect) e o guard de perfil `editabled = 'no'` (protegido) agora tem
 * teste direto do metodo save() — plano 009 tornou basic_redir()/
 * json_response()/array_to_csv() testaveis (lancam TerminalResponse sob
 * TESTING em vez de exit()), fechando a pendencia registrada aqui.
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

    /** Invoca um metodo privado de profiles_controller para testar em isolamento. */
    private function callPrivate(string $method, array $args = []): mixed
    {
        $controller = new profiles_controller();
        $ref        = new ReflectionMethod($controller, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($controller, $args);
    }

    /**
     * save() termina em json_response()/basic_redir() (plano 009), que
     * comitam ou revertem o singleton de localPDO (CommonFunctions.php). Sem
     * resetar o singleton antes, essas chamadas comitariam a transacao
     * nunca-fechada de OUTROS testes do mesmo processo — mesmo raciocinio do
     * CommitGateTest.php. Os testes abaixo resetam o singleton no inicio e no
     * fim, e limpam manualmente qualquer fixture que tenha sido comitada.
     */
    private function resetSingleton(): void
    {
        $prop = new ReflectionProperty(localPDO::class, 'instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testSaveWithNoRedirectReturnsJsonOk(): void
    {
        $GLOBALS['profiles_url'] = constant('cFrontend') . 'perfis';
        $marker = uniqid();
        $slug   = 'no-redirect-' . $marker;

        $this->resetSingleton();
        $createdId = null;
        try {
            $_SESSION['_csrf_token'] = 'tok-' . $marker;
            $post = [
                '_csrf_token' => $_SESSION['_csrf_token'],
                'name'        => "Perfil {$marker}",
                'slug'        => $slug,
                'no-redirect' => '1',
            ];

            ob_start();
            try {
                (new profiles_controller())->save(['post' => $post]);
                $this->fail('save() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                ob_get_clean();
                $this->assertSame(TerminalResponse::KIND_JSON, $e->kind);
                // a_walk()/toUtf8() converte todo valor escalar do payload de
                // json_response() para string antes do json_encode —
                // comportamento pre-existente, nao introduzido por este plano
                // (mesmo achado documentado em TerminalResponseTest.php).
                $this->assertSame('1', $e->payload['data']['ok'], 'save(..., no-redirect) deve responder ok');
                $createdId = (int) $e->payload['data']['idx'];
                $this->assertGreaterThan(0, $createdId);
            }
        } finally {
            unset($_SESSION['_csrf_token'], $_SESSION['_csrf_used']);
            $this->resetSingleton();
            if ($createdId) {
                (new localPDO())->executePrepared("DELETE FROM profiles WHERE idx = ?", [$createdId]);
            }
        }
    }

    public function testSaveOfEditabledNoProfileIsBlockedWithProtectedMessage(): void
    {
        $GLOBALS['profiles_url'] = constant('cFrontend') . 'perfis';
        $marker = uniqid();
        $slug   = 'protegido-' . $marker;

        $this->resetSingleton();
        unset($_SESSION['messages_app']);
        $protectedId = null;
        try {
            $insert = new profiles_model();
            $insert->populate(['name' => "Protegido {$marker}", 'slug' => $slug, 'editabled' => 'no']);
            $protectedId = (int) $insert->save();
            $this->assertGreaterThan(0, $protectedId, 'Insert de fixture deve retornar um ID valido');

            $_SESSION['_csrf_token'] = 'tok-' . $marker;
            $post = [
                '_csrf_token' => $_SESSION['_csrf_token'],
                'name'        => "Tentativa {$marker}",
                'slug'        => $slug,
            ];

            try {
                (new profiles_controller())->save(['post' => $post, 1 => $slug]);
                $this->fail('save() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $messages = implode(' ', $_SESSION['messages_app']['danger'] ?? []);
                $this->assertStringContainsString('protegido', $messages, 'Guard de perfil nao-editavel deve setar mensagem de danger');
            }
        } finally {
            unset($_SESSION['_csrf_token'], $_SESSION['_csrf_used'], $_SESSION['messages_app']);
            $this->resetSingleton();
            if ($protectedId) {
                (new localPDO())->executePrepared("DELETE FROM profiles WHERE idx = ?", [$protectedId]);
            }
        }
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

    public function testFilterByAdmReturnsOnlyMatchingRows(): void
    {
        $marker = uniqid();

        $admin = new profiles_model();
        $admin->populate(['name' => "admin_{$marker}", 'slug' => "admin-{$marker}", 'adm' => 'yes']);
        $adminId = (int) $admin->save();
        $this->assertGreaterThan(0, $adminId, 'Insert de fixture deve retornar um ID valido');

        $this->makeProfile("regular_{$marker}", "regular-{$marker}");

        $model = new profiles_model();
        $model->set_field([' idx ', ' adm ']);
        $model->set_filter([" active = 'yes' ", " adm = ? "], ['yes']);
        $model->set_order([' idx ASC ']);
        $model->load_data(false);

        $matched = array_column($model->data, 'idx');
        $this->assertContains($adminId, $matched, 'Filtro adm=yes deve incluir o perfil admin criado');
        foreach ($model->data as $row) {
            $this->assertSame('yes', $row['adm'], 'Toda linha retornada pelo filtro adm=yes deve ter adm = yes');
        }
    }

    public function testFilterByParentReturnsOnlyMatchingRows(): void
    {
        $marker    = uniqid();
        $parentIdx = $this->makeProfile("parent_{$marker}", "parent-{$marker}");

        $child = new profiles_model();
        $child->populate(['name' => "child_{$marker}", 'slug' => "child-{$marker}", 'parent' => $parentIdx]);
        $childId = (int) $child->save();
        $this->assertGreaterThan(0, $childId, 'Insert de fixture deve retornar um ID valido');

        $this->makeProfile("unrelated_{$marker}", "unrelated-{$marker}");

        $model = new profiles_model();
        $model->set_field([' idx ', ' parent ']);
        $model->set_filter([" active = 'yes' ", " parent = ? "], [$parentIdx]);
        $model->set_order([' idx ASC ']);
        $model->load_data(false);

        $this->assertCount(1, $model->data, 'Filtro parent deve retornar apenas o perfil filho');
        $this->assertSame($childId, (int) $model->data[0]['idx']);
    }

    public function testSafeInternalUrlAcceptsInternalDestination(): void
    {
        $internal = constant('cFrontend') . 'perfis?filter_name=foo';

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
}

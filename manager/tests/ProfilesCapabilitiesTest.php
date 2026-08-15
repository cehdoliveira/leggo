<?php

declare(strict_types=1);

/**
 * Cobre o vinculo perfil<->capacidade via save_attach() — fatia 4 do desenho
 * em plans/016-DESIGN.md (plano 029). O campo oculto capabilities_sent
 * distingue "form sem a secao" (nao mexe no vinculo) de "desmarquei tudo"
 * (desvincula tudo); o guard de perfil `editabled = 'no'` (protegido) barra
 * qualquer alteracao antes do save_attach() ser alcancado.
 *
 * save() termina em json_response()/basic_redir() (plano 009); os testes que
 * exercitam o controller usam resetSingleton() (DBTestCase) antes/depois pelo
 * mesmo motivo do ProfilesFilterTest.php, e limpam manualmente qualquer
 * fixture que tenha sido comitada.
 */
final class ProfilesCapabilitiesTest extends DBTestCase
{
    private function makeProfile(string $name, string $slug, string $editabled = 'yes'): int
    {
        $insert = new profiles_model();
        $insert->populate([
            'name'      => $name,
            'slug'      => $slug,
            'editabled' => $editabled,
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    private function linkCapability(int $profileId, int $capabilityId, string $active = 'yes'): void
    {
        (new profiles_model())->execute_raw_prepared(
            "INSERT INTO profiles_capabilities (created_at, created_by, active, profiles_id, capabilities_id) VALUES (now(), 0, ?, ?, ?)",
            [$active, $profileId, $capabilityId]
        );
    }

    /**
     * IDs das capacidades ativas do seed da migration 012 — ja comitadas bem
     * antes deste teste comecar, entao $this->con enxerga sem problema de
     * isolamento de transacao.
     *
     * @return list<int>
     */
    private function capabilityIds(): array
    {
        $rows = $this->con->executePrepared(
            "SELECT idx FROM capabilities WHERE active = 'yes' ORDER BY idx ASC"
        )->fetchAll();
        $ids = array_map('intval', array_column($rows, 'idx'));
        $this->assertGreaterThanOrEqual(2, count($ids), 'Seed da migration 012 precisa de ao menos 2 capacidades ativas');

        return $ids;
    }

    /** @return list<int> */
    private function activeLinkedCapabilities(int $profileId): array
    {
        $rows = (new localPDO())->executePrepared(
            "SELECT capabilities_id FROM profiles_capabilities WHERE profiles_id = ? AND active = 'yes' ORDER BY capabilities_id ASC",
            [$profileId]
        )->fetchAll();

        return array_map('intval', array_column($rows, 'capabilities_id'));
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
     * Autentica a sessao de teste como um usuario vinculado a um perfil
     * adm = 'yes' — bypassa auth_controller::has() para qualquer capacidade,
     * simulando quem hoje de fato usa esta tela. Mesmo padrao de
     * CapabilityGateTest::makeUserWithProfile(). Devolve [userId, profileId].
     *
     * @return array{0: int, 1: int}
     */
    private function authenticateAsAdmin(string $marker): array
    {
        $profile = new profiles_model();
        $profile->populate([
            'name' => "Admin Session {$marker}",
            'slug' => "admin-session-{$marker}",
            'adm'  => 'yes',
        ]);
        $profileId = (int) $profile->save();
        $this->assertGreaterThan(0, $profileId, 'Insert de fixture (profile admin) deve retornar um ID valido');

        $user = new users_model();
        $user->populate([
            'name'     => "Admin {$marker}",
            'mail'     => "admin_session_{$marker}@example.com",
            'login'    => "admin_session_{$marker}",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        $userId = (int) $user->save();
        $this->assertGreaterThan(0, $userId, 'Insert de fixture (user admin) deve retornar um ID valido');

        localPDO::getInstance()->executePrepared(
            "INSERT INTO users_profiles (created_at, created_by, active, users_id, profiles_id) VALUES (NOW(), 0, 'yes', ?, ?)",
            [$userId, $profileId]
        );

        $_SESSION[constant('cAppKey')]['credential']['idx'] = $userId;

        return [$userId, $profileId];
    }

    /** Desfaz o que authenticateAsAdmin() criou. */
    private function cleanupAdminSession(?int $userId, ?int $profileId): void
    {
        unset($_SESSION[constant('cAppKey')]);
        $cleanup = new localPDO();
        if ($userId) {
            $cleanup->executePrepared("DELETE FROM users_profiles WHERE users_id = ?", [$userId]);
            $cleanup->executePrepared("DELETE FROM users WHERE idx = ?", [$userId]);
        }
        if ($profileId) {
            $cleanup->executePrepared("DELETE FROM profiles WHERE idx = ?", [$profileId]);
        }
    }

    public function testSaveWithCapabilitiesSentLinksExactlyThoseIds(): void
    {
        $GLOBALS['profiles_url'] = constant('cFrontend') . 'perfis';
        $marker = uniqid();
        $slug   = 'cap-link-' . $marker;
        $twoIds = array_slice($this->capabilityIds(), 0, 2);

        $this->resetSingleton();
        $profileId = null;
        $adminUserId = null;
        $adminProfileId = null;
        try {
            [$adminUserId, $adminProfileId] = $this->authenticateAsAdmin($marker);
            $profileId = $this->makeProfile("Cap Link {$marker}", $slug);

            $_SESSION['_csrf_token'] = 'tok-' . $marker;
            $post = [
                '_csrf_token'       => $_SESSION['_csrf_token'],
                'name'              => "Cap Link {$marker}",
                'slug'              => $slug,
                'capabilities_sent' => '1',
                'capabilities_id'   => $twoIds,
                'no-redirect'       => '1',
            ];

            ob_start();
            try {
                (new profiles_controller())->save(['post' => $post, 1 => $slug]);
                if (ob_get_level()) {
                    ob_end_clean();
                }
                $this->fail('save() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                ob_get_clean();
                $this->assertSame(TerminalResponse::KIND_JSON, $e->kind);
                $this->assertSame('1', $e->payload['data']['ok'], 'save(..., no-redirect) deve responder ok');
            }

            $linked = $this->activeLinkedCapabilities($profileId);
            sort($twoIds);
            $this->assertSame($twoIds, $linked, 'save() com capabilities_sent deve vincular exatamente os IDs enviados, quando o autor ja tem essas capacidades');
        } finally {
            unset($_SESSION['_csrf_token'], $_SESSION['_csrf_used']);
            $this->resetSingleton();
            $cleanup = new localPDO();
            if ($profileId) {
                $cleanup->executePrepared("DELETE FROM profiles_capabilities WHERE profiles_id = ?", [$profileId]);
                $cleanup->executePrepared("DELETE FROM profiles WHERE idx = ?", [$profileId]);
            }
            $this->cleanupAdminSession($adminUserId, $adminProfileId);
        }
    }

    public function testSaveDoesNotLinkCapabilityAuthorDoesNotHave(): void
    {
        $GLOBALS['profiles_url'] = constant('cFrontend') . 'perfis';
        $marker = uniqid();
        $slug   = 'cap-noescalate-' . $marker;
        $capId  = $this->capabilityIds()[0];

        $this->resetSingleton();
        $profileId = null;
        $plainUserId = null;
        $plainProfileId = null;
        try {
            // Sessao autenticada, mas vinculada a um perfil sem adm e sem
            // nenhuma capacidade — o oposto de authenticateAsAdmin().
            $plainProfile = new profiles_model();
            $plainProfile->populate([
                'name' => "Sem Capacidade {$marker}",
                'slug' => "sem-capacidade-{$marker}",
            ]);
            $plainProfileId = (int) $plainProfile->save();
            $this->assertGreaterThan(0, $plainProfileId);

            $plainUser = new users_model();
            $plainUser->populate([
                'name'     => "Sem Capacidade {$marker}",
                'mail'     => "sem_capacidade_{$marker}@example.com",
                'login'    => "sem_capacidade_{$marker}",
                'password' => password_hash('secret', PASSWORD_BCRYPT),
            ]);
            $plainUserId = (int) $plainUser->save();
            $this->assertGreaterThan(0, $plainUserId);

            localPDO::getInstance()->executePrepared(
                "INSERT INTO users_profiles (created_at, created_by, active, users_id, profiles_id) VALUES (NOW(), 0, 'yes', ?, ?)",
                [$plainUserId, $plainProfileId]
            );
            $_SESSION[constant('cAppKey')]['credential']['idx'] = $plainUserId;

            $profileId = $this->makeProfile("Cap NoEscalate {$marker}", $slug);

            $_SESSION['_csrf_token'] = 'tok-' . $marker;
            $post = [
                '_csrf_token'       => $_SESSION['_csrf_token'],
                'name'              => "Cap NoEscalate {$marker}",
                'slug'              => $slug,
                'capabilities_sent' => '1',
                'capabilities_id'   => [$capId],
                'no-redirect'       => '1',
            ];

            ob_start();
            try {
                (new profiles_controller())->save(['post' => $post, 1 => $slug]);
                if (ob_get_level()) {
                    ob_end_clean();
                }
                $this->fail('save() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                ob_get_clean();
                $this->assertSame(TerminalResponse::KIND_JSON, $e->kind);
            }

            $this->assertSame([], $this->activeLinkedCapabilities($profileId), 'save() nao deve vincular capacidade nova que o autor do POST nao possui');
        } finally {
            unset($_SESSION['_csrf_token'], $_SESSION['_csrf_used']);
            $this->resetSingleton();
            $cleanup = new localPDO();
            if ($profileId) {
                $cleanup->executePrepared("DELETE FROM profiles_capabilities WHERE profiles_id = ?", [$profileId]);
                $cleanup->executePrepared("DELETE FROM profiles WHERE idx = ?", [$profileId]);
            }
            unset($_SESSION[constant('cAppKey')]);
            if ($plainUserId) {
                $cleanup->executePrepared("DELETE FROM users_profiles WHERE users_id = ?", [$plainUserId]);
                $cleanup->executePrepared("DELETE FROM users WHERE idx = ?", [$plainUserId]);
            }
            if ($plainProfileId) {
                $cleanup->executePrepared("DELETE FROM profiles WHERE idx = ?", [$plainProfileId]);
            }
        }
    }

    public function testSaveKeepsPreviouslyLinkedCapabilityEvenIfAuthorLacksIt(): void
    {
        $GLOBALS['profiles_url'] = constant('cFrontend') . 'perfis';
        $marker = uniqid();
        $slug   = 'cap-keep-' . $marker;
        $capId  = $this->capabilityIds()[0];

        $this->resetSingleton();
        $profileId = null;
        $plainUserId = null;
        $plainProfileId = null;
        try {
            $plainProfile = new profiles_model();
            $plainProfile->populate([
                'name' => "Sem Capacidade Keep {$marker}",
                'slug' => "sem-capacidade-keep-{$marker}",
            ]);
            $plainProfileId = (int) $plainProfile->save();
            $this->assertGreaterThan(0, $plainProfileId);

            $plainUser = new users_model();
            $plainUser->populate([
                'name'     => "Sem Capacidade Keep {$marker}",
                'mail'     => "sem_capacidade_keep_{$marker}@example.com",
                'login'    => "sem_capacidade_keep_{$marker}",
                'password' => password_hash('secret', PASSWORD_BCRYPT),
            ]);
            $plainUserId = (int) $plainUser->save();
            $this->assertGreaterThan(0, $plainUserId);

            localPDO::getInstance()->executePrepared(
                "INSERT INTO users_profiles (created_at, created_by, active, users_id, profiles_id) VALUES (NOW(), 0, 'yes', ?, ?)",
                [$plainUserId, $plainProfileId]
            );
            $_SESSION[constant('cAppKey')]['credential']['idx'] = $plainUserId;

            $profileId = $this->makeProfile("Cap Keep {$marker}", $slug);
            $this->linkCapability($profileId, $capId);

            $_SESSION['_csrf_token'] = 'tok-' . $marker;
            $post = [
                '_csrf_token'       => $_SESSION['_csrf_token'],
                'name'              => "Cap Keep {$marker}",
                'slug'              => $slug,
                'capabilities_sent' => '1',
                'capabilities_id'   => [$capId],
                'no-redirect'       => '1',
            ];

            ob_start();
            try {
                (new profiles_controller())->save(['post' => $post, 1 => $slug]);
                if (ob_get_level()) {
                    ob_end_clean();
                }
                $this->fail('save() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                ob_get_clean();
                $this->assertSame(TerminalResponse::KIND_JSON, $e->kind);
            }

            $this->assertSame([$capId], $this->activeLinkedCapabilities($profileId), 'save() deve manter vinculo pre-existente mesmo se o autor do POST nao tiver essa capacidade');
        } finally {
            unset($_SESSION['_csrf_token'], $_SESSION['_csrf_used']);
            $this->resetSingleton();
            $cleanup = new localPDO();
            if ($profileId) {
                $cleanup->executePrepared("DELETE FROM profiles_capabilities WHERE profiles_id = ?", [$profileId]);
                $cleanup->executePrepared("DELETE FROM profiles WHERE idx = ?", [$profileId]);
            }
            unset($_SESSION[constant('cAppKey')]);
            if ($plainUserId) {
                $cleanup->executePrepared("DELETE FROM users_profiles WHERE users_id = ?", [$plainUserId]);
                $cleanup->executePrepared("DELETE FROM users WHERE idx = ?", [$plainUserId]);
            }
            if ($plainProfileId) {
                $cleanup->executePrepared("DELETE FROM profiles WHERE idx = ?", [$plainProfileId]);
            }
        }
    }

    public function testSaveWithCapabilitiesSentAndEmptyListUnlinksAll(): void
    {
        $GLOBALS['profiles_url'] = constant('cFrontend') . 'perfis';
        $marker = uniqid();
        $slug   = 'cap-unlink-' . $marker;
        $twoIds = array_slice($this->capabilityIds(), 0, 2);

        $this->resetSingleton();
        $profileId = null;
        try {
            $profileId = $this->makeProfile("Cap Unlink {$marker}", $slug);
            $this->linkCapability($profileId, $twoIds[0]);
            $this->linkCapability($profileId, $twoIds[1]);

            $_SESSION['_csrf_token'] = 'tok-' . $marker;
            $post = [
                '_csrf_token'       => $_SESSION['_csrf_token'],
                'name'              => "Cap Unlink {$marker}",
                'slug'              => $slug,
                'capabilities_sent' => '1',
                'capabilities_id'   => [],
                'no-redirect'       => '1',
            ];

            ob_start();
            try {
                (new profiles_controller())->save(['post' => $post, 1 => $slug]);
                if (ob_get_level()) {
                    ob_end_clean();
                }
                $this->fail('save() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                ob_get_clean();
                $this->assertSame(TerminalResponse::KIND_JSON, $e->kind);
                $this->assertSame('1', $e->payload['data']['ok'], 'save(..., no-redirect) deve responder ok');
            }

            $this->assertSame([], $this->activeLinkedCapabilities($profileId), 'capabilities_id vazio com capabilities_sent deve desvincular tudo (soft-delete)');
        } finally {
            unset($_SESSION['_csrf_token'], $_SESSION['_csrf_used']);
            $this->resetSingleton();
            $cleanup = new localPDO();
            if ($profileId) {
                $cleanup->executePrepared("DELETE FROM profiles_capabilities WHERE profiles_id = ?", [$profileId]);
                $cleanup->executePrepared("DELETE FROM profiles WHERE idx = ?", [$profileId]);
            }
        }
    }

    public function testSaveWithoutCapabilitiesSentDoesNotTouchExistingLink(): void
    {
        $GLOBALS['profiles_url'] = constant('cFrontend') . 'perfis';
        $marker = uniqid();
        $slug   = 'cap-untouched-' . $marker;
        $capId  = $this->capabilityIds()[0];

        $this->resetSingleton();
        $profileId = null;
        try {
            $profileId = $this->makeProfile("Cap Untouched {$marker}", $slug);
            $this->linkCapability($profileId, $capId);

            $_SESSION['_csrf_token'] = 'tok-' . $marker;
            // Sem capabilities_sent nem capabilities_id — simula um POST que
            // nao passa pela secao de capacidades do form.
            $post = [
                '_csrf_token' => $_SESSION['_csrf_token'],
                'name'        => "Cap Untouched {$marker}",
                'slug'        => $slug,
                'no-redirect' => '1',
            ];

            ob_start();
            try {
                (new profiles_controller())->save(['post' => $post, 1 => $slug]);
                if (ob_get_level()) {
                    ob_end_clean();
                }
                $this->fail('save() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                ob_get_clean();
                $this->assertSame(TerminalResponse::KIND_JSON, $e->kind);
                $this->assertSame('1', $e->payload['data']['ok'], 'save(..., no-redirect) deve responder ok');
            }

            $this->assertSame([$capId], $this->activeLinkedCapabilities($profileId), 'save() sem capabilities_sent nao deve alterar o vinculo existente');
        } finally {
            unset($_SESSION['_csrf_token'], $_SESSION['_csrf_used']);
            $this->resetSingleton();
            $cleanup = new localPDO();
            if ($profileId) {
                $cleanup->executePrepared("DELETE FROM profiles_capabilities WHERE profiles_id = ?", [$profileId]);
                $cleanup->executePrepared("DELETE FROM profiles WHERE idx = ?", [$profileId]);
            }
        }
    }

    public function testSaveOfProtectedProfileDoesNotTouchLink(): void
    {
        $GLOBALS['profiles_url'] = constant('cFrontend') . 'perfis';
        $marker = uniqid();
        $slug   = 'cap-protected-' . $marker;
        $capIds = $this->capabilityIds();
        $capId  = $capIds[0];
        $other  = $capIds[1];

        $this->resetSingleton();
        unset($_SESSION['messages_app']);
        $profileId = null;
        try {
            $profileId = $this->makeProfile("Cap Protected {$marker}", $slug, 'no');
            $this->linkCapability($profileId, $capId);

            $_SESSION['_csrf_token'] = 'tok-' . $marker;
            $post = [
                '_csrf_token'       => $_SESSION['_csrf_token'],
                'name'              => "Tentativa {$marker}",
                'slug'              => $slug,
                'capabilities_sent' => '1',
                'capabilities_id'   => [$other],
            ];

            try {
                (new profiles_controller())->save(['post' => $post, 1 => $slug]);
                $this->fail('save() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                // O guard de is_editabled() redireciona ANTES do try/save_attach()
                // — perfil protegido nunca chega la, entao nem "no-redirect" seria
                // respeitado aqui (nao foi enviado de proposito, para provar isso).
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
            }

            $this->assertSame([$capId], $this->activeLinkedCapabilities($profileId), 'Perfil protegido deve manter o vinculo original, mesmo com capabilities_sent no POST');
        } finally {
            unset($_SESSION['_csrf_token'], $_SESSION['_csrf_used'], $_SESSION['messages_app']);
            $this->resetSingleton();
            $cleanup = new localPDO();
            if ($profileId) {
                $cleanup->executePrepared("DELETE FROM profiles_capabilities WHERE profiles_id = ?", [$profileId]);
                $cleanup->executePrepared("DELETE FROM profiles WHERE idx = ?", [$profileId]);
            }
        }
    }

    public function testSelectedCapabilitiesReturnsLinkedId(): void
    {
        $marker = uniqid();
        $capId  = $this->capabilityIds()[0];

        $this->resetSingleton();
        $profileId = null;
        try {
            $profileId = $this->makeProfile("Selected {$marker}", "selected-{$marker}");
            $this->linkCapability($profileId, $capId);

            $selected = $this->callPrivate('selected_capabilities', [$profileId]);

            $this->assertSame([$capId], $selected, 'selected_capabilities() deve devolver o ID vinculado ao perfil');
        } finally {
            $this->resetSingleton();
            $cleanup = new localPDO();
            if ($profileId) {
                $cleanup->executePrepared("DELETE FROM profiles_capabilities WHERE profiles_id = ?", [$profileId]);
                $cleanup->executePrepared("DELETE FROM profiles WHERE idx = ?", [$profileId]);
            }
        }
    }

    public function testAvailableCapabilitiesReturnsOnlyActiveOrderedBySlug(): void
    {
        $marker = uniqid();

        $this->resetSingleton();
        $activeId = null;
        $inactiveId = null;
        try {
            $active = new capabilities_model();
            $active->populate(['slug' => "zzz-ativa-{$marker}", 'name' => "Ativa {$marker}"]);
            $activeId = (int) $active->save();
            $this->assertGreaterThan(0, $activeId);

            $inactive = new capabilities_model();
            $inactive->populate(['slug' => "aaa-inativa-{$marker}", 'name' => "Inativa {$marker}"]);
            $inactiveId = (int) $inactive->save();
            $this->assertGreaterThan(0, $inactiveId);
            // Mesma conexao (singleton) do insert acima — uma conexao nova
            // (new localPDO()) nao enxergaria a linha ainda nao comitada.
            localPDO::getInstance()->executePrepared("UPDATE capabilities SET active = 'no' WHERE idx = ?", [$inactiveId]);

            $available = $this->callPrivate('available_capabilities');

            $this->assertArrayHasKey($activeId, $available, 'capacidade ativa deve aparecer em available_capabilities()');
            $this->assertArrayNotHasKey($inactiveId, $available, 'capacidade inativa nao deve aparecer em available_capabilities()');
            $this->assertSame(
                sprintf('zzz-ativa-%s — Ativa %s', $marker, $marker),
                $available[$activeId],
                'formato deve ser "slug — name", consumido por explode() na view'
            );
        } finally {
            // Nada aqui foi comitado (nenhum save_attach()/json_response()
            // no meio do caminho) — resetSingleton() so derruba a transacao
            // de verdade quando a ultima referencia ao singleton some, entao
            // solta $active/$inactive antes de resetar. Sem DELETE manual:
            // a transacao nunca comitada reverte sozinha no destructor.
            unset($active, $inactive);
            $this->resetSingleton();
        }
    }

    public function testDisplayJsonAttachesLinkedCapabilityIdsPerProfile(): void
    {
        $GLOBALS['profiles_url'] = constant('cFrontend') . 'perfis';
        $marker = uniqid();
        $slug   = 'cap-display-' . $marker;
        $capId  = $this->capabilityIds()[0];

        $this->resetSingleton();
        $profileId = null;
        try {
            $profileId = $this->makeProfile("Cap Display {$marker}", $slug);
            $this->linkCapability($profileId, $capId);

            ob_start();
            try {
                (new profiles_controller())->display(['get' => ['paginate' => '99999999'], 1 => '.json']);
                if (ob_get_level()) {
                    ob_end_clean();
                }
                $this->fail('display() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                ob_get_clean();
                $this->assertSame(TerminalResponse::KIND_JSON, $e->kind);

                $row = null;
                foreach ($e->payload['data']['row'] as $candidate) {
                    if ((int)($candidate['idx'] ?? 0) === $profileId) {
                        $row = $candidate;
                        break;
                    }
                }
                $this->assertNotNull($row, 'perfil fixture deveria aparecer na resposta de display(.json)');
                $attachedIds = array_map('intval', array_column($row['capabilities_attach'] ?? [], 'idx'));
                $this->assertSame([$capId], $attachedIds, 'attach() em lote de display() deve trazer o idx da capacidade vinculada, nao so a contagem');
            }
        } finally {
            $this->resetSingleton();
            $cleanup = new localPDO();
            if ($profileId) {
                $cleanup->executePrepared("DELETE FROM profiles_capabilities WHERE profiles_id = ?", [$profileId]);
                $cleanup->executePrepared("DELETE FROM profiles WHERE idx = ?", [$profileId]);
            }
        }
    }
}

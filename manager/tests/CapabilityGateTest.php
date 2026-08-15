<?php

declare(strict_types=1);

/**
 * Cobre auth_controller::can() na fatia 2 (modo log) do desenho em
 * plans/016-DESIGN.md: a checagem de SESSAO ja bloqueia, a de CAPACIDADE
 * ainda nao. O caso mais importante aqui e o primeiro — sem login, can()
 * nega; se ele passar a devolver true, as 18 rotas do manager viram
 * acesso anonimo.
 *
 * Fixture escrita pelo singleton localPDO::getInstance() (mesma conexao que
 * can()/access_rows() usam), nao pela conexao propria do DBTestCase — ver
 * comentario no plano 021 sobre o isolamento entre as duas conexoes.
 */
final class CapabilityGateTest extends DBTestCase
{
    private function pdo(): localPDO
    {
        return localPDO::getInstance();
    }

    /** Cria um usuario ativo vinculado a um perfil novo. Devolve [userId, profileId]. */
    private function makeUserWithProfile(string $marker, string $adm): array
    {
        $profile = new profiles_model();
        $profile->populate([
            'name' => "Perfil {$marker}",
            'slug' => "perfil-{$marker}",
            'adm'  => $adm,
        ]);
        $profileId = (int) $profile->save();
        $this->assertGreaterThan(0, $profileId, 'Insert de fixture (profile) deve retornar um ID valido');

        $user = new users_model();
        $user->populate([
            'name'     => "Usuario {$marker}",
            'mail'     => "capability_{$marker}@example.com",
            'login'    => "capability_{$marker}",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        $userId = (int) $user->save();
        $this->assertGreaterThan(0, $userId, 'Insert de fixture (user) deve retornar um ID valido');

        $this->pdo()->executePrepared(
            "INSERT INTO users_profiles (created_at, created_by, active, users_id, profiles_id) VALUES (NOW(), 0, 'yes', ?, ?)",
            [$userId, $profileId]
        );

        return [$userId, $profileId];
    }

    private function grant(int $profileId, string $capabilitySlug): void
    {
        $this->pdo()->executePrepared(
            "INSERT INTO profiles_capabilities (created_at, created_by, active, profiles_id, capabilities_id)
             SELECT NOW(), 0, 'yes', ?, idx FROM capabilities WHERE slug = ?",
            [$profileId, $capabilitySlug]
        );
    }

    /**
     * Chama auth_controller::access_rows() (private static) via reflection.
     * No modo log, can() sempre devolve true para quem tem sessao — entao os
     * testes de bypass/match precisam inspecionar as linhas de verdade, nao
     * so o retorno agregado de can().
     */
    private function accessRows(int $userId): array
    {
        $method = new ReflectionMethod(auth_controller::class, 'access_rows');
        $method->setAccessible(true);

        return $method->invoke(null, $userId);
    }

    protected function tearDown(): void
    {
        unset($_SESSION[constant('cAppKey')]);
        parent::tearDown();
    }

    public function testNegaSemSessao(): void
    {
        unset($_SESSION[constant('cAppKey')]);
        $this->assertFalse(auth_controller::can('usuarios.ler'));
    }

    public function testAceitaPerfilAdmSemCapacidade(): void
    {
        $marker = uniqid();
        [$userId] = $this->makeUserWithProfile($marker, 'yes');
        $_SESSION[constant('cAppKey')]['credential']['idx'] = $userId;

        $this->assertTrue(auth_controller::can('usuarios.ler'));

        // No modo log, can() devolveria true de qualquer jeito — confirma que
        // e de fato o bypass adm='yes' (e nao so o fallback) quem responde.
        $rows = $this->accessRows($userId);
        $this->assertNotEmpty($rows, 'access_rows() deveria trazer o perfil recem-criado');
        $this->assertSame('yes', $rows[0]['adm'] ?? null);
    }

    public function testAceitaPerfilNaoAdmComCapacidade(): void
    {
        $marker = uniqid();
        [$userId, $profileId] = $this->makeUserWithProfile($marker, 'no');
        $this->grant($profileId, 'emails.ler');
        $_SESSION[constant('cAppKey')]['credential']['idx'] = $userId;

        $this->assertTrue(auth_controller::can('emails.ler'));

        // No modo log, can() devolveria true de qualquer jeito — confirma que
        // o grant() de fato criou a linha com o slug esperado (e nao o bypass
        // de adm, que aqui e 'no').
        $rows = $this->accessRows($userId);
        $this->assertSame('no', $rows[0]['adm'] ?? null);
        $this->assertContains('emails.ler', array_column($rows, 'slug'));
    }

    public function testModoLogAceitaPerfilNaoAdmSemCapacidade(): void
    {
        // adm='no', sem grant nenhum — na fatia 2 ainda passa.
        // O plano 022 inverte esta asserção para assertFalse.
        $marker = uniqid();
        [$userId] = $this->makeUserWithProfile($marker, 'no');
        $_SESSION[constant('cAppKey')]['credential']['idx'] = $userId;

        $this->assertTrue(auth_controller::can('emails.ler'));
    }

    public function testHasAceitaPerfilAdmSemCapacidade(): void
    {
        $marker = uniqid();
        [$userId] = $this->makeUserWithProfile($marker, 'yes');
        $_SESSION[constant('cAppKey')]['credential']['idx'] = $userId;

        $this->assertTrue(auth_controller::has('perfis.ler'));
    }

    public function testHasNegaPerfilNaoAdmSemACapacidadePedida(): void
    {
        $marker = uniqid();
        [$userId, $profileId] = $this->makeUserWithProfile($marker, 'no');
        $this->grant($profileId, 'usuarios.ler');
        $_SESSION[constant('cAppKey')]['credential']['idx'] = $userId;

        $this->assertTrue(auth_controller::has('usuarios.ler'));
        $this->assertFalse(auth_controller::has('perfis.ler'));
    }

    public function testHasNegaSemSessao(): void
    {
        unset($_SESSION[constant('cAppKey')]);
        $this->assertFalse(auth_controller::has('usuarios.ler'));
    }

    public function testCanNaoRegrideNoModoLogQuandoHasNega(): void
    {
        $marker = uniqid();
        [$userId, $profileId] = $this->makeUserWithProfile($marker, 'no');
        $this->grant($profileId, 'usuarios.ler');
        $_SESSION[constant('cAppKey')]['credential']['idx'] = $userId;

        $this->assertFalse(auth_controller::has('perfis.ler'));
        $this->assertTrue(auth_controller::can('perfis.ler'));
    }

    /**
     * Cobre o guard do plano 026 (manager/public_html/index.php): sem
     * sessao, routeGuard() nega sem chegar perto de render_error_page() —
     * regressao aqui e visitante anonimo tomando 403 em vez de ir pro login.
     */
    public function testRouteGuardNegaSemSessao(): void
    {
        unset($_SESSION[constant('cAppKey')]);
        $this->assertFalse(auth_controller::routeGuard('usuarios.ler'));
    }

    /**
     * Hoje, logado sempre passa (can() em modo log nunca nega pra quem tem
     * sessao) — o ramo do 403 fica inerte ate o plano 022. Confirma que
     * routeGuard() nao dispara render_error_page() enquanto isso.
     */
    public function testRouteGuardAceitaLogadoNoModoLog(): void
    {
        $marker = uniqid();
        [$userId] = $this->makeUserWithProfile($marker, 'no');
        $_SESSION[constant('cAppKey')]['credential']['idx'] = $userId;

        $this->assertTrue(auth_controller::routeGuard('capacidade-inexistente'));
    }

    /**
     * Simula a fatia 022 (can() negando pra quem esta logado) via seam de
     * teste, pra provar que o mecanismo do 403 funciona ANTES dela existir
     * de verdade — sem isso, uma regressao na ordem das checagens (ex.:
     * inverter can()/check_login(), ou nunca chamar render_error_page) so
     * apareceria em producao depois do plano 022 ligar o bloqueio.
     */
    public function testRouteGuardDisparaTerminalResponse403QuandoLogadoSemCapacidade(): void
    {
        $_SESSION[constant('cAppKey')]['credential']['idx'] = 1;
        $GLOBALS['home_url']   = '/';
        $GLOBALS['login_url']  = '/login';
        $GLOBALS['logout_url'] = '/logout';

        ob_start();
        try {
            auth_controller::routeGuard(
                'usuarios.ler',
                can: fn(string $c): bool => false,
                checkLogin: fn(): bool => true,
            );
            $this->fail('routeGuard() deveria ter lancado TerminalResponse');
        } catch (TerminalResponse $e) {
            ob_end_clean();
            $this->assertSame(TerminalResponse::KIND_ERROR, $e->kind);
            $this->assertSame(403, $e->payload['code']);
        }
    }

    /**
     * Mesma simulacao, mas sem sessao: routeGuard() tem que negar (false),
     * nunca cair no 403 — visitante anonimo vai pro login, nao pra tela de
     * acesso negado.
     */
    public function testRouteGuardNaoDisparaErroSemSessaoMesmoComCanNegando(): void
    {
        unset($_SESSION[constant('cAppKey')]);

        $result = auth_controller::routeGuard(
            'usuarios.ler',
            can: fn(string $c): bool => false,
            checkLogin: fn(): bool => false,
        );

        $this->assertFalse($result);
    }
}

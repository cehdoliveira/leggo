<?php

declare(strict_types=1);

/**
 * Cobre o teto de paginacao (plano 010): profiles_controller, users_controller
 * e emails_controller agora tem PER_PAGE_MAX = 200 alem do piso PER_PAGE_MIN,
 * para que ?paginate=<numero grande> nao devolva a tabela inteira (achado:
 * /emails seleciona a coluna body, LONGTEXT, e messages so cresce).
 *
 * O caso 1 exercita o caminho real — .json de profiles_controller::display() —
 * capturando a TerminalResponse lancada por json_response() sob TESTING
 * (mecanismo do plano 009, ver TerminalResponseTest.php). Como json_response()
 * chama close_request_transaction(), que comita o singleton do localPDO, a
 * fixture criada antes da chamada precisa ser limpa manualmente e o singleton
 * resetado — mesmo padrao de ProfilesFilterTest::testSaveWithNoRedirectReturnsJsonOk.
 *
 * Os casos 2 e 3 testam o calculo diretamente com as constantes lidas via
 * Reflection (o proprio plano 010 permite essa forma para o caso 2: "se
 * preferir testar o calculo direto") — evita depender da contagem de linhas
 * ja existentes no banco de teste, que nao e controlada por este teste. O
 * caso 4 e o guard puro: trava a regressao de um quarto controller de
 * listagem aparecer sem as duas constantes.
 */
final class PerPageCapTest extends DBTestCase
{
    private const CONTROLLERS = [
        'profiles_controller',
        'users_controller',
        'emails_controller',
    ];

    /** Aplica a formula do Step 2 (min(MAX, max(MIN, x))) com as constantes reais do controller, lidas via Reflection. */
    private function computePaginate(string $controllerClass, int $requested): int
    {
        $min = (new ReflectionClassConstant($controllerClass, 'PER_PAGE_MIN'))->getValue();
        $max = (new ReflectionClassConstant($controllerClass, 'PER_PAGE_MAX'))->getValue();

        return min($max, max($min, $requested));
    }

    public function testPaginateAcimaDoTetoEhLimitadoA200NaRespostaJsonDePerfis(): void
    {
        $marker = uniqid();

        $this->resetSingleton();
        $id = null;
        try {
            $insert = new profiles_model();
            $insert->populate(['name' => "cap_{$marker}", 'slug' => "cap-{$marker}"]);
            $id = (int) $insert->save();
            $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

            (new profiles_controller())->display(['get' => ['paginate' => '99999999'], 1 => '.json']);
            $this->fail('display() deveria ter lancado TerminalResponse');
        } catch (TerminalResponse $e) {
            $this->assertSame(TerminalResponse::KIND_JSON, $e->kind);
            $this->assertLessThanOrEqual(200, count($e->payload['data']['row']), 'paginate=99999999 nao pode devolver mais de 200 linhas');
        } finally {
            $this->resetSingleton();
            if ($id) {
                (new localPDO())->executePrepared("DELETE FROM profiles WHERE idx = ?", [$id]);
            }
        }
    }

    public function testPaginateIntermediarioEhRespeitadoNosTresControllers(): void
    {
        foreach (self::CONTROLLERS as $controllerClass) {
            $this->assertSame(50, $this->computePaginate($controllerClass, 50), "{$controllerClass}: paginate=50 deve ser respeitado (nao rebaixado)");
        }
    }

    public function testPaginateAbaixoDoPisoEhElevadoA20NosTresControllers(): void
    {
        foreach (self::CONTROLLERS as $controllerClass) {
            $this->assertSame(20, $this->computePaginate($controllerClass, 1), "{$controllerClass}: paginate=1 deve ser elevado ao piso PER_PAGE_MIN");
        }
    }

    public function testTresControllersDeclaramPisoETetoDePaginacao(): void
    {
        foreach (self::CONTROLLERS as $controllerClass) {
            $this->assertTrue(class_exists($controllerClass), "{$controllerClass} deveria existir");

            $min = (new ReflectionClassConstant($controllerClass, 'PER_PAGE_MIN'))->getValue();
            $max = (new ReflectionClassConstant($controllerClass, 'PER_PAGE_MAX'))->getValue();

            $this->assertLessThan($max, $min, "{$controllerClass}: PER_PAGE_MIN deve ser menor que PER_PAGE_MAX");
            $this->assertSame(200, $max, "{$controllerClass}: PER_PAGE_MAX deve ser 200");
        }
    }
}

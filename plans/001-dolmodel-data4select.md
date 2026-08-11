# Plan 001: Adicionar `DOLModel::data4select()` — mapa chave→rótulo com params bindados

> **Executor instructions**: Siga este plano passo a passo. Rode cada comando de
> verificação e confirme o resultado esperado antes de seguir. Se ocorrer
> qualquer item da seção "STOP conditions", pare e reporte — não improvise.
> Ao terminar, atualize a linha deste plano em `plans/README.md`.
>
> **Drift check (rode primeiro)**:
> `git diff --stat a032c73..HEAD -- manager/app/inc/lib/DOLModel.php site/app/inc/lib/DOLModel.php`
> Se algum arquivo em escopo mudou desde este plano, compare os trechos de
> "Current state" com o código vivo antes de prosseguir; divergência = STOP.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: commit `a032c73`, 2026-08-11

## Why this matters

O padrão de controller que este projeto vai adotar (planos 005–007) depende de
uma operação genérica: consultar uma entidade trazendo só duas colunas e devolver
um dicionário `chave => rótulo`. Ela alimenta `<select>` de formulário e, usada
invertida (rótulo = `idx`, filtro = `slug`), traduz o identificador público da
URL no identificador interno do registro — que é como todas as rotas por slug do
padrão resolvem o alvo.

Hoje isso não existe. `profiles_controller::index()` monta o `<select>` de
"perfil pai" com um segundo model e ~6 linhas manuais, e nenhum controller
resolve slug→idx. O `DOLModel` já tem 90% da lógica em `_list_data()` — falta
o `array_column` final e o bind de parâmetros. Sem esta peça, cada controller
do padrão vai reescrever a mesma coisa (e a versão legada em
`exemplo_controller.php:4-14` a escreve concatenando string em SQL).

## Current state

Arquivos relevantes:

- `manager/app/inc/lib/DOLModel.php` — model base do framework. Cópia idêntica em
  `site/app/inc/lib/DOLModel.php`.
- `manager/app/inc/model/profiles_model.php` — model concreto usado nos testes.

`DOLModel::_list_data()` faz quase o que precisamos, mas devolve as linhas cruas
e não aceita parâmetros bindados (`manager/app/inc/lib/DOLModel.php:212-219`):

```php
	public function _list_data(string $value = "name", array $filter = array(), string $key = "idx", string $order = ""): array
	{
		$this->set_field(array($key, $value));
		$this->set_filter(count($filter) ? array_merge(array(" active = 'yes' "), $filter) : array(" active = 'yes' "));
		$this->set_order(array($order == "" ? preg_replace("/.+ as (.+)$/", "$1", $value) . " asc " : $order));
		$this->load_data();
		return $this->data;
	}
```

`set_filter()` já aceita valores bindados como segundo argumento
(`DOLModel.php:181-185`):

```php
	public function set_filter(array $conditions, array $params = []): void
	{
		$this->filter = $conditions;
		$this->filterParams = $params;
	}
```

`load_data(bool $withCount = true)` (`DOLModel.php:263-292`) roda uma segunda
query de `COUNT()` quando `$withCount` é `true`. Para um mapa de select isso é
desperdício — passe `false`.

O model concreto define os defaults (`manager/app/inc/model/profiles_model.php`):

```php
class profiles_model extends DOLModel
{
    protected array $field = ["idx", "name", "editabled", "slug", "adm", "parent"];
    protected array $filter = ["active = 'yes'"];

    function __construct()
    {
        parent::__construct("profiles");
    }
}
```

**Convenção obrigatória deste repo** (`AGENTS.md`, seção "Código compartilhado
vs. por ambiente"): `app/inc/lib/` e `app/inc/model/` são **duas cópias
byte-a-byte idênticas** entre `manager/` e `site/`. Toda alteração em
`DOLModel.php` deve ser aplicada nas duas, senão `bin/check-shared-sync.sh`
falha e o pre-commit bloqueia.

**Estilo**: `DOLModel.php` é indentado com **tabs** (ver `.editorconfig`).
Docblocks em PT-BR, como o de `set_filter()` acima.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0, `[OK] No errors` |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0, `[OK] No errors` |
| Testes manager | `cd manager && php app/inc/lib/vendor/bin/phpunit` | exit 0, todos passam |
| Teste único | `cd manager && php app/inc/lib/vendor/bin/phpunit --filter Data4Select` | exit 0 |
| Guard de sync | `bash bin/check-shared-sync.sh` | exit 0, sem arquivos listados |
| Verificação completa | `bin/test.sh` | exit 0 |

Os testes precisam de banco vivo. Se o container não estiver de pé:
`docker compose -f docker/docker-compose.yml up -d`.

## Scope

**In scope**:
- `manager/app/inc/lib/DOLModel.php` (editar)
- `site/app/inc/lib/DOLModel.php` (editar — cópia idêntica)
- `manager/tests/Data4SelectTest.php` (criar)
- `site/tests/Data4SelectTest.php` (criar — mesmo conteúdo)

**Out of scope** (não toque):
- `_list_data()` — não altere nem remova; ainda pode haver chamadores fora do
  escopo auditado, e o novo método não depende dele.
- Qualquer controller. A adoção do novo método acontece nos planos 005–007.
- `rootOBJ.php` — o `__call` mágico já expõe tudo que é preciso.

## Git workflow

- Branch: `advisor/001-dolmodel-data4select`
- Commits em PT-BR, Conventional Commits — padrão observado no `git log`
  (ex.: `refactor: migra controllers de execute_raw_prepared() para select()`).
  Sugestão: `feat: adiciona DOLModel::data4select() com params bindados`.
- Não faça push nem abra PR a menos que o operador peça.

## Steps

### Step 1: Adicionar `data4select()` ao `DOLModel` do manager

Em `manager/app/inc/lib/DOLModel.php`, insira o método logo **depois** de
`_list_data()` (ou seja, após a linha 219, antes de `_current_data()`).
Use tabs para indentar.

```php
	/**
	 * Devolve um mapa `chave => rótulo` — alimenta <select> de formulário e
	 * serve como tabela de tradução.
	 *
	 * Uso direto (mapa para um <select>):
	 *   $map = (new profiles_model())->data4select("idx", [" active = 'yes' "], "name");
	 *
	 * Uso invertido (traduz identificador público em identificador interno):
	 *   $idx = (int)current((new profiles_model())->data4select("name", ["slug = ?"], "idx", [$slug]));
	 *
	 * Se $field vier como expressão com apelido ("CONCAT(a,b) as label"), o
	 * apelido passa a valer como nome da coluna na ordenação e no resultado.
	 *
	 * @param string        $key     coluna que vira a chave do mapa
	 * @param array<string> $filters condições WHERE (use ? para valores)
	 * @param string        $field   coluna (ou expressão com apelido) que vira o rótulo
	 * @param array<mixed>  $params  valores para bind dos ? em $filters
	 * @return array<string|int, mixed>
	 */
	public function data4select(string $key = "idx", array $filters = array(" active = 'yes' "), string $field = "name", array $params = array()): array
	{
		$keyName   = trim($key);
		$fieldName = trim((string)preg_replace("/.+ as (.+)$/i", "$1", trim($field)));

		$this->set_field(array($key, $field));
		$this->set_filter($filters, $params);
		$this->set_order(array($fieldName . " asc "));
		$this->load_data(false);

		return array_column($this->data, $fieldName, $keyName);
	}
```

Três detalhes que **não** podem ser simplificados:

1. `trim()` no `$key` e no apelido — as chaves de `$this->data` vêm do PDO com o
   nome exato da coluna, sem os espaços que a convenção do repo usa nos arrays
   (`[" idx ", " name "]`). Sem o trim, `array_column()` devolve array vazio.
2. `load_data(false)` — evita a query de `COUNT()`, inútil aqui.
3. O cast `(string)` no `preg_replace` — PHPStan level 4 acusa `string|null`.

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0,
`[OK] No errors`.

### Step 2: Replicar byte-a-byte no `site`

Copie o arquivo inteiro:

```bash
cp manager/app/inc/lib/DOLModel.php site/app/inc/lib/DOLModel.php
```

**Verify**: `bash bin/check-shared-sync.sh` → exit 0, nenhum arquivo divergente
listado.

### Step 3: Escrever o teste

Crie `manager/tests/Data4SelectTest.php`. Modele a estrutura por
`manager/tests/MessagesFilterTest.php` (mesma classe base, mesmo estilo de
fixture com `uniqid()`, mesmas asserções em PT-BR).

```php
<?php

declare(strict_types=1);

/**
 * Cobre DOLModel::data4select() (plano 001) — mapa chave=>rotulo, uso
 * invertido para traduzir slug em idx, e bind de parametros.
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
            [" active = 'yes' ", " slug LIKE ? "],
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
            [" active = 'yes' ", " slug = ? "],
            "idx",
            ["perfil-{$marker}"]
        ));

        $this->assertSame($id, $found, 'Uso invertido deve devolver o idx do registro do slug');
    }

    public function testUnknownSlugReturnsEmptyMap(): void
    {
        $map = (new profiles_model())->data4select(
            "name",
            [" active = 'yes' ", " slug = ? "],
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
            [" active = 'yes' ", " idx = ? "],
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
            [" active = 'yes' ", " slug = ? "],
            "idx",
            ["' OR 1=1 -- "]
        );

        $this->assertSame([], $map, 'Valor malicioso deve ser tratado como dado, nao como SQL');
    }
}
```

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpunit --filter Data4Select`
→ exit 0, 5 testes passando.

### Step 4: Replicar o teste no `site`

```bash
cp manager/tests/Data4SelectTest.php site/tests/Data4SelectTest.php
```

Os testes não precisam ser byte-a-byte idênticos entre ambientes (`AGENTS.md`),
mas neste caso são — nada aqui depende do ambiente.

**Verify**: `cd site && php app/inc/lib/vendor/bin/phpunit --filter Data4Select`
→ exit 0, 5 testes passando.

### Step 5: Verificação completa

**Verify**: `bin/test.sh` → exit 0 (PHPStan nos dois ambientes + PHPUnit nos dois).

## Test plan

- Arquivo novo: `manager/tests/Data4SelectTest.php` + cópia em `site/tests/`.
- Casos cobertos: mapa chave→rótulo com ordenação alfabética; uso invertido
  (slug→idx); slug inexistente devolve mapa vazio; expressão com apelido vira o
  nome da coluna no resultado; parâmetro malicioso é bindado, não interpolado.
- Padrão estrutural: `manager/tests/MessagesFilterTest.php` (`DBTestCase`,
  fixtures com `uniqid()`, mensagens de asserção em PT-BR).
- Comando: `cd manager && php app/inc/lib/vendor/bin/phpunit` → todos passam,
  incluindo os 5 novos.

## Done criteria

Todos devem valer:

- [ ] `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0
- [ ] `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0
- [ ] `bash bin/check-shared-sync.sh` → exit 0
- [ ] `bin/test.sh` → exit 0, com os 5 testes novos passando nos dois ambientes
- [ ] `diff manager/app/inc/lib/DOLModel.php site/app/inc/lib/DOLModel.php` → sem saída
- [ ] `git status` não mostra arquivos fora da lista de escopo
- [ ] Linha do plano 001 atualizada em `plans/README.md`

## STOP conditions

Pare e reporte se:

- `DOLModel.php` não bate com os trechos de "Current state" (drift).
- O banco não está acessível e os testes não rodam — reporte em vez de
  mockar ou marcar os testes como skipped.
- `array_column()` devolver array vazio mesmo com linhas em `$this->data`:
  provavelmente o `trim()` da chave/apelido não está batendo com o nome real da
  coluna. Verifique com `var_dump(array_keys($this->data[0]))` antes de mudar a
  assinatura do método.
- A verificação de um passo falhar duas vezes após uma tentativa razoável de
  correção.

## Maintenance notes

- Qualquer mudança futura em `DOLModel.php` precisa ser aplicada nas **duas**
  cópias — o pre-commit bloqueia se esquecer.
- `data4select()` deixa o model sujo (`field`, `filter`, `order` foram
  sobrescritos). Como todos os chamadores previstos instanciam o model só para
  isso, não vale complicar; se algum dia alguém reusar a mesma instância depois
  de chamar `data4select()`, é aí que a surpresa aparece — vale um comentário no
  PR de quem fizer isso.
- O revisor deve olhar especificamente: o `trim()` nas duas pontas e o
  `load_data(false)`. São os dois pontos onde uma "simplificação" quebra o método
  silenciosamente (mapa vazio / query extra).
- Deferido de propósito: não foi criado wrapper estático `data4select()` em cada
  controller (como faz `exemplo_controller.php:4`). Os controllers chamam o
  método do model direto — uma implementação em vez de N.

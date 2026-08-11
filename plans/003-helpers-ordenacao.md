# Plan 003: Helpers de ordenação com allowlist (`resolve_ordenation`, `ordenation_header`)

> **Executor instructions**: Siga este plano passo a passo. Rode cada comando de
> verificação e confirme o resultado esperado antes de seguir. Se ocorrer
> qualquer item da seção "STOP conditions", pare e reporte — não improvise.
> Ao terminar, atualize a linha deste plano em `plans/README.md`.
>
> **Drift check (rode primeiro)**:
> `git diff --stat a032c73..HEAD -- manager/app/inc/lib/CommonFunctions.php site/app/inc/lib/CommonFunctions.php`
> Se algum arquivo em escopo mudou desde este plano, compare os trechos de
> "Current state" com o código vivo antes de prosseguir; divergência = STOP.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none (mas 002 mexe no mesmo arquivo — execute 002 antes para
  evitar conflito de merge)
- **Category**: security
- **Planned at**: commit `a032c73`, 2026-08-11

## Why this matters

O padrão de controller que este projeto vai adotar (planos 005–007) recebe a
ordenação da tela como um par "coluna + direção" codificado num único parâmetro
de query string (`?ordenation=name-desc`), decompõe esse par e monta o `ORDER BY`.

`ORDER BY` **não aceita parâmetro bindado** — o nome da coluna tem que ir
literal no SQL. Ou seja: sem allowlist, o valor do query string entra cru na
query. É exatamente o que o controller legado faz
(`exemplo_controller.php:40` e `:54`), e é injeção de SQL direta. A mesma tela
também precisa, para cada coluna clicável, do link da próxima ordenação e do
ícone do estado atual — lógica idêntica em toda listagem.

Duas funções pequenas resolvem os dois problemas de uma vez e ficam disponíveis
para as três listagens do manager. Sem elas, cada controller e cada view
reinventa o `preg_replace("/-/", " ")` do legado — que é o bug.

## Current state

Arquivo: `manager/app/inc/lib/CommonFunctions.php` (cópia idêntica em
`site/app/inc/lib/CommonFunctions.php`).

Nenhuma listagem do manager tem ordenação hoje — as três ordenam com valor fixo
no controller:

- `manager/app/inc/controller/site_controller.php:33` → `set_order([" created_at DESC "])`
- `manager/app/inc/controller/profiles_controller.php:31` → `set_order([" name ASC "])`
- `manager/app/inc/controller/emails_controller.php:34` → `set_order([" sent_at DESC "])`

O que **não** deve ser copiado, do controller legado
(`exemplo_controller.php:40`):

```php
$ordenation = isset($info["get"]["ordenation"]) ? preg_replace("/-/", " ", $info["get"]["ordenation"]) : 'name asc';
```

`?ordenation=name-asc,(select+...)` vira `ORDER BY name asc,(select ...)`.

Convenções que valem aqui:

- **Ícones**: o projeto usa **Bootstrap Icons** (`bi bi-*`), não FontAwesome.
  Ver `manager/public_html/ui/page/profiles.php:16,26,51` (`bi bi-people`,
  `bi bi-person-badge`, `bi bi-pencil`). O legado usa `fas fa-*` — não replique.
- **Estilo do arquivo**: `CommonFunctions.php` usa indentação de **2 espaços**.
  Docblocks em PT-BR.
- **Cópia dupla**: `app/inc/lib/` é byte-a-byte idêntico entre `manager/` e
  `site/` (`AGENTS.md`); `bin/check-shared-sync.sh` bloqueia o commit se divergir.
- **Montagem de URL**: `set_url($url, $params)` (`CommonFunctions.php:104`)
  preserva os parâmetros já presentes na URL e sobrescreve os informados — é o
  que mantém filtros e paginação ao trocar a ordenação.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0, `[OK] No errors` |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| Teste único | `cd manager && php app/inc/lib/vendor/bin/phpunit --filter Ordenation` | exit 0 |
| Guard de sync | `bash bin/check-shared-sync.sh` | exit 0 |
| Verificação completa | `bin/test.sh` | exit 0 |

## Scope

**In scope**:
- `manager/app/inc/lib/CommonFunctions.php` (adicionar 2 funções)
- `site/app/inc/lib/CommonFunctions.php` (cópia idêntica)
- `manager/tests/OrdenationTest.php` (criar)
- `site/tests/OrdenationTest.php` (criar — mesmo conteúdo)

**Out of scope** (não toque):
- Os três controllers. A adoção acontece nos planos 005–007; aqui só nascem os
  helpers e seus testes.
- As views em `public_html/ui/page/`. Idem.
- `set_url()` — já faz o que precisamos.

## Git workflow

- Branch: `advisor/003-helpers-ordenacao`
- Commit em PT-BR, Conventional Commits. Sugestão:
  `feat: adiciona helpers de ordenacao com allowlist de colunas`.
- Não faça push nem abra PR a menos que o operador peça.

## Steps

### Step 1: Adicionar as duas funções

Em `manager/app/inc/lib/CommonFunctions.php`, adicione as duas funções logo
**depois** de `valid_slug()` (final do arquivo, linha 852 em diante). Indente
com 2 espaços.

```php
/**
 * Decompoe o parametro "coluna-direcao" (ex.: "name-desc") em ORDER BY seguro.
 *
 * ORDER BY nao aceita parametro bindado — o nome da coluna vai literal no SQL.
 * Por isso a coluna SO pode sair da allowlist; qualquer coisa fora dela cai no
 * default silenciosamente (a tela nao deve dar erro por query string torta).
 *
 * @param array<string> $allowed colunas que a tela permite ordenar
 * @return array{0: string, 1: string} [coluna, direcao] — direcao e 'asc' ou 'desc'
 */
function resolve_ordenation(?string $param, array $allowed, string $default = 'name', string $defaultDir = 'asc'): array
{
  $column    = $default;
  $direction = $defaultDir === 'desc' ? 'desc' : 'asc';

  if ($param !== null && preg_match('/^([a-zA-Z0-9_]+)-(asc|desc)$/', $param, $m) === 1) {
    if (in_array($m[1], $allowed, true)) {
      $column    = $m[1];
      $direction = $m[2];
    }
  }

  return [$column, $direction];
}

/**
 * Estado de um cabecalho clicavel: o valor de `ordenation` que o link deve
 * aplicar e o icone que representa a ordenacao vigente.
 *
 * Coluna que esta ordenando agora: o link oferece a direcao invertida e o icone
 * mostra a direcao atual. Demais colunas: link com a direcao inicial (asc) e
 * icone neutro.
 *
 * @return array{0: string, 1: string} [valor de ordenation, classe do icone]
 */
function ordenation_header(string $column, string $currentColumn, string $currentDirection): array
{
  if ($column !== $currentColumn) {
    return [$column . '-asc', 'bi bi-arrow-down-up'];
  }

  if ($currentDirection === 'asc') {
    return [$column . '-desc', 'bi bi-caret-up-fill'];
  }

  return [$column . '-asc', 'bi bi-caret-down-fill'];
}
```

Note o `=== 1` no `preg_match`: PHPStan level 4 reclama de comparação implícita
com `int|false`.

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0.

### Step 2: Replicar byte-a-byte no `site`

```bash
cp manager/app/inc/lib/CommonFunctions.php site/app/inc/lib/CommonFunctions.php
```

**Verify**: `bash bin/check-shared-sync.sh` → exit 0.

### Step 3: Escrever o teste

Crie `manager/tests/OrdenationTest.php`. Estas funções não tocam o banco, então
estenda `TestCase` puro — modele por `manager/tests/CommonFunctionsTest.php`
(que também é `TestCase` sem banco e testa helpers deste mesmo arquivo).

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre resolve_ordenation() e ordenation_header() (plano 003) — a allowlist
 * que impede injecao no ORDER BY e a alternancia clique-a-clique dos
 * cabecalhos.
 */
final class OrdenationTest extends TestCase
{
    private const ALLOWED = ['name', 'slug', 'created_at'];

    public function testDefaultWhenParamIsAbsent(): void
    {
        $this->assertSame(['name', 'asc'], resolve_ordenation(null, self::ALLOWED));
    }

    public function testDecomposesColumnAndDirection(): void
    {
        $this->assertSame(['slug', 'desc'], resolve_ordenation('slug-desc', self::ALLOWED));
        $this->assertSame(['created_at', 'asc'], resolve_ordenation('created_at-asc', self::ALLOWED));
    }

    public function testColumnOutsideAllowlistFallsBackToDefault(): void
    {
        $this->assertSame(['name', 'asc'], resolve_ordenation('password-asc', self::ALLOWED));
    }

    public function testInjectionAttemptFallsBackToDefault(): void
    {
        $malicious = "name-asc,(select 1 from users)";
        $this->assertSame(['name', 'asc'], resolve_ordenation($malicious, self::ALLOWED));

        $this->assertSame(['name', 'asc'], resolve_ordenation("name asc; drop table users", self::ALLOWED));
        $this->assertSame(['name', 'asc'], resolve_ordenation("name-asc'", self::ALLOWED));
    }

    public function testUnknownDirectionFallsBackToDefault(): void
    {
        $this->assertSame(['name', 'asc'], resolve_ordenation('slug-sideways', self::ALLOWED));
    }

    public function testCustomDefaults(): void
    {
        $this->assertSame(['created_at', 'desc'], resolve_ordenation(null, self::ALLOWED, 'created_at', 'desc'));
    }

    public function testHeaderOfTheActiveColumnOffersTheOppositeDirection(): void
    {
        $this->assertSame(['name-desc', 'bi bi-caret-up-fill'], ordenation_header('name', 'name', 'asc'));
        $this->assertSame(['name-asc', 'bi bi-caret-down-fill'], ordenation_header('name', 'name', 'desc'));
    }

    public function testHeaderOfOtherColumnsOffersAscAndNeutralIcon(): void
    {
        $this->assertSame(['slug-asc', 'bi bi-arrow-down-up'], ordenation_header('slug', 'name', 'asc'));
    }
}
```

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpunit --filter Ordenation`
→ exit 0, 8 testes passando.

### Step 4: Replicar o teste no `site` e verificar tudo

```bash
cp manager/tests/OrdenationTest.php site/tests/OrdenationTest.php
```

**Verify**: `bin/test.sh` → exit 0 nos dois ambientes.

## Test plan

- Arquivo novo: `manager/tests/OrdenationTest.php` + cópia em `site/tests/`.
- Casos: default sem parâmetro; decomposição coluna+direção; coluna fora da
  allowlist cai no default; três tentativas de injeção caem no default; direção
  inválida cai no default; defaults customizados; alternância do cabeçalho ativo;
  cabeçalho neutro das demais colunas.
- Padrão estrutural: `manager/tests/CommonFunctionsTest.php`.
- Comando: `cd manager && php app/inc/lib/vendor/bin/phpunit` → todos passam,
  incluindo os 8 novos.

## Done criteria

Todos devem valer:

- [ ] `grep -n "function resolve_ordenation\|function ordenation_header" manager/app/inc/lib/CommonFunctions.php` → 2 linhas
- [ ] `diff manager/app/inc/lib/CommonFunctions.php site/app/inc/lib/CommonFunctions.php` → sem saída
- [ ] `bash bin/check-shared-sync.sh` → exit 0
- [ ] `bin/test.sh` → exit 0, com os 8 testes novos passando nos dois ambientes
- [ ] `grep -rn "fas fa-" manager/app/inc/lib/CommonFunctions.php` → sem resultado (ícones são Bootstrap Icons)
- [ ] `git status` não mostra arquivos fora da lista de escopo
- [ ] Linha do plano 003 atualizada em `plans/README.md`

## STOP conditions

Pare e reporte se:

- `CommonFunctions.php` não bater com os trechos de "Current state" (drift).
- Você concluir que precisa aceitar coluna fora da allowlist para atender algum
  caso — não é o caso de nenhuma tela prevista; reporte em vez de afrouxar a
  validação.
- Aparecer necessidade de ordenar por expressão (ex.: `COUNT(*)`), que a regex
  `[a-zA-Z0-9_]+` não aceita. Reporte: a solução é mapear o apelido na allowlist,
  não relaxar a regex.
- A verificação de um passo falhar duas vezes após uma tentativa razoável de
  correção.

## Maintenance notes

- A allowlist mora no controller que chama (`private const ORDER_ALLOWED`), não
  aqui. Ao adicionar coluna clicável numa listagem, a coluna precisa entrar na
  allowlist **e** existir na tabela — o helper não valida contra o schema.
- Se algum dia a ordenação precisar aceitar mais de uma coluna
  (`?ordenation=name-asc,slug-desc`), esta função precisa mudar de contrato;
  hoje ela devolve um par só, de propósito.
- O revisor deve olhar: a regex de `resolve_ordenation` (é o gate de segurança
  inteiro) e o fato de nenhum controller ter sido tocado neste plano.

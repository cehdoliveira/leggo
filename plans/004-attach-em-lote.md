# Plan 004: Trocar o N+2 de `DOLModel::attach()` por duas queries em lote

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

- **Priority**: P2
- **Effort**: M
- **Risk**: MED — `attach()` é usado no caminho de login; um erro aqui quebra a
  autenticação do manager.
- **Depends on**: none (mas 001 mexe no mesmo arquivo — execute 001 antes para
  evitar conflito de merge)
- **Category**: perf
- **Planned at**: commit `a032c73`, 2026-08-11

## Why this matters

`attach()` existe para enriquecer uma lista com os vínculos de cada registro
**sem** consultar o banco linha a linha. Ele faz exatamente o contrário: roda
duas queries preparadas por registro. Numa listagem de 25 linhas com perfis
anexados são ~50 round-trips onde bastariam 2.

Isso vira gargalo assim que o padrão de controller dos planos 005–007 entrar: a
listagem de usuários chama `attach(["profiles"])` em toda página, e a versão
`.json` do mesmo endpoint traz a coleção inteira sem paginação — aí o N+2 é
sobre a base toda.

O próprio `DOLModel` já tem o padrão certo: `join()` foi otimizado para lote
(`DOLModel.php:361-391`) e o caminho por linha ficou só como fallback. Este
plano aplica a mesma forma em `attach()`.

## Current state

Arquivo: `manager/app/inc/lib/DOLModel.php` (cópia byte-a-byte idêntica em
`site/app/inc/lib/DOLModel.php`).

`attach()` como está hoje (`manager/app/inc/lib/DOLModel.php:303-344`) — as duas
queries estão **dentro** do `foreach` sobre as linhas:

```php
	public function attach(array $classes = array(), ?string $reverse_table = null, ?string $options = null, ?array $class_field = null): void
	{
		$new_data = array();
		$_data = $this->data;
		foreach ($_data as $key => $value) {
			$new_data[$key] = $value;
			foreach ($classes as $class) {
				$junctionTable = sprintf(
					"%s_%s",
					$reverse_table ? $class : $this->table,
					$reverse_table ? $this->table : $class
				);
				$parentCol = sprintf("%s_id", $this->table);
				$childCol  = sprintf("%s_id", $class);

				$r = $this->con->executePrepared(
					sprintf("SELECT %s as k FROM %s WHERE active = 'yes' AND %s = ?", $childCol, $junctionTable, $parentCol),
					[(int)$value["idx"]]
				);
				$filter_key_vals = array();
				foreach ($this->con->results($r) as $key_r => $data) {
					$filter_key_vals[] = $data["k"];
				}
				if (empty($filter_key_vals)) {
					$new_data[$key][$class . "_attach"] = array();
					continue;
				}
				$placeholders = implode(',', array_fill(0, count($filter_key_vals), '?'));
				$fields = isset($class_field) ? implode(", ", $class_field) : "*";
				$sql = sprintf(
					"SELECT %s FROM %s WHERE active = 'yes' AND idx IN (%s) %s",
					$fields,
					$class,
					$placeholders,
					$options ?? ''
				);
				$r = $this->con->executePrepared($sql, $filter_key_vals);
				$new_data[$key][$class . "_attach"] = $this->con->results($r);
			}
		}
		$this->set_data($new_data);
	}
```

O padrão em lote que já existe no mesmo arquivo, em `join()`
(`DOLModel.php:361-391`) — colete os ids, uma query com `IN (...)`, agrupe o
resultado num mapa e distribua pelas linhas:

```php
			$batchResults = [];
			if (!empty($lookupIds)) {
				$placeholders = implode(',', array_fill(0, count($lookupIds), '?'));
				$fields = isset($field) ? implode(", ", $field) : "*";
				$sql = sprintf(
					"SELECT %s FROM %s WHERE active = 'yes' AND %s IN (%s)",
					$fields, $table, $fwColumn, $placeholders
				);
				$r = $this->con->executePrepared($sql, $lookupIds);
				foreach ($this->con->results($r) as $row) {
					$batchResults[$row[$fwColumn]][] = $row;
				}
			}

			foreach ($_data as $key => $value) {
				$new_data[$key] = $value;
				$lookupVal = isset($value[$dataKey]) ? (int)$value[$dataKey] : null;
				$new_data[$key][$name . "_attach"] = $batchResults[$lookupVal] ?? [];
			}
```

**Chamadores vivos de `attach()`** — o comportamento precisa ficar idêntico para
os três:

| Chamador | Chamada |
|---|---|
| `manager/app/inc/controller/auth_controller.php:55` | `$users->attach(["profiles"]);` — decide se o usuário é admin no login |
| `site/app/inc/controller/auth_controller.php` | mesma chamada no login do site (confirme com `grep -n "attach(" site/app/inc/controller/auth_controller.php`) |
| `manager/app/inc/lib/DOLModel.php:237` | dentro de `_current_data()`, repassa `direction` e `specific` |

O contrato observável que **não** pode mudar:

1. Cada linha ganha a chave `"{$class}_attach"`, sempre presente — array vazio
   quando não há vínculo.
2. A ordem das linhas de `$this->data` é preservada, com as mesmas chaves de
   array.
3. `$reverse_table` inverte a ordem dos nomes na tabela de junção.
4. `$options` é um trecho de SQL cru concatenado ao final da query filha
   (ex.: `' and idx > 1 '`, usado em `exemplo_controller.php:60`).
5. `$class_field` limita as colunas da query filha; `null` = `*`.
6. Só entram linhas com `active = 'yes'` na junção **e** na tabela filha.

**Convenções**: `DOLModel.php` usa **tabs**; `app/inc/lib/` é cópia byte-a-byte
entre `manager/` e `site/` (`bin/check-shared-sync.sh` bloqueia o commit se
divergir).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0, `[OK] No errors` |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| Teste único | `cd manager && php app/inc/lib/vendor/bin/phpunit --filter Attach` | exit 0 |
| Suíte inteira | `cd manager && php app/inc/lib/vendor/bin/phpunit` | exit 0 |
| Guard de sync | `bash bin/check-shared-sync.sh` | exit 0 |
| Verificação completa | `bin/test.sh` | exit 0 |

## Scope

**In scope**:
- `manager/app/inc/lib/DOLModel.php` (reescrever o corpo de `attach()`)
- `site/app/inc/lib/DOLModel.php` (cópia idêntica)
- `manager/tests/AttachBatchTest.php` (criar)
- `site/tests/AttachBatchTest.php` (criar — mesmo conteúdo)

**Out of scope** (não toque):
- `attach_son()` (`DOLModel.php:425`) — tem o mesmo problema, mas é usado só via
  `_current_data()` sobre **uma** linha, onde o N+2 não custa. Deixe como está;
  está registrado nas notas como follow-up.
- `join()` — já é em lote; é a referência, não o alvo.
- `save_attach()` — escrita, fora do escopo deste plano.
- Qualquer controller ou view.

## Git workflow

- Branch: `advisor/004-attach-em-lote`
- Commit em PT-BR, Conventional Commits. Sugestão:
  `perf: agrupa queries de DOLModel::attach() em lote`.
- Não faça push nem abra PR a menos que o operador peça.

## Steps

### Step 1: Escrever o teste de caracterização ANTES de mudar o código

Este passo é obrigatório e vem primeiro: o teste tem que passar com o `attach()`
**atual**, para provar que ele descreve o comportamento existente. Só então o
Step 2 troca a implementação e o mesmo teste vira a rede de segurança.

Crie `manager/tests/AttachBatchTest.php`, modelando por
`manager/tests/MessagesFilterTest.php` (`DBTestCase`, fixtures com `uniqid()`).
Use `users` + `users_profiles` + `profiles`, que é a junção real do projeto
(migration `004_create_table_users_profiles.sql`).

```php
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
}
```

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpunit --filter Attach`
→ exit 0, 5 testes passando **com o `attach()` ainda não modificado**. Se algum
falhar agora, veja STOP conditions.

### Step 2: Reescrever `attach()` em lote

Substitua o corpo inteiro de `attach()` em `manager/app/inc/lib/DOLModel.php`
(mantenha a assinatura exatamente como está). Indente com tabs.

```php
	public function attach(array $classes = array(), ?string $reverse_table = null, ?string $options = null, ?array $class_field = null): void
	{
		$_data = $this->data;
		if (empty($_data) || empty($classes)) {
			return;
		}

		$parentIds = array();
		foreach ($_data as $value) {
			if (isset($value["idx"])) {
				$parentIds[] = (int)$value["idx"];
			}
		}
		$parentIds = array_values(array_unique($parentIds));

		$new_data = $_data;

		foreach ($classes as $class) {
			// Toda linha recebe a chave, mesmo sem vinculo.
			foreach ($new_data as $key => $value) {
				$new_data[$key][$class . "_attach"] = array();
			}
			if (empty($parentIds)) {
				continue;
			}

			$junctionTable = sprintf(
				"%s_%s",
				$reverse_table ? $class : $this->table,
				$reverse_table ? $this->table : $class
			);
			$parentCol = sprintf("%s_id", $this->table);
			$childCol  = sprintf("%s_id", $class);

			// Query 1: todos os vinculos ativos dos pais desta pagina, de uma vez.
			$parentPlaceholders = implode(',', array_fill(0, count($parentIds), '?'));
			$r = $this->con->executePrepared(
				sprintf(
					"SELECT %s as p, %s as k FROM %s WHERE active = 'yes' AND %s IN (%s)",
					$parentCol,
					$childCol,
					$junctionTable,
					$parentCol,
					$parentPlaceholders
				),
				$parentIds
			);

			$childIdsByParent = array();
			$allChildIds = array();
			foreach ($this->con->results($r) as $link) {
				$childIdsByParent[(int)$link["p"]][] = (int)$link["k"];
				$allChildIds[] = (int)$link["k"];
			}
			$allChildIds = array_values(array_unique($allChildIds));
			if (empty($allChildIds)) {
				continue;
			}

			// Query 2: todas as linhas filhas ativas, de uma vez.
			$childPlaceholders = implode(',', array_fill(0, count($allChildIds), '?'));
			$fields = isset($class_field) ? implode(", ", $class_field) : "*";
			$r = $this->con->executePrepared(
				sprintf(
					"SELECT %s FROM %s WHERE active = 'yes' AND idx IN (%s) %s",
					$fields,
					$class,
					$childPlaceholders,
					$options ?? ''
				),
				$allChildIds
			);

			$childRowsById = array();
			foreach ($this->con->results($r) as $row) {
				$childRowsById[(int)$row["idx"]] = $row;
			}

			foreach ($new_data as $key => $value) {
				$parentId = isset($value["idx"]) ? (int)$value["idx"] : null;
				if ($parentId === null || !isset($childIdsByParent[$parentId])) {
					continue;
				}
				$rows = array();
				foreach ($childIdsByParent[$parentId] as $childId) {
					if (isset($childRowsById[$childId])) {
						$rows[] = $childRowsById[$childId];
					}
				}
				$new_data[$key][$class . "_attach"] = $rows;
			}
		}

		$this->set_data($new_data);
	}
```

Dois pontos que **não** podem ser simplificados:

- O mapa `$childRowsById` é indexado por `idx`, então `$class_field` **precisa**
  incluir `idx`. Se o chamador passar `class_field` sem `idx`, o agrupamento
  perde as linhas. Trate isso no Step 3.
- `$options` continua sendo SQL cru concatenado — é assim que os chamadores
  existentes usam. Não tente parametrizá-lo neste plano.

**Verify**:
1. `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0.
2. `cd manager && php app/inc/lib/vendor/bin/phpunit --filter Attach` → exit 0,
   os mesmos 5 testes do Step 1 passando.

### Step 3: Garantir `idx` na lista de colunas filhas

Ainda em `attach()`, logo antes de montar `$fields`, force a presença de `idx`:

```php
			$fields = "*";
			if (isset($class_field)) {
				$normalized = array_map('trim', $class_field);
				if (!in_array("idx", $normalized, true)) {
					$class_field[] = " idx ";
				}
				$fields = implode(", ", $class_field);
			}
```

Sem isso, `attach(['profiles'], null, null, [' name '])` devolveria vínculos
vazios — regressão silenciosa em relação à versão por linha, que não dependia do
`idx` na resposta.

Acrescente ao teste do Step 1 um caso que prova isso:

```php
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
```

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpunit --filter Attach` →
exit 0, 6 testes passando.

### Step 4: Confirmar que o login não regrediu

`attach()` decide se o usuário é admin no login
(`manager/app/inc/controller/auth_controller.php:55` seguido do laço em `:72-77`
sobre `profiles_attach`). Rode a suíte inteira, que cobre o fluxo de auth.

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpunit` → exit 0, sem
falha em `AuthFunctionsTest` nem em nenhum outro teste que passava antes.

### Step 5: Replicar no `site` e verificar tudo

```bash
cp manager/app/inc/lib/DOLModel.php site/app/inc/lib/DOLModel.php
cp manager/tests/AttachBatchTest.php site/tests/AttachBatchTest.php
```

**Verify**: `bash bin/check-shared-sync.sh` → exit 0; `bin/test.sh` → exit 0.

### Step 6: Confirmar a redução de queries

Prove que o objetivo foi atingido, não só que nada quebrou. Rode este script
pontual (crie em `/tmp`, **não** no repositório) contando as queries com
`SHOW SESSION STATUS LIKE 'Questions'` antes e depois de um `attach()` sobre 3
linhas:

```bash
docker exec leggo php -r '
$_SERVER["HTTP_HOST"] = "manager.leggo.local";
require "/var/www/leggo/manager/app/inc/main.php";
$m = new users_model();
$m->set_field([" idx "]);
$m->set_filter([" idx > 0 "]);
$m->set_paginate([0, 3]);
$m->load_data(false);
$con = localPDO::getInstance();
$before = (int)$con->result($con->executePrepared("SHOW SESSION STATUS LIKE \"Questions\""), "Value", 0);
$m->attach(["profiles"]);
$after = (int)$con->result($con->executePrepared("SHOW SESSION STATUS LIKE \"Questions\""), "Value", 0);
echo "queries do attach: ", ($after - $before - 1), PHP_EOL;
'
```

**Verify**: a saída deve mostrar **2** (ou menos, se não houver vínculo). Antes
da mudança, com 3 linhas, seriam 6. Se der mais que 2, a implementação ainda tem
query dentro do laço — volte ao Step 2.

## Test plan

- Arquivo novo: `manager/tests/AttachBatchTest.php` + cópia em `site/tests/`.
- Casos: agrupamento correto por pai (2/1/0 vínculos); ordem e chaves das linhas
  preservadas; `class_field` e `options` respeitados; vínculo inativo ignorado;
  conjunto vazio não quebra; `class_field` sem `idx` continua funcionando.
- Escrito **antes** da mudança (caracterização) e mantido depois — é o critério
  de "comportamento idêntico".
- Padrão estrutural: `manager/tests/MessagesFilterTest.php`.

## Done criteria

Todos devem valer:

- [ ] `cd manager && php app/inc/lib/vendor/bin/phpunit --filter Attach` → exit 0, 6 testes
- [ ] `bin/test.sh` → exit 0 nos dois ambientes
- [ ] O script do Step 6 imprime `queries do attach: 2` (ou menos)
- [ ] `diff manager/app/inc/lib/DOLModel.php site/app/inc/lib/DOLModel.php` → sem saída
- [ ] `bash bin/check-shared-sync.sh` → exit 0
- [ ] A assinatura de `attach()` é idêntica à original (`grep -n "public function attach" manager/app/inc/lib/DOLModel.php`)
- [ ] `git status` não mostra arquivos fora da lista de escopo
- [ ] Linha do plano 004 atualizada em `plans/README.md`

## STOP conditions

Pare e reporte se:

- Algum teste do Step 1 **falhar antes** de qualquer mudança em `attach()`.
  Isso significa que o teste não descreve o comportamento atual — corrigir o
  teste para casar com a implementação nova invalidaria toda a caracterização.
- `attach()` no repositório não bater com o trecho de "Current state" (drift).
- Aparecer um chamador de `attach()` que dependa de comportamento não listado no
  "contrato observável" (ex.: contar com o model sujo depois da chamada).
- O login do manager parar de reconhecer o admin em teste manual.
- A verificação de um passo falhar duas vezes após uma tentativa razoável de
  correção.

## Maintenance notes

- `attach_son()` (`DOLModel.php:425`) tem o mesmo N+2 e ficou de fora de
  propósito: hoje só roda via `_current_data()`, sobre uma linha. Se algum dia
  for usado numa listagem, aplique este mesmo padrão.
- Limite prático: os dois `IN (...)` crescem com o número de linhas da página. Com
  a paginação atual (25) não há risco; se alguma tela passar a trazer milhares de
  linhas (o `.json` sem paginação do padrão de controller, por exemplo), vale
  fatiar em blocos.
- O revisor deve olhar: (a) que a chave `"{$class}_attach"` é sempre criada,
  inclusive nos caminhos de saída antecipada; (b) o `idx` forçado em
  `class_field`; (c) que `$options` continua sendo concatenado igual.

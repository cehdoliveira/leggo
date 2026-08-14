# Plan 012: `save_attach()` desvincula quando a lista vem vazia (e o contorno com SQL cru sai do controller)

> **Executor instructions**: Siga este plano passo a passo. Rode todo comando de
> verificação e confirme o resultado esperado antes de avançar. Se acontecer
> qualquer coisa listada em "STOP conditions", pare e reporte — não improvise.
> Ao terminar, atualize a linha deste plano em `plans/README.md`.
>
> **Drift check (rode primeiro)**:
> `git diff --stat 23f6d0f..HEAD -- manager/app/inc/lib/DOLModel.php site/app/inc/lib/DOLModel.php manager/app/inc/controller/users_controller.php`
> Se algum arquivo em escopo mudou, compare com "Current state"; se não bater, é
> STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none (o teste fica melhor com o plano 009, mas não depende dele — o caso central é testável direto no model)
- **Category**: bug
- **Planned at**: commit `23f6d0f`, 2026-08-13

## Why this matters

`DOLModel::save_attach()` só executa quando a lista de vínculos recebida **não
está vazia**. Consequência: "desmarquei todos os perfis e salvei" não desvincula
nada — o registro fica com os vínculos antigos, silenciosamente. Não é um caso de
borda: é a operação normal de tirar todos os papéis de um usuário.

Isso já foi descoberto no plano 006 e contornado **dentro do controller**, com um
`UPDATE` em SQL cru (`users_controller.php:353-359`). A pendência registrada em
`plans/README.md` diz: "a correção limpa é no framework (duas cópias + testes) e
merece plano próprio se o contorno se repetir numa segunda entidade". O ponto é
que a segunda entidade many-to-many vai repetir o bug — o autor dela não tem como
saber que precisa do contorno, porque a assinatura de `save_attach()` não sugere
nada. Corrigir no framework apaga a armadilha e deleta o SQL cru do controller.

## Current state

Arquivos relevantes:

- `manager/app/inc/lib/DOLModel.php` e `site/app/inc/lib/DOLModel.php` — cópias
  **byte-a-byte idênticas**; `save_attach()` começa na linha 576.
- `manager/app/inc/controller/users_controller.php:349-360` — o contorno.
- `manager/tests/DolModelWriteTest.php:102-123` — teste existente de
  `save_attach()` (caso com lista não vazia). Exemplar estrutural.
- `manager/tests/UsersControllerTest.php:176` — teste que hoje cobre o contorno
  ("Contorno do Step 5b: save_attach() nao age com lista vazia").
- `migrations/004_create_table_users_profiles.sql` — a tabela de junção:
  `users_id`, `profiles_id`, `active`, `removed_at`, `removed_by`, UNIQUE
  `(users_id, profiles_id)`.

Código atual, `DOLModel.php:582-614` — o `if (count($varexecute))` da linha 592 é
o defeito: ele engloba **também** o `UPDATE` de desativação:

```php
		foreach ($classes as $class) {
			if (isset($info["post"][$class . "_id"])) {
				$execute = $info["post"][$class . "_id"];
				$varexecute = array();
				if (is_array($execute) && count($execute)) {
					$varexecute = $execute;
				} elseif (!is_array($execute) && (int)$execute > 0) {
					$varexecute[] = $execute;
				}

				if (count($varexecute)) {
					$junctionTable = sprintf(
						" %s_%s ",
						$reverse_table ? $class : $this->table,
						$reverse_table ? $this->table : $class
					);
					$tableIdCol = sprintf(" %s_id ", $this->table);

					$this->con->executePrepared(
						"UPDATE {$junctionTable} SET active = 'no', removed_at = now(), removed_by = ? WHERE active = 'yes' AND {$tableIdCol} = ?",
						[$userId, $info["idx"]]
					);

					$classIdCol = sprintf(" %s_id ", $class);
					foreach ($varexecute as $var) {
						$this->con->executePrepared(
							"INSERT INTO {$junctionTable} ({$classIdCol}, {$tableIdCol}, created_by, created_at) VALUES (?, ?, ?, now()) ON DUPLICATE KEY UPDATE active = 'yes', removed_at = NULL, removed_by = NULL, modified_at = now(), modified_by = ?",
							[$var, $info["idx"], $userId, $userId]
						);
					}
				}
			}
		}
```

Contorno atual, `users_controller.php:349-360`:

```php
            if ($keepOwnAdminAccess) {
                // Vínculos do próprio admin permanecem como estavam.
            } elseif ($profileIds !== []) {
                $model->save_attach(['idx' => $idx, 'post' => ['profiles_id' => $profileIds]], ['profiles']);
            } else {
                // save_attach() não age com lista vazia (DOLModel.php:498) — sem
                // isto, desmarcar todos os perfis não desvincularia nada.
                $model->execute_raw_prepared(
                    "UPDATE users_profiles SET active = 'no', removed_at = now(), removed_by = ? WHERE active = 'yes' AND users_id = ?",
                    [$loggedInId, $idx]
                );
            }
```

(Note que o comentário cita `DOLModel.php:498`, número desatualizado — o método
está hoje na linha 576. Isso também será resolvido, já que o comentário sai.)

Convenções que se aplicam:

- **As duas cópias de `app/inc/lib/` são idênticas** — o guard
  `bin/check-shared-sync.sh` roda no pre-commit e bloqueia o commit se divergirem.
  Edite `manager/` e copie para `site/` com `cp`.
- Soft-delete universal: nunca `DELETE FROM`; sempre `active = 'no'` +
  `removed_at`/`removed_by`.
- Todo valor variável vai bindado (`?` + array de params).
- Testes que tocam banco estendem `DBTestCase` (transação + rollback por teste).
- Comentários em PT-BR. `NEVER` reformatar arquivo inteiro.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Sintaxe | `php -l manager/app/inc/lib/DOLModel.php` | `No syntax errors` |
| Sync guard | `bash bin/check-shared-sync.sh` | exit 0 |
| PHPStan | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` (repita em `site`) | `[OK] No errors` |
| PHPUnit manager | `docker exec -w /var/www/leggo/manager leggo php app/inc/lib/vendor/bin/phpunit` | exit 0 |
| Testes de attach | `docker exec -w /var/www/leggo/manager leggo php app/inc/lib/vendor/bin/phpunit --filter Attach` | exit 0 |
| Gate completo | `bash bin/test.sh` | exit 0 |

## Scope

**In scope**:

- `manager/app/inc/lib/DOLModel.php`
- `site/app/inc/lib/DOLModel.php` (cópia byte-a-byte)
- `manager/app/inc/controller/users_controller.php` (remover o contorno)
- `manager/tests/DolModelWriteTest.php` (adicionar casos)
- `site/tests/DolModelWriteTest.php` (cópia byte-a-byte)
- `manager/tests/UsersControllerTest.php` (ajustar o teste que cita o contorno)

**Out of scope**:

- `attach()`, `attach_son()`, `join()` — não mexa. `attach_son()` tem um N+2
  conhecido, registrado como nota de manutenção do plano 004; não é assunto deste
  plano.
- `save()` — assunto do plano 013.
- `profiles_controller.php`, `emails_controller.php` — não usam `save_attach()`.
- A assinatura pública de `save_attach()` — **não pode mudar**. Mesma ordem, mesmos
  tipos, mesmos defaults. É o contrato que 3 arquivos e 4 testes consomem.

## Git workflow

- Branch: `advisor/012-save-attach-lista-vazia`
- Dois commits (framework+teste; remoção do contorno). Conventional Commits em
  PT-BR. Exemplo real: `fix: DOLModel::populate() não ignora mais valores falsy como "0"`
  Sugestão: `fix: save_attach() desvincula quando a lista vem vazia`
- **Não** faça push nem abra PR a menos que o operador peça.

## Steps

### Step 1: Escrever o teste que falha (caracterização do bug)

Antes de tocar no framework, adicione a `manager/tests/DolModelWriteTest.php` um
caso que prove o bug — ele deve **falhar** agora:

- Crie um usuário e um perfil de fixture; vincule com
  `save_attach(['idx' => $userId, 'post' => ['profiles_id' => [$profileId]]], ['profiles'])`.
- Confirme que a linha de junção está `active = 'yes'`.
- Chame `save_attach(['idx' => $userId, 'post' => ['profiles_id' => []]], ['profiles'])`.
- Afirme que a linha de junção agora está `active = 'no'`.

Modele por `DolModelWriteTest.php:95-125` (o caso de `save_attach` que já existe:
mesma montagem de fixture, mesma consulta de verificação via
`execute_raw_prepared`).

**Verify**:

```bash
docker exec -w /var/www/leggo/manager leggo php app/inc/lib/vendor/bin/phpunit --filter DolModelWrite
```
→ **1 falha**, exatamente no caso novo. Se ele passar, o bug não existe mais →
STOP condition.

### Step 2: Corrigir `save_attach()`

Em `manager/app/inc/lib/DOLModel.php`, mova o cálculo do nome da tabela de junção
e o `UPDATE` de desativação para **fora** do `if (count($varexecute))`, deixando
dentro do `if` apenas o laço de INSERT. A forma alvo (substitui as linhas 592-612):

```php
				$junctionTable = sprintf(
					" %s_%s ",
					$reverse_table ? $class : $this->table,
					$reverse_table ? $this->table : $class
				);
				$tableIdCol = sprintf(" %s_id ", $this->table);

				// Desativa os vinculos atuais SEMPRE que a chave foi enviada,
				// inclusive com lista vazia — "desmarquei todos" e uma operacao
				// valida e tem que desvincular. O guard `isset()` acima e o que
				// distingue "nao enviou o campo" (nao mexe) de "enviou vazio"
				// (desvincula tudo).
				$this->con->executePrepared(
					"UPDATE {$junctionTable} SET active = 'no', removed_at = now(), removed_by = ? WHERE active = 'yes' AND {$tableIdCol} = ?",
					[$userId, $info["idx"]]
				);

				if (count($varexecute)) {
					$classIdCol = sprintf(" %s_id ", $class);
					foreach ($varexecute as $var) {
						$this->con->executePrepared(
							"INSERT INTO {$junctionTable} ({$classIdCol}, {$tableIdCol}, created_by, created_at) VALUES (?, ?, ?, now()) ON DUPLICATE KEY UPDATE active = 'yes', removed_at = NULL, removed_by = NULL, modified_at = now(), modified_by = ?",
							[$var, $info["idx"], $userId, $userId]
						);
					}
				}
```

**A distinção que importa** (e que o comentário registra): o `isset($info["post"][$class . "_id"])`
da linha 583 continua sendo o portão. Quem **não envia** a chave não tem vínculo
tocado; quem envia vazio desvincula tudo. Não altere esse `isset`.

Copie para o site:
`cp manager/app/inc/lib/DOLModel.php site/app/inc/lib/DOLModel.php`

> Se `diff manager/app/inc/lib/DOLModel.php site/app/inc/lib/DOLModel.php`
> mostrar diferença **antes** da sua edição, é STOP condition.

**Verify**:

```bash
php -l manager/app/inc/lib/DOLModel.php
diff manager/app/inc/lib/DOLModel.php site/app/inc/lib/DOLModel.php    # sem saída
bash bin/check-shared-sync.sh                                          # exit 0
docker exec -w /var/www/leggo/manager leggo php app/inc/lib/vendor/bin/phpunit --filter DolModelWrite   # exit 0, o caso novo passa
docker exec -w /var/www/leggo/manager leggo php app/inc/lib/vendor/bin/phpunit --filter Attach          # exit 0
```

### Step 3: Copiar os testes para o site e rodar as duas suítes

```bash
cp manager/tests/DolModelWriteTest.php site/tests/DolModelWriteTest.php
docker exec -w /var/www/leggo/manager leggo php app/inc/lib/vendor/bin/phpunit
docker exec -w /var/www/leggo/site   leggo php app/inc/lib/vendor/bin/phpunit
```

**Verify**: exit 0 nos dois, zero falhas. Baseline em `23f6d0f`: manager 146,
site 111 — agora +1 em cada (ou mais, se você escreveu casos extras).

### Step 4: Remover o contorno de `users_controller.php`

Substitua o bloco de `users_controller.php:349-360` por:

```php
            if ($keepOwnAdminAccess) {
                // Vínculos do próprio admin permanecem como estavam.
            } else {
                // Lista vazia desvincula tudo — save_attach() trata isso desde o
                // plano 012.
                $model->save_attach(['idx' => $idx, 'post' => ['profiles_id' => $profileIds]], ['profiles']);
            }
```

Depois disso, verifique se `$loggedInId` ficou sem uso **por causa da sua
mudança** (era usado no `execute_raw_prepared` removido). Se sim, e se ele não
tiver outro uso no método, remova a atribuição — é órfão criado pela sua edição.
Se ele tiver outro uso, deixe. Confirme com:

```bash
grep -n "loggedInId" manager/app/inc/controller/users_controller.php
```

**Verify**:

```bash
grep -n "execute_raw_prepared" manager/app/inc/controller/users_controller.php
```
→ ainda deve aparecer no `action()`/export ou onde já existia antes; **não** deve
aparecer mais no bloco de `save()` com `users_profiles`. Especificamente:

```bash
grep -c "UPDATE users_profiles" manager/app/inc/controller/users_controller.php   # 0
php -l manager/app/inc/controller/users_controller.php
cd manager && php app/inc/lib/vendor/bin/phpstan analyse    # [OK] No errors
```

### Step 5: Ajustar o teste do contorno em `UsersControllerTest`

`manager/tests/UsersControllerTest.php` tem um teste
(`testUnlinkingAllProfilesRemovesAttach`, comentário na linha 176 citando "Contorno
do Step 5b") que verifica o comportamento de desvincular tudo. **O teste continua
valendo** — o comportamento é o mesmo, a implementação mudou de lugar. Ajuste
apenas o comentário, que passa a estar errado:

- Troque a menção a "contorno" por: `// Desvincular todos passa por save_attach() (plano 012).`

Não mude asserções.

**Verify**:

```bash
grep -rn "Contorno do Step 5b" manager/tests/            # nenhuma saída
grep -rn "save_attach() não age com lista vazia" manager/ | grep -v plans/   # nenhuma saída
docker exec -w /var/www/leggo/manager leggo php app/inc/lib/vendor/bin/phpunit --filter UsersController   # exit 0
```

### Step 6: Gate completo

```bash
bash bin/test.sh
```

**Verify**: exit 0.

## Test plan

- Caso novo em `manager/tests/DolModelWriteTest.php` (+ cópia em `site/tests/`):
  vincula 1 perfil → desvincula com lista vazia → junção fica `active = 'no'`.
- Caso novo no mesmo arquivo: **chave ausente** (`post` sem `profiles_id`) **não**
  desvincula nada — é a metade do contrato que protege quem salva um formulário
  parcial. Esse caso é obrigatório: sem ele, alguém "simplifica" o `isset()` e
  quebra tudo silenciosamente.
- Caso novo: vincular A+B, depois salvar só A → B fica `active = 'no'` e A
  continua `'yes'` (garante que a mudança não regrediu o caminho normal).
- Padrão estrutural: `manager/tests/DolModelWriteTest.php:95-125`.
- Teste existente `UsersControllerTest::testUnlinkingAllProfilesRemovesAttach`
  passa a exercitar o caminho do framework — ele é a prova de que o contorno podia
  sair.
- Verificação: `bash bin/test.sh` → exit 0, com 3 testes novos por ambiente.

## Done criteria

Todos devem valer:

- [ ] `grep -c "UPDATE users_profiles" manager/app/inc/controller/users_controller.php` → 0
- [ ] `diff manager/app/inc/lib/DOLModel.php site/app/inc/lib/DOLModel.php` → sem saída
- [ ] `diff manager/tests/DolModelWriteTest.php site/tests/DolModelWriteTest.php` → sem saída
- [ ] `bash bin/check-shared-sync.sh` → exit 0
- [ ] PHPStan `[OK] No errors` nos dois ambientes
- [ ] `bash bin/test.sh` → exit 0, com 3 testes novos em cada ambiente
- [ ] `grep -n "public function save_attach" manager/app/inc/lib/DOLModel.php` mostra a assinatura **inalterada**
- [ ] `git status` não mostra arquivo fora do escopo
- [ ] Linha do plano 012 atualizada em `plans/README.md`

## STOP conditions

Pare e reporte se:

- O teste do Step 1 **passar** antes da correção (bug já corrigido por outro
  caminho).
- `diff` entre as cópias de `DOLModel.php` mostrar divergência antes da edição.
- Algum teste existente de `attach()` quebrar. `attach()` lê `active = 'yes'` da
  junção; se um teste que vinculava e relia passar a ver vazio, a ordem
  UPDATE/INSERT ficou errada — revise, e se não resolver de primeira, reporte.
- A remoção do contorno exigir mexer em mais que o bloco indicado do
  `users_controller.php`.
- Aparecer uma terceira entidade usando `save_attach()` que não está mapeada aqui.

## Maintenance notes

- O contrato agora é: **chave ausente = não mexe; chave presente (mesmo vazia) =
  substitui o conjunto**. Isso é o que qualquer formulário parcial precisa. Quem
  mexer no `isset()` da linha 583 quebra a primeira metade.
- O `save_attach()` faz 1 UPDATE + N INSERTs. Para listas grandes isso é N+1 —
  aceitável hoje (perfis por usuário é dezenas no pior caso). Se aparecer uma
  entidade com centenas de vínculos, o INSERT em lote (`VALUES (...), (...)`) é a
  próxima otimização.
- O que um revisor deve olhar: se o `UPDATE` de desativação ficou **fora** do
  `if (count(...))` mas **dentro** do `if (isset(...))`. Fora dos dois, um POST
  sem a chave apagaria vínculos.
- Deferido de propósito: `attach_son()` (N+2 conhecido, custo irrelevante hoje) e
  INSERT em lote.

# Plan 002: Fechar a transação em respostas terminais sem redirect (`json_response`, `array_to_csv`)

> **Executor instructions**: Siga este plano passo a passo. Rode cada comando de
> verificação e confirme o resultado esperado antes de seguir. Se ocorrer
> qualquer item da seção "STOP conditions", pare e reporte — não improvise.
> Ao terminar, atualize a linha deste plano em `plans/README.md`.
>
> **Drift check (rode primeiro)**:
> `git diff --stat a032c73..HEAD -- manager/app/inc/lib/CommonFunctions.php site/app/inc/lib/CommonFunctions.php manager/app/inc/lib/localPDO.php`
> Se algum arquivo em escopo mudou desde este plano, compare os trechos de
> "Current state" com o código vivo antes de prosseguir; divergência = STOP.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: MED — mexe no gate de transação de todo request que responde JSON/CSV.
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `a032c73`, 2026-08-11

## Why this matters

Neste framework `basic_redir()` é o **único** ponto que faz commit da transação
global do request. Quem responde e encerra o request por outro caminho sai sem
commitar, e o `__destruct()` do `localPDO` faz rollback de segurança — a escrita
some sem erro nenhum, nem no log.

Isso é hoje latente (o único chamador é uma exportação CSV somente-leitura, onde
o rollback não custa nada), mas o padrão de controller que este projeto vai
adotar tem um caminho de gravação que termina exatamente assim: `save()` com o
formulário sinalizando "não navegue" responde uma confirmação mínima e encerra.
Sem esta correção, esse caminho grava e o request desfaz — o usuário vê "ok" e
o dado não existe. É a correção que precisa entrar **antes** dos planos 005–007.

## Current state

Arquivos relevantes:

- `manager/app/inc/lib/CommonFunctions.php` — helpers do framework, inclusive as
  três funções terminais. Cópia idêntica em `site/app/inc/lib/CommonFunctions.php`.
- `manager/app/inc/lib/localPDO.php` — dono da transação global.

O rollback de segurança (`manager/app/inc/lib/localPDO.php:44-49`):

```php
	public function __destruct()
	{
		if ($this->ownsTransaction && $this->inTransaction) {
			$this->rollback();
		}
	}
```

A transação abre sozinha na primeira chamada a `getInstance()`
(`localPDO.php:34-42`), com `ownsTransaction = true`.

O gate que **funciona** hoje, e cujo padrão deve ser copiado
(`manager/app/inc/lib/CommonFunctions.php:144-159`):

```php
function basic_redir(string|array $url, int $code = 302, bool $use_html = false, bool $rollback = false): never
{
  if (is_array($url)) {
    $url = $url[0];
  }

  try {
    if ($rollback) {
      localPDO::getInstance()->rollback();
    } else {
      localPDO::getInstance()->commit();
    }
  } catch (\Throwable) {
    // localPDO might not be initialized if no DB ops occurred
  }
```

As duas funções terminais **sem** gate:

`json_response()` (`CommonFunctions.php:804-825`) — sai por `exit()` em dois
pontos (erro de encode e final) sem tocar na transação:

```php
function json_response(mixed $data, int $code = 200): never
{
  http_response_code($code);
  header('Content-Type: application/json; charset=UTF-8');
  ...
  echo $json;
  exit();
}
```

`array_to_csv()` (`CommonFunctions.php:772-802`) — mesma coisa, com dois `exit()`
(um no caminho de dados vazios, outro no final).

Chamador atual de `array_to_csv()`:
`manager/app/inc/controller/site_controller.php:66-75` (ação `export-csv`).
`json_response()` não tem chamador em controller hoje
(`grep -rn "json_response" manager/app/inc/controller site/app/inc/controller`
não retorna nada).

**Estilo**: `CommonFunctions.php` é indentado com **2 espaços** (diferente do
`DOLModel.php`, que usa tabs). Mantenha 2 espaços. Comentários em PT-BR.

**Convenção obrigatória** (`AGENTS.md`): `app/inc/lib/` é cópia byte-a-byte
idêntica entre `manager/` e `site/`. `bin/check-shared-sync.sh` bloqueia o
commit se divergir.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0, `[OK] No errors` |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| Testes manager | `cd manager && php app/inc/lib/vendor/bin/phpunit` | exit 0 |
| Teste único | `cd manager && php app/inc/lib/vendor/bin/phpunit --filter CommitGate` | exit 0 |
| Guard de sync | `bash bin/check-shared-sync.sh` | exit 0 |
| Verificação completa | `bin/test.sh` | exit 0 |

## Scope

**In scope**:
- `manager/app/inc/lib/CommonFunctions.php` (editar `json_response` e `array_to_csv`)
- `site/app/inc/lib/CommonFunctions.php` (cópia idêntica)
- `manager/tests/CommitGateTest.php` (criar)
- `site/tests/CommitGateTest.php` (criar — mesmo conteúdo)

**Out of scope** (não toque):
- `localPDO.php` — o rollback de segurança no destrutor está **correto** e é a
  rede de proteção do framework. Não o afrouxe.
- `basic_redir()` — já funciona; não refatore para "compartilhar" o bloco de
  commit com as novas funções. Duas cópias de 6 linhas custam menos que uma
  indireção nova no gate de transação.
- Qualquer controller. A adoção acontece nos planos 005–007.
- `render_xml()` — também é terminal, mas não tem chamador e não faz parte do
  padrão; deixe como está (registrado como follow-up nas notas).

## Git workflow

- Branch: `advisor/002-commit-gate`
- Commit em PT-BR, Conventional Commits. Sugestão:
  `fix: commita a transacao em respostas terminais sem redirect`.
- Não faça push nem abra PR a menos que o operador peça.

## Steps

### Step 1: Extrair o gate em uma função privada do arquivo

Em `manager/app/inc/lib/CommonFunctions.php`, **imediatamente antes** de
`function array_to_csv(...)` (linha 772), adicione:

```php
/**
 * Fecha a transacao global antes de uma resposta terminal que nao passa por
 * basic_redir(). Sem isso o request sai sem commit e o __destruct() do
 * localPDO faz rollback de seguranca — a escrita some silenciosamente.
 *
 * Respostas de erro (>= 400) fazem rollback: se o handler decidiu falhar,
 * nada do que ele gravou deve persistir.
 */
function close_request_transaction(int $code = 200): void
{
  try {
    if ($code >= 400) {
      localPDO::getInstance()->rollback();
    } else {
      localPDO::getInstance()->commit();
    }
  } catch (\Throwable) {
    // localPDO pode nao ter sido inicializado se nenhuma operacao de banco ocorreu.
  }
}
```

O `try/catch (\Throwable)` não é opcional: em request sem banco (ou em teste sem
DB) `getInstance()` lança, e uma resposta JSON não pode virar erro 500 por causa
disso. É exatamente o mesmo tratamento que `basic_redir()` já faz.

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0.

### Step 2: Chamar o gate em `array_to_csv()`

Em `array_to_csv()`, insira `close_request_transaction();` como **primeira
instrução da função**, antes do primeiro `header()`. Uma chamada só cobre os
dois `exit()` (o de dados vazios e o final).

Resultado esperado do início da função:

```php
function array_to_csv(array $data, string $filename = 'export.csv', ?array $headers = null): never
{
  close_request_transaction();

  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
```

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0.

### Step 3: Chamar o gate em `json_response()`

Em `json_response()`, insira `close_request_transaction($code);` como **primeira
instrução**, antes de `http_response_code($code)`. Passe o `$code` — é ele que
decide commit ou rollback.

```php
function json_response(mixed $data, int $code = 200): never
{
  close_request_transaction($code);

  http_response_code($code);
  header('Content-Type: application/json; charset=UTF-8');
```

Note que o `exit()` do caminho "JSON encoding failed" (que responde 500) fica
depois de um commit já feito, se o `$code` original era 2xx. É aceitável: a
gravação foi bem-sucedida, só a serialização da resposta falhou — desfazer o
dado gravado seria pior. Não tente "corrigir" isso.

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0.

### Step 4: Replicar byte-a-byte no `site`

```bash
cp manager/app/inc/lib/CommonFunctions.php site/app/inc/lib/CommonFunctions.php
```

**Verify**: `bash bin/check-shared-sync.sh` → exit 0.

### Step 5: Escrever o teste

Crie `manager/tests/CommitGateTest.php`. Não dá para testar `json_response()`
direto (ela chama `exit()`), então o teste ataca o comportamento que importa:
**depois de `close_request_transaction()`, a escrita sobrevive à transação**.

Três fatos do ambiente de teste que você precisa saber antes de escrever isso —
todos verificados, não os descubra por tentativa e erro:

1. `localPDO` **não** expõe `inTransaction()` público. Métodos públicos:
   `getInstance`, `beginTransaction`, `commit`, `rollback`, `recordcount`,
   `result`, `results`, `fields_config`, `getPdo`, `lastInsertId`,
   `executePrepared`. Não adicione métodos novos — o teste não precisa.
2. `DBTestCase::setUp()` faz `$this->con = new localPDO()` — uma conexão
   **separada** do singleton `localPDO::getInstance()` que os models usam. O
   rollback do `tearDown` não desfaz o que os models gravaram; o isolamento vem
   de a transação do singleton nunca ser commitada durante o processo de teste.
3. Por causa de (2), commitar o singleton no meio da suíte persistiria as
   fixtures de **todos** os testes anteriores do mesmo processo. Por isso o teste
   zera o singleton por reflection antes de começar, para que a transação que ele
   commita contenha só a própria fixture.

```php
<?php

declare(strict_types=1);

/**
 * Cobre close_request_transaction() (plano 002) — o gate de commit das
 * respostas terminais que nao passam por basic_redir(). Sem ele, um save()
 * que responde JSON grava e o __destruct() do localPDO desfaz.
 *
 * O teste zera o singleton do localPDO antes de cada caso: assim a transacao
 * commitada contem apenas a fixture deste teste, e nao as fixtures dos testes
 * anteriores do mesmo processo (que vivem na transacao nunca-commitada do
 * singleton compartilhado).
 */
final class CommitGateTest extends DBTestCase
{
    private function resetSingleton(): void
    {
        $prop = new ReflectionProperty(localPDO::class, 'instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSingleton();
    }

    protected function tearDown(): void
    {
        $this->resetSingleton();
        parent::tearDown();
    }

    public function testFunctionExists(): void
    {
        $this->assertTrue(
            function_exists('close_request_transaction'),
            'close_request_transaction() deve existir em CommonFunctions.php'
        );
    }

    public function testCommitMakesTheWriteVisibleToAnotherConnection(): void
    {
        $slug = 'commit-gate-' . uniqid();

        $insert = new profiles_model();
        $insert->populate(['name' => 'Commit Gate', 'slug' => $slug, 'editabled' => 'yes']);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id);

        // Conexao independente: ainda nao enxerga a escrita nao-commitada.
        $observer = new localPDO();
        $this->assertSame(0, $this->countBySlug($observer, $slug), 'Antes do gate a escrita nao deve estar visivel');

        close_request_transaction(200);

        $this->assertSame(1, $this->countBySlug($observer, $slug), 'Apos o gate com 2xx a escrita deve estar commitada');

        // Limpeza: a linha esta commitada, o rollback do tearDown nao a alcanca.
        $observer->executePrepared("DELETE FROM profiles WHERE slug = ?", [$slug]);
    }

    public function testErrorCodeDiscardsTheWrite(): void
    {
        $slug = 'commit-gate-erro-' . uniqid();

        $insert = new profiles_model();
        $insert->populate(['name' => 'Commit Gate Erro', 'slug' => $slug, 'editabled' => 'yes']);
        $this->assertGreaterThan(0, (int) $insert->save());

        close_request_transaction(500);

        $observer = new localPDO();
        $this->assertSame(0, $this->countBySlug($observer, $slug), 'Codigo >= 400 deve reverter a escrita');
    }

    private function countBySlug(localPDO $con, string $slug): int
    {
        $stmt = $con->executePrepared("SELECT COUNT(*) AS total FROM profiles WHERE slug = ?", [$slug]);

        return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    }
}
```

O `DELETE FROM` no teste é intencional e é a **única** exceção à regra de
soft-delete do projeto: a linha foi commitada de propósito e precisa sair do
banco. Não replique esse padrão em código de aplicação.

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpunit --filter CommitGate`
→ exit 0, 3 testes passando. Depois rode a suíte inteira
(`cd manager && php app/inc/lib/vendor/bin/phpunit`) e confirme que nenhum teste
pré-existente quebrou e que a tabela `profiles` não ficou com lixo:
`docker exec leggo mysql -uroot -p"$DB_ROOT_PASS" -e "SELECT COUNT(*) FROM profiles WHERE slug LIKE 'commit-gate-%'"`
deve devolver 0 (ou rode a mesma query pelo cliente que você já usa).

### Step 6: Replicar o teste no `site` e verificar tudo

```bash
cp manager/tests/CommitGateTest.php site/tests/CommitGateTest.php
```

**Verify**: `bin/test.sh` → exit 0 nos dois ambientes, suíte inteira passando
(atenção especial: nenhum teste pré-existente pode ter quebrado — se algum
teste de listagem/CSV falhar agora, é sinal de que o commit vazou isolamento).

## Test plan

- Arquivo novo: `manager/tests/CommitGateTest.php` + cópia em `site/tests/`.
- Casos: função existe; com código 2xx a escrita fica visível para outra conexão
  (é o commit real, e é a regressão que este plano corrige); com código >= 400 a
  escrita é descartada.
- Padrão estrutural: `manager/tests/DolModelWriteTest.php` (interação direta com
  `localPDO`) e `MessagesFilterTest.php` (estilo de asserção e fixtures com
  `uniqid()`).
- Regressão a vigiar: a suíte inteira precisa continuar verde. A ação
  `export-csv` em `site_controller.php:66-75` passa a commitar; como ela só lê,
  o efeito prático é nulo, mas é o único chamador vivo afetado.

## Done criteria

Todos devem valer:

- [ ] `grep -n "close_request_transaction" manager/app/inc/lib/CommonFunctions.php` → 3 ocorrências (definição + 2 chamadas)
- [ ] `diff manager/app/inc/lib/CommonFunctions.php site/app/inc/lib/CommonFunctions.php` → sem saída
- [ ] `bash bin/check-shared-sync.sh` → exit 0
- [ ] `bin/test.sh` → exit 0, com os 3 testes novos passando nos dois ambientes
- [ ] `git status` não mostra arquivos fora da lista de escopo
- [ ] Linha do plano 002 atualizada em `plans/README.md`

## STOP conditions

Pare e reporte se:

- `basic_redir()` ou `localPDO::__destruct()` não baterem com os trechos de
  "Current state" (drift no gate de transação — o risco desta mudança depende
  desses dois trechos serem o que o plano descreve).
- A propriedade estática privada `localPDO::$instance` não existir mais (o teste
  a zera por reflection). **Não adicione método público de reset** ao `localPDO`
  para contornar: reporte.
- Qualquer teste que passava antes começar a falhar depois do Step 2, 3 ou 5 —
  isso indica que algum caminho de teste depende do rollback implícito, ou que o
  reset do singleton vazou para outros testes.
- Sobrar linha com `slug LIKE 'commit-gate-%'` na tabela `profiles` depois da
  suíte: a limpeza do teste falhou e o banco ficou sujo.
- A verificação de um passo falhar duas vezes após uma tentativa razoável de
  correção.

## Maintenance notes

- Regra que passa a valer no projeto: **toda função terminal (`: never`) que
  encerra o request precisa fechar a transação**. Hoje são três:
  `basic_redir()`, `array_to_csv()`, `json_response()`. Se alguém criar uma
  quarta, ela precisa chamar `close_request_transaction()`.
- `render_xml()` (`CommonFunctions.php:319`) não encerra o request e não tem
  chamador — ficou de fora de propósito. Se um dia virar resposta terminal,
  entra na mesma regra.
- O revisor deve olhar: (a) o `try/catch (\Throwable)`, que evita 500 em request
  sem banco; (b) a decisão de rollback em `>= 400`; (c) que nenhum controller
  foi alterado neste plano.

# Plan 007: Aplicar o padrão a e-mails — `emails_controller::display` (somente leitura)

> **Executor instructions**: Siga este plano passo a passo. Rode cada comando de
> verificação e confirme o resultado esperado antes de seguir. Se ocorrer
> qualquer item da seção "STOP conditions", pare e reporte — não improvise.
> Ao terminar, atualize a linha deste plano em `plans/README.md`.
>
> **Drift check (rode primeiro)**:
> `git diff --stat a032c73..HEAD -- manager/app/inc/controller/emails_controller.php manager/public_html/index.php manager/public_html/ui/page/emails.php`
> Se algum arquivo em escopo mudou desde este plano, compare os trechos de
> "Current state" com o código vivo antes de prosseguir; divergência = STOP.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW — tela somente leitura, sem escrita.
- **Depends on**: `plans/005-padrao-controller-profiles.md` (o exemplar), e por
  consequência 001, 002, 003.
- **Category**: tech-debt
- **Planned at**: commit `a032c73`, 2026-08-11

## Why this matters

É a última das três telas do manager fora do padrão. Ela é somente leitura, então
o trabalho é pequeno: `index()` vira `display()`, o filtro ad-hoc por `q` vira
`filter()`, aparece ordenação por coluna e o COUNT escrito à mão sai em favor do
`recordset` que `load_data()` já calcula com o mesmo WHERE e os mesmos
parâmetros. Fechar esta tela é o que permite dizer que a convenção antiga
(`index` + `action`) não existe mais em lugar nenhum do painel.

Sem ela, sobra uma tela com uma terceira forma de paginar e filtrar, e o próximo
controller nasce copiando a errada.

## Current state

### Arquivos

- `manager/app/inc/controller/emails_controller.php` — 51 linhas, um método.
- `manager/public_html/ui/page/emails.php` — lista + modal de visualização do corpo.
- `manager/app/inc/model/messages_model.php`:

```php
class messages_model extends DOLModel
{
    protected array $filter = ["active = 'yes'"];

    function __construct()
    {
        parent::__construct("messages");
    }
}
```

### O controller atual (íntegra da lógica)

`manager/app/inc/controller/emails_controller.php:9-50`:

```php
    public function index(array $info): void
    {
        $perPage = 25;
        $page    = (int)($info['get']['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $perPage;
        $q      = trim($info['get']['q'] ?? '');

        try {
            $model = new messages_model();

            if ($q !== '') {
                $like         = '%' . addcslashes($q, '\\%_') . '%';
                $countStmt    = $model->select([" COUNT(*) AS total "], "WHERE active = 'yes' AND to_mail LIKE ?", [$like]);
                $total_emails = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

                $model->set_filter([" active = 'yes' ", " to_mail LIKE ? "], [$like]);
            } else {
                $countStmt    = $model->select([" COUNT(*) AS total "], "WHERE active = 'yes'");
                $total_emails = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
            }

            $model->set_field([" idx ", " to_mail ", " subject ", " body ", " sent_at "]);
            $model->set_order([" sent_at DESC "]);
            $model->set_paginate([$offset, $perPage]);
            $model->load_data(false);
            $emails = $model->data;
        } catch (RuntimeException $e) {
            $emails       = [];
            $total_emails = 0;
        }

        $totalPages = (int)ceil($total_emails / $perPage);
        ...
    }
```

Dois problemas visíveis aí, que o padrão elimina:

1. O `COUNT` é escrito duas vezes, à mão, e precisa repetir o WHERE e os params —
   duplicação que só existe porque a chamada é `load_data(false)`.
   `load_data()` (sem argumento) já preenche `recordset` com a contagem do mesmo
   WHERE, sem o `LIMIT` (`DOLModel.php:279-291`).
2. O parâmetro de busca se chama `q` — nome diferente do `filter_*` que as outras
   duas telas usam depois dos planos 005 e 006.

### Rota atual (`manager/public_html/index.php`)

```php
// Outbox de e-mails — spike/leitura (requer autenticação)
$dispatcher->add_route("GET",  "/emails", "emails_controller:index", $authGuard, $params);
```

### Escapes e convenções

Todas as do plano 005. Este controller **não** tem `form`, `save` nem `remove` —
a tela é somente leitura por decisão de produto registrada no comentário do topo
do arquivo:

```php
    /**
     * Spike (plano 027): lista somente leitura das mensagens registradas em
     * `messages` (cadastro, forgot-password, reset, criação de usuário).
     * Sem reenvio, sem edição, sem export — ver plans/027-DESIGN.md.
     */
```

Preserve essa decisão: não adicione ações de escrita "para completar o padrão".
O padrão admite controllers parciais; o contrário (criar reenvio de e-mail sem
ninguém pedir) seria escopo inventado.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| Testes manager | `cd manager && php app/inc/lib/vendor/bin/phpunit` | exit 0 |
| Teste do filtro | `cd manager && php app/inc/lib/vendor/bin/phpunit --filter MessagesFilter` | exit 0 |
| Verificação completa | `bin/test.sh` | exit 0 |

## Scope

**In scope**:
- `manager/app/inc/controller/emails_controller.php` (reescrever)
- `manager/public_html/index.php` (ajustar 1 rota)
- `manager/public_html/ui/page/emails.php` (busca, ordenação, paginação por `sr`)
- `manager/tests/MessagesFilterTest.php` (estender com o caso de contagem)

**Out of scope** (não toque):
- `app/inc/lib/`, `app/inc/model/` — só consumo.
- `messages_model.php` — o `set_field` explícito do controller já basta.
- Qualquer ação de escrita sobre `messages` (reenvio, edição, remoção).
- `redact_email_body()` e quem grava em `messages` (`auth_controller`,
  `users_controller`) — fora do escopo.

## Git workflow

- Branch: `advisor/007-padrao-controller-emails`
- Commit em PT-BR, Conventional Commits. Sugestão:
  `refactor: adota padrao display em emails_controller`.
- Não faça push nem abra PR a menos que o operador peça.

## Steps

### Step 1: Ajustar a rota

Em `manager/public_html/index.php`:

```php
// Outbox de e-mails — somente leitura (requer autenticação)
$dispatcher->add_route("GET", "/emails(\.json|\.html)?", "emails_controller:display", $authGuard, $params);
```

**Verify**: `cd manager && php -l public_html/index.php` → `No syntax errors`.

### Step 2: Reescrever o controller

Substitua o conteúdo de `manager/app/inc/controller/emails_controller.php`.
**Abra `manager/app/inc/controller/profiles_controller.php` (resultado do plano
005) e espelhe a estrutura**: mesmas constantes, mesmo `filter()` devolvendo
`[$done, $filter, $params]`, mesmo `display()`.

```php
<?php

/**
 * Lista somente leitura das mensagens registradas em `messages` (cadastro,
 * forgot-password, reset, criação de usuário). Sem reenvio, sem edição, sem
 * remoção — decisão de produto, ver plans/027-DESIGN.md.
 *
 * Segue o padrão display/filter do projeto; não tem form/save/remove porque a
 * tela não escreve. Exemplar do padrão: profiles_controller.
 */
class emails_controller
{
    private const ORDER_ALLOWED = ['to_mail', 'subject', 'sent_at'];

    private const PER_PAGE_MIN = 20;

    /**
     * @return array{0: array<string, string>, 1: array<string>, 2: array<mixed>}
     */
    private function filter(array $info): array
    {
        $get    = $info['get'] ?? [];
        $done   = [];
        $filter = [" active = 'yes' "];
        $params = [];

        $mail = trim((string)($get['filter_mail'] ?? ''));
        if ($mail !== '') {
            $done['filter_mail'] = $mail;
            $filter[]            = " to_mail LIKE ? ";
            $params[]            = '%' . addcslashes($mail, '\\%_') . '%';
        }

        $subject = trim((string)($get['filter_subject'] ?? ''));
        if ($subject !== '') {
            $done['filter_subject'] = $subject;
            $filter[]               = " subject LIKE ? ";
            $params[]               = '%' . addcslashes($subject, '\\%_') . '%';
        }

        return [$done, $filter, $params];
    }

    public function display(array $info): void
    {
        global $emails_url;

        $format   = ($info[1] ?? '') === '.json' ? '.json' : '.html';
        $paginate = max(self::PER_PAGE_MIN, (int)($info['get']['paginate'] ?? 0));
        $offset   = (int)($info['sr'] ?? 0);

        [$ordenationColumn, $ordenationDirection] = resolve_ordenation(
            $info['get']['ordenation'] ?? null,
            self::ORDER_ALLOWED,
            'sent_at',
            'desc'
        );

        [$done, $filter, $params] = $this->filter($info);

        try {
            $model = new messages_model();
            $model->set_field([" idx ", " to_mail ", " subject ", " body ", " sent_at "]);
            $model->set_filter($filter, $params);
            $model->set_order([" {$ordenationColumn} {$ordenationDirection} "]);

            if ($format === '.html') {
                $model->set_paginate([$offset, $paginate]);
            }

            // A contagem sai do próprio load_data() — mesmo WHERE, mesmos params,
            // sem o LIMIT. Não escreva um COUNT à mão.
            $model->load_data();
            $total  = (int)$model->get_recordset();
            $emails = $model->data;
        } catch (RuntimeException $e) {
            Logger::getInstance()->error("emails display failed", ["error" => $e->getMessage()]);
            $emails = [];
            $total  = 0;
        }

        if ($format === '.json') {
            json_response(["total" => $total, "row" => $emails]);
        }

        $page          = 'E-mails';
        $sidebar_color = 'rgba(56, 189, 248, 0.92)';

        $form = [
            "done"    => rawurlencode($done !== [] ? set_url($emails_url, $done) : $emails_url),
            "pattern" => [
                "search" => $emails_url,
            ],
        ];

        $ordenation = [];
        foreach (self::ORDER_ALLOWED as $column) {
            $ordenation[$column] = ordenation_header($column, $ordenationColumn, $ordenationDirection);
        }

        $totalPages = $paginate > 0 ? (int)ceil($total / $paginate) : 0;

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/emails.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }
}
```

Note que `$form['pattern']` só tem `search` — não existe "new" nem "action"
porque a tela não cria nem edita. É assim que o padrão acomoda um controller
parcial.

**Verify**:
1. `cd manager && php -l app/inc/controller/emails_controller.php` → `No syntax errors`.
2. `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0.
3. `grep -n "COUNT(\*)" manager/app/inc/controller/emails_controller.php` → sem resultado.

### Step 3: Ajustar a view

Em `manager/public_html/ui/page/emails.php`:

1. Troque o campo de busca `q` por dois campos, `filter_mail` e `filter_subject`,
   repovoados a partir de `$done` (`htmlspecialchars($done['filter_mail'] ?? '', ENT_QUOTES, 'UTF-8')`).
   O formulário é `method="GET"` para `$form['pattern']['search']`.
2. Torne clicáveis os cabeçalhos de destinatário, assunto e data, usando
   `$ordenation['to_mail']`, `$ordenation['subject']` e `$ordenation['sent_at']`,
   no mesmo formato do plano 005:

```php
<a href="<?php echo htmlspecialchars(set_url($GLOBALS['emails_url'], ['ordenation' => $ordenation['sent_at'][0]] + $done), ENT_QUOTES, 'UTF-8'); ?>">
    Enviado em <i class="<?php echo $ordenation['sent_at'][1]; ?>" aria-hidden="true"></i>
</a>
```

3. Troque a paginação de `page` para `sr`: os três links atuais
   (`emails.php:101,105,109`) usam `['page' => ...]` e a condição
   `(($q ?? '') !== '' ? ['q' => $q] : [])`. Passe a usar
   `['sr' => ($p - 1) * $paginate] + $done`, e calcule a página corrente como
   `(int)floor($offset / $paginate) + 1`.
4. Remova toda referência à variável `$q` e a `$perPage` (que não existem mais);
   as variáveis disponíveis agora são `$emails`, `$total`, `$totalPages`,
   `$paginate`, `$offset`, `$done`, `$ordenation`, `$form`.

**Verify**:
1. `cd manager && php -l public_html/ui/page/emails.php` → `No syntax errors`.
2. `grep -n '\$q\b\|perPage' manager/public_html/ui/page/emails.php` → sem resultado.

### Step 4: Estender o teste existente

`manager/tests/MessagesFilterTest.php` já cobre o filtro por `to_mail` e o escape
de curinga. Acrescente **um** caso, que é a mudança de comportamento deste plano:
a contagem passa a vir de `load_data()`.

```php
    public function testRecordsetMatchesFilteredTotalWithoutManualCount(): void
    {
        $marker = uniqid();
        $this->makeMessage("carol_{$marker}_1@example.com");
        $this->makeMessage("carol_{$marker}_2@example.com");
        $this->makeMessage("carol_{$marker}_3@example.com");

        $like = '%' . addcslashes("carol_{$marker}", '\\%_') . '%';

        $model = new messages_model();
        $model->set_field([' idx ', ' to_mail ']);
        $model->set_filter([" active = 'yes' ", " to_mail LIKE ? "], [$like]);
        $model->set_order([' idx ASC ']);
        $model->set_paginate([0, 2]);
        $model->load_data();

        $this->assertCount(2, $model->data, 'A pagina traz 2 linhas por causa do LIMIT');
        $this->assertSame(3, (int) $model->get_recordset(), 'recordset ignora o LIMIT e conta o total filtrado');
    }
```

Este teste é o que justifica remover o `COUNT` manual: prova que `recordset`
conta o total filtrado, não a página.

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpunit --filter MessagesFilter`
→ exit 0, com o teste novo passando.

### Step 5: Verificação manual

Logado no manager:

- [ ] `/emails` lista as mensagens, mais recentes primeiro
- [ ] `/emails.json` devolve `{"total": N, "row": [...]}`
- [ ] Buscar por destinatário e por assunto filtra, e os campos continuam
      preenchidos depois da busca
- [ ] Ordenar por destinatário, assunto e data funciona nos dois sentidos e o
      ícone acompanha
- [ ] A paginação preserva busca e ordenação ao trocar de página
- [ ] O modal que mostra o corpo do e-mail continua funcionando

**Verify**: todos os itens marcados.

### Step 6: Verificação completa

**Verify**: `bin/test.sh` → exit 0.

## Test plan

- Arquivo existente estendido: `manager/tests/MessagesFilterTest.php`, com um
  caso novo (`testRecordsetMatchesFilteredTotalWithoutManualCount`).
- Não há teste novo de arquivo: os dois casos que já existem cobrem o filtro, e o
  caso novo cobre a contagem — que é a única mudança de comportamento real.
- A checagem manual do Step 5 cobre rota, render e paginação.

## Done criteria

Todos devem valer:

- [ ] `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0
- [ ] `bin/test.sh` → exit 0, com o caso novo passando
- [ ] `grep -n "function display\|function filter" manager/app/inc/controller/emails_controller.php` → 2 linhas
- [ ] `grep -n "function index" manager/app/inc/controller/emails_controller.php` → sem resultado
- [ ] `grep -rn "COUNT(\*)" manager/app/inc/controller/emails_controller.php` → sem resultado
- [ ] `grep -rn "controller:index\|controller:action" manager/public_html/index.php` → sem resultado (a convenção antiga sumiu do painel)
- [ ] Os 6 itens do Step 5 verificados manualmente
- [ ] `git status` não mostra arquivos fora da lista de escopo
- [ ] Linha do plano 007 atualizada em `plans/README.md`

## STOP conditions

Pare e reporte se:

- O plano 005 não estiver aplicado (`grep -n "function display" manager/app/inc/controller/profiles_controller.php`
  precisa devolver resultado) — este plano espelha aquele arquivo.
- `get_recordset()` devolver a contagem da **página** em vez do total filtrado.
  Isso significaria que `load_data()` foi chamado com `false` em algum lugar;
  não volte a escrever o COUNT manual, corrija a chamada.
- Você concluir que a tela precisa de ação de escrita para o padrão fechar — não
  precisa; o padrão admite controller parcial.
- A verificação de um passo falhar duas vezes após uma tentativa razoável de
  correção.

## Maintenance notes

- Com este plano, nenhum controller do manager usa mais `index()`/`action()`
  como convenção de listagem. A única `action()` que sobra é a de
  `users_controller`, deliberada, para o que não é CRUD de registro (export CSV,
  ativar/inativar, reset de senha) — ver plano 006.
- `body` vai inteiro para a listagem (é o que alimenta o modal). Se a tabela
  `messages` crescer, considere trazer `body` só sob demanda; hoje a paginação
  de 20–25 linhas segura.
- O corpo já é redigido por `redact_email_body()` **na gravação**, não aqui —
  qualquer token que apareça na tela é bug de quem gravou, não desta listagem.
- O revisor deve olhar: (a) que o COUNT manual sumiu e `recordset` ocupou o
  lugar; (b) que a decisão de "somente leitura" continua registrada no docblock;
  (c) que a ordenação só aceita as três colunas da allowlist.

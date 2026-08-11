# Plan 005: Estabelecer o padrão `display/form/save/remove` — exemplar em `profiles_controller`

> **Executor instructions**: Siga este plano passo a passo. Rode cada comando de
> verificação e confirme o resultado esperado antes de seguir. Se ocorrer
> qualquer item da seção "STOP conditions", pare e reporte — não improvise.
> Ao terminar, atualize a linha deste plano em `plans/README.md`.
>
> **Drift check (rode primeiro)**:
> `git diff --stat a032c73..HEAD -- manager/app/inc/controller/profiles_controller.php manager/app/inc/urls.php manager/public_html/index.php manager/public_html/ui/page/profiles.php`
> Se algum arquivo em escopo mudou desde este plano, compare os trechos de
> "Current state" com o código vivo antes de prosseguir; divergência = STOP.

## Status

- **Priority**: P1
- **Effort**: L
- **Risk**: MED — troca a convenção de rotas e reescreve uma tela inteira.
- **Depends on**: `plans/001-dolmodel-data4select.md`,
  `plans/002-commit-gate-resposta-sem-redirect.md`,
  `plans/003-helpers-ordenacao.md`
- **Category**: tech-debt
- **Planned at**: commit `a032c73`, 2026-08-11

## Why this matters

Os controllers do manager hoje seguem uma convenção improvisada: um método
`index()` que lista e um método `action()` que multiplexa criar/editar/remover
por um campo `action` no POST, com os formulários vivendo em modais Alpine na
própria tela de lista. Cada tela reimplementa paginação, contagem e ausência de
ordenação do seu jeito.

Este plano troca isso por um contrato explícito de seis operações
(`data4select`, `filter`, `display`, `form`, `save`, `remove`), que é o padrão
que o projeto adota daqui em diante, e o implementa em `profiles_controller`
como **exemplar de referência** — os planos 006 e 007 replicam a mesma forma nas
outras duas telas usando este arquivo como modelo. O ganho concreto: ordenação
por coluna (hoje inexistente), filtros de busca que sobrevivem à navegação, uma
URL própria por registro, endpoint `.json` da mesma listagem sem código
duplicado, e uma contagem que sai do próprio `load_data()` em vez de uma segunda
query escrita à mão.

## Current state

### Arquivos e papéis

- `manager/app/inc/controller/profiles_controller.php` — CRUD de perfis hoje, em
  `index()` + `action()` (179 linhas).
- `manager/app/inc/urls.php` — variáveis globais de URL.
- `manager/public_html/index.php` — front controller, registra as rotas.
- `manager/public_html/ui/page/profiles.php` — tela de lista + dois modais.
- `manager/public_html/assets/js/alpine/profilesController.js` — Alpine da tela.

### O controller atual

`index()` (`profiles_controller.php:10-59`) — pagina por `page`, roda um COUNT
separado e carrega a lista completa de perfis só para o `<select>` de pai:

```php
        $perPage = 25;
        $page    = (int)($info['get']['page'] ?? 1);
        ...
            $countStmt      = $model->select([" COUNT(*) AS total "], "WHERE active = 'yes'");
            $total_profiles = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $model->set_field([" idx ", " name ", " slug ", " adm ", " editabled ", " parent ", " created_at "]);
            $model->set_filter([" active = 'yes' "]);
            $model->set_order([" name ASC "]);
            $model->set_paginate([$offset, $perPage]);
            $model->load_data(false);
```

`action()` (`profiles_controller.php:61-178`) — multiplexa por `$post['action']`
(`criar` / `editar` / `remover`), valida CSRF uma vez no topo, e tem **dois
guards que precisam sobreviver a esta reescrita**:

```php
        if (($action === 'editar' || $action === 'remover') && ($target['editabled'] ?? 'yes') === 'no') {
            $_SESSION["messages_app"]["danger"] = ["Este perfil é protegido e não pode ser editado ou removido."];
            basic_redir($profiles_url);
        }
```

```php
            if ($parent === $idx) {
                $_SESSION["messages_app"]["danger"] = ["Um perfil não pode ser pai de si mesmo."];
                basic_redir($profiles_url);
            }
```

E a regra documentada no topo do arquivo (`profiles_controller.php:4-9`), que
**também precisa sobreviver**:

> `adm` nunca é lido de `$_POST` nem gravado por este controller — é o gate de
> privilégio de todo o painel manager (ver `auth_controller::login()`) e é sempre
> exibido como somente leitura na view.

### Rotas e URLs atuais

`manager/app/inc/urls.php`:

```php
$profiles_url      = sprintf("%s%s", constant("cFrontend"), "perfis");
```

`manager/public_html/index.php`:

```php
// Perfis — spike/CRUD (requer autenticação)
$dispatcher->add_route("GET",  "/perfis", "profiles_controller:index",  $authGuard, $params);
$dispatcher->add_route("POST", "/perfis", "profiles_controller:action", $authGuard, $params);
```

### Como o Dispatcher entrega os parâmetros

`Dispatcher::exec()` (`manager/app/inc/lib/Dispatcher.php:107,143-145`) casa
`"/^" . pattern . "$/"` contra o path e faz:

```php
						$matches["server_uri"] = $this->_path_info;
						$matches = array_merge($entry["args"], $matches);
						$class->{$method_name}($matches);
```

Ou seja: **os grupos de captura chegam por índice numérico** — `$info[1]` é o
primeiro grupo. `auth_controller::display_set_password()` já usa esse padrão
(`$token = $info[1] ?? null;`). Os `$args` da rota são o array `$params` montado
em `index.php`:

```php
$params = [
	"sr" => isset($_GET["sr"]) && (int)$_GET["sr"] > 1 ? (int)$_GET["sr"] : 0,
	"format" => ".html",
	"post" => $_POST ?? null,
	"get" => $_GET ?? null,
];
```

`sr` já existe e hoje **não é usado por nenhum controller** — o padrão passa a
usá-lo como deslocamento da paginação.

### Peças do framework que este plano consome

- `DOLModel::data4select($key, $filters, $field, $params)` — plano 001. Mapa
  chave→rótulo; usado invertido resolve slug→idx.
- `close_request_transaction()` dentro de `json_response()` — plano 002. Sem ele,
  o save sem redirect grava e o request desfaz.
- `resolve_ordenation($param, $allowed, $default, $defaultDir)` e
  `ordenation_header($column, $currentColumn, $currentDirection)` — plano 003.
- `set_filter($conditions, $params)` com `?` — **obrigatório** para todo valor
  vindo do usuário (`AGENTS.md`).
- `load_data(true)` preenche `recordset` com o total **sem** o `LIMIT`
  (`DOLModel.php:279-291`) — use `get_recordset()`, não escreva um COUNT à mão.
- `validate_csrf($token, $redirectUrl)` — obrigatório em todo POST.
- `basic_redir($url)` commita; `basic_redir($url, rollback: true)` reverte.
- `valid_slug($slug)`, `set_url($url, $params)`, `html_notification_print()`.

### Convenções de estilo

- Controllers usam **4 espaços** de indentação (veja o arquivo atual). Só
  `app/inc/lib/*.php` usa tabs.
- Mensagens de UI em PT-BR, via `$_SESSION["messages_app"]["danger"|"success"]`.
- Views escapam tudo com `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`.
- Ícones: Bootstrap Icons (`bi bi-*`).
- `profiles_controller.php` é **por ambiente** — não existe cópia no `site/` e
  `bin/check-shared-sync.sh` não o cobre.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0, `[OK] No errors` |
| Testes manager | `cd manager && php app/inc/lib/vendor/bin/phpunit` | exit 0 |
| Teste único | `cd manager && php app/inc/lib/vendor/bin/phpunit --filter ProfilesFilter` | exit 0 |
| Verificação completa | `bin/test.sh` | exit 0 |
| Subir a stack | `docker compose -f docker/docker-compose.yml up -d` | containers no ar |

## Scope

**In scope**:
- `manager/app/inc/controller/profiles_controller.php` (reescrever)
- `manager/app/inc/urls.php` (adicionar 3 URLs)
- `manager/public_html/index.php` (trocar as 2 rotas de perfis por 6)
- `manager/public_html/ui/page/profiles.php` (reescrever a lista; remover modais)
- `manager/public_html/ui/page/profile.php` (criar — tela de formulário)
- `manager/public_html/assets/js/alpine/profilesController.js` (enxugar)
- `manager/tests/ProfilesFilterTest.php` (criar)

**Out of scope** (não toque):
- Qualquer arquivo em `site/` — o site não tem CRUD; o padrão vale para os
  controllers de recurso do manager.
- `app/inc/lib/` e `app/inc/model/` — este plano só **consome** o que os planos
  001–004 entregaram. Se sentir falta de algo no framework, é STOP, não edição.
- `site_controller.php` e `emails_controller.php` — planos 006 e 007.
- A coluna `adm`: não a leia do POST, não a grave, não a torne editável.
- `migrations/` — `profiles` já tem `slug` com índice único (migration 006).

## Git workflow

- Branch: `advisor/005-padrao-controller-profiles`
- Commits em PT-BR, Conventional Commits, um por passo lógico. Sugestões:
  `feat: adota padrao display/form/save/remove em profiles_controller`,
  `feat: separa tela de formulario de perfil da listagem`.
- Não faça push nem abra PR a menos que o operador peça.

## O padrão (contrato que este plano estabelece)

Leia antes de codar — os planos 006 e 007 vão replicar isto.

| Operação | Assinatura | Responsabilidade |
|---|---|---|
| `data4select` | `DOLModel::data4select()` (framework, plano 001) | mapa chave→rótulo; invertido, traduz slug→idx |
| `filter` | `private function filter(array $info): array` | traduz o que o usuário buscou em **três** coisas: `[$done, $filter, $params]` |
| `display` | `public function display(array $info): void` | lista em `.html` ou `.json` |
| `form` | `public function form(array $info): void` | uma entrada, dois modos (cadastro / edição) |
| `save` | `public function save(array $info): void` | grava e decide o destino |
| `remove` | `public function remove(array $info): void` | soft-delete e volta para a lista |

Três desvios deliberados do contrato legado de `exemplo_controller.php`, todos
obrigatórios aqui:

1. **`filter()` devolve três elementos**, não dois: `[$done, $filter, $params]`.
   O terceiro carrega os valores bindados. O legado concatenava os valores no SQL
   (`exemplo_controller.php:21,25,29`) — isso é injeção e não entra neste repo.
2. **A ordenação passa por allowlist** (`resolve_ordenation`). `ORDER BY` não
   aceita bind; o legado joga o query string cru na query
   (`exemplo_controller.php:40,54`).
3. **`data4select` mora no `DOLModel`**, não como método estático de cada
   controller. Uma implementação em vez de uma por entidade.

## Steps

### Step 1: Adicionar as URLs

Em `manager/app/inc/urls.php`, logo depois da linha de `$profiles_url`:

```php
$newprofile_url    = sprintf("%s%s", constant("cFrontend"), "novo-perfil");
$profile_url       = sprintf("%s%s/%s", constant("cFrontend"), "perfil", "%s");
$removeprofile_url = sprintf("%s%s/%s/%s", constant("cFrontend"), "perfil", "%s", "remover");
```

`$profile_url` e `$removeprofile_url` são templates de `sprintf()` — o `%s` é o
slug, igual ao `$tkpwd_url` que já existe no arquivo.

**Verify**: `cd manager && php -l app/inc/urls.php` → `No syntax errors`.

### Step 2: Trocar as rotas

Em `manager/public_html/index.php`, substitua o bloco "Perfis" por:

```php
// Perfis — padrão display/form/save/remove (requer autenticação)
$dispatcher->add_route("GET",  "/perfis(\.json|\.html)?",        "profiles_controller:display", $authGuard, $params);
$dispatcher->add_route("GET",  "/novo-perfil",                   "profiles_controller:form",    $authGuard, $params);
$dispatcher->add_route("POST", "/novo-perfil",                   "profiles_controller:save",    $authGuard, $params);
$dispatcher->add_route("GET",  "/perfil/([a-z0-9_-]+)",          "profiles_controller:form",    $authGuard, $params);
$dispatcher->add_route("POST", "/perfil/([a-z0-9_-]+)",          "profiles_controller:save",    $authGuard, $params);
$dispatcher->add_route("POST", "/perfil/([a-z0-9_-]+)/remover",  "profiles_controller:remove",  $authGuard, $params);
```

A classe de caracteres `[a-z0-9_-]+` não casa `/`, então `/perfil/x/remover` só
bate na última rota. A ordem entre elas não importa.

**Verify**: `cd manager && php -l public_html/index.php` → `No syntax errors`.

### Step 3: Reescrever o controller

Substitua o conteúdo inteiro de
`manager/app/inc/controller/profiles_controller.php` por:

```php
<?php

/**
 * Padrão de controller do projeto: display / form / save / remove, apoiados
 * por filter() (tradução dos parâmetros de busca) e por
 * DOLModel::data4select() (mapa chave=>rótulo e resolução de slug em idx).
 *
 * Este arquivo é o exemplar de referência — veja plans/005-*.md.
 *
 * `adm` nunca é lido de $_POST nem gravado aqui: é o gate de privilégio de todo
 * o painel manager (ver auth_controller::login()) e aparece na tela como
 * somente leitura.
 */
class profiles_controller
{
    /** Colunas que a listagem aceita ordenar. ORDER BY não aceita bind. */
    private const ORDER_ALLOWED = ['name', 'slug', 'created_at'];

    /** Piso de itens por página: pedidos abaixo disso são elevados. */
    private const PER_PAGE_MIN = 20;

    private const SIDEBAR_COLOR = 'rgba(99, 102, 241, 0.92)';

    /**
     * Traduz os parâmetros de busca em três coleções que caminham juntas:
     *   $done   — o que o usuário escolheu (repovoa a tela e remonta a URL de volta)
     *   $filter — as condições WHERE, com ? nos valores
     *   $params — os valores bindados, na mesma ordem dos ?
     *
     * @return array{0: array<string, string>, 1: array<string>, 2: array<mixed>}
     */
    private function filter(array $info): array
    {
        $get    = $info['get'] ?? [];
        $done   = [];
        $filter = [" active = 'yes' "];
        $params = [];

        $name = trim((string)($get['filter_name'] ?? ''));
        if ($name !== '') {
            $done['filter_name'] = $name;
            $filter[]            = " name LIKE ? ";
            $params[]            = '%' . addcslashes($name, '\\%_') . '%';
        }

        $adm = trim((string)($get['filter_adm'] ?? ''));
        if ($adm !== '' && in_array($adm, ['yes', 'no'], true)) {
            $done['filter_adm'] = $adm;
            $filter[]           = " adm = ? ";
            $params[]           = $adm;
        }

        $parent = (int)($get['filter_parent'] ?? 0);
        if ($parent > 0) {
            $done['filter_parent'] = (string)$parent;
            $filter[]              = " parent = ? ";
            $params[]              = $parent;
        }

        return [$done, $filter, $params];
    }

    public function display(array $info): void
    {
        global $profiles_url, $newprofile_url, $profile_url;

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $format   = ($info[1] ?? '') === '.json' ? '.json' : '.html';
        $paginate = max(self::PER_PAGE_MIN, (int)($info['get']['paginate'] ?? 0));
        $offset   = (int)($info['sr'] ?? 0);

        [$ordenationColumn, $ordenationDirection] = resolve_ordenation(
            $info['get']['ordenation'] ?? null,
            self::ORDER_ALLOWED,
            'name'
        );

        [$done, $filter, $params] = $this->filter($info);

        try {
            $model = new profiles_model();
            $model->set_field([" idx ", " name ", " slug ", " adm ", " editabled ", " parent ", " created_at "]);
            $model->set_filter($filter, $params);
            $model->set_order([" {$ordenationColumn} {$ordenationDirection} "]);

            if ($format === '.html') {
                $model->set_paginate([$offset, $paginate]);
            }

            // load_data(true) preenche recordset com o total SEM o LIMIT —
            // é a contagem da paginação, não escreva um COUNT à mão.
            $model->load_data();
            $total    = (int)$model->get_recordset();
            $profiles = $model->data;

            $availableParents = (new profiles_model())->data4select("idx", [" active = 'yes' "], "name");
        } catch (RuntimeException $e) {
            Logger::getInstance()->error("profiles display failed", ["error" => $e->getMessage()]);
            $profiles         = [];
            $total            = 0;
            $availableParents = [];
        }

        if ($format === '.json') {
            json_response(["total" => $total, "row" => $profiles]);
        }

        $page          = 'Perfis';
        $sidebar_color = self::SIDEBAR_COLOR;

        // URL de volta: o endereço atual já com os filtros aplicados, codificado
        // para viajar como parâmetro — salvar ou cancelar traz o usuário de volta
        // à mesma busca.
        $form = [
            "done"    => rawurlencode($done !== [] ? set_url($profiles_url, $done) : $profiles_url),
            "pattern" => [
                "new"    => $newprofile_url,
                "action" => $profile_url,
                "search" => $profiles_url,
            ],
        ];

        // Cabeçalhos clicáveis: [valor do próximo ordenation, classe do ícone]
        $ordenation = [];
        foreach (self::ORDER_ALLOWED as $column) {
            $ordenation[$column] = ordenation_header($column, $ordenationColumn, $ordenationDirection);
        }

        $totalPages = $paginate > 0 ? (int)ceil($total / $paginate) : 0;

        $alpineControllers = ['profiles'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/profiles.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function form(array $info): void
    {
        global $profiles_url, $newprofile_url, $profile_url;

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $slug = $info[1] ?? null;
        $idx  = $slug !== null ? $this->idx_by_slug($slug) : 0;

        // Modo cadastro é o default; a presença do identificador vira edição.
        $data = [];
        $form = [
            "title" => "Cadastrar Perfil",
            "url"   => $newprofile_url,
            "done"  => (string)($info['get']['done'] ?? ''),
        ];

        if ($idx > 0) {
            $model = new profiles_model();
            $model->set_field([" idx ", " name ", " slug ", " adm ", " editabled ", " parent ", " created_at "]);
            $model->set_filter([" active = 'yes' ", " idx = ? "], [$idx]);
            $model->set_paginate([1]);
            $model->load_data(false);
            $data = $model->data[0] ?? [];

            if ($data === []) {
                $_SESSION["messages_app"]["danger"] = ["Perfil não encontrado."];
                basic_redir($profiles_url);
            }

            $form["title"] = "Editar Perfil";
            $form["url"]   = sprintf($profile_url, rawurlencode((string)$data['slug']));
        }

        $availableParents = (new profiles_model())->data4select("idx", [" active = 'yes' "], "name");

        $page          = 'Perfil';
        $sidebar_color = self::SIDEBAR_COLOR;

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/profile.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function save(array $info): void
    {
        global $profiles_url;

        $post = $info['post'] ?? [];
        validate_csrf($post['_csrf_token'] ?? null, $profiles_url);

        $slug = $info[1] ?? null;
        $idx  = $slug !== null ? $this->idx_by_slug($slug) : 0;

        $name      = trim((string)($post['name'] ?? ''));
        $postSlug  = trim((string)($post['slug'] ?? ''));
        $parent    = (int)($post['parent'] ?? 0);
        $backUrl   = $this->back_url($post, $profiles_url);

        if ($name === '' || $postSlug === '') {
            $_SESSION["messages_app"]["danger"] = ["Nome e slug são obrigatórios."];
            basic_redir($backUrl);
        }
        if (!valid_slug($postSlug)) {
            $_SESSION["messages_app"]["danger"] = ["Slug inválido: use minúsculas, números, '-' ou '_' (ex.: meu-perfil)."];
            basic_redir($backUrl);
        }

        if ($idx > 0) {
            if (!$this->is_editabled($idx)) {
                $_SESSION["messages_app"]["danger"] = ["Este perfil é protegido e não pode ser editado."];
                basic_redir($backUrl);
            }
            if ($parent === $idx) {
                $_SESSION["messages_app"]["danger"] = ["Um perfil não pode ser pai de si mesmo."];
                basic_redir($backUrl);
            }
        }

        $rollback = false;

        try {
            $model = new profiles_model();

            // Filtro definido = UPDATE naquele registro; sem filtro = INSERT.
            if ($idx > 0) {
                $model->set_filter([" idx = ? "], [$idx]);
                $model->populate(['name' => $name, 'slug' => $postSlug, 'parent' => $parent]);
                $model->save();
            } else {
                $model->populate([
                    'name'      => $name,
                    'slug'      => $postSlug,
                    'parent'    => $parent,
                    'editabled' => 'yes',
                ]);
                $idx = (int)$model->save();
            }

            $_SESSION["messages_app"]["success"] = [$slug !== null ? "Perfil atualizado com sucesso." : "Perfil criado com sucesso."];
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("profiles save failed", [
                "error" => $e->getMessage(),
                "idx"   => $idx,
            ]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao salvar o perfil. Verifique se o slug já está em uso."];
        }

        // Três saídas: sem navegação (salvamento em segundo plano), URL de volta
        // preservando a busca, ou a listagem padrão.
        if (isset($post['no-redirect'])) {
            // json_response() fecha a transação (plano 002) — sem isso a escrita
            // seria revertida pelo __destruct() do localPDO.
            json_response(["ok" => !$rollback, "idx" => $idx], $rollback ? 500 : 200);
        }

        basic_redir($backUrl, rollback: $rollback);
    }

    public function remove(array $info): void
    {
        global $profiles_url;

        $post = $info['post'] ?? [];
        validate_csrf($post['_csrf_token'] ?? null, $profiles_url);

        $slug    = $info[1] ?? null;
        $idx     = $slug !== null ? $this->idx_by_slug($slug) : 0;
        $backUrl = $this->back_url($post, $profiles_url);

        if ($idx > 0 && $this->is_editabled($idx)) {
            $model = new profiles_model();
            $model->set_filter([" idx = ? "], [$idx]);
            // Remoção lógica: marca active = 'no'. O registro some das consultas
            // (que sempre filtram por ativos) mas continua recuperável.
            $model->remove();
        } elseif ($idx > 0) {
            $_SESSION["messages_app"]["danger"] = ["Este perfil é protegido e não pode ser removido."];
        }

        basic_redir($backUrl);
    }

    /** Traduz o identificador público da URL no identificador interno. */
    private function idx_by_slug(string $slug): int
    {
        $found = (new profiles_model())->data4select(
            "name",
            [" active = 'yes' ", " slug = ? "],
            "idx",
            [$slug]
        );

        return (int)current($found);
    }

    private function is_editabled(int $idx): bool
    {
        $model = new profiles_model();
        $model->set_field([" idx ", " editabled "]);
        $model->set_filter([" active = 'yes' ", " idx = ? "], [$idx]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return (($model->data[0]['editabled'] ?? 'yes') === 'yes');
    }

    /** URL de volta que o formulário carregou, ou a listagem padrão. */
    private function back_url(array $post, string $fallback): string
    {
        $done = trim((string)($post['done'] ?? ''));
        if ($done === '') {
            return $fallback;
        }

        $decoded = rawurldecode($done);

        // Só aceita destino interno — impede open redirect via campo do form.
        return str_starts_with($decoded, constant("cFrontend")) ? $decoded : $fallback;
    }
}
```

Pontos que **não** podem ser simplificados:

- `back_url()` valida o prefixo. O contrato legado redireciona para o valor cru
  de um campo do formulário — isso é open redirect.
- `is_editabled()` é chamado em `save` e em `remove`. O contrato legado diz que
  `remove` "nunca reporta falha"; aqui o guard de perfil protegido é uma regra de
  segurança já existente e prevalece.
- Nada lê `$post['adm']`.
- `load_data()` sem argumento em `display` (com contagem) e `load_data(false)`
  nas buscas de uma linha só.

**Verify**:
1. `cd manager && php -l app/inc/controller/profiles_controller.php` → `No syntax errors`.
2. `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0.
3. `grep -n "adm" app/inc/controller/profiles_controller.php` → só aparece em
   `set_field`, no `filter()` (leitura do GET) e no comentário do topo; **nunca**
   lendo `$post['adm']`.

### Step 4: Criar a tela de formulário

Crie `manager/public_html/ui/page/profile.php`. Reaproveite a estrutura de
`ui/page/profiles.php` (sidebar + `manager-content` + `content-panel`) e os
campos dos modais que serão removidos no Step 5 — nome, slug e `<select>` de
pai, com os mesmos `style=` inline e classes.

Requisitos da tela:

- `<form method="POST" action="<?php echo htmlspecialchars($form['url'], ENT_QUOTES, 'UTF-8'); ?>">`
- campo oculto `_csrf_token` com `$_SESSION['_csrf_token']`
- campo oculto `done` com `$form['done']`
- `name` e `slug` preenchidos com `$data['name']` / `$data['slug']` quando existirem
- `<select name="parent">` populado por `$availableParents` (mapa `idx => nome`,
  ou seja: `foreach ($availableParents as $parentIdx => $parentName)`), com a
  opção `0` = "Nenhum (raiz)", `selected` no valor atual e a própria linha
  desabilitada em modo edição
- `adm` exibido como **texto somente leitura** (Sim/Não), nunca como input
- título vindo de `$form['title']`
- botão "Cancelar" apontando para `rawurldecode($form['done'])` ou
  `$GLOBALS['profiles_url']`
- `html_notification_print()` no topo do conteúdo, como nas outras telas
- todo eco escapado com `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`

Não use Alpine nesta tela — ela não tem modal. Não defina `$alpineControllers`
em `form()`.

**Verify**: `cd manager && php -l public_html/ui/page/profile.php` →
`No syntax errors`.

### Step 5: Reescrever a tela de lista

Em `manager/public_html/ui/page/profiles.php`:

1. **Remova** os dois modais inteiros (blocos "Modal de criação" e "Modal de
   edição", linhas 177-266 do arquivo atual).
2. O botão "Novo Perfil" vira link:
   `<a href="<?php echo htmlspecialchars($form['pattern']['new'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">`.
3. O botão de editar vira link para o formulário daquele registro:
   `sprintf($form['pattern']['action'], rawurlencode($p['slug']))`, com
   `?done=` + `$form['done']` na query string para a volta.
4. O formulário de remover passa a postar em
   `sprintf($GLOBALS['removeprofile_url'], rawurlencode($p['slug']))`, mantém o
   `_csrf_token`, mantém o `confirmRemove` do Alpine, e **perde** os campos
   ocultos `idx` e `action`. Acrescente um campo oculto `done` com `$form['done']`.
5. **Adicione** um formulário de busca (GET para `$form['pattern']['search']`)
   com os três filtros — `filter_name` (texto), `filter_adm` (select Sim/Não/
   Todos) e `filter_parent` (select de `$availableParents`) — repovoados a partir
   de `$done` via `old()` ou `htmlspecialchars($done['filter_name'] ?? '')`.
6. **Torne os cabeçalhos clicáveis** para as três colunas ordenáveis. Para cada
   uma, `$ordenation['name']` é o par `[valor, ícone]`:

```php
<th>
    <a href="<?php echo htmlspecialchars(set_url($GLOBALS['profiles_url'], ['ordenation' => $ordenation['name'][0]] + $done), ENT_QUOTES, 'UTF-8'); ?>"
       class="text-decoration-none">
        Nome <i class="<?php echo $ordenation['name'][1]; ?>" aria-hidden="true"></i>
    </a>
</th>
```

7. **Troque a paginação de `page` para `sr`** (deslocamento, não número de
   página). O bloco atual usa `['page' => $p]`; passe a usar
   `['sr' => ($p - 1) * $paginate] + $done`, e calcule a página atual como
   `(int)floor($offset / $paginate) + 1`. O `$offset` e o `$paginate` precisam
   estar disponíveis na view — eles já são variáveis locais de `display()`, que
   a view enxerga por estar dentro do escopo do método.
8. A coluna "Perfil pai" hoje faz um laço linear sobre `$availableParents` para
   achar o nome. Como `$availableParents` agora é um **mapa `idx => nome`**,
   troque o laço por `$availableParents[$parentIdx] ?? '—'`.

**Verify**:
1. `cd manager && php -l public_html/ui/page/profiles.php` → `No syntax errors`.
2. `grep -n "createProfileModal\|editProfileModal\|name=\"action\"" public_html/ui/page/profiles.php`
   → sem resultado.

### Step 6: Enxugar o Alpine

Em `manager/public_html/assets/js/alpine/profilesController.js`, remova
`editData`, `_editModal`, `_createModal`, `init()`, `openCreate()` e
`openEdit()`. Sobra apenas `confirmRemove(form, profileName)`.

Como `init()` deixa de existir, remova também o `x-init="init()"` do
`x-data="profilesController()"` na view.

**Verify**:
`grep -n "Modal\|openEdit\|openCreate" manager/public_html/assets/js/alpine/profilesController.js`
→ sem resultado.

### Step 7: Escrever o teste

Crie `manager/tests/ProfilesFilterTest.php`. O `filter()` é privado, então o
teste ataca o comportamento pelo mesmo caminho que o controller usa: monta as
condições e roda contra o banco. Modele por
`manager/tests/MessagesFilterTest.php`.

Casos obrigatórios:

1. Busca parcial por nome retorna só as fixtures que contêm o texto.
2. Curinga `%` digitado pelo usuário é escapado (`addcslashes`) e não vira
   curinga — mesmo caso que `MessagesFilterTest::testFilterEscapesLikeWildcards`.
3. `data4select` invertido resolve o slug de um perfil no seu `idx`
   (é o que `idx_by_slug()` faz).
4. Slug inexistente resolve para `0`.
5. `resolve_ordenation` com uma coluna fora de `ORDER_ALLOWED` cai em
   `['name', 'asc']` (garante que a allowlist do controller está correta):
   `$this->assertSame(['name', 'asc'], resolve_ordenation('password-desc', ['name','slug','created_at']));`

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpunit --filter ProfilesFilter`
→ exit 0, 5 testes passando.

### Step 8: Verificação manual das seis operações

Com a stack no ar (`docker compose -f docker/docker-compose.yml up -d`) e
logado no manager, confirme cada item:

- [ ] `/perfis` lista, com os três cabeçalhos clicáveis alternando asc/desc e o
      ícone mudando junto
- [ ] `/perfis.json` devolve `{"total": N, "row": [...]}` com
      `Content-Type: application/json`
- [ ] Buscar por nome parcial filtra, e o campo continua preenchido depois da
      busca; o link de "Novo Perfil" volta para a mesma busca ao salvar
- [ ] `/novo-perfil` abre o formulário vazio com título "Cadastrar Perfil";
      salvar cria e volta para a listagem filtrada
- [ ] `/perfil/<slug>` abre preenchido com título "Editar Perfil"; salvar
      atualiza
- [ ] Remover um perfil editável tira ele da lista; tentar editar/remover um
      perfil protegido (`editabled = 'no'`) mostra a mensagem e não altera nada
- [ ] `adm` aparece somente leitura no formulário e não muda ao salvar

**Verify**: todos os itens acima marcados. Se algum falhar, é STOP.

### Step 9: Verificação completa

**Verify**: `bin/test.sh` → exit 0.

## Test plan

- Arquivo novo: `manager/tests/ProfilesFilterTest.php` (5 casos, listados no
  Step 7). Não há cópia em `site/` — este controller é só do manager.
- Padrão estrutural: `manager/tests/MessagesFilterTest.php`.
- A checagem manual do Step 8 cobre o que o teste automatizado não alcança
  (rotas, redirect, render) — não pule.

## Done criteria

Todos devem valer:

- [ ] `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0
- [ ] `bin/test.sh` → exit 0, com os 5 testes novos passando
- [ ] `grep -n "function display\|function form\|function save\|function remove\|function filter" manager/app/inc/controller/profiles_controller.php` → 5 linhas
- [ ] `grep -n "function index\|function action" manager/app/inc/controller/profiles_controller.php` → sem resultado
- [ ] `grep -rn "COUNT(\*)" manager/app/inc/controller/profiles_controller.php` → sem resultado (a contagem vem de `get_recordset()`)
- [ ] `grep -n "post\['adm'\]\|post\[\"adm\"\]" manager/app/inc/controller/profiles_controller.php` → sem resultado
- [ ] Os 7 itens do Step 8 verificados manualmente
- [ ] `git status` não mostra arquivos fora da lista de escopo
- [ ] Linha do plano 005 atualizada em `plans/README.md`

## STOP conditions

Pare e reporte se:

- Os planos 001, 002 ou 003 não estiverem aplicados —
  `grep -n "function data4select" manager/app/inc/lib/DOLModel.php` e
  `grep -n "function resolve_ordenation\|function close_request_transaction" manager/app/inc/lib/CommonFunctions.php`
  precisam devolver resultado antes de você começar o Step 3.
- Você concluir que precisa editar qualquer arquivo em `app/inc/lib/` ou
  `app/inc/model/` para o padrão funcionar. Essas são cópias duplas do framework
  e estão fora do escopo deste plano.
- `$info[1]` não trouxer o slug (o Dispatcher pode ter mudado). Verifique com um
  `Logger::getInstance()->debug(...)` antes de mudar a estratégia de rota.
- O guard de `editabled` ou a regra de somente-leitura de `adm` não puderem ser
  preservados na forma nova — são regras de segurança existentes; regredi-las não
  é uma opção.
- Um perfil protegido conseguir ser editado ou removido no Step 8.
- A verificação de um passo falhar duas vezes após uma tentativa razoável de
  correção.

## Maintenance notes

- **Este arquivo é o exemplar.** Os planos 006 (usuários) e 007 (e-mails) devem
  replicar a mesma forma; qualquer divergência entre eles precisa de justificativa
  no PR.
- Partes do contrato que **não** aparecem aqui, por ausência de coluna em
  `profiles`, e onde vão parar:
  - *sincronizar vínculos com perfis* (`attach` / `save_attach`) → plano 006
    (usuários têm a junção `users_profiles`);
  - *geração automática do identificador público* (`generate_key(10) . date("ymd")`)
    → plano 006 (perfis têm slug de domínio, digitado e validado por
    `valid_slug()`, então não se gera);
  - *upload de arquivo* → nenhuma entidade atual tem coluna de imagem. Quando
    houver, use `handle_upload($_FILES['x'], 'subdir', ['convert' => 'webp'])`
    (`CommonFunctions.php:524`), que já faz validação de MIME real, slug do nome,
    selo temporal e criação de diretório. **Não** replique o
    `move_uploaded_file()` sem validação de `exemplo_controller.php:178-213`.
- O `.json` da listagem não pagina (traz a coleção inteira, por contrato). Com o
  crescimento da base isso vira um problema — se acontecer, o caminho é
  paginar o `.json` também, não aumentar o limite.
- O revisor deve olhar: (a) `back_url()` e a validação de prefixo; (b) que a
  ordenação só aceita colunas da allowlist; (c) que nenhum COUNT manual voltou;
  (d) que `adm` continua fora do POST.

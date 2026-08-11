# Plan 006: Aplicar o padrão a usuários — `users_controller` com slug, vínculos e upload-ready

> **Executor instructions**: Siga este plano passo a passo. Rode cada comando de
> verificação e confirme o resultado esperado antes de seguir. Se ocorrer
> qualquer item da seção "STOP conditions", pare e reporte — não improvise.
> Ao terminar, atualize a linha deste plano em `plans/README.md`.
>
> **Drift check (rode primeiro)**:
> `git diff --stat a032c73..HEAD -- manager/app/inc/controller/site_controller.php manager/app/inc/urls.php manager/public_html/index.php manager/public_html/ui/page/dashboard.php migrations/`
> Se algum arquivo em escopo mudou desde este plano, compare os trechos de
> "Current state" com o código vivo antes de prosseguir; divergência = STOP.

## Status

- **Priority**: P2
- **Effort**: L
- **Risk**: MED — mexe na tela principal do painel e adiciona coluna em `users`.
- **Depends on**: `plans/005-padrao-controller-profiles.md` (o exemplar),
  e por consequência 001, 002, 003. `plans/004-attach-em-lote.md` não é
  obrigatório, mas sem ele a listagem faz 2 queries por linha.
- **Category**: tech-debt
- **Planned at**: commit `a032c73`, 2026-08-11

## Why this matters

A tela de usuários é a única que exercita as duas partes do padrão que `profiles`
não alcança: **vínculos many-to-many** (cada usuário tem perfis, via
`users_profiles`) e **identificador público gerado** (usuários não têm slug de
domínio, então ele nasce na criação). Sem este plano, o padrão fica pela metade
e a tela principal do painel continua no formato antigo — `index` + POST
multiplexado por um campo `action`, com formulários em modal.

Também é a tela que mais sofre com o que falta: não dá para ordenar por nome nem
por data, não dá para filtrar por perfil, e a URL de um usuário não existe (só
`idx` escondido em campo oculto).

## Current state

### Arquivos e papéis

- `manager/app/inc/controller/site_controller.php` — hoje contém `dashboard()`
  (lista + contadores) e `users_action()` (multiplexador POST). 170 linhas.
- `manager/public_html/ui/page/dashboard.php` — tela de lista com modais.
- `manager/public_html/assets/js/alpine/dashboardController.js` — Alpine da tela.
- `manager/app/inc/model/users_model.php`:

```php
class users_model extends DOLModel
{
    protected array $field = [" idx ", " name ", " mail ", " login "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("users");
    }
}
```

### Schema de `users` (migration `002_create_table_users.sql`)

Colunas relevantes: `idx`, `created_at`, `active`, `mail` (UNIQUE), `login`,
`password`, `name`, `last_login`, `phone`, `genre`, `enabled`, `email_token`,
`email_verified_at`, `email_token_expires_at`.

**Não existe coluna `slug`** — o Step 1 a adiciona.

A junção existe: `users_profiles` (`migration 004`), com
`UNIQUE KEY uq_users_profiles (users_id, profiles_id)`.

### O que precisa sobreviver à reescrita

`site_controller::dashboard()` (`site_controller.php:20-29`) — os contadores
agregados, que têm teste dedicado (`manager/tests/DashboardCountsTest.php`):

```php
            $countStmt = $model->select(
                [" COUNT(*) AS total ", " SUM(active = 'yes') AS ativos ", " SUM(active = 'yes' AND enabled = 'yes') AS habilitados "],
                "WHERE idx > 0"
            );
```

`site_controller::users_action()` — cinco ações, todas com regra própria:

- `export-csv` → `array_to_csv(...)` (`site_controller.php:66-75`)
- `inativar` / `ativar` → `populate(["enabled" => ...])` + `save()`
- `remover` → `remove()`, **com o guard de auto-remoção**
  (`site_controller.php:83-85`):

```php
        if ($action === 'remover' && $idx === $adminId) {
            basic_redir($users_url);
        }
```

- `editar` → nome e e-mail
- `reset-senha` → gera token, envia e-mail via `EmailProducer`, registra em
  `messages_model` com `redact_email_body()` (`site_controller.php:108-157`).
  **Todo esse bloco precisa ser preservado como está**, só mudando de método.

### Rotas atuais (`manager/public_html/index.php:79-85`)

```php
$dispatcher->add_route("GET",  "/?",     "site_controller:dashboard", $authGuard, $params);
$dispatcher->add_route("GET",  "/admin", "site_controller:dashboard", $authGuard, $params);
$dispatcher->add_route("GET",  "/usuarios", "site_controller:dashboard",    $authGuard, $params);
$dispatcher->add_route("POST", "/usuarios", "site_controller:users_action", $authGuard, $params);
```

### Limitação conhecida de `save_attach()`

`DOLModel::save_attach()` (`manager/app/inc/lib/DOLModel.php:482-521`) só age se
`$info["post"]["profiles_id"]` existir **e** tiver pelo menos um item:

```php
				if (count($varexecute)) {
					// desativa todos os vínculos e reinsere os enviados
```

Ou seja: se o usuário desmarcar **todos** os perfis, o formulário não envia
`profiles_id` e os vínculos antigos permanecem. O Step 5 contorna isso no
controller (4 linhas), sem tocar no framework — `app/inc/lib/` é cópia dupla e
está fora do escopo deste plano.

### Convenções

Todas as do plano 005 valem aqui. Releia
`plans/005-padrao-controller-profiles.md`, seção "O padrão" — este plano
**replica** aquela forma. O controller resultante deve ser reconhecivelmente o
mesmo arquivo, com outra entidade.

Migrations (`AGENTS.md`): numeradas (`009_*.sql`), idempotentes, uma transação
por arquivo. O runner faz `explode(';')` ingênuo — **nenhum `;` dentro de
literal**. O padrão de DDL idempotente está em `migrations/006_add_unique_constraints.sql`
(checagem em `information_schema` + `PREPARE`/`EXECUTE`).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| Testes manager | `cd manager && php app/inc/lib/vendor/bin/phpunit` | exit 0 |
| Rodar migrations | `docker exec leggo php /var/www/leggo/site/cgi-bin/run_migrations.php` | sem erro; `009` aparece como executada |
| Verificação completa | `bin/test.sh` | exit 0 |

## Scope

**In scope**:
- `migrations/009_add_slug_to_users.sql` (criar)
- `manager/app/inc/model/users_model.php` (adicionar `slug` ao `$field`)
- `site/app/inc/model/users_model.php` (cópia byte-a-byte — `app/inc/model/` é compartilhado)
- `manager/app/inc/controller/users_controller.php` (criar)
- `manager/app/inc/controller/site_controller.php` (remover — ver Step 7)
- `manager/app/inc/urls.php` (adicionar 3 URLs)
- `manager/public_html/index.php` (rotas)
- `manager/public_html/ui/page/dashboard.php` (reescrever a lista; remover modais)
- `manager/public_html/ui/page/user.php` (criar — formulário)
- `manager/public_html/assets/js/alpine/dashboardController.js` (enxugar)
- `manager/tests/UsersControllerTest.php` (criar)

**Out of scope** (não toque):
- `app/inc/lib/` — inclusive `save_attach()`. A limitação acima é contornada no
  controller.
- `auth_controller.php` (manager e site) — o fluxo de login lê
  `profiles_attach`; não mexa.
- `profiles_controller.php` e `emails_controller.php` — planos 005 e 007.
- A coluna `password`: nunca a exponha em `set_field` de listagem nem em JSON.
- Qualquer controller do `site/` — o site não tem CRUD.

## Git workflow

- Branch: `advisor/006-padrao-controller-usuarios`
- Commits em PT-BR, Conventional Commits. Sugestões:
  `feat: adiciona slug publico a users (migration 009)`,
  `feat: adota padrao display/form/save/remove em users_controller`.
- Não faça push nem abra PR a menos que o operador peça.

## Steps

### Step 1: Migration — coluna `slug` em `users`

Crie `migrations/009_add_slug_to_users.sql`. Indentação com espaços (`.editorconfig`).
Siga o padrão idempotente de `006_add_unique_constraints.sql`: cheque
`information_schema` antes de cada DDL e monte o comando via SQL dinâmico.

Estrutura obrigatória, nesta ordem:

1. `ADD COLUMN slug VARCHAR(32) NULL DEFAULT NULL` — guardado por uma checagem em
   `information_schema.COLUMNS` (`TABLE_NAME = 'users' AND COLUMN_NAME = 'slug'`).
2. Backfill das linhas existentes:

```sql
UPDATE `users`
SET `slug` = CONCAT(SUBSTRING(MD5(CONCAT(idx, RAND())), 1, 10), DATE_FORMAT(COALESCE(created_at, NOW()), '%y%m%d'))
WHERE `slug` IS NULL OR `slug` = '';
```

3. `ADD UNIQUE` em `slug` — guardado por checagem em
   `information_schema.STATISTICS` (`INDEX_NAME = 'slug_UNIQUE'`), igual ao
   bloco 1 da migration 006.

O formato do slug (`10 caracteres + aammdd`) é o mesmo que o controller gera no
Step 5 — mantenha os dois iguais.

**Verify**:
1. `docker exec leggo php /var/www/leggo/site/cgi-bin/run_migrations.php` → sem erro.
2. Rode de novo o mesmo comando → também sem erro (idempotência).
3. Confirme que não há slug nulo nem duplicado:
   `SELECT COUNT(*) FROM users WHERE slug IS NULL OR slug = ''` → 0, e
   `SELECT COUNT(*) - COUNT(DISTINCT slug) FROM users` → 0.

### Step 2: Expor `slug` no model

Em `manager/app/inc/model/users_model.php`, acrescente `" slug "` ao `$field`:

```php
    protected array $field = [" idx ", " name ", " mail ", " login ", " slug "];
```

Copie para o `site`:

```bash
cp manager/app/inc/model/users_model.php site/app/inc/model/users_model.php
```

**Verify**: `bash bin/check-shared-sync.sh` → exit 0.

### Step 3: URLs e rotas

Em `manager/app/inc/urls.php`, depois de `$users_url`:

```php
$newuser_url    = sprintf("%s%s", constant("cFrontend"), "novo-usuario");
$user_url       = sprintf("%s%s/%s", constant("cFrontend"), "usuario", "%s");
$removeuser_url = sprintf("%s%s/%s/%s", constant("cFrontend"), "usuario", "%s", "remover");
```

Em `manager/public_html/index.php`, substitua as quatro rotas atuais de
dashboard/usuários por:

```php
// Usuários — padrão display/form/save/remove (requer autenticação)
$dispatcher->add_route("GET",  "/?",                              "users_controller:display", $authGuard, $params);
$dispatcher->add_route("GET",  "/admin",                          "users_controller:display", $authGuard, $params);
$dispatcher->add_route("GET",  "/usuarios(\.json|\.html)?",       "users_controller:display", $authGuard, $params);
$dispatcher->add_route("POST", "/usuarios",                       "users_controller:action",  $authGuard, $params);
$dispatcher->add_route("GET",  "/novo-usuario",                   "users_controller:form",    $authGuard, $params);
$dispatcher->add_route("POST", "/novo-usuario",                   "users_controller:save",    $authGuard, $params);
$dispatcher->add_route("GET",  "/usuario/([a-z0-9_-]+)",          "users_controller:form",    $authGuard, $params);
$dispatcher->add_route("POST", "/usuario/([a-z0-9_-]+)",          "users_controller:save",    $authGuard, $params);
$dispatcher->add_route("POST", "/usuario/([a-z0-9_-]+)/remover",  "users_controller:remove",  $authGuard, $params);
```

A rota `POST /usuarios → action` é deliberada e é a **única** sobrevivente do
formato antigo: ela hospeda as operações que não são CRUD de um registro
(`export-csv`) e as de mudança de estado em massa (`ativar` / `inativar` /
`reset-senha`), que não têm formulário próprio. Está documentada no Step 5.

**Verify**: `cd manager && php -l app/inc/urls.php public_html/index.php` →
`No syntax errors` para os dois.

### Step 4: Criar `users_controller` — parte 1: `filter` e `display`

Crie `manager/app/inc/controller/users_controller.php`. **Abra
`manager/app/inc/controller/profiles_controller.php` (resultado do plano 005) e
espelhe a estrutura**: mesmas constantes, mesma ordem de métodos, mesmos
helpers privados (`idx_by_slug`, `back_url`).

Diferenças específicas de usuários:

```php
    private const ORDER_ALLOWED = ['name', 'mail', 'login', 'created_at', 'last_login'];
    private const PER_PAGE_MIN  = 20;
```

`filter()` — os três critérios do contrato, agora com o de **relacionamento**:

```php
    /**
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
            $filter[]            = " ( name LIKE ? OR mail LIKE ? ) ";
            $like                = '%' . addcslashes($name, '\\%_') . '%';
            $params[]            = $like;
            $params[]            = $like;
        }

        $enabled = trim((string)($get['filter_enabled'] ?? ''));
        if ($enabled !== '' && in_array($enabled, ['yes', 'no'], true)) {
            $done['filter_enabled'] = $enabled;
            $filter[]               = " enabled = ? ";
            $params[]               = $enabled;
        }

        // Pertinência a um conjunto: o usuário entra se tiver vínculo ATIVO com
        // o perfil escolhido. Não é atributo da própria linha.
        $profile = (int)($get['filter_profile'] ?? 0);
        if ($profile > 0) {
            $done['filter_profile'] = (string)$profile;
            $filter[]               = " idx IN ( SELECT users_id FROM users_profiles WHERE active = 'yes' AND profiles_id = ? ) ";
            $params[]               = $profile;
        }

        return [$done, $filter, $params];
    }
```

`display()` — igual ao de `profiles`, com quatro diferenças:

1. `set_field` da listagem: `idx, name, mail, login, slug, active, enabled, created_at, last_login, email_verified_at`. **Nunca** `password`.
2. Depois do `load_data()`, enriqueça com os perfis vinculados:
   `$model->attach(["profiles"]);` — assim a lista mostra os vínculos sem
   consulta por linha.
3. Os contadores agregados do dashboard continuam, com o mesmo `select()` do
   trecho citado em "Current state" (o `DashboardCountsTest` cobre essa query).
4. O `<select>` de filtro por perfil vem de
   `(new profiles_model())->data4select("idx", [" active = 'yes' "], "name")`.

O envelope JSON é o mesmo: `json_response(["total" => $total, "row" => $users]);`

**Verify**:
1. `cd manager && php -l app/inc/controller/users_controller.php` → `No syntax errors`.
2. `grep -n "password" manager/app/inc/controller/users_controller.php` → só pode
   aparecer em `password_hash`/`reset-senha`, **nunca** dentro de `set_field`.

### Step 5: Parte 2 — `form`, `save`, `remove` e `action`

`form()` — espelha o de `profiles`, com:

- `attach(["profiles"])` sobre a linha carregada, para marcar os perfis atuais no
  formulário;
- `$availableProfiles = (new profiles_model())->data4select("idx", [" active = 'yes' "], "name");`

`save()` — espelha o de `profiles`, com estas diferenças:

**a) Geração do identificador público na criação.** É o passo do contrato que
`profiles` não exercita:

```php
        if ($idx > 0) {
            $model->set_filter([" idx = ? "], [$idx]);
            $model->populate($values);
            $model->save();
        } else {
            // Identificação pública: sequência aleatória + data. Única sem depender
            // de contador, e opaca na URL.
            $values['slug'] = generate_key(10) . date("ymd");
            $model->populate($values);
            $idx = (int)$model->save();
        }
```

`generate_key(int $size = 10)` está em `CommonFunctions.php:47` e devolve
`substr(bin2hex(random_bytes(...)), 0, $size)` — hexadecimal minúsculo, portanto
já compatível com a regex de rota `[a-z0-9_-]+`. O slug final tem 16 caracteres
(10 do gerador + `aammdd`).

**b) Sincronização dos vínculos**, depois de capturar o `$idx`:

```php
        $profileIds = array_map('intval', (array)($post['profiles_id'] ?? []));
        $profileIds = array_values(array_filter($profileIds, static fn(int $v): bool => $v > 0));

        if ($profileIds !== []) {
            $model->save_attach(['idx' => $idx, 'post' => ['profiles_id' => $profileIds]], ['profiles']);
        } else {
            // save_attach() não age com lista vazia (DOLModel.php:498) — sem isto,
            // desmarcar todos os perfis não desvincularia nada.
            $userId = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);
            $model->execute_raw_prepared(
                "UPDATE users_profiles SET active = 'no', removed_at = now(), removed_by = ? WHERE active = 'yes' AND users_id = ?",
                [$userId, $idx]
            );
        }
```

**c) Validação**: `name` e `mail` obrigatórios; `mail` precisa passar em
`filter_var($mail, FILTER_VALIDATE_EMAIL)`. Nunca aceite `password` deste
formulário — a senha é definida pelo próprio usuário via `definir-senha/<token>`
ou redefinida pela ação `reset-senha`.

`remove()` — espelha o de `profiles`, trocando o guard de `editabled` pelo guard
de auto-remoção que já existe hoje:

```php
        $adminId = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);
        if ($idx > 0 && $idx !== $adminId) {
            // ... remove()
        }
```

`action()` — **mova sem reescrever** os blocos `export-csv`, `ativar`,
`inativar` e `reset-senha` de `site_controller::users_action()` para cá,
mantendo `validate_csrf()` no topo e o `try/catch` com `Logger` e
`basic_redir($users_url, rollback: $rollback)`. O bloco de `reset-senha`
(`site_controller.php:108-157`), incluindo `EmailProducer`, `redact_email_body()`
e o log em `messages_model`, vai **idêntico**. As ações `criar`, `editar` e
`remover` **não** vêm — elas agora são `save()`/`remove()`.

**Verify**:
1. `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0.
2. `grep -n "'criar'\|'editar'\|'remover'" manager/app/inc/controller/users_controller.php`
   → nenhuma ocorrência dentro de `action()`.
3. `grep -n "redact_email_body\|EmailProducer" manager/app/inc/controller/users_controller.php`
   → presentes (o bloco de reset-senha foi preservado).

### Step 6: Views

`manager/public_html/ui/page/dashboard.php` — aplique as mesmas seis mudanças do
Step 5 do plano 005: remover modais, "Novo Usuário" vira link para
`$form['pattern']['new']`, editar vira link para `sprintf($user_url, $slug)`,
remover posta em `sprintf($removeuser_url, $slug)`, formulário de busca com os
três filtros (nome/e-mail, situação, perfil), cabeçalhos clicáveis com
`$ordenation[<coluna>]`, paginação por `sr`.

Preserve: os cards de contadores no topo, o botão de exportar CSV (posta em
`$users_url` com `action=export-csv`), e os botões de ativar/inativar/reset-senha
(que continuam postando em `$users_url` com o campo `action`).

Acrescente uma coluna "Perfis" na tabela, lendo `$u['profiles_attach']`:

```php
<?php foreach (($u['profiles_attach'] ?? []) as $prof): ?>
    <span class="user-badge badge-active"><?php echo htmlspecialchars($prof['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
<?php endforeach; ?>
```

`manager/public_html/ui/page/user.php` (criar) — espelha `ui/page/profile.php`
do plano 005, com os campos `name`, `mail`, `login`, `phone` e um grupo de
checkboxes `profiles_id[]` marcando os perfis atuais:

```php
<?php foreach ($availableProfiles as $profileIdx => $profileName): ?>
    <label class="form-check">
        <input class="form-check-input" type="checkbox" name="profiles_id[]" value="<?php echo (int)$profileIdx; ?>"
            <?php echo in_array((int)$profileIdx, $currentProfileIds, true) ? 'checked' : ''; ?>>
        <span class="form-check-label"><?php echo htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8'); ?></span>
    </label>
<?php endforeach; ?>
```

`$currentProfileIds` sai de `array_column($data['profiles_attach'] ?? [], 'idx')`
no `form()`, com `array_map('intval', ...)`.

Enxugue `dashboardController.js` do mesmo jeito que o plano 005 fez com
`profilesController.js`: sobram só os `confirm*` de SweetAlert2 usados pelos
botões que restaram.

**Verify**:
1. `cd manager && php -l public_html/ui/page/dashboard.php public_html/ui/page/user.php` → `No syntax errors`.
2. `grep -n "name=\"idx\"" manager/public_html/ui/page/dashboard.php` → só nas
   ações que ficaram em `action()` (ativar/inativar/reset-senha), nunca em
   editar/remover.

### Step 7: Remover `site_controller` do manager

Depois que tudo acima estiver funcionando, o arquivo
`manager/app/inc/controller/site_controller.php` fica sem nenhuma rota apontando
para ele. Apague-o.

Antes de apagar, confirme:

```bash
grep -rn "site_controller" manager/app manager/public_html manager/tests | grep -v "^manager/app/inc/controller/site_controller.php"
```

Só pode sobrar o comentário em `manager/tests/DashboardCountsTest.php:6` —
atualize esse comentário para citar `users_controller::display`.

**Cuidado**: `site/app/inc/controller/site_controller.php` é outro arquivo
(home/termos/privacidade do site público) e **não** deve ser tocado.

**Verify**:
1. `git status` mostra `manager/app/inc/controller/site_controller.php` como
   deletado, e nenhum arquivo do `site/` alterado.
2. `cd manager && php app/inc/lib/vendor/bin/phpunit` → exit 0.

### Step 8: Teste

Crie `manager/tests/UsersControllerTest.php`, modelando por
`manager/tests/DashboardCountsTest.php` e `MessagesFilterTest.php`.

Casos obrigatórios:

1. Filtro por perfil traz só os usuários com vínculo **ativo** com aquele perfil
   (crie 2 usuários vinculados e 1 sem vínculo; depois desative um vínculo e
   confirme que ele sai do resultado).
2. Busca parcial casa em `name` **e** em `mail`.
3. Curinga `%` digitado é escapado e não vira curinga.
4. O slug gerado no formato `generate_key(10) . date("ymd")` tem 16 caracteres e
   é aceito pela regex de rota:
   `$this->assertMatchesRegularExpression('/^[a-z0-9_-]{16}$/', $slug);`
   (regressão a vigiar: se algum dia `generate_key()` deixar de ser hex
   minúsculo, a rota para de casar o slug e este teste pega.)
5. Desmarcar todos os perfis desvincula: crie usuário com 2 perfis, rode o
   `UPDATE users_profiles ... active = 'no'` do Step 5b, e confirme que
   `attach(["profiles"])` devolve array vazio.

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpunit --filter UsersController`
→ exit 0, 5 testes passando.

### Step 9: Verificação manual

Logado no manager:

- [ ] `/`, `/admin` e `/usuarios` mostram a listagem, com contadores no topo
- [ ] `/usuarios.json` devolve `{"total": N, "row": [...]}` e **nenhum campo
      `password`**
- [ ] Ordenar por nome, e-mail e último login funciona nos dois sentidos
- [ ] Filtrar por perfil traz só quem tem o vínculo; o filtro sobrevive à
      paginação
- [ ] `/novo-usuario` cria; o usuário criado ganha slug e a URL
      `/usuario/<slug>` abre o formulário preenchido
- [ ] Marcar/desmarcar perfis no formulário reflete na lista — inclusive
      desmarcar **todos**
- [ ] Tentar remover a si mesmo não faz nada
- [ ] Exportar CSV continua funcionando
- [ ] Reset de senha continua enviando o e-mail e registrando em `/emails`

**Verify**: todos os itens marcados. Qualquer falha é STOP.

### Step 10: Verificação completa

**Verify**: `bin/test.sh` → exit 0.

## Test plan

- Arquivo novo: `manager/tests/UsersControllerTest.php` (5 casos, Step 8).
- Regressão: `DashboardCountsTest` precisa continuar passando sem alteração de
  asserção (só o comentário muda) — é o que prova que os contadores sobreviveram.
- Padrão estrutural: `manager/tests/DashboardCountsTest.php`.
- A checagem manual do Step 9 cobre rotas, redirect e render.

## Done criteria

Todos devem valer:

- [ ] `docker exec leggo php /var/www/leggo/site/cgi-bin/run_migrations.php` roda duas vezes sem erro
- [ ] `SELECT COUNT(*) FROM users WHERE slug IS NULL OR slug = ''` → 0
- [ ] `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0
- [ ] `bin/test.sh` → exit 0, com os 5 testes novos passando
- [ ] `grep -n "function display\|function form\|function save\|function remove\|function filter\|function action" manager/app/inc/controller/users_controller.php` → 6 linhas
- [ ] `manager/app/inc/controller/site_controller.php` não existe mais; `site/app/inc/controller/site_controller.php` está intacto
- [ ] `grep -rn "site_controller" manager/public_html/index.php` → sem resultado
- [ ] `diff manager/app/inc/model/users_model.php site/app/inc/model/users_model.php` → sem saída
- [ ] Os 9 itens do Step 9 verificados manualmente
- [ ] Linha do plano 006 atualizada em `plans/README.md`

## STOP conditions

Pare e reporte se:

- O plano 005 não estiver aplicado: `grep -n "function display" manager/app/inc/controller/profiles_controller.php`
  precisa devolver resultado antes de você começar o Step 4 — este plano espelha
  aquele arquivo.
- A migration 009 falhar por slug duplicado no backfill. Não relaxe o índice
  único: reporte, com a saída de
  `SELECT slug, COUNT(*) FROM users GROUP BY slug HAVING COUNT(*) > 1`.
- `generate_key()` produzir caracteres fora de `[a-z0-9_-]` (o teste 4 do Step 8
  pega isso). Hoje ela devolve hex minúsculo, então isso só acontece se o
  framework tiver mudado. Reporte em vez de alargar a regex da rota — a correção
  certa é o gerador, que é código de framework e está fora do escopo deste plano.
- Você concluir que precisa alterar `save_attach()` ou qualquer arquivo em
  `app/inc/lib/`.
- O login do manager parar de reconhecer o admin (o `attach(["profiles"])` do
  `auth_controller` compartilha código com esta tela).
- O guard de auto-remoção não puder ser preservado.
- A verificação de um passo falhar duas vezes após uma tentativa razoável de
  correção.

## Maintenance notes

- A rota `POST /usuarios → action()` é a exceção deliberada ao padrão: abriga o
  que não é CRUD de um registro (export CSV) e mudanças de estado sem formulário
  (ativar/inativar/reset-senha). Se uma dessas ganhar tela própria, ela migra
  para `form`/`save` e sai de `action()`.
- O contorno do `save_attach()` com lista vazia (Step 5b) é dívida consciente:
  a correção limpa é fazer `save_attach()` reconciliar também o conjunto vazio,
  mas isso é `app/inc/lib/` (duas cópias + testes) e merece plano próprio.
- `users` agora tem slug público. Qualquer tela nova que exponha usuário na URL
  deve usar o slug, nunca o `idx` — o `idx` é sequencial e vaza volume de base.
- Upload continua sem uso: nenhuma coluna de imagem em `users`. Quando houver,
  use `handle_upload()` (`CommonFunctions.php:524`), que já valida MIME real,
  normaliza o nome com `generate_slug()`, acrescenta selo temporal e cria o
  diretório.
- O revisor deve olhar: (a) que `password` não aparece em nenhum `set_field`;
  (b) o guard de auto-remoção; (c) a subquery do filtro por perfil, que precisa
  estar bindada; (d) que o bloco de reset-senha veio idêntico.

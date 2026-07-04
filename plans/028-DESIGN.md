# 028 — DESIGN: CRUD admin genérico sobre `DOLModel`, prototipado em `profiles`

> Spike/design executado a partir de `plans/028-spike-crud-scaffold.md`.
> Deliverable: schema real, mapeamento declarativo proposto, protótipo funcional de
> CRUD para `profiles`, perguntas em aberto e a forma da abstração (não implementada).

## Schema real (`SHOW COLUMNS FROM profiles`)

Confirmado nesta sessão via `docker exec mysql mysql -uroot -p123456 -e 'SHOW COLUMNS FROM profiles' db_leggo`:

```
Field         Type               Null  Key  Default  Extra
idx           int                NO    PRI  NULL     auto_increment
created_at    datetime           YES        NULL
created_by    int                YES        NULL
modified_at   datetime           YES        NULL
modified_by   int                YES        NULL
removed_at    datetime           YES        NULL
removed_by    int                YES        NULL
active        enum('yes','no')   YES        yes
name          varchar(255)       YES        NULL
editabled     enum('yes','no')   YES        yes
slug          varchar(255)       NO   UNI   NULL
adm           enum('yes','no')   YES        no
parent        int                YES        0
```

`profiles_model.php` (idêntico em `site/`, não tocado por este spike):

```php
protected array $field = ["idx", "name", "editabled", "slug", "adm", "parent"];
protected array $filter = ["active = 'yes'"];
```

## Onde o controller foi colocado — decisão

**Novo arquivo**: `manager/app/inc/controller/profiles_controller.php` (classe `profiles_controller`),
em vez de um novo método em `site_controller.php`.

Razão: `site_controller` já mistura `dashboard()` (view de usuários) com `users_action()`
(mutações de usuários) — é especificamente o controller de usuários, apesar do nome
genérico. Seguindo o precedente do spike 027 (`emails_controller.php`, arquivo próprio para
uma entidade nova), um controller próprio por entidade é o padrão mais fácil de generalizar
depois (ver "Abstração proposta" abaixo) — cada arquivo já nasce como o "slot" que a config
futura preencheria. Também evita inflar ainda mais `site_controller.php`.

## Mapeamento declarativo proposto (forma, não implementada)

O objetivo aqui é mostrar a forma que uma config *hipotética* teria, useful para o
scaffold genérico — o protótipo de `profiles` é escrito à mão seguindo exatamente essa
forma, campo a campo, sem nenhum motor genérico por trás:

```php
// Hipotético — NÃO existe neste código, é a forma proposta para o scaffold futuro.
return [
    'entity'      => 'profiles',
    'model'       => profiles_model::class,
    'route_base'  => '/perfis',           // GET lista, POST ações
    'url_var'     => 'profiles_url',      // nome da variável em urls.php
    'per_page'    => 25,
    'order'       => 'name ASC',

    'list' => [
        'fields' => ['idx', 'name', 'slug', 'adm', 'editabled', 'parent', 'created_at'],
    ],

    'create' => [
        // allow-list: única fonte da verdade sobre o que é aceito de $_POST
        'fields' => [
            'name'   => ['required' => true],
            'slug'   => ['required' => true],
            'parent' => ['required' => false, 'type' => 'int', 'default' => 0],
        ],
        'defaults' => ['editabled' => 'yes'], // nunca vem do POST
    ],

    'edit' => [
        'fields' => [
            'name'   => ['required' => true],
            'slug'   => ['required' => true],
            'parent' => ['required' => false, 'type' => 'int', 'default' => 0],
        ],
        // adm é OMITIDO de propósito — nunca aparece em nenhuma allow-list de escrita.
    ],

    'guard' => [
        // condição que bloqueia edit/remove, com mensagem de erro amigável
        'field'   => 'editabled',
        'blocked' => 'no',
        'message' => 'Este perfil é protegido e não pode ser editado ou removido.',
    ],

    'readonly_display' => ['adm'], // renderizado como badge, nunca em <input>
];
```

Campos como `readonly_display` e `guard` só existem porque `profiles` os exige — um
scaffold genérico precisaria decidir se authz/guard é uma feature de primeira classe do
motor ou algo que cada entidade declara por fora (ver "Abstração proposta").

## Protótipo implementado nesta sessão

- **Rotas** (`manager/public_html/index.php`), ambas atrás de `$authGuard`, mesmo padrão
  de `/usuarios`:
  ```php
  $dispatcher->add_route("GET",  "/perfis", "profiles_controller:index",  $authGuard, $params);
  $dispatcher->add_route("POST", "/perfis", "profiles_controller:action", $authGuard, $params);
  ```
- **URL helper**: `$profiles_url` adicionado em `manager/app/inc/urls.php`.
- **Controller**: `manager/app/inc/controller/profiles_controller.php`:
  - `index()`: paginado (`set_paginate` + `load_data(false)` + `COUNT(*)` separado via
    `execute_raw_prepared`, mesmo padrão do plano 026), mais uma segunda query
    (sem paginação) só com `idx`/`name` para alimentar o `<select>` de `parent` nos
    formulários de criar/editar.
  - `action()`: uma única rota POST despachando por `$post['action']` — `criar`, `editar`,
    `remover` — mesmo padrão do `users_action`:
    - CSRF via `validate_csrf($post['_csrf_token'] ?? null, $profiles_url)` como primeira
      linha.
    - `criar`: allow-list `name`, `slug`, `parent` (trim + validação de não-vazio para
      `name`/`slug`); `editabled` é setado explicitamente para `'yes'` no `populate()`,
      nunca lido do POST; `adm` nunca é lido nem setado (fica no default `'no'` da coluna).
    - Antes de `editar`/`remover`: uma query carrega `editabled` da linha alvo. Se
      `'no'`, a mutação é recusada e `$_SESSION["messages_app"]["danger"]` é setado —
      sem mutação silenciosa.
    - `editar`: mesma allow-list de `criar` (`name`, `slug`, `parent`); `adm` e `editabled`
      nunca são tocados.
    - `remover`: `$model->remove()` (soft-delete), só alcançado se o guard de `editabled`
      passar.
    - `try/catch(RuntimeException)` em volta de cada mutação, `$rollback = true` +
      `Logger::getInstance()->error(...)` em falha, único `basic_redir($profiles_url,
      rollback: $rollback)` no fim de cada branch.
- **View**: `manager/public_html/ui/page/profiles.php`, réplica estrutural de
  `dashboard.php`/`emails.php` (mesmo `manager-layout`/`content-panel`/paginação), com:
  - Badge para `adm` (somente leitura, nunca em `<input>`) e para `editabled`.
  - Botão "Novo Perfil" abrindo modal de criação; linha da tabela com botão de editar
    (abre modal preenchido) e botão de remover — ambos **ocultos** quando `editabled ===
    'no'` (mesmo padrão de ocultar ações para o usuário logado em `dashboard.php`).
  - `parent` como `<select>` das demais profiles ativas (`Nenhum (raiz)` = `0`), não um
    `<input type=number>` cru — ver "Perguntas em aberto" sobre por quê.
  - Todo output com `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- **Alpine**: `manager/public_html/assets/js/alpine/profilesController.js`, mesmo padrão
  de `dashboardController.js` (dois modais Bootstrap — criar/editar — e confirmação via
  SweetAlert2 para remoção).
- **Nav**: link "Perfis" adicionado à sidebar em `dashboard.php`, `emails.php` e
  `profiles.php` (a sidebar é duplicada por página neste código — mesmo padrão já
  encontrado quando o spike 027 adicionou "E-mails"; não extraí um partial porque isso
  seria refatorar código não relacionado ao pedido).

## Perguntas em aberto

1. **`parent` — `<select>` ou `<input type="number">`?** Optei por `<select>` (dropdown
   com todas as profiles ativas, "Nenhum (raiz)" = `0`) porque é um ponteiro de
   hierarquia — um int cru convida a digitar um `idx` inexistente ou o próprio `idx` da
   linha (auto-referência), sem nenhuma validação hoje. O `<select>` não elimina o
   problema de auto-referência (a própria linha aparece na lista ao editar), mas reduz o
   espaço de erro a "escolher entre profiles que existem". Validar ciclos/auto-referência
   de verdade ficaria para uma iteração futura — não implementado aqui.
2. **Validação por campo além de "não vazio"?** Não implementada. `slug` não passa por
   nenhuma normalização (ex.: `slugify()`, que já existe em `CommonFunctions.php` — não
   usei porque o pedido era mirror do padrão de `users_action`, que também não normaliza
   `mail`/`name`). Um scaffold genérico provavelmente quer declarar validadores por campo
   (`required`, `unique`, `slug`, `int>=0`, etc.) — ver mapeamento acima.
3. **Reuso de export CSV** (como em `/usuarios`)? Não implementado neste protótipo —
   fora do "Required behavior" do plano. Seria um `if ($action === 'export-csv')` idêntico
   ao de `users_action`, então é um bom candidato a virar parte da config declarativa
   (`'export' => true`) no scaffold genérico.
4. **Paginação**: já resolvida por mirror direto do padrão do plano 026
   (`set_paginate` + `load_data(false)` + `COUNT(*)` agregado separado). Nada em aberto
   aqui.
5. **Risco residual não endereçado por este spike**: o `editabled` guard protege a UI,
   mas os dois registros atuais (`Administrador`, `Usuário`) têm `editabled = 'yes'`.
   Isso significa que, hoje, um admin pode soft-deletar a profile `Administrador` via
   este CRUD. Como `attach(["profiles"])` (usado em `auth_controller::login()`) filtra
   por `active = 'yes'` na tabela `profiles`, isso *removeria retroativamente* o acesso
   de todo usuário que dependa dessa profile para passar no gate `adm === 'yes'` — não
   por alterar `profiles_model.php` ou a lógica de `login()` (que continuam intocados),
   mas porque a profile deixaria de aparecer no join. **Não implementei proteção extra
   para isso** porque (a) o plano pede para não inventar uma nova política de
   privilégio, e (b) a coluna `editabled` já é o mecanismo de proteção pretendido pelo
   schema — cabe ao operador marcar `editabled = 'no'` na profile `Administrador` via
   SQL direto (ou uma migration/seed futura) se quiser essa proteção. Documentando aqui
   para o operador decidir, não decidindo por ele.

## Decisão pré-resolvida: `adm` nunca editável

Conforme o plano, `adm` é o gate de privilégio administrativo de todo o painel manager
(`auth_controller.php`, checagem em `login()`: qualquer profile anexada ao usuário com
`adm === 'yes'` autoriza acesso). Permitir editar `adm` por um CRUD genérico sem um
conceito de "super-admin" seria uma escalada de privilégio trivial (qualquer admin
promoveria a profile de qualquer usuário para admin). **Não inventei um novo tier de
privilégio** — isso é uma decisão de política que cabe ao operador, não a este spike.
Em vez disso: `adm` nunca é lido de `$_POST` em `profiles_controller::action()` (grep
confirma: a única ocorrência de `adm` no diff deste spike é uma leitura, na view, para
renderizar o badge) e é exibido como badge somente-leitura na tabela — nunca aparece em
`<input>`/`<select>` de nenhum formulário.

## Abstração proposta

O que é boilerplate repetível (→ viraria configuração no scaffold genérico) vs.
específico de `profiles`:

**Repetível / candidato a config**:
- Paginação (`set_paginate` + `load_data(false)` + `COUNT(*)` separado) — idêntico em
  `dashboard()`, `emails_controller::index()` e `profiles_controller::index()`. Só muda
  o model e os campos.
- O esqueleto de `action()`: ler `$post['action']`/`$post['idx']`, `validate_csrf`
  primeiro, `try/catch(RuntimeException)` por mutação, `Logger::getInstance()->error(...)`
  em falha, `basic_redir($url, rollback: $rollback)` único no fim.
- Allow-list de campos com `trim()` + checagem de não-vazio para os `required`.
- O guard de `editabled` (campo booleano-ish que bloqueia edit/remove com mensagem via
  sessão) é um padrão, não algo específico de `profiles` — outra entidade com um flag
  "protegido" análogo reusaria a mesma checagem.
- Estrutura da view: `manager-layout` + sidebar + `content-panel` + tabela + paginação +
  modal de criar/editar via Alpine + confirmação via SweetAlert2 para remoção — visto 3x
  agora (`dashboard.php`, `emails.php`, `profiles.php`) com o mesmo esqueleto HTML.
- Nome do arquivo Alpine (`{entidade}Controller.js`) e o registro via `$alpineControllers`.

**Específico de `profiles` (não generalizável sem mais casos de teste)**:
- A decisão de que `adm` é somente-leitura é uma regra de negócio de *privilégio*, não
  uma propriedade genérica de campo — um scaffold genérico precisaria de uma forma
  declarativa de "campo nunca editável por este CRUD, mesmo que exista no schema"
  (`readonly_display` no mapeamento acima é a tentativa disso, mas só foi exercitada com
  um campo, uma entidade).
- `parent` como dropdown de outras linhas da mesma tabela é um padrão de "campo
  relacional auto-referenciado" — só foi resolvido de forma ad-hoc aqui (uma query extra
  no `index()`); um motor genérico precisaria de uma declaração tipo `'type' =>
  'self_reference_select'`.
- O guard de `editabled` tem semântica de negócio (mensagem amigável, decisão de bloquear
  totalmente em vez de, por exemplo, permitir editar mas não remover) que outra entidade
  poderia querer diferente.

**Esforço estimado para o motor genérico**: **M/G**. Um único caso de teste
(`profiles`) mostra o esqueleto de paginação/CRUD/CSRF/rollback repetido quase
literalmente 3 vezes no código-fonte (`dashboard`, `emails`, `profiles`), o que é
evidência forte de que a extração *da parte mecânica* (paginação, CSRF, rollback,
allow-list com `trim`+`required`) é direta — provavelmente P/M isoladamente. O que eleva
o esforço para M/G é a parte de authz por campo (`readonly_display`, guards tipo
`editabled`) e campos relacionais (`parent` como dropdown): só há **uma** entidade de
teste real até agora, e regra geral de scaffolding é "espere até ter 3 casos concretos
antes de generalizar" — este spike propositalmente não tentou resolver isso com um único
exemplo. Recomendação: implementar um segundo CRUD real (candidato natural: alguma
entidade sem authz especial, ex. uma tabela de configuração simples) antes de extrair o
motor, para não hardcodar decisões de `profiles` na abstração genérica.

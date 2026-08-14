# 015 — DESIGN: `display/form/save/remove` vira scaffold? E qual a forma dele?

> Spike/design. Nenhum código de produção foi alterado por este documento — ver
> `git status --short` no final. Medição refeita no worktree do executor
> (commit-base `3f90417`), não no texto original do plano — ver nota de drift.

## Nota de drift (medição refeita)

O plano foi escrito contra `23f6d0f`. Desde então, os planos 010/011 (teto
`PER_PAGE_MAX`) e 012 (`save_attach` com lista vazia) foram mergeados e tocaram
os três arquivos. Contagem de linhas medida **neste worktree**
(`wc -l manager/app/inc/controller/*.php`):

| Arquivo | Linhas (plano original) | Linhas (medido agora) |
|---|---|---|
| `profiles_controller.php` | 370 | **373** |
| `users_controller.php` | 558 | **556** |
| `emails_controller.php` | 111 | **114** |

A tabela "Current state" do plano está desatualizada nesses três números; o
resto (métodos, contrato, exemplos de código citados) continua válido —
conferido linha a linha contra o código vivo nesta sessão.

## Restrições herdadas

Lidos nesta sessão: `plans/005-padrao-controller-profiles.md`,
`plans/006-padrao-controller-usuarios.md`, `plans/007-padrao-controller-emails.md`,
`plans/README.md` (seções "Desvios deliberados" e "Partes do contrato ainda sem
cobertura") e `git show 5294f13^:plans/028-DESIGN.md`.

Qualquer forma de scaffold proposta abaixo preserva os **7 desvios deliberados**
registrados em `plans/README.md`:

1. `filter()` devolve **três** coleções (`[$done, $filter, $params]`), valores
   sempre bindados, nunca concatenados no SQL.
2. `ORDER BY` passa por allowlist (`ORDER_ALLOWED` + `resolve_ordenation()`) —
   nunca query string cru.
3. `data4select` vive no `DOLModel`, não como estático por controller.
4. **Todo POST valida CSRF** (`validate_csrf()`), inclusive logout.
5. `remove()` mantém os guards de permissão (perfil protegido, auto-remoção) —
   regredir controle de acesso não é opção, mesmo que o contrato legado diga que
   "remove nunca reporta falha".
6. Upload usa `handle_upload()`, nunca `move_uploaded_file()` cru.
7. Paginação usa `sr` (deslocamento), não `page`.

E as restrições de segurança do topo de `users_controller.php` e
`profiles_controller.php`:

- `adm` nunca é lido de `$_POST` nem gravado.
- `password` nunca aparece em `set_field()` nem é lido de `$_POST`.
- Allowlist explícita de campos aceitos, por operação (criar ≠ editar).
- Toda saída escapada com `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

O design anterior (`028-DESIGN.md`, pré-005/006/007) contribui a **forma da
config declarativa** (`entity`, `model`, `list.fields`, `create.fields`,
`edit.fields`, `guard`, `readonly_display`) — reaproveitada no Step 4. A parte
descartada é o protótipo com `index()`/`action()`, substituído pelo contrato
atual. `028-DESIGN.md` já recomendava esperar por um segundo caso real antes de
extrair um motor genérico ("espere até ter 3 casos concretos") — este spike é
esse segundo olhar, agora com três controllers reais no padrão novo.

## Step 1: Medição real da duplicação

### Métodos por arquivo (medido: `grep -n "function " *.php`)

- `profiles_controller.php` (373 linhas): `filter`, `available_parents`,
  `resolve_format`, `display`, `form`, `save`, `remove`, `idx_by_slug`,
  `is_editabled`, `back_url`, `safe_internal_url` — **11**.
- `users_controller.php` (556 linhas): `filter`, `available_profiles`,
  `json_safe`, `resolve_format`, `display`, `form`, `save`, `remove`, `action`,
  `idx_by_slug`, `back_url`, `safe_internal_url` — **12**.
- `emails_controller.php` (114 linhas): `filter`, `resolve_format`, `display` —
  **3**.

Total de ocorrências: 11 + 12 + 3 = 26, distribuídas em 13 métodos únicos
(tabela abaixo).

### Duplicação literal medida (`diff -w`, ignora espaço)

```
diff -w profiles_controller.php users_controller.php | grep -c '^[<>]'   → 409
diff -w profiles_controller.php emails_controller.php | grep -c '^[<>]'  → 319
```

`diff` conta linhas que **divergem**; o que não aparece no resultado é texto
idêntico nas duas pontas, alinhado pelo algoritmo de diff. Com
`total = A + B` e `divergentes = D`, o número de linhas literalmente idênticas
é `X = (total - D) / 2`:

- profiles (373) × users (556): total 929, D 409 → **X = 260 linhas idênticas**
  (~70% do arquivo de profiles reaparece byte-a-byte em users).
- profiles (373) × emails (114): total 487, D 319 → **X = 84 linhas idênticas**
  (~74% do arquivo de emails reaparece byte-a-byte em profiles).

Isso é evidência direta de que `users_controller` e `emails_controller` foram
**copiados** de `profiles_controller` e ajustados — exatamente o modo de
criação que o Step 4 (028-DESIGN) e a "Why this matters" deste plano descrevem.

### Blocos idênticos confirmados por leitura direta (os 4 candidatos + outros achados)

```
resolve_format()   — idêntico em profiles/users/emails (grep -n -A3 confirma texto igual)
back_url()         — idêntico em profiles/users
safe_internal_url()— idêntico em profiles/users
idx_by_slug()      — idêntico em profiles/users, exceto o nome da classe do model
                      (`new profiles_model()` vs `new users_model()`)
```

Além dos 4 citados no plano, a medição encontrou duplicação literal **dentro**
de `display()`, não isolada em método próprio:

```
$paginate = min(self::PER_PAGE_MAX, max(self::PER_PAGE_MIN, ...));   → idêntico nos 3 arquivos
$totalPages = (int)ceil($total / $paginate);                          → idêntico nos 3 arquivos
$ordenation[$column] = ordenation_header($column, ...);               → idêntico nos 3 arquivos
// return_data() chama load_data(true) por baixo — ...                → comentário idêntico nos 3
```

### Tabela — método × controllers × classificação

| Método | Aparece em | Classificação | O que difere (se "quase") |
|---|---|---|---|
| `filter` | profiles, users, emails | quase | Forma idêntica (`[$done, $filter, $params]`, escape com `addcslashes`); as colunas e condições WHERE são da entidade (ex.: subquery M2M só em `users`) |
| `available_parents` / `available_profiles` | profiles, users | **idêntico** | Corpo 100% byte-a-byte igual nos dois (confirmado em `profiles_controller.php:68-71` e `users_controller.php:80-83`): ambos chamam `(new profiles_model())->data4select(...)` — só o nome do método muda, não o model |
| `json_safe` | users | específico irredutível | Só existe em `users` porque só ali há `attach()` trazendo colunas nulas para o JSON |
| `resolve_format` | profiles, users, emails | **idêntico** | — |
| `display` | profiles, users, emails | quase | Esqueleto igual (format, paginate clamp, `resolve_ordenation`, try/`set_field`/`set_filter`/`set_order`/`set_paginate`/`return_data`, `json_response`, loop de `$ordenation`, `$totalPages`); diferem CSRF-init (só profiles/users), contadores agregados (só users), `attach()` (só users), `json_safe` no JSON (só users), chaves de `$form['pattern']`, `$alpineControllers`, nomes de template incluído |
| `form` | profiles, users | quase | Mesmo esqueleto (CSRF-init, resolve slug, guard "não encontrado", `$form`, carga condicional se `$idx > 0`); diferem campos do model e `attach(["profiles"])` (só users) |
| `save` | profiles, users | quase | Mesmo esqueleto (CSRF validate, resolve slug, guard, `$backUrl`, trim de campos, try/populate/save com ramo INSERT×UPDATE, catch, `no-redirect`, `basic_redir`); específico: geração de slug na criação (só users), `save_attach` M2M (só users), guard de auto-demoção de admin (só users), guard "pai de si mesmo" (só profiles) |
| `remove` | profiles, users | quase | Mesmo esqueleto (CSRF validate, resolve slug, `$backUrl`, `basic_redir`); guard é específico e diferente por entidade (`is_editabled()` em profiles, checagem de auto-remoção em users) |
| `idx_by_slug` | profiles, users | quase | Só o nome da classe do model instanciado |
| `is_editabled` | profiles | específico irredutível | Regra de negócio só de perfis (perfil protegido) |
| `back_url` | profiles, users | **idêntico** | — |
| `safe_internal_url` | profiles, users | **idêntico** | — |
| `action` | users | específico irredutível | export-csv, ativar/inativar, reset-senha — exceção documentada ao próprio contrato (plano 006) |

13 linhas cobrindo as 26 ocorrências dos três arquivos (11 + 12 + 3).

## Step 2: Boilerplate vs. específico — critério declarado

**Critério usado** (o sugerido pelo plano, sem alteração): *se dois controllers
de entidades diferentes produzem o mesmo comportamento observável, é
boilerplate; se um deles tem um guard, validação ou efeito colateral que o
outro não pode ter, é específico.* Refinamento aplicado: quando a forma é igual
mas o conteúdo (nomes de coluna, nome do model, mensagem) muda, classifico como
**boilerplate parametrizável**, não como específico — porque o comportamento
observável de fora (paginar, ordenar, filtrar, responder `.json`) é o mesmo,
só o "de qual tabela" muda.

### Boilerplate puro (idêntico, zero conhecimento da entidade)

- `resolve_format()` — 3 cópias byte-a-byte.
- `back_url()` — 2 cópias byte-a-byte (falta em emails porque emails não tem
  formulário).
- `safe_internal_url()` — 2 cópias byte-a-byte.
- `available_parents()`/`available_profiles()` — corpo 100% byte-a-byte
  idêntico (mesma chamada a `profiles_model()`, só o nome do método muda). Não
  entra na lista de extração do Step 4: o corpo já é uma única linha, extrair
  para `lib/` trocaria uma chamada de 1 linha por outra sem reduzir código.
- Dentro de `display()`: a linha de clamp de `$paginate`, a linha de
  `$totalPages`, o loop de `$ordenation`, o comentário sobre `return_data()`.
  Não têm nome de método próprio hoje — vivem soltos dentro do corpo.

### Boilerplate parametrizável (mesma forma, difere por nome/model/campos)

- `idx_by_slug()` — o único ponto variável é a classe do model.
- `available_parents()` / `available_profiles()` — mesmo padrão
  (`data4select` sobre perfis), nomes diferentes só por convenção de leitura.
- O esqueleto de `display()` como um todo (sem os pedaços específicos citados
  abaixo): resolver formato, clamp de paginação, resolver ordenação, montar
  `$filter`, try/model/`set_field`/`set_filter`/`set_order`/`set_paginate`/
  `return_data`, `catch`, responder `.json`, montar `$form`, montar
  `$ordenation`, `$totalPages`, `include`s.
- O esqueleto de `form()`: CSRF-init, resolver slug→idx, guard "não
  encontrado", montar `$form` (`title`/`url`/`done`/`cancelUrl`), carregar
  registro se `$idx > 0`.
- O esqueleto de `save()`: CSRF-validate, resolver slug→idx, guard "não
  encontrado", `$backUrl`, `try { populate/save com INSERT×UPDATE } catch`,
  `no-redirect` → `json_response`, `basic_redir` final.
- O esqueleto de `remove()`: CSRF-validate, resolver slug→idx, `$backUrl`,
  `basic_redir` final — **sem** o guard, que é o pedaço específico.
- `filter()`: a forma (`$done`/`$filter`/`$params`, `trim()`, `addcslashes`
  para LIKE) é idêntica; o conteúdo (quais campos, quais operadores, se há
  subquery M2M) é da entidade.

### Específico irredutível (regra de negócio ou segurança da entidade)

- `is_editabled()` — perfil protegido, só existe em `profiles`.
- Guard de auto-remoção em `users_controller::remove()` — só existe em `users`.
- Guard de auto-demoção de admin em `users_controller::save()`
  (`$keepOwnAdminAccess`) — só existe em `users`, e depende de outra tabela
  (`profiles`, coluna `adm`).
- `json_safe()` — só existe porque `users` usa `attach()` e o JSON não aceita
  `null`.
- `action()` inteiro — export-csv, ativar/inativar, reset-senha (com
  `EmailProducer`, `redact_email_body()`, `messages_model`) — é a exceção que o
  próprio plano 006 documenta como "não é CRUD de um registro".
- Geração de slug na criação (`generate_key(10) . date("ymd")`) — só em
  `users`; `profiles` recebe o slug digitado no formulário.
- `save_attach()` M2M — só em `users` (perfis são a única relação hoje).
- Guard "perfil não pode ser pai de si mesmo" em `profiles::save()` — só ali.

## Step 3: As três perguntas

### 1. Onde fica a autorização?

**Resposta: (a) — authz continua 100% no controller, fora de qualquer
scaffold.**

Os guards medidos acima (`is_editabled`, auto-remoção, auto-demoção de admin)
são de três formas diferentes — um bloqueia completamente, outro nega uma ação
específica, o terceiro nega parcialmente (mantém vínculos como estavam, mas
segue salvando o resto).

- **(a) authz 100% no controller (escolhida)** — cada entidade nova
  reexplicita seu guard, mas ele fica **legível na primeira leitura do
  arquivo**, que é justamente o que os planos 005/006 valorizam ("os guards de
  hoje são explícitos e legíveis"). Custo: nenhuma reutilização do guard em si.
- **(b) authz declarada em config, motor aplica** — esconderia precisamente o
  que security review precisa achar rápido; descartada.
- **(c) motor com hook `guard()` que a entidade implementa** — considerada,
  mas com 3 guards de formato diferente ela só empurra a mesma lógica para
  dentro de um método com nome padronizado, sem eliminar código — não paga o
  custo de mais uma camada de indireção.

Um motor de authz por config precisaria de pelo menos três primitivas
diferentes para cobrir os três casos já existentes, com apenas duas entidades
de exemplo — é generalizar cedo demais.

### 2. Motor em runtime ou gerador de código?

**Resposta: gerador de código, se algo for feito.** Um motor
(`ResourceController::handle($config)`) reduziria linhas mas criaria
indireção: o PHPStan nível 3 do projeto teria dificuldade em checar tipos de
config array-shaped, e qualquer debug precisaria pular entre a config
declarativa e o motor genérico para entender o que uma rota faz — o oposto do
padrão atual, que é "abra o arquivo, leia de cima a baixo". Um gerador
(`bin/make-resource.sh nome_entidade` cospe um controller novo a partir de um
template, já preenchido com os nomes) mantém cada controller 100% explícito e
grepável, sem adicionar abstração em tempo de execução — o boilerplate
continua existindo no arquivo gerado, só ninguém digita à mão. O custo do
gerador: ele próprio é código a manter (e testar), e diverge do controller
template se o padrão evoluir — precisa ser regenerado ou editado a mão nos
dois lugares. Dado que só há 3 controllers hoje (e um deles, `emails`, é
parcial), esse custo de manutenção do gerador provavelmente supera o que ele
economiza — ver Recomendação.

### 3. O que fazer com `emails_controller` (somente leitura)?

**Resposta: fica fora de qualquer scaffold para escrita, tratado como caso
separado.** Um scaffold desenhado para as 5 operações completas
(`display/form/save/remove` + `filter`) tende a gerar `form()`/`save()`/
`remove()` vazios ou stubs para uma entidade somente-leitura — código morto
que alguém vai "completar por engano" mais tarde. O trade-off: sem um modo
dedicado, `emails` continua copiando manualmente só o pedaço que usa
(`filter`+`display`+`resolve_format`), que já é exatamente o que
`emails_controller.php` faz hoje (114 linhas, 3 métodos, sem sobra). Dado que
só existe **uma** entidade somente-leitura no projeto, não há ainda um segundo
caso para validar a forma de um "modo leitura" do scaffold — inventá-lo agora
seria design especulativo.

## Step 4: Recomendação

**Não fazer o scaffold (motor nem gerador) agora — extrair só os 3 helpers que
já são boilerplate puro, e parar aí.**

### Por que "não fazer" — sustentado pelos números do Step 1/2, não por gosto

- Boilerplate **puro** (o único que se extrai sem risco de esconder algo
  específico) é pequeno: 3 métodos (`resolve_format`, `back_url`,
  `safe_internal_url`) e 4 linhas soltas dentro de `display()`. `idx_by_slug`
  foi avaliado como 4º candidato mas fica de fora da extração — ver justificativa
  abaixo. Isso é uma extração de dezenas de linhas, não centenas.
- O boilerplate **parametrizável** (o esqueleto de `display/form/save/remove`)
  é real e mede ~260 linhas idênticas entre profiles e users — mas ele está
  **entrelaçado** com o específico irredutível linha a linha dentro do mesmo
  método (ex.: `save()` intercala validação genérica com o guard de
  auto-demoção de admin no meio do `try`). Extrair isso como config exigiria um
  motor com hooks em pontos intermediários do fluxo — não é uma extração
  limpa de método, é uma reescrita do fluxo de controle.
- Só há **duas** entidades completas (`profiles`, `users`) e uma parcial
  (`emails`). `028-DESIGN.md` (spike anterior) já recomendava esperar por um
  terceiro caso real antes de generalizar — essa recomendação segue válida
  hoje, com uma entidade a mais de dado do que quando foi escrita.
- Nenhum controller aqui chega perto da escala do `oitoestacoes` (77
  controllers, 56 repetindo paginação) que motivou o spike. O projeto está
  numa escala onde "grep e copie" ainda é mais barato que manter um scaffold.

### O que extrair mesmo assim (helpers pequenos)

- `resolve_format(array $info): string` — mover para `app/inc/lib/` (nome
  sugerido: `CommonFunctions.php`, ao lado de `resolve_ordenation`).
- `safe_internal_url(string $url, string $fallback): string` — idem.
- `back_url(array $post, string $fallback): string` — idem; internamente já
  chama `safe_internal_url`.
- `idx_by_slug` **não** vira helper de `lib/` como está — ele instancia um
  model concreto (`profiles_model`/`users_model`), então extrair exigiria
  passar o model como parâmetro ou usar uma interface comum. Dado que só
  existem 2 usos e a diferença é uma palavra (`new X_model()`), não parametrizar
  isso agora é razoável — repetir 8 linhas 2 vezes é mais barato que inventar
  uma abstração para 2 chamadores.

**Custo desta extração pequena, que não é zero** (nota de manutenção do
próprio plano): todo helper novo em `app/inc/lib/` existe em **duas cópias
byte-a-byte** (`manager/` e `site/`), com guard de pre-commit
(`bin/check-shared-sync.sh`). Três helpers = três funções a manter
sincronizadas nos dois lados, mesmo que `site/` não tenha nenhum controller
de recurso que os use hoje — o guard de sync não distingue "usado" de
"presente". Ainda assim, três funções puras e pequenas (3-4 linhas cada) é um
custo baixo comparado ao de um motor ou gerador.

**Esforço estimado**: S (extrair 3 funções, ajustar 2 controllers para
chamá-las, phpstan + phpunit). Não é P porque toca dois controllers e precisa
do gate de shared-sync.

### Sinal futuro que justificaria revisitar

- Quando existir uma **terceira** entidade com o CRUD completo
  (`display/form/save/remove`, não somente-leitura) — aí há dado suficiente
  para separar o esqueleto parametrizável do específico com confiança (o que
  falta hoje: só profiles/users para comparar).
- Quando a primeira entidade com upload de arquivo aparecer — o padrão de
  `handle_upload()` ainda não foi exercitado em nenhum controller do padrão
  novo; vale documentar antes de generalizar.
- Quando o número de controllers de recurso passar de ~6 — é o ponto em que
  `grep`+copiar deixa de escalar bem, e o custo de um gerador (mesmo que
  simples) começa a se pagar.

### Se a decisão futura for "fazer" mesmo assim

Não recomendado agora, mas caso o operador decida seguir com um gerador antes
do sinal acima, o roteiro seria:

1. `bin/make-resource.sh <entidade> <model_class>` copia
   `profiles_controller.php` como template, faz find/replace de
   `profiles`→`<entidade>` e `profiles_model`→`<model_class>`.
2. O dev abre o arquivo gerado e **apaga** o que não se aplica (guards de
   perfis, `available_parents`) e **adiciona** o que é específico da nova
   entidade (guards próprios, campos do formulário).
3. Repete os passos de URL/rota manualmente (`urls.php`, `index.php`) — não
   automatizado, para manter a rota legível e revisável no diff.
4. Cria a view (`ui/page/<entidade>.php`, `ui/page/<entidade_singular>.php`)
   copiando `profile.php`/`profiles.php` como referência.
5. Roda `phpstan` + `phpunit` + a checklist manual dos planos 005/006/007 como
   critério de pronto.
   Esforço estimado do gerador em si: **M** (script + template + doc de uso).

## Verificação de que produção não foi tocada

```
git status --short
```

Resultado observado nesta sessão: apenas `plans/015-DESIGN.md` como untracked.
Nenhum arquivo em `manager/app/` ou `site/app/` foi lido para edição — apenas
lido para medição (Read), nunca editado (Edit/Write).

`bash bin/test.sh`: **não executado nesta sessão** — ver seção de status ao
final da execução deste plano para o motivo (ambiente Docker/DB não
disponível neste worktree de execução).

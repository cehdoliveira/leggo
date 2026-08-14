# 016 — DESIGN: permissão por perfil no lugar do gate `adm` binário

> Spike/design. Nenhum código de produção foi alterado por este documento — ver
> `git status --short` no final. Nenhuma rota, controller, schema ou fluxo de
> autenticação foi tocado.

## Nota de drift

`git diff --stat 23f6d0f..HEAD -- manager/public_html/index.php
site/public_html/index.php manager/app/inc/controller/auth_controller.php
manager/app/inc/lib/Dispatcher.php` não retornou nada nesta sessão: os quatro
arquivos-base estão idênticos ao commit em que o plano foi escrito. A seção
"Current state" do plano foi conferida linha a linha contra o código vivo
(`manager/public_html/index.php`, `manager/app/inc/lib/Dispatcher.php`,
`manager/app/inc/controller/auth_controller.php`) e está correta — reaproveitada
abaixo sem alteração de fato, só reorganizada por capacidade.

## Step 1: Inventário rota → capacidade

Levantamento de `grep -n add_route manager/public_html/index.php` — 24 rotas
registradas. Nem toda rota representa uma capacidade de negócio: as de
autenticação (login, logout, definir-senha) são infraestrutura de acesso, não
uma permissão que varia por perfil.

| Rota | Método | Exec | Guard hoje | Capacidade proposta |
|---|---|---|---|---|
| `/(index...)` | GET | `function:basic_redir` | nenhum | — (redirect interno) |
| `/login` | GET/POST | `auth_controller:display`/`login` | nenhum | — (infra pré-login) |
| `/sair` | POST | `auth_controller:logout` | nenhum | — (infra; qualquer sessão logada pode sair) |
| `/cadastro` | GET/POST | `auth_controller:display_register`/`register` | `$authGuard` | `usuarios.escrever` (cria novo usuário) |
| `/definir-senha/:token` | GET/POST | `auth_controller:display_set_password`/`set_password` | nenhum | — (infra; token no path já é a credencial) |
| `/`, `/admin`, `/usuarios` | GET | `users_controller:display` | `$authGuard` | `usuarios.ler` |
| `/usuarios` | POST | `users_controller:action` | `$authGuard` | `usuarios.escrever` |
| `/novo-usuario` | GET/POST | `users_controller:form`/`save` | `$authGuard` | `usuarios.escrever` |
| `/usuario/:id` | GET/POST | `users_controller:form`/`save` | `$authGuard` | `usuarios.escrever` (dados do usuário) **+ `usuarios.atribuir_perfil`** só para o campo `profiles_id` — ver nota abaixo |
| `/usuario/:id/remover` | POST | `users_controller:remove` | `$authGuard` | `usuarios.escrever` |
| `/emails` | GET | `emails_controller:display` | `$authGuard` | `emails.ler` |
| `/perfis` | GET | `profiles_controller:display` | `$authGuard` | `perfis.ler` |
| `/novo-perfil` | GET/POST | `profiles_controller:form`/`save` | `$authGuard` | `perfis.escrever` |
| `/perfil/:slug` | GET/POST | `profiles_controller:form`/`save` | `$authGuard` | `perfis.escrever` |
| `/perfil/:slug/remover` | POST | `profiles_controller:remove` | `$authGuard` | `perfis.escrever` |

6 capacidades de negócio cobrem as 19 rotas protegidas por `$authGuard`:
`usuarios.ler`, `usuarios.escrever`, `usuarios.atribuir_perfil`, `emails.ler`,
`perfis.ler`, `perfis.escrever`. Nenhuma se chama `display` ou `save` — os
nomes de método do controller aparecem na coluna "Exec", não na de capacidade.

**Por que `usuarios.atribuir_perfil` é separada de `usuarios.escrever`**:
`users_controller.php:330-357` (código existente, não tocado por este design)
grava o campo `profiles_id` de `/usuario/:id` via `save_attach()` sem
restrição sobre *qual* perfil está sendo atribuído — o único guard hoje
impede um admin de se autodesvincular do próprio perfil admin, não impede
conceder o perfil `admin` a terceiros. Isso é inofensivo **hoje** porque
`auth_controller::login()` só deixa entrar quem já tem `adm='yes'` (quem
chega nesse formulário já é admin). O Step 5.4 muda essa regra de entrada
para "`adm='yes'` OU tem capacidade ativa" — a partir daí, se
`usuarios.escrever` sozinha bastasse para tocar `profiles_id`, um usuário
com só essa capacidade poderia logar e se autopromover a admin pela mesma
rota, anulando a granularidade que todo o design propõe. Regra do design:
**alterar `profiles_id` exige `usuarios.atribuir_perfil`; atribuir
especificamente o perfil com `adm='yes'` exige que quem atribui já seja
`adm='yes'`** (checagem adicional no controller, independente de capacidade
— ninguém vira admin exceto por mão de quem já é admin).

Isso já mostra caso de uso real para granularidade: hoje um perfil "só
relatórios" (leitura de `emails`, sem tocar em `usuarios`/`perfis`) é
impossível — ou a pessoa é `adm='yes'` e vê as 6 capacidades, ou não entra.
Não é o caso do STOP condition "todas as rotas são administrativas sem caso de
uso para granularidade": `emails.ler` isolado de `usuarios.escrever`/
`perfis.escrever` já é uma segmentação com valor de negócio.

## Step 2: Onde a permissão é verificada

| Opção | Mecanismo | Prós | Contras |
|---|---|---|---|
| (a) No registro da rota | rota só existe na tabela se o perfil tiver a capacidade (modelo do legado) | esconde a existência da tela para quem não pode vê-la; menu decorre da mesma fonte | exige rotas vindas do banco (ou uma camada de registro condicional por request) — maior mudança estrutural; roteamento passa a depender do banco a cada request |
| (b) No `check` do dispatcher | o guard (`$authGuard`) consulta a capacidade antes de instanciar o controller | `Dispatcher::evaluateCheck()` **já** aceita `callable` hoje, sem mudar uma linha do `Dispatcher.php`; rota permanece 100% no código; `fn() => auth_controller::can('usuarios.escrever')` é um guard válido imediatamente | rota "existe" (retorna redirect, não 404) para quem não tem a capacidade — vaza a existência da tela, não o conteúdo |
| (c) Dentro do controller | primeira linha de cada método action chama `can()` e aborta | granularidade fina por método sem mexer em `index.php` | duplica a checagem em cada método (12 métodos hoje, um a um); fácil esquecer em método novo; guard fica longe do mapa de rotas, mais difícil auditar "quem pode ver o quê" olhando um arquivo só |

**Escolhida: (b).** É a única das três que não exige tocar `Dispatcher.php` nem
mover rotas para o banco — o mecanismo de guard (`evaluateCheck()` avaliando um
`callable`) já existe e já é usado por todas as 19 rotas protegidas via
`$authGuard`. Trocar `$authGuard` por um guard por capacidade
(`fn() => auth_controller::can('usuarios.escrever')`) é substituição de valor,
não de framework. É também a opção de menor diff e menor risco: nenhum código
de roteamento muda, só o valor passado no 4º argumento de `add_route()`.

## Step 3: Fonte das rotas e os 3 defeitos do legado

**Decisão: rotas permanecem no código; só a permissão vem do banco.**

Isso é o que a escolha (b) do Step 2 já implica, e é a recomendação do próprio
plano — entrega perfil configurável e menu por perfil (a query que monta o menu
é a mesma tabela `profiles_capabilities` proposta no Step 4) sem abrir nenhum
dos três buracos do legado, porque nenhum dado do banco vira `pattern` de regex
nem nome de classe:

| Defeito do legado | Onde ele mora lá | Contramedida neste design |
|---|---|---|
| 1. Classe arbitrária do banco (`new $class_name` a partir do campo `controller`) | `routes_model` + `index.php` do legado montam `$dispatcher->add_route(..., $v["controller"], ...)` | Não se aplica: `add_route()` continua chamado com literais de string no código (`"users_controller:display"`), como hoje. O banco nunca contém nome de classe nem de método. |
| 2. Regex do banco (`pattern` interpolado em `preg_match`) | mesmo loop, `$v["pattern"]` | Não se aplica: `url_pattern` continua literal no `index.php`, como hoje. O banco não guarda nem um identificador de rota — ele guarda apenas o **slug da capacidade** (`usuarios.escrever`), que é lido pelo guard, não pelo roteador. |
| 3. Variável-variável (`$GLOBALS[$v["btncheck"]]`, `$GLOBALS[$v["params"]]`) | mesmo loop | Não se aplica: o guard é uma closure fixa no código (`fn() => auth_controller::can('usuarios.escrever')`), nunca um nome resolvido dinamicamente. `can()` recebe uma string literal escrita no `index.php`, não um valor lido do banco em tempo de roteamento — o banco só é consultado **dentro** de `can()`, para saber se o perfil do usuário logado tem aquela capacidade (via `?`/bind, igual a qualquer outra query do projeto). |

Como consequência dessa escolha, **o roteamento não depende do banco a cada
request** — o quarto ponto do plano (fragilidade de infra do legado) também
desaparece: se o banco engasgar, o pior caso é `can()` falhar fechado (nega
acesso), não o site inteiro parar de rotear.

## Step 4: Schema proposto (não criar migration)

Duas tabelas novas, seguindo a convenção de `migrations/004_create_table_users_profiles.sql`
(M2M com auditoria completa e UNIQUE no par) e o padrão de índice de
`migrations/010_add_users_indexes.sql`.

```sql
-- Proposto — NÃO criar migration neste plano.

-- Tabela de capacidades: o vocabulário fechado de permissões do manager.
-- Slugs no formato "<dominio>.<acao>" (ex.: usuarios.ler, perfis.escrever),
-- os mesmos nomes do inventário do Step 1.
CREATE TABLE IF NOT EXISTS `capabilities` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') NOT NULL DEFAULT 'yes',
    `slug` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`idx`),
    UNIQUE KEY `uq_capabilities_slug` (`slug`),
    KEY `idx_capabilities_active` (`active`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Vocabulário fechado de permissões do manager (ex.: usuarios.ler)';

-- Vínculo M2M perfil <-> capacidade, mesmo formato de users_profiles.
CREATE TABLE IF NOT EXISTS `profiles_capabilities` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') NOT NULL DEFAULT 'yes',
    `profiles_id` INT NOT NULL,
    `capabilities_id` INT NOT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_profiles_id` (`profiles_id`),
    KEY `idx_capabilities_id` (`capabilities_id`),
    KEY `idx_active` (`active`),
    UNIQUE KEY `uq_profiles_capabilities` (`profiles_id`, `capabilities_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Relação many-to-many entre profiles e capabilities';

-- Seed: as 6 capacidades do inventário do Step 1.
INSERT IGNORE INTO `capabilities` (`created_at`, `created_by`, `active`, `slug`, `name`)
VALUES
    (NOW(), 0, 'yes', 'usuarios.ler',              'Ver usuários'),
    (NOW(), 0, 'yes', 'usuarios.escrever',          'Criar/editar/remover usuários'),
    (NOW(), 0, 'yes', 'usuarios.atribuir_perfil',   'Alterar o(s) perfil(is) de um usuário'),
    (NOW(), 0, 'yes', 'emails.ler',                 'Ver emails'),
    (NOW(), 0, 'yes', 'perfis.ler',                 'Ver perfis'),
    (NOW(), 0, 'yes', 'perfis.escrever',             'Criar/editar/remover perfis');

-- Seed: perfil Administrador (slug 'admin', criado em 003) recebe TODAS as
-- capacidades — senão o primeiro deploy tranca o operador fora do painel.
INSERT IGNORE INTO `profiles_capabilities` (`created_at`, `created_by`, `active`, `profiles_id`, `capabilities_id`)
SELECT NOW(), 0, 'yes', p.idx, c.idx
FROM `profiles` p
CROSS JOIN `capabilities` c
WHERE p.slug = 'admin';

-- Checagem obrigatória pós-seed (critério de "pronto" da fatia 1, não é
-- parte da migration): o CROSS JOIN acima produz silenciosamente ZERO linhas
-- se o slug 'admin' não bater com nenhum perfil — sem erro de SQL, sem
-- exceção, e nenhum admin recebe capacidade nenhuma (o exato lockout que o
-- Step 5 existe para evitar). Antes de considerar a fatia 1 concluída, rode:
--   SELECT COUNT(*) FROM profiles_capabilities;
-- Resultado esperado: igual à contagem de capacidades cadastradas (6 no
-- primeiro deploy). Contagem menor → o seed não populou tudo; investigue o
-- slug do perfil admin antes de prosseguir para a fatia 2.

-- ROLLBACK MANUAL (o runner nao tem suporte a down; execute a mao se preciso):
--   DROP TABLE `profiles_capabilities`;
--   DROP TABLE `capabilities`;
-- DESTRUTIVO: apaga todo vinculo perfil-capacidade; se o gate `can()` já
-- estiver em modo bloqueante (Step 5, fase 3), ninguém passa em nenhum guard
-- de capacidade depois disso — reverter só é seguro enquanto o gate ainda lê
-- `adm='yes'` como bypass (fases 1-2 do Step 5).
```

Índice: `uq_profiles_capabilities (profiles_id, capabilities_id)` cobre tanto o
UNIQUE quanto o JOIN mais comum (buscar capacidades de um perfil); `idx_active`
segue o mesmo padrão de `users_profiles.idx_active`, usado no WHERE de toda
query de `can()`.

## Step 5: Migração de estado — como não trancar o operador fora

Sequência de deploy em 3 fases, cada uma um plano fatiado independente
(ver Step 6):

1. **Schema + seed** (fatia 1). A migration do Step 4 roda; `Administrador`
   nasce com as 6 capacidades **na mesma transação** do seed (o runner de
   migration já executa cada arquivo em uma transação — ver AGENTS.md). Nenhum
   guard existente muda de comportamento ainda: `$authGuard` continua sendo
   `auth_controller::check_login()` puro. Usuários existentes não percebem
   nada.

2. **`can()` em modo log, sem bloquear** (fatia 2). Introduz
   `auth_controller::can(string $capability): bool`. **Primeira linha de
   `can()`, sem exceção nas três fases: `if (!auth_controller::check_login())
   { return false; }`** — a troca de `$authGuard` por `can()` substitui a
   checagem de *capacidade*, nunca a de *sessão*. Sem essa linha, uma
   requisição sem login também "não é admin e não tem a capacidade" e cairia
   no mesmo `return true` do modo log, abrindo as 19 rotas para acesso anônimo
   enquanto a fatia 2 estiver no ar. Regra de compatibilidade explícita, válida
   só depois do `check_login()` acima: **`adm = 'yes'` continua significando
   "todas as capacidades"** — `can()` retorna `true` imediatamente se qualquer
   perfil ativo do usuário logado tem `adm = 'yes'`, sem nem consultar
   `profiles_capabilities`. Isso é o que impede o lockout mesmo que o seed da
   fatia 1 tenha ficado incompleto: quem já entra hoje (porque tem
   `adm='yes'`) continua entrando, para sempre, até uma decisão explícita
   futura de aposentar `adm`. Os guards das 19 rotas são trocados de
   `$authGuard` para `fn() => auth_controller::can('usuarios.ler')` (etc.),
   mas nesta fase, **para um usuário já logado**, `can()` nunca nega — se o
   perfil não é admin e não tem a capacidade, ela loga (`error_log` ou canal
   equivalente do projeto) e retorna `true` mesmo assim. Isso dá visibilidade
   real de quem seria bloqueado antes de bloquear ninguém, sem nunca abrir mão
   da exigência de sessão válida.

3. **`can()` bloqueante** (fatia 3). Depois de um período observando o log da
   fase 2 sem falso-positivo, `can()` passa a retornar `false` de fato para
   quem não é `adm` e não tem a capacidade — vira o redirect padrão do
   `evaluateCheck()` (`basic_redir($GLOBALS["login_url"])`), mesmo caminho que
   `$authGuard` já usa hoje.

4. **`auth_controller::login()`** (também fatia 3, mesmo deploy): hoje o gate
   de entrada exige `adm = 'yes'` (linha ~73-82). Isso muda para "tem
   `adm='yes'` **ou** tem pelo menos uma capacidade ativa" — senão um perfil
   "só relatórios" nunca consegue nem logar, mesmo com `emails.ler` concedido.
   Esse é o ponto do design que **exige** mudar `auth_controller.php`, fora do
   escopo deste plano — fica registrado aqui como decisão, para a fatia 3
   implementar.

   **Critério de pronto da fatia 3 — testes obrigatórios antes de fechar**
   (é a fatia que decide lockout vs. acesso indevido, não fecha sem isto):
   - `can()` nega quando o usuário não é `adm` e não tem a capacidade pedida.
   - `can()` aceita quando o usuário não é `adm` mas tem a capacidade pedida.
   - `can()` aceita quando o usuário é `adm`, independente de capacidade.
   - `can()` nega requisição sem sessão válida (não regrediu para a fatia 2).
   - `login()` aceita perfil não-`adm` com ao menos uma capacidade ativa.
   - `login()` nega perfil sem `adm` e sem nenhuma capacidade ativa.
   - `usuarios.escrever` sozinha **não** altera `profiles_id` (regra do Step 1
     contra a escalada via `usuarios.atribuir_perfil`).

Resposta direta a "como o operador não se tranca fora": (i) o seed da fatia 1
é atômico com a criação do schema; (ii) `adm='yes'` nunca para de funcionar
como bypass total em `can()` — não há fase em que ele passe a ser insuficiente
sem uma decisão explícita separada; (iii) a fase 2 (log, não bloqueia) existe
exatamente para detectar seed incompleto ou capacidade esquecida antes de
qualquer bloqueio valer.

## Step 6: Recomendação

**Fazer, fatiado.** A escolha (b) do Step 2 (guard callable, sem tocar
`Dispatcher.php`) é o que torna isso baixo risco — vale destacar: **o
`Dispatcher` não precisa mudar em nenhuma fatia deste design.**

| # | Fatia | Esforço | Entrega sozinha |
|---|---|---|---|
| 1 | Schema (`capabilities`, `profiles_capabilities`) + seed do Administrador | S | Estrutura de dados existe; zero mudança de comportamento observável |
| 2 | Helper `auth_controller::can()` + guards trocados, em modo log (nunca bloqueia) | M | Visibilidade real de quem seria afetado, sem risco de lockout |
| 3 | `can()` bloqueante + `auth_controller::login()` aceita perfil não-adm com capacidade | S | Permissão por perfil funcionando de fato nas 19 rotas do inventário |
| 4 | Tela de gestão de capacidades por perfil (CRUD em `/perfis`, análogo ao M2M perfil↔usuário do plano 006) | M | Operador configura permissão pela UI, sem SQL manual |
| 5 | Menu do manager filtrado por capacidade (mesma query de `can()`, aplicada à navegação) | S | Perfil "só relatórios" não vê nem o link para telas que não pode abrir |

Cada fatia é um plano próprio, executável de forma independente e nessa ordem
— 1→2→3 é o caminho crítico de segurança; 4 e 5 são UX e podem esperar.

## Step 7: Confirmação de que produção não foi tocada

```
git status --short
```
Saída: só `plans/016-DESIGN.md` (novo arquivo, sem tracking anterior).

```
bash bin/test.sh
```
Resultado: falhou neste worktree (`Could not open input file:
app/inc/lib/vendor/bin/phpstan`) por ausência de `kernel.php`/`vendor/` — artefatos
gitignored que um worktree isolado não recebe, não uma regressão desta branch (o
diff não toca nenhum arquivo PHP). Confirmado na árvore principal, com o ambiente
completo: `bash bin/test.sh` → exit 0 (PHPStan limpo em `site` e `manager`;
PHPUnit `site` 126 testes/304 assertions OK; `manager` 174 testes/447 assertions
OK).

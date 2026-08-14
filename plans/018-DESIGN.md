# 018 — DESIGN: import de usuários por CSV em duas fases

> Spike/design. Nenhum código de produção foi alterado por este documento — ver
> `git status --short` no final. Nenhum controller, rota, model, migration ou
> `composer.json` foi tocado.

## Nota de drift

`git diff --stat 23f6d0f..HEAD -- manager/app/inc/controller/users_controller.php
manager/app/inc/lib/CommonFunctions.php` retorna mudanças nas duas cópias (16
linhas em `users_controller.php`, 93 em `CommonFunctions.php` — planos 011–017
mergeados depois do commit-base). Conferi cada trecho citado pelo plano direto no
código vivo desta sessão em vez de confiar nos números de linha do plano:

| Referência do plano | Onde está de fato | Conteúdo confere? |
|---|---|---|
| `export-csv`, linha 435 | `manager/app/inc/controller/users_controller.php:433-442` | Sim, idêntico ao trecho citado |
| `array_to_csv()`, linha 793 | `manager/app/inc/lib/CommonFunctions.php:808-833` | Sim |
| `handle_upload()`, linha 524 | `manager/app/inc/lib/CommonFunctions.php:539-...` | Sim, e sem caller hoje (confirmado por grep) |
| `random_token()`, linha 852 | `manager/app/inc/lib/CommonFunctions.php:867-870` | Sim, `bin2hex(random_bytes(32))` = 64 chars hex |
| `reset-senha`, linhas 462-511 | `manager/app/inc/controller/users_controller.php:460-508` | Sim |
| `save_attach()` lista vazia | `manager/app/inc/lib/DOLModel.php:592-627` | Confirmado: o `UPDATE ... SET active='no'` roda sempre que a chave foi enviada (`isset()`), inclusive vazia — plano 012 já corrigiu isso, é o comportamento atual |

Uma descoberta que o plano não citava e muda uma decisão do Step 5: encontrei
`auth_controller::register()` (`manager/app/inc/controller/auth_controller.php:115-195`),
que é um precedente **melhor** que `reset-senha` para "criar usuário sem senha
vinda de fora": gera `password = password_hash(random_token(), PASSWORD_BCRYPT)`
como placeholder inerte, `enabled = 'no'`, `email_token` + `email_token_expires_at`
(+72h), atribui `DEFAULT_USER_PROFILE_ID` via `save_attach()`, envia
`ui/mail/new_admin_credentials.php` e loga em `messages`. É exatamente o que uma
linha de CSV "criar" precisa fazer — uso isso como modelo abaixo em vez de
inventar um caminho novo.

## Step 1: Contrato do arquivo

**Colunas aceitas**: `name`, `mail`, `login`, `enabled` — o mesmo subconjunto do
export, menos `idx`, `active`, `created_at`, `last_login` (metadados do banco,
não dados de entrada).

| Coluna | Obrigatória | Validação | Se inválida |
|---|---|---|---|
| `name` | Sim | Não vazio, ≤ 255 chars (limite da coluna) | Linha marcada como erro: "name obrigatório" |
| `mail` | Sim | `filter_var($v, FILTER_VALIDATE_EMAIL)` — mesma checagem de `users_controller::save()` (`users_controller.php:306`) | Linha marcada como erro: "e-mail inválido" |
| `login` | Não | Se vazio, `NULL` (coluna já é `DEFAULT NULL`); se preenchido, ≤ 255 chars | Linha marcada como erro só se exceder tamanho; vazio é aceito |
| `enabled` | Não | Aceita `yes`/`no` (case-insensitive); vazio ou ausente vira `yes` (default da coluna) | Qualquer outro valor: erro "enabled deve ser yes ou no" |

**Colunas ignoradas se vierem no arquivo** (round-trip com o export não pode
falhar por causa delas): `idx`, `active`, `created_at`, `last_login`. São lidas,
descartadas, e não geram erro de linha.

**Campos nunca aceitos de arquivo**, mesmo que uma coluna com esse nome apareça
no CSV: `password`, `adm`, `slug`, `email_token`, `email_token_expires_at`,
`email_verified_at`. Motivo: `password` nunca vem de input externo no projeto
(nasce de `password_hash(random_token(), ...)`); `adm` nunca é gravado por CRUD
(regra do plano 016/`users_controller`); `slug` é gerado no `save()` do model;
os três campos de token/verificação são controlados pelo fluxo de
autenticação, não por dado de operador. Se uma dessas colunas aparecer no
arquivo, o parser a descarta silenciosamente (mesmo tratamento das colunas
ignoradas) — não é erro de linha, porque um CSV re-exportado por este mesmo
sistema no futuro (se `export-csv` um dia crescer esses campos) não pode
quebrar o import.

**Delimitador e encoding**: `;` como delimitador e `"` como quote — o mesmo que
`array_to_csv()` escreve (`fputcsv($output, $row, ';', '"', '\\')`,
`CommonFunctions.php:823`). Round-trip é requisito: o CSV que sai de
`export-csv` tem que entrar de volta sem edição. Dois problemas reais de
encoding a tratar antes do `fgetcsv()`:

- **BOM UTF-8**: Excel no Windows salva "CSV UTF-8" com um BOM (`EF BB BF`) nos
  primeiros 3 bytes. Detectar com `str_starts_with($conteudo, "\xEF\xBB\xBF")` e
  remover antes de abrir o stream com `fgetcsv()` — senão o BOM gruda no nome do
  primeiro cabeçalho (`﻿name` em vez de `name`) e o mapeamento por nome do
  Step seguinte falha silenciosamente na primeira coluna.
- **Latin-1/Windows-1252**: é o caso mais comum na prática — Excel BR "Salvar
  como CSV (separado por vírgula)" grava em ANSI (Windows-1252), não UTF-8, e
  como o locale é pt-BR o separador de campo vira `;` (porque `,` já é o
  decimal). Detectar com `mb_check_encoding($linha, 'UTF-8')`; se falhar,
  `mb_convert_encoding($linha, 'UTF-8', 'Windows-1252')` linha a linha antes de
  processar. Isso cobre o caso real sem exigir que o operador abra o arquivo
  num editor e resalve.

**Cabeçalho obrigatório: sim, mapeamento por nome de coluna.** Por posição seria
mais simples de escrever, mas frágil a qualquer reordenação — e o objetivo
declarado é aceitar de volta o que `export-csv` gera, cujas colunas vêm numa
ordem fixa hoje mas que ninguém garante que continue assim. Cabeçalho por nome
também é o que permite ignorar colunas extras (`idx`, `active`, ...) e detectar
coluna obrigatória faltante (`mail` ausente do cabeçalho → arquivo inteiro
rejeitado no preview, antes de processar qualquer linha).

**Vínculo com perfis: fora do escopo da v1.** Uma coluna `profiles` com slugs
separados por vírgula é tecnicamente viável — `save_attach()` já aceita lista
vazia sem quebrar (correção do plano 012) — mas exige resolver slug→idx por
linha, decidir o que fazer com slug inexistente (erro de linha? ignora
silenciosamente?), e não é o gargalo que motiva o plano ("300 vezes o
formulário de cadastro"). Usuário importado nasce com `DEFAULT_USER_PROFILE_ID`
(mesmo default de `auth_controller::register()`) e o operador ajusta perfil
pela tela de edição existente (`/usuario/:id`) depois, um a um ou em lote por
outra via. Se doer na prática, é a fatia 3 do Step 6, não a v1.

**Verify**: tabela acima cobre `coluna | obrigatória | validação | erro`; seção
"nunca aceitos de arquivo" lista os 6 campos pedidos.

## Step 2: As duas fases e o estado intermediário

**Onde o rascunho vive: tabela de estágio (opção a).** Comparação:

| Opção | Custo |
|---|---|
| (a) Tabela de estágio | Uma tabela nova + migration. Em troca: sobrevive a F5, dá histórico de imports de graça (a mesma tabela alimenta `display()`), e o dado já está no formato que o resto do framework manipula (array/`DOLModel`) |
| (b) Sessão | Estoura com poucas centenas de linhas (`session.gc_maxlifetime`/tamanho de sessão não foi dimensionado para isso); some se o operador trocar de aba ou a sessão expirar no meio do processo; zero histórico |
| (c) Arquivo em disco via `handle_upload()` | Resolve o problema de tamanho, mas exige rotina de limpeza que não existe (arquivo órfão se o operador nunca confirma), e não dá histórico — teria que duplicar metadado em algum lugar de qualquer forma, o que já é a tabela de estágio |

(a) vence sem ambiguidade para o volume em jogo (200-1000 linhas, ver Step 3).

**Sequência numerada**:

1. **Upload** (`POST /importar-usuarios/novo`) — operador envia o arquivo.
   `handle_upload()` valida MIME real e move para `UPLOAD_DIR` (com
   `allowed_types` incluindo `csv` explícito nas options — o default do kernel,
   `UPLOAD_ALLOWED_TYPES`, não inclui `csv`). Estado: arquivo validado em disco,
   nada no banco ainda.
2. **Parse** (mesma requisição) — `fgetcsv()` linha a linha sobre o arquivo
   recém-salvo, com BOM/encoding tratados (Step 1), cabeçalho mapeado por nome,
   cada linha validada e classificada (`criar` / `atualizar` / `erro` + motivo).
   Estado: array de linhas classificadas, em memória.
3. **Estágio** (mesma requisição, fim do POST) — o array vira JSON e é gravado
   num registro da tabela `users_imports` (`dados` = JSON, `imported_at = NULL`).
   Redireciona (`basic_redir`) para `GET /importar-usuarios/:idx`. Estado:
   rascunho persistido, nada em `users` ainda.
4. **Preview** (`GET /importar-usuarios/:idx`) — lê o JSON de `dados` e renderiza
   a tabela: total de linhas, quantas criam, quantas atualizam (`mail` já existe
   em `users`), quantas têm erro **com número da linha e o motivo** (não "23
   erros" — "linha 47: e-mail inválido", "linha 112: name obrigatório"). Estado:
   inalterado, é leitura.
5. **Confirmação** (`POST /importar-usuarios`, `action=confirmar`, `idx=N`) —
   reprocessa o JSON de `dados` (não confia em nada vindo do `$_POST` além do
   `idx`), aplica **em duas passadas** dentro da transação única do request
   (`users_controller`-style: um `try/catch` com `basic_redir(..., rollback:
   true)` no erro): (1) todas as escritas em `users`/`users_profiles`, linha a
   linha; só depois, se a passada 1 inteira terminou sem erro, (2) dispara os
   e-mails de convite. Isso não é opcional — ver justificativa no Step 4 (achado
   de review: e-mail interleado com a escrita sobrevive a um rollback e o link
   já entregue fica morto). Ao final, marca `imported_at`/`imported_by` no
   registro de estágio. Estado final: usuários criados/atualizados, e-mails
   disparados, rascunho marcado como aplicado.

**Tempo de vida do rascunho**: enquanto `imported_at IS NULL`. Não há limpeza
automática desenhada aqui — registrar como decisão pendente (ver Step 6, não é
um worker novo, é potencialmente um item de faxina manual ou uma linha a mais
numa rotina de manutenção já existente, fora do escopo deste spike).

**Duplo submit (F5 no POST de confirmação)**: o CSRF do projeto tem 10s de
graça exatamente para sobreviver a F5, então o token *não* impede reprocessar.
A proteção é a marca `imported_at`: `action=confirmar` primeiro faz
`SELECT ... WHERE idx = ? AND imported_at IS NULL FOR UPDATE` — **com** lock
explícito, diferente do resto do projeto (que não usa `FOR UPDATE` em lugar
nenhum hoje), porque aqui é o ponto de aplicação de um import inteiro, não uma
leitura qualquer: sem o lock, duas confirmações concorrentes do mesmo `idx`
(F5 duplo, dois operadores) podem ambas ler `imported_at IS NULL` antes que a
primeira transação commite, e ambas seguirem para a passada de escrita —
combinado com o envio de e-mail (Step 4), isso duplicaria convites com tokens
diferentes para o mesmo lote antes da segunda transação falhar na
`UNIQUE KEY mail_UNIQUE`. Se `imported_at` já não é nulo, a confirmação é
no-op e redireciona com mensagem "este import já foi aplicado em `<data>`",
igual ao padrão do legado.

**Verify**: sequência de 5 passos acima nomeia o estado do sistema em cada
etapa (arquivo validado → linhas classificadas em memória → rascunho persistido
→ preview lido → aplicado), cobrindo upload → parse → preview → confirmação.

## Step 3: Transação e tamanho do lote

**Atomicidade: tudo ou nada.** É o comportamento natural da transação global do
projeto — `localPDO` abre uma transação por request, `basic_redir()` commita.
Não existe commit intermediário no framework hoje, e criar um mexeria em
`localPDO`, que é infraestrutura compartilhada usada por toda requisição do
sistema, não só o import. Registrado como não-decisão: **não proponho mexer em
`localPDO`** — se um import precisar de lotes com commit parcial, isso é um
projeto à parte, maior que este.

**Teto de linhas: 200 por arquivo.** Acima disso, o preview rejeita o arquivo
inteiro antes de processar qualquer linha, com mensagem clara ("arquivo tem N
linhas, o máximo é 200 — divida em arquivos menores"). Cálculo de tempo:

- Por linha "criar": 1 `SELECT` (checar `mail` duplicado) + 1 `INSERT` em
  `users` + 1 `INSERT`/`ON DUPLICATE KEY` em `users_profiles`
  (`DEFAULT_USER_PROFILE_ID`) = 3 queries.
- Por linha "atualizar": 1 `SELECT` + 1 `UPDATE` = 2 queries.
- Pior caso (200 linhas, todas "criar"): 200 × 3 × ~2ms = **1,2s**. Bem dentro
  de qualquer `max_execution_time` de PHP-FPM configurado neste projeto (o
  padrão do PHP é 30s; nada no `kernel.php.example` reduz isso).
- O envio de e-mail **não** entra nessa conta — é tratado à parte no Step 4
  porque, sem `rdkafka`, ele sozinho estoura esse orçamento de tempo muito antes
  do banco.

200 é conservador de propósito: cobre o caso motivador do plano ("300
usuários") em duas rodadas, e um teto baixo é reversível (subir depois é trivial
se a prática mostrar folga) enquanto um teto alto que trava em produção não é.

**Verify**: teto concreto = 200 linhas; atomicidade = tudo ou nada; tempo
calculado (1,2s para o lote de banco) dentro do limite de execução do PHP-FPM.

## Step 4: Senha e e-mail

Nenhuma senha vem do arquivo — replico o que `auth_controller::register()`
já faz (`auth_controller.php:139-155`), não o `reset-senha` (que é para usuário
já existente com senha já definida):

- Linha "criar": `password = password_hash(random_token(), PASSWORD_BCRYPT)`
  (placeholder inerte, nunca usado para logar), `enabled = 'no'`,
  `email_token = random_token()`, `email_token_expires_at = +72h` (mesma janela
  do cadastro manual — 2h do reset é curto demais para um convite que a pessoa
  pode não ver no mesmo dia), perfil `DEFAULT_USER_PROFILE_ID` via
  `save_attach()`. E-mail usa o template existente
  `ui/mail/new_admin_credentials.php` com o link
  `/definir-senha/<token>` — zero template novo.
- Linha "atualizar" (`mail` já existe em `users`): **não** reenvia link de
  senha, **não** mexe em `password`/`email_token`. Atualiza só `name`, `login`,
  `enabled` a partir do arquivo. Regra explícita: reimportar um usuário existente
  é uma correção de cadastro, não um convite de novo.

**Ordem de disparo — achado de review, corrige o desenho original**: os e-mails
**não** podem ser enviados intercalados com as escritas em `users` dentro do
mesmo laço. A transação é tudo-ou-nada (Step 3): se a linha 150 de 200 falhar,
o `catch` dispara `basic_redir(..., rollback: true)`, que desfaz **todas** as
inserções anteriores — mas um SMTP já disparado para as ~149 linhas
anteriores não tem como ser "desfeito". O usuário recebe um link
`/definir-senha/<token>` cujo `token` nunca vai existir em `users` (o insert
foi revertido), e uma nova tentativa de confirmação gera um `email_token`
diferente — o e-mail já entregue fica morto, sem qualquer sinal pro operador.
Por isso a Etapa 5 do Step 2 exige duas passadas: **primeiro** todas as
escritas de banco da linha "criar"/"atualizar", **só depois** — se a passada
inteira terminou sem erro, ainda dentro da mesma transação, antes do
`basic_redir()` — o disparo dos e-mails. Isso não resolve o teto de tempo
abaixo, só evita gerar convites órfãos.

**Envio em massa**: 200 linhas "criar" no pior caso = até 200 e-mails, disparados
na segunda passada acima. Com `rdkafka` disponível, `EmailProducer` enfileira
as 200 mensagens de forma assíncrona — o tempo de request não é afetado, igual
ao `reset-senha` de hoje enfileirando um e-mail por vez. **Sem `rdkafka`**
(fail-open, cai para envio síncrono): 200 chamadas SMTP sequenciais dentro do
mesmo request antes do `basic_redir()` — a ~200-500ms por envio síncrono
típico, isso é 40-100s, **estoura qualquer `max_execution_time` razoável**.
Este é um limite real que este spike não resolve: registro como risco explícito
(não invento fila/worker aqui, é o STOP condition do plano). Mitigação possível
sem worker novo — a avaliar num plano de implementação, não decidida aqui:
reduzir o teto de linhas quando `rdkafka` não está disponível, ou aceitar que o
ambiente de produção sempre tem `rdkafka` (verificar, não assumir).

**`messages`**: cada e-mail enviado grava uma linha via `redact_email_body()`,
igual ao `register()`/`reset-senha`. Um import de 200 linhas "criar" gera até
200 linhas em `messages`. O índice `idx_messages_active_sent_at` do plano 011
(`migrations/011_add_messages_profiles_indexes.sql`) já cobre o padrão de
consulta da tela `/emails` (`WHERE active='yes' ORDER BY sent_at DESC`) —
nenhum índice novo necessário por causa do import; é só mais volume sobre um
caminho já indexado. Retenção de `messages` continua em aberto desde o plano
011 e este import pressiona essa fila mais rápido — vale citar ao operador, não
resolver aqui.

**Verify**: decisão de envio em massa registrada (assíncrono ok, síncrono sem
`rdkafka` é o limite real) e estimativa concreta (até 200 e-mails / 200 linhas em
`messages` por import no teto proposto).

## Step 5: Onde o código moraria

**Controller próprio: `usersimports_controller`.** `users_controller.php` já
tem 556 linhas e um `action()` com três responsabilidades (ativar/inativar,
reset-senha, export-csv); import é uma quarta feature com fluxo próprio de duas
fases — inflar mais o `action()` existente é o oposto do que os spikes 027/028
(arquivo próprio por entidade, citados pelo plano) recomendam.

**Mapeamento no contrato `display/form/save/remove` do projeto** — cabe sem
inventar um quinto método genérico, reaproveitando um padrão que já existe
*dentro deste mesmo controller de usuários* (`action()` para transição de
estado sem formulário de campo):

| Método | Rota | O que faz |
|---|---|---|
| `display()` | `GET /importar-usuarios` | Histórico de imports (linhas de `users_imports`, mais recentes primeiro) + link "baixar modelo" (reusa `array_to_csv()` com só o cabeçalho: `name;mail;login;enabled`) |
| `form()` | `GET /importar-usuarios/novo` (upload em branco) e `GET /importar-usuarios/:idx` (preview de um rascunho existente, lendo `dados`) | Mesmo método reaproveitado por presença de `:idx`, igual a `users_controller::form()` hoje para criar vs. editar |
| `save()` | `POST /importar-usuarios/novo` | Recebe o arquivo (`handle_upload` + `fgetcsv`), classifica linhas, grava o rascunho em `users_imports.dados`, redireciona para o preview — **não** escreve em `users` |
| `action()` | `POST /importar-usuarios` (`action=confirmar`, `idx=N`) | Aplica o rascunho em `users` dentro da transação única, marca `imported_at` — o "método a mais" do plano é este, e já tem precedente direto no `action()` do `users_controller` de hoje |
| `remove()` | `POST /importar-usuarios/:idx/remover` | Soft-delete de um rascunho **não aplicado** (`imported_at IS NULL`); rascunho já aplicado não pode ser removido, só consultado no histórico |

**Rotas** (todas atrás de `$authGuard`, seguindo `manager/public_html/index.php`):

```
GET  /importar-usuarios              usersimports_controller:display
GET  /importar-usuarios/novo         usersimports_controller:form
POST /importar-usuarios/novo         usersimports_controller:save
GET  /importar-usuarios/([0-9]+)     usersimports_controller:form
POST /importar-usuarios              usersimports_controller:action
POST /importar-usuarios/([0-9]+)/remover  usersimports_controller:remove
```

**Migration proposta (não criar agora)** — segue o padrão de auditoria completo
de `migrations/002_create_table_users.sql` e o índice de
`migrations/011_add_messages_profiles_indexes.sql`:

```sql
-- Proposto — NÃO criar migration neste plano.
CREATE TABLE IF NOT EXISTS `users_imports` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') NOT NULL DEFAULT 'yes',
    `name` VARCHAR(255) DEFAULT NULL,       -- nome do arquivo original
    `dados` LONGTEXT NOT NULL,              -- JSON: linhas classificadas (criar/atualizar/erro)
    `imported_at` DATETIME DEFAULT NULL,
    `imported_by` INT DEFAULT NULL,
    PRIMARY KEY (`idx`),
    INDEX `idx_users_imports_active_created` (`active`, `created_at`)
);

-- ROLLBACK MANUAL (o runner nao tem suporte a down; execute a mao se preciso):
--   DROP TABLE `users_imports`;
-- Risco: perde o historico de imports (rascunhos e aplicados); nao afeta `users`.
```

**Consequência não óbvia da cópia byte-a-byte**: `app/inc/model/` precisa ser
idêntico entre `manager/` e `site/` (confirmado: `diff -rq manager/app/inc/model
site/app/inc/model` está vazio hoje). Um `usersimports_model.php` novo teria que
existir **também** em `site/app/inc/model/`, mesmo que o `site` (frontend
público) nunca tenha rota nem controller que o use — é o preço de manter as
cópias sincronizadas, e o guard `bin/check-shared-sync.sh` bloquearia o commit
se só um lado ganhasse o arquivo.

**Arquivos que a implementação criaria/tocaria**:

- `migrations/012_create_table_users_imports.sql` (nova — checar próximo
  número livre no momento da implementação; sem prefixo `manager/`, mesmo
  diretório único na raiz usado por `migrations/002_create_table_users.sql` e
  `migrations/011_add_messages_profiles_indexes.sql` citados acima)
- `manager/app/inc/controller/usersimports_controller.php` (novo)
- `manager/app/inc/model/users_imports_model.php` **e**
  `site/app/inc/model/users_imports_model.php` (novo, byte-idêntico nas duas cópias)
- `manager/public_html/index.php` (6 rotas novas, ver acima)
- `manager/app/inc/urls.php` (`$usersimports_url` ou similar, se o padrão de
  `$users_url` for seguido)
- Views novas (upload, preview, histórico) — caminho exato depende de onde as
  views de `users_controller` moram hoje (não inspecionado neste spike; a
  implementação confirma antes de criar)
- `manager/tests/UsersImportsControllerTest.php` (novo) — cobrir: parse de linha
  válida/inválida, mapeamento de cabeçalho fora de ordem, linha com número de
  colunas diferente do cabeçalho (rejeitada/marcada como erro antes do
  mapeamento por nome, não só reordenação), BOM/Latin-1, round-trip do CSV
  exportado, `mail`/`name` iniciado por `=`/`+`/`-`/`@` tratado como texto puro
  no parser (não é sanitização de escrita reaplicada na leitura — CSV
  injection), duas linhas com o mesmo `mail` no mesmo arquivo marcadas como
  erro no preview antes da confirmação, teto de 200 linhas, confirmação dupla
  (idempotência via `imported_at`), rollback em erro no meio do lote,
  `handle_upload` rejeitando MIME que não é CSV real (é o primeiro teste que
  esse helper receberia — nota de manutenção do plano)

**Verify**: lista de arquivos com caminho completo acima; justificativa
(controller próprio + `action()` reaproveitado, sem quinto método genérico)
registrada.

## Step 6: Recomendação e fatiamento

**Recomendação: fazer, como versão mínima primeiro.**

**Versão mínima (fatia 1, esforço M)**: só `name` + `mail`, sem `login` nem
`enabled` no arquivo (ambos usam default), sem coluna de perfis, preview simples
(total / criar / atualizar / erro com linha+motivo — isso não é opcional, é o
mínimo do Step 2), teto de 200 linhas, e-mail de definição de senha via
`new_admin_credentials.php`. Fecha a lacuna real do plano ("300 vezes o
formulário") com a fração de escopo mais barata que ainda é honesta sobre o que
vai acontecer antes de aplicar.

| # | Fatia | Esforço | Entrega sozinha |
|---|---|---|---|
| 1 | Migration `users_imports` + controller com `display/form/save/action/remove` + parser `name`+`mail` + preview simples + teto 200 + e-mail de convite | M | Import funcional ponta a ponta para o caso comum (usuário novo, sem perfil) |
| 2 | Colunas `login`/`enabled` no parser + atualização de usuário existente (`mail` já cadastrado) | S | Cobre reimport/correção de cadastro, não só criação |
| 3 | Coluna `profiles` (slugs) + resolução slug→idx + `save_attach()` por linha | M | Fecha a atribuição de perfil no mesmo import, sem precisar editar um a um depois |
| 4 | Baixa de teto dinâmica ou outra mitigação quando `rdkafka` indisponível (decisão do Step 4) | S–M, depende da mitigação escolhida | Import seguro mesmo em ambiente sem fila real |

Fatias 2-4 só valem a pena se a fatia 1 mostrar uso real — não implementar
antecipado.

**Riscos que a implementação precisa vigiar**:

- **Fórmula em CSV**: `csv_sanitize_cell()` protege a **escrita** (prefixa `'`
  em valores que começam com `=`, `+`, `-`, `@`); ela não roda na **leitura**.
  Um `mail` ou `name` que comece com `=` (fórmula) precisa ser tratado como
  texto puro no parser do import — não reexecutar sanitização de escrita como
  se fosse validação de entrada, são propósitos diferentes.
- **E-mail duplicado dentro do mesmo arquivo** (duas linhas com o mesmo `mail`):
  o preview precisa detectar isso e marcar como erro, senão a segunda linha
  colide com o `UNIQUE KEY mail_UNIQUE` no meio da transação de confirmação e
  derruba o lote inteiro (comportamento correto dado "tudo ou nada", mas o
  preview deveria ter avisado antes).
- **Linha com colunas a mais/a menos** que o cabeçalho: `fgetcsv()` não trava,
  mas o mapeamento por nome pode ficar desalinhado — validar contagem de campos
  por linha contra o cabeçalho antes de mapear.
- **Detecção de MIME de CSV é frágil**: `handle_upload()` usa
  `finfo_file(FILEINFO_MIME_TYPE)`, que para um `.csv` de texto puro pode
  retornar `text/plain` em vez de `text/csv` dependendo do conteúdo e da base
  `magic` do sistema — `$mimeMap` só reconhece `text/csv`. Isso pode rejeitar
  CSVs legítimos gerados por planilhas diferentes. A implementação precisa
  testar com arquivos reais (Excel BR, LibreOffice, o próprio `export-csv`) e
  decidir se aceita `text/plain` também quando a extensão declarada é `.csv`
  (não é gratuito flexibilizar `handle_upload()` para todo mundo por causa
  disso — decisão local ao import, não mudança do helper genérico).
- **Arquivo de 50MB**: `UPLOAD_MAX_SIZE` (kernel, default 10MB) já limita isso
  em `handle_upload()`; não precisa de checagem adicional no import, só garantir
  que as `options` passadas não elevem esse teto sem necessidade.

**Nenhuma dependência nova**: nada acima exige `phpoffice/phpspreadsheet` ou
qualquer parser de planilha — `fgetcsv()` (stdlib do PHP) resolve o formato
inteiro. Não há pergunta pendente ao operador sobre dependência.

**Verify**: recomendação inequívoca (fazer, mínimo primeiro) com fatias e
esforço; versão mínima descrita com escopo concreto (`name`+`mail`, sem perfil,
teto 200).

## Step 7: Confirmação de que produção não foi tocada

```
git status --short
```
Saída (no momento da checagem, antes do commit deste documento): vazia —
`plans/` inteiro está no `.gitignore` (`.gitignore:44`, "rascunho de trabalho
local — não sincroniza"), então um arquivo novo e ainda não adicionado ao
índice dentro de `plans/` não aparece em `git status`. Confirmado com
`git check-ignore -v plans/018-DESIGN.md` naquele instante. Isso não significa
que arquivos em `plans/` sejam imunes a `git add`/commit — um arquivo já
adicionado ao índice deixa de ser filtrado pelo `.gitignore`, e é exatamente o
que aconteceu aqui: este documento foi versionado com `git add -f` (mesmo
precedente de `plans/015-DESIGN.md` e `plans/016-DESIGN.md`, já rastreados do
mesmo jeito) e faz parte do commit `788d180` desta branch. Nenhum arquivo de
produção foi tocado — só este documento, e ele está rastreado por decisão
explícita, não por engano do `.gitignore`.

```
bash bin/test.sh
```
Resultado nesta sessão: **falhou por ausência de ambiente**, não por regressão
— `kernel.php` não existe neste worktree (é gitignored, precisa ser copiado de
`.example` manualmente por ambiente) e o container Docker (`leggo`) não está
rodando aqui. Isso é esperado e consistente com o que o plano 016 já registrou
para o mesmo tipo de worktree isolado: nenhum arquivo PHP foi tocado por este
spike (só `plans/018-DESIGN.md` foi criado), então não há como este documento
ter introduzido uma regressão que `bin/test.sh` pegaria. Não afirmo "exit 0"
porque não rodei com sucesso — registro a causa da falha em vez de inventar um
resultado.

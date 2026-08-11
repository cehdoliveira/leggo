# Planos de implementação — padrão de controller

Gerados pela skill `improve` em 2026-08-11, contra o commit `a032c73`.

Origem: reimplementar os controllers do framework segundo um contrato de
comportamento de seis operações (`data4select`, `filter`, `display`, `form`,
`save`, `remove`), tendo `exemplo_controller.php` (controller legado) como
referência de convenção — **não** de implementação.

Decisões tomadas com o solicitante antes do planejamento:

- O padrão vale para **todos os controllers de recurso do manager**, não para uma
  entidade nova. `auth_controller` e as páginas estáticas do `site/` ficam fora:
  não são CRUD.
- O padrão **substitui já** a convenção atual (`index()` + POST `action()` com
  formulários em modal), não coexiste com ela.

Execute na ordem abaixo. Cada executor: leia o plano inteiro antes de começar,
respeite as STOP conditions, e atualize a linha correspondente ao terminar.

## Ordem de execução e status

| Plano | Título | Prioridade | Esforço | Depende de | Status |
|---|---|---|---|---|---|
| 001 | `DOLModel::data4select()` com params bindados | P1 | S | — | DONE |
| 002 | Commit gate em respostas terminais sem redirect | P1 | S | — | TODO |
| 003 | Helpers de ordenação com allowlist | P1 | S | — | TODO |
| 004 | `attach()` em lote (fim do N+2) | P2 | M | — | TODO |
| 005 | Padrão `display/form/save/remove` — exemplar em `profiles` | P1 | L | 001, 002, 003 | TODO |
| 006 | Padrão em usuários (`users_controller`) + slug + vínculos | P2 | L | 005 | TODO |
| 007 | Padrão em e-mails (somente leitura) | P3 | S | 005 | TODO |

Valores de status: TODO | IN PROGRESS | DONE | BLOCKED (com motivo em uma linha) |
REJECTED (com justificativa em uma linha).

## Notas de dependência

- **001, 002, 003 são as peças de framework que 005 consome.** Os três mexem em
  arquivos compartilhados (`app/inc/lib/`, duas cópias byte-a-byte); 002 e 003
  editam o mesmo arquivo (`CommonFunctions.php`) e 001 e 004 editam o mesmo
  arquivo (`DOLModel.php`) — execute na ordem numérica para evitar conflito.
- **004 é independente e pode entrar a qualquer momento**, mas quanto antes
  melhor: 006 usa `attach()` numa listagem paginada, que é exatamente o caso
  onde o N+2 dói.
- **005 é o exemplar.** 006 e 007 espelham o arquivo que 005 produz. Executar 006
  ou 007 antes de 005 significa inventar o padrão duas vezes.
- 007 é o último porque é o menor e o de menor risco — e é ele que permite
  afirmar que `index()`/`action()` não sobrou em nenhuma tela.

## Desvios deliberados do contrato legado

Registrados aqui para não serem "corrigidos" de volta por quem ler só o
`exemplo_controller.php`:

1. **`filter()` devolve três coleções** (`[$done, $filter, $params]`), não duas.
   O legado concatena os valores do usuário no SQL
   (`exemplo_controller.php:21,25,29`) — injeção. Aqui os valores vão bindados.
2. **A ordenação passa por allowlist.** `ORDER BY` não aceita bind; o legado joga
   o query string cru na query (`exemplo_controller.php:40,54`).
3. **`data4select` vive no `DOLModel`**, não como método estático de cada
   controller — uma implementação em vez de uma por entidade.
4. **Todo POST valida CSRF.** O contrato legado não menciona CSRF; este repo
   exige `validate_csrf()` em toda rota POST, inclusive logout.
5. **`remove` mantém os guards de permissão** (perfil protegido, auto-remoção),
   mesmo o contrato dizendo que a operação "nunca reporta falha". Regredir
   controle de acesso não é opção.
6. **Upload usa `handle_upload()`**, não `move_uploaded_file()` cru. O helper já
   valida MIME real, normaliza o nome, acrescenta selo temporal e cria o
   diretório (`CommonFunctions.php:524`). O legado
   (`exemplo_controller.php:178-213`) aceita qualquer arquivo enviado.
7. **A paginação passa a usar `sr`** (deslocamento), que o front controller já
   monta e nenhum controller usava, no lugar do `page` improvisado por tela.

## Partes do contrato ainda sem cobertura

- **Upload de arquivo**: nenhuma entidade atual (`users`, `profiles`, `messages`)
  tem coluna de imagem. O passo está documentado nos planos 005 e 006 com o
  helper a usar quando a primeira entidade com imagem aparecer, mas não há código
  a escrever hoje — escrever seria código morto.

## Achados considerados e descartados

- **Contagem duplicada (`SELECT COUNT(*)` manual nos três controllers)**: é
  achado real, mas não virou plano próprio — some dentro de 005, 006 e 007, que
  reescrevem os três controllers e passam a usar o `recordset` de `load_data()`.
  Cada um desses planos tem um critério de conclusão específico
  (`grep -rn "COUNT(\*)"` sem resultado).
- **`attach_son()` com o mesmo N+2 de `attach()`**: só roda via `_current_data()`
  sobre uma linha, onde o custo é irrelevante. Registrado como nota de manutenção
  no plano 004; não vale plano.
- **`save_attach()` não desvincula quando a lista vem vazia** (`DOLModel.php:498`):
  contornado no controller em 4 linhas no plano 006. A correção limpa é no
  framework (duas cópias + testes) e merece plano próprio se o contorno se
  repetir numa segunda entidade.
- **Bug no `remove()` do `exemplo_controller.php`** (usa
  `biblioteca_conteudos_model` e redireciona para `libraries_url` num controller
  de banners, linhas 242 e 249): é erro do arquivo de referência, não do repo.
  Nada a corrigir aqui — apenas não replicar.

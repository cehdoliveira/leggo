# 027 — DESIGN: view admin de outbox de e-mails (`messages`)

> Spike/design executado a partir de `plans/027-spike-email-outbox-view.md`.
> Deliverable: schema real, decisões, protótipo de leitura, perguntas em aberto.

## Schema real (`SHOW COLUMNS FROM messages`)

Rodado via `docker exec mysql mysql -uuser_leggo -p123456 -e 'SHOW COLUMNS FROM messages;' db_leggo`:

```
Field        Type               Null  Key  Default             Extra
idx          int                NO    PRI  NULL                auto_increment
created_at   datetime           NO         NULL
created_by   int                NO         0
modified_at  datetime           YES        NULL
modified_by  int                YES        NULL
removed_at   datetime           YES        NULL
removed_by   int                YES        NULL
active       enum('yes','no')   NO         yes
to_mail      varchar(255)       NO         NULL
subject      varchar(500)       NO         NULL
body         longtext           NO         NULL
sent_at      datetime           NO         CURRENT_TIMESTAMP   DEFAULT_GENERATED
```

Coincide exatamente com `migrations/005_create_table_messages.sql`. **Não há coluna
de status de entrega nem de erro** — a tabela registra que uma tentativa de envio foi
*logada*, não que o e-mail chegou ao destinatário. Isso limita o que a view pode
honestamente mostrar (ver "Perguntas em aberto").

## Rota, controller e view propostos (implementados neste spike)

- **Rota**: `GET /emails`, registrada em `manager/public_html/index.php` com
  `$authGuard` (mesmo padrão de `/usuarios`):
  ```php
  $dispatcher->add_route("GET", "/emails", "emails_controller:index", $authGuard, $params);
  ```
- **URL helper**: `$emails_url` adicionado em `manager/app/inc/urls.php`, seguindo o
  padrão de `$users_url`.
- **Controller**: `manager/app/inc/controller/emails_controller.php`, método `index()`.
  Usa `messages_model` → `set_field([...])` → `set_order([" sent_at DESC "])` →
  `set_paginate([$offset, 25])` → `load_data(false)`, com uma query `COUNT(*)` separada
  via `execute_raw_prepared` para o total (mesmo padrão do `site_controller::dashboard`,
  que já faz isso para evitar a segunda query de contagem embutida no `load_data`).
- **View**: `manager/public_html/ui/page/emails.php`, réplica estrutural de
  `dashboard.php` (mesmo `manager-layout`/`manager-sidebar`/`content-panel`, sem CSS
  novo). Um link "E-mails" foi adicionado à sidebar de `dashboard.php` e de `emails.php`
  para navegação cruzada.

### Colunas exibidas na tabela

| Coluna       | Origem                                  |
|--------------|------------------------------------------|
| #            | `idx`                                    |
| Destinatário | `to_mail` (htmlspecialchars)             |
| Assunto      | `subject` (htmlspecialchars)             |
| Corpo        | `str_limit(body, 120)` (já redigido, ver abaixo) |
| Enviado      | `time_ago(sent_at)`                      |

**Decisão**: a coluna "status" mencionada no plano original *não foi incluída* —
não há dado que a sustente honestamente. Mostrar um badge fixo ("Enviado") para
100% das linhas seria enganoso, pois a tabela registra a tentativa de log, não a
confirmação de entrega (ver commit dos controllers: o `EmailProducer->send()` e o
`messages_model->save()` estão em blocos `try/catch` **separados** — uma falha no
envio real não impede o registro do log, e vice-versa). Ver "Perguntas em aberto".

### Paginação

Reuso de `set_paginate` + o mesmo padrão de paginação HTML (`set_url` com `page=`)
usado em `dashboard.php`. 25 registros por página.

### Sem ação de reenvio no MVP

Conforme o plano — sem reenvio, edição ou export nesta view. Apenas leitura.

### Segurança — corpo exibido

O corpo exibido é **sempre** o valor persistido em `messages.body`, que já passa por
`redact_email_body()` em todos os 4 pontos de escrita (auditado nesta sessão):

- `site/app/inc/controller/auth_controller.php:172` (cadastro/verificação de e-mail)
- `site/app/inc/controller/auth_controller.php:441` (forgot-password)
- `manager/app/inc/controller/auth_controller.php:182` (criação de usuário admin)
- `manager/app/inc/controller/site_controller.php:147` (reset de senha)

Todos os 4 caminhos chamam `redact_email_body($body)` antes de `populate()`/`save()`.
Nenhuma lacuna encontrada — **não é um achado de segurança** (condição de STOP do
plano não disparada). A view nunca acessa `body` fora do que vem do banco.

## Perguntas em aberto

1. **Não há coluna de status de entrega/erro.** A tabela `messages` é hoje um *log de
   tentativa*, não um outbox real. Se o objetivo é responder "o e-mail X foi
   entregue?", a resposta honesta com o schema atual é "foi registrado que tentamos
   enviar" — não "foi entregue". Vale a pena adicionar `status` (`queued`/`sent`/
   `failed`) e `error_message`? Isso exigiria mudar os 4 pontos de escrita para saber
   o resultado do `EmailProducer->send()` antes de logar (hoje os dois try/catch são
   independentes).
2. **Precisa de filtro por destinatário?** Um operador investigando "o usuário X
   recebeu o e-mail de reset?" hoje precisa varrer páginas manualmente. Um filtro
   `WHERE to_mail LIKE ?` seria a extensão natural mais barata.
3. **O corpo redigido é seguro para exibição integral, ou só truncado?** Neste
   protótipo optei por truncar (`str_limit(..., 120)`, que também faz `strip_tags`)
   por prudência e por ser suficiente para o caso de uso de suporte ("foi essa a
   mensagem enviada?"). Exibir o HTML completo (ainda que redigido) abriria espaço
   para um modal/detalhe — decisão de produto para uma iteração futura, não deste
   spike.
4. **Falta paginação/ordenação por outros critérios** (ex.: por destinatário, por
   assunto) — fora de escopo deste spike; documentado aqui como possível follow-up.

## Follow-ups (fora de escopo deste spike)

- Transformar `messages` em outbox de verdade: coluna de status de entrega + retry,
  alinhado ao trade-off identificado no plano 019 (janela de flush menor do
  `EmailProducer` → mais falhas visíveis que um outbox absorveria). **Esforço
  estimado**: M/G — exige mudar os 4 pontos de escrita para propagar o resultado do
  envio, mais uma migration para as novas colunas, mais worker/retry se quisermos
  reenvio automático.
- Filtro por destinatário/assunto na view. **Esforço estimado**: P — um `WHERE ... LIKE`
  a mais no controller e um input de busca na view, seguindo o padrão de paginação já
  existente.
- Ação de reenvio manual (buscar o usuário pelo `to_mail`, reconstruir o e-mail e
  reenviar). **Esforço estimado**: M — depende de conseguir reconstruir o conteúdo
  original (hoje só temos o HTML já redigido, não os dados estruturados que geraram o
  template), então não é um reenvio trivial de "replay" do `body` persistido.
- Export CSV da lista de e-mails (mesmo padrão de `array_to_csv` já usado em
  `/usuarios`). **Esforço estimado**: P.

## Verificação executada nesta sessão

- `docker exec mysql mysql ... SHOW COLUMNS FROM messages` → colunas confirmadas
  (schema acima).
- PHPStan manager (`php app/inc/lib/vendor/bin/phpstan analyse`) → `[OK] No errors`.
- Protótipo testado ponta a ponta: copiado para um path temporário dentro do
  container `leggo` (não o path com bind-mount do checkout principal, para não
  misturar com o repositório do operador), servido via `php -S` local ao container,
  com sessão autenticada simulada. `GET /emails` sem sessão → `302` (auth guard
  funcionando); `GET /emails` autenticado → `200`, tabela vazia renderizada
  corretamente; após inserir uma linha de teste em `messages` (removida depois do
  teste), a linha apareceu na tabela com destinatário, assunto, corpo redigido e
  `time_ago` corretos. Nenhuma alteração permanente foi feita no banco de dados
  compartilhado (linha de teste inserida e removida na mesma sessão).

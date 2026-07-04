# DESIGN 029: init-whitelabel — inventário de pontos de toque por-marca

> Companion do plano `plans/029-spike-init-whitelabel.md`. Este documento é o
> deliverable 1 (inventário completo) + perguntas em aberto. O protótipo do
> script é `bin/init-whitelabel.sh` (deliverable 2).

## Método

Lidos por inteiro `site/app/inc/kernel.php.example` e
`manager/app/inc/kernel.php.example` (106 linhas cada, hoje idênticos exceto
pelos valores por-marca/por-ambiente). Também inspecionados: `docker/.env.example`
(credenciais de banco, fora dos kernels), `docker/docker-compose.yml` (paths de
mount fixos), `public_html/assets/css/main.css` de site e manager (cor da
marca — não é uma constante do kernel), e `.gitignore` (confirma que
`kernel.php` e `docker/.env` continuam ignorados).

## Inventário completo — constantes dos dois `kernel.php.example`

Legenda da coluna **Ação**:
- **Marca (script)** — o script `init-whitelabel.sh` substitui com valor derivado do nome/URL informado.
- **Segredo (operador)** — só o operador sabe o valor real; o script NUNCA inventa, preserva o placeholder do example e avisa no final.
- **Infra (mantém default)** — não muda por marca; é decisão de ambiente/infra, mantém o valor do example.
- **Computado (sem ação)** — resolvido em runtime a partir de `$_SERVER`/outras constantes; não é uma linha estática para substituir.

| Constante | site (example) | manager (example) | Ação | Observação |
|---|---|---|---|---|
| `DB_HOST` | `mysql` | `mysql` | Infra (mantém default) | Nome do serviço Docker; igual em qualquer instância que usa o `docker-compose.yml` deste repo. |
| `DB_NAME` | `db_leggo` | `db_leggo` | Infra (mantém default) | Teria que casar com `MYSQL_DATABASE` em `docker/.env` (fora do escopo dos kernels) — ver "Perguntas em aberto". |
| `DB_USER` | `user_leggo` | `user_leggo` | Infra (mantém default) | Mesma observação de `DB_NAME`. |
| `DB_PASS` | `SUA_SENHA_AQUI` | `SUA_SENHA_AQUI` | **Segredo (operador)** | Precisa bater com `MYSQL_PASSWORD` em `docker/.env`. Script preserva o placeholder e avisa. |
| `REDIS_HOST` | `redis` | `redis` | Infra (mantém default) | — |
| `REDIS_PORT` | `6379` | `6379` | Infra (mantém default) | — |
| `REDIS_PREFIX` | `leggo:site:` | `leggo:manager:` | **Marca (script)** | Contém o slug da marca (`leggo`) — evita colisão de chaves se infra for compartilhada entre instâncias. Script gera `${SLUG}:site:` / `${SLUG}:manager:`. |
| `REDIS_DATABASE` | `0` | `0` | Infra (mantém default) | — |
| `REDIS_ENABLED` | `true` | `true` | Infra (mantém default) | — |
| `REDIS_DEFAULT_TTL` | `3600` | `3600` | Infra (mantém default) | — |
| `KAFKA_HOST` | `kafka` | `kafka` | Infra (mantém default) | — |
| `KAFKA_PORT` | `9092` | `9092` | Infra (mantém default) | — |
| `KAFKA_TOPIC_EMAIL` | `leggo_site_emails` | `leggo_manager_emails` | **Marca (script)** | Contém slug. Script gera `${SLUG}_site_emails` / `${SLUG}_manager_emails`. |
| `KAFKA_CONSUMER_GROUP` | `leggo-site-email-worker-group` | `leggo-manager-email-worker-group` | **Marca (script)** | Contém slug. Script gera `${SLUG}-site-email-worker-group` / `${SLUG}-manager-email-worker-group`. |
| `mail_from_name` | `leggo` | `leggo Manager` | **Marca (script)** | Nome exibido no remetente do e-mail. Script usa o nome da marca informado (` Manager` sufixo no manager). |
| `mail_from_mail` | `seu_email@exemplo.com` | `seu_email@exemplo.com` | **Segredo (operador)** | Endereço real de envio (depende de conta SMTP real). Script preserva o placeholder e avisa. |
| `mail_from_host` | `smtp.gmail.com` | `smtp.gmail.com` | Infra (mantém default) | Provedor SMTP é decisão operacional, não de marca. |
| `mail_from_port` | `587` | `587` | Infra (mantém default) | — |
| `mail_from_user` | `seu_email@exemplo.com` | `seu_email@exemplo.com` | **Segredo (operador)** | Normalmente igual a `mail_from_mail`; depende da conta real. |
| `mail_from_pwd` | `SUA_APP_PASSWORD_AQUI` | `SUA_APP_PASSWORD_AQUI` | **Segredo (operador)** | Exemplo citado no próprio plano como condição de atenção (STOP). Script preserva o placeholder e avisa. |
| `mail_encryption` | `tls` | `tls` | Infra (mantém default) | — |
| `cAppKey` | `leggo_site_session` | `leggo_manager_session` | **Marca (script)** | Chave de sessão — precisa ser única por marca/ambiente para não colidir cookies entre instâncias. Script gera `${SLUG}_site_session` / `${SLUG}_manager_session`. |
| `cTitle` | `leggo` | `leggo Manager` | **Marca (script)** | Nome da marca exibido na UI. |
| `cAppRoot` | `/` | `/` | Infra (mantém default) | Caminho da aplicação sob o document root; não muda por marca no setup padrão. |
| `cRootServer` / `cRootServer_APP` | computado | computado | Computado (sem ação) | Deriva de `$_SERVER["DOCUMENT_ROOT"]` + `cAppRoot`. |
| bloco `$_detected_protocol` (detecção HTTPS) | lógica | lógica | Computado (sem ação) | Não é uma constante de marca. |
| `ALLOWED_HOSTS` | `leggo.local` | `manager.leggo.local` | **Marca (script)** | Domínio de produção da marca. Crítico para segurança (Host Header Injection) — script usa o host extraído da URL informada. |
| `cFrontend` / `cAssets` | computado | computado | Computado (sem ação) | Deriva de `HTTP_HOST` em runtime. |
| `DEFAULT_USER_PROFILE_ID` | `2` | `2` | Infra (mantém default) | Referência a uma linha semeada no banco (perfil padrão); não é identidade de marca. |
| `APP_VERSION` | `1.8.1.0` | `1.8.1.0` | Infra (mantém default) | Já resolvido pelo plano 025 (Abordagem A, valor estático). Este script **apenas copia o valor tal como está** do example — nenhuma substituição, nenhuma leitura de `VERSION`. |
| `LOG_LEVEL` | `info` | `info` | Infra (mantém default) | — |
| `UPLOAD_DIR` | `/var/www/leggo/site/...` | `/var/www/leggo/manager/...` | Infra (mantém default) | Contém a string `leggo`, mas é o **path de mount fixo do container** (`docker/docker-compose.yml:13-27`), igual para qualquer marca que rode este `docker-compose.yml`. Não trocar. |
| `UPLOAD_MAX_SIZE` | `10` | `10` | Infra (mantém default) | — |
| `UPLOAD_ALLOWED_TYPES` | `jpg,jpeg,...` | `jpg,jpeg,...` | Infra (mantém default) | — |
| `SITE_CANONICAL_URL` | `http://leggo.local` | — | **Marca (script)** | URL canônica de produção do site. Script usa a URL informada tal como digitada. |
| `MANAGER_CANONICAL_URL` | — | `http://manager.leggo.local` | **Marca (script)** | URL canônica de produção do manager. |
| `SESSION_LIFETIME` | `7200` | `7200` | Infra (mantém default) | — |
| `SESSION_USE_REDIS` | `false` | `false` | Infra (mantém default) | — |
| bloco `INICIALIZAR REDIS` | lógica | lógica | Computado (sem ação) | Não é uma constante. |

### Fora do kernel.php, mas relevante para o "carimbar marca nova"

| Ponto de toque | Onde | Ação recomendada |
|---|---|---|
| `MYSQL_DATABASE` / `MYSQL_USER` / `MYSQL_PASSWORD` | `docker/.env` (gitignored, cópia de `docker/.env.example`) | **Fora do escopo deste script** (deliverable é só "os DOIS kernel.php"). Documentado como pergunta em aberto abaixo — precisa ficar consistente com `DB_NAME`/`DB_USER`/`DB_PASS` do kernel se algum dia deixarem de ser "Infra (mantém default)". |
| `logo.png`, `favicon.svg` | `site/public_html/assets/img/` e `manager/public_html/assets/img/` | Manual — explicitamente permitido pelo plano ("Não precisa cobrir troca de assets no MVP"). |
| Cor primária da marca | `site/public_html/assets/css/main.css` (`--accent`, `--accent-hover`, `--accent-dim`, `--accent-glow`), `manager/public_html/assets/css/main.css` (`--app-primary`, `--app-primary-glow`, `--app-primary-bright`, + variante `[data-theme="light"]`), **e** cores hex hardcoded (não via variável) no template de e-mail `site/public_html/ui/mail/reset_password.php` (`#2563eb`, `#3b82f6` etc. aparecem repetidos inline) | **Não implementado neste script** — ver "Desvio do plano" abaixo. |

## Desvio do plano: "cor primária" não é substituída pelo script

O texto do deliverable 2 pede um script que "pergunta nome da marca / URLs /
cor primária". A investigação mostrou que **não existe nenhuma constante de
cor no kernel.php** — a cor de marca vive em pelo menos 3 lugares
independentes (CSS do site, CSS do manager — com nomes de variável
*diferentes* entre os dois — e cores hex hardcoded, fora de variável, no HTML
inline do e-mail de reset de senha). Trocar isso de forma correta:

1. não é uma substituição de constante (é edição de CSS = "código de
   runtime", explicitamente fora de escopo do plano);
2. exigiria tocar 2 arquivos CSS com esquemas de variáveis diferentes + 1
   template de e-mail com cores hardcoded fora de variável — não é uma
   operação de complexidade equivalente a um `sed` de constante;
3. o próprio plano permite adiar esse tipo de item: "Não precisa cobrir troca
   de assets no MVP — documente como passo manual se for complexo."

**Decisão**: o protótipo NÃO pergunta nem aplica cor primária. Perguntar e
não fazer nada com a resposta seria pior (UX enganosa) do que não perguntar.
Ficou documentado aqui como follow-up (ver "Perguntas em aberto").

## Quais valores são aleatórios / derivados / informados pelo usuário

- **Informados pelo usuário** (via prompt ou flag): nome da marca, URL de
  produção do site, URL de produção do manager.
- **Derivados** (calculados a partir do nome da marca, sem novo prompt): slug
  da marca (usado em `REDIS_PREFIX`, `KAFKA_TOPIC_EMAIL`,
  `KAFKA_CONSUMER_GROUP`, `cAppKey`), host extraído da URL (usado em
  `ALLOWED_HOSTS`).
- **Nenhum valor é gerado aleatoriamente neste protótipo.** O plano original
  cogitava "gerar chaves de sessão aleatórias" como pergunta em aberto — a
  investigação mostrou que `cAppKey` é um **nome/namespace** de sessão
  (string usada para nomear o cookie/sessão), não um segredo criptográfico;
  não há, nos dois kernels, nenhuma constante do tipo "segredo de assinatura"
  que precise de aleatoriedade (ex.: não há `APP_SECRET`/`CSRF_SECRET` fixo
  no kernel — buscar por esse tipo de constante é um follow-up, ver abaixo).
- **Nunca inventados**: `DB_PASS`, `mail_from_pwd`, `mail_from_mail`,
  `mail_from_user` — permanecem com o placeholder do example; o script avisa
  no final que precisam ser preenchidos manualmente.

## Perguntas em aberto

1. **Interativo vs arquivo de config?** O protótipo é interativo (prompts) com
   fallback para flags (`--name`, `--site-url`, `--manager-url`), pensado para
   uso manual único ao instanciar uma marca. Um arquivo de config
   (`whitelabel.yml` ou `.env`-like) seria melhor para CI/automação, mas
   adiciona um novo formato de arquivo a manter — não implementado no MVP.
2. **`ALLOWED_HOSTS` com múltiplos hosts** (ex.: `www` + apex, ou
   staging+produção) — o protótipo aceita só 1 host por ambiente. O kernel já
   suporta lista separada por vírgula; o script poderia aceitar múltiplas
   `--site-url` ou um input com vírgulas. Não implementado no MVP.
3. **`DB_NAME`/`DB_USER`/`DB_PASS` vs `docker/.env`** — hoje são "Infra
   (mantém default)" nos dois kernels porque, na prática, cada instância
   whitelabel normalmente roda sua própria stack Docker isolada (valores
   default funcionam). Se o objetivo evoluir para múltiplas marcas
   compartilhando a mesma infra de banco, o script precisaria também gerar
   `docker/.env` a partir de `docker/.env.example` e manter os três valores
   sincronizados com o kernel — não implementado no MVP (fora do escopo
   declarado: "os DOIS kernel.php").
4. **Cor primária / tema visual** — ver seção "Desvio do plano" acima. Uma
   implementação futura precisaria unificar os nomes de variável CSS entre
   site e manager (hoje `--accent*` vs `--app-primary*`) antes de conseguir
   um único input "cor primária" que reflita em ambos, e also tratar as
   cores hardcoded no template de e-mail.
5. **Troca de `logo.png`/`favicon.svg`** — documentado como manual no plano;
   o script poderia, no futuro, aceitar `--logo <path>` e copiar o arquivo
   para os dois `public_html/assets/img/`.
6. **Segredos criptográficos** — não foi encontrada, nos dois
   `kernel.php.example`, nenhuma constante do tipo "chave de assinatura"
   (CSRF usa sessão, não uma constante fixa — confirmar isso é responsabilidade
   de quem revisar `app/inc/lib` se este design for retomado). Se uma constante
   desse tipo existir ou vier a existir, o script deveria gerá-la
   aleatoriamente (`openssl rand -hex 32` ou similar) em vez de copiar do
   example.

## Regra de manutenção

Todo novo ponto de toque por-marca (nova constante em qualquer `kernel.php.example`,
novo asset, nova variável de tema) precisa ser adicionado tanto a esta tabela
quanto ao `sed` correspondente em `bin/init-whitelabel.sh` — caso contrário o
script vai gerar um kernel com o valor genérico "leggo" sem avisar.

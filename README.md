# Leggo — Whitelabel Starter

Stack PHP 8.4 + MySQL 8.0 com framework próprio, rodando em Docker.
Dois ambientes: **Manager** (painel admin) e **Site** (frontend público).

## Requisitos

- Docker e Docker Compose
- 4 GB de RAM disponível (MySQL + Redis + Kafka + Nginx)

## Setup rápido

```bash
# 1. Clone
git clone <repo-url> meu-projeto && cd meu-projeto

# 2. Copie as configs (gitignored — nunca commite credenciais)
cp manager/app/inc/kernel.php.example manager/app/inc/kernel.php
cp site/app/inc/kernel.php.example site/app/inc/kernel.php

# 3. Suba os containers
docker compose -f docker/docker-compose.yml up -d --build

# 4. Rode as migrations e ative a senha do admin (nasce desabilitado, sem senha usável)
docker exec leggo php /var/www/leggo/site/cgi-bin/run_migrations.php
echo 'sua-senha' | docker exec -i leggo php /var/www/leggo/manager/cgi-bin/set_admin_password.php

# 5. Habilite pre-commit hooks (PHPStan)
git config core.hooksPath .githooks

# 6. Acesse
# Manager: http://manager.leggo.local
# Site:    http://leggo.local
# Kafka UI: http://localhost:8080
```

## Estrutura

```
manager/               ← Painel admin (manager.leggo.local)
  app/inc/
    controller/        ← Lógica das rotas
    lib/               ← Framework LEGGO (Dispatcher, ORM, PDO, Redis, Kafka, Logger)
    model/             ← Models (users, profiles, messages)
    kernel.php         ← Config sensível (gitignored, copiar do .example)
  public_html/         ← Raiz web
  tests/               ← Testes PHPUnit
  phpstan.neon         ← PHPStan config (level 4)

site/                  ← Site público (leggo.local)
  app/inc/             ← Mesma estrutura do manager
  public_html/         ← Raiz web
  tests/               ← Testes PHPUnit

migrations/            ← Migrations SQL (compartilhadas) — atômicas (transaction por arquivo)
docker/                ← Dockerfile, nginx, php.ini, entrypoint, .env.example
.githooks/             ← pre-commit (PHPStan) + pre-push (PHPUnit)
.editorconfig          ← Estilo de código
```

## Comandos

CI (GitHub Actions) roda sync-guard + PHPStan + PHPUnit (com MySQL de serviço) em todo push/PR.

```bash
# Análise estática — PHPStan nível 4
cd manager && php app/inc/lib/vendor/bin/phpstan analyse
cd site && php app/inc/lib/vendor/bin/phpstan analyse

# Testes
cd manager && php app/inc/lib/vendor/bin/phpunit
cd site && php app/inc/lib/vendor/bin/phpunit

# Teste único
php app/inc/lib/vendor/bin/phpunit --filter testMethodName

# Migrations manuais (roda automático a cada 5 min)
docker exec leggo php /var/www/leggo/site/cgi-bin/run_migrations.php

# Acessar MySQL
docker exec -it mysql mysql -u user_leggo -p db_leggo

# Acessar Redis
docker exec -it redis redis-cli

# Rebuild após mudanças no Dockerfile
docker compose -f docker/docker-compose.yml up -d --build
```

**Release**: ao bumpar `VERSION`, atualize também `APP_VERSION` nos dois
`kernel.php.example` (usado no cache-bust de assets `?v=` em `foot.php`).

## Framework LEGGO

Projeto roda sobre framework próprio (não Laravel/Symfony).

| Componente | Arquivo | Função |
|-----------|---------|--------|
| Router | `Dispatcher.php` | `add_route(METHOD, pattern, "controller:method", guard, args)` |
| ORM | `DOLModel.php` | Active record com soft-delete, `populate()`/`save()`/`remove()`, prepared statements, batch `join()` |
| Database | `localPDO.php` | Wrapper PDO com `select()`, `insert()`, `update()`, `executePrepared(sql, params)` |
| Cache | `RedisCache.php` | Singleton Redis com TTL, fail-open |
| Email | `EmailProducer.php` | Producer Kafka assíncrono (fallback sem rdkafka) |
| Migrations | `MigrationRunner.php` | Runner idempotente de arquivos .sql |
| Auth | `auth_controller.php` | Login bcrypt + migração MD5, CSRF com grace period de 10s, rate limit |
| Logger | `Logger.php` | Log estruturado em JSON com níveis debug/info/warning/error |
| Util | `CommonFunctions.php` | `generate_slug()`, `sanitize_string()`, `basic_redir()`, `canonical_url()`, CSRF, `time_ago()`, `str_limit()`, `old()`, `json_response()`, `array_to_csv()`, `random_token()`, `handle_upload()` |

### Convenções

- **PHP 8.4**. Classes `PascalCase`, arquivos `snake_case`, variáveis `snake_case`.
- **Models** estendem `DOLModel`, definem `$field` e `$filter` como arrays SQL:
  ```php
  $model->set_field([" idx ", " name "]);
  $model->set_filter(["active = 'yes'", "mail = ?"], [$mail]);
  $model->load_data();
  ```
- **Prepared statements** — `set_filter()` aceita `?` com valores no segundo parâmetro. `populate()` + `save()` usam bind automático.
- **Soft-delete**: `active = 'yes'/'no'`. Nunca `DELETE FROM`.
- **CSRF com grace period**: tokens válidos por 10s após primeiro uso via `validate_csrf()`, regenerados a cada página.
- **Sessão**: `$_SESSION[cAppKey]["credential"]`. Chave diferente por ambiente.
- **Testes com banco**: estenda `DBTestCase` (transação + rollback automático). Testes sem banco: `TestCase`.
- **Logging**: `Logger::getInstance()->warning("msg", ["key" => $val])`. Nível controlado por `LOG_LEVEL` no kernel.php.

### Personalização

**Nome e marca** — rode `bin/init-whitelabel.sh` (protótipo) para gerar os dois
`kernel.php` (site e manager) a partir do nome da marca e das URLs de produção:
```bash
bin/init-whitelabel.sh --name "Minha Marca" --site-url "https://minhamarca.com.br" \
    --manager-url "https://manager.minhamarca.com.br"
```
Sem flags, o script pergunta interativamente. Ele não sobrescreve um `kernel.php`
existente (use `--force` para isso) e não inventa segredos — `DB_PASS` e as
credenciais SMTP continuam como placeholder para preenchimento manual.
Inventário completo dos pontos de toque por-marca e follow-ups (logo, cor
primária, etc.) em `plans/029-DESIGN.md`.

Substitua `public_html/assets/img/logo.png` e `favicon.svg` manualmente.

**Rotas** — adicione no `index.php` de cada ambiente:
```php
$dispatcher->add_route("GET", "/minha-rota", "meu_controller:meu_metodo", $authGuard, $params);
```

**Banco de dados** — crie `.sql` em `migrations/` com nome numérico (`006_descricao.sql`). Executam automaticamente.

**Logging** — controle o nível em `kernel.php`:
```php
define("LOG_LEVEL", "info"); // debug | info | warning | error
```

## Segurança

- **CSRF em todas as rotas POST**, incluindo logout (formulário POST com token, não link GET)
- **Senhas bcrypt** com migração automática de hashes MD5 legados
- **Rate limit** de login (5 tentativas/60s por IP) com fallback via arquivo se Redis indisponível
- **CSP, X-Frame-Options, HSTS** configurados no nginx (nginx `default.conf`)
- **Credenciais** extraídas do `docker-compose.yml` para `docker/.env` (gitignored)
- **Logs SQL** não incluem queries completas — previne vazamento de PII em erros de banco

## Rotas

### Site (`leggo.local`)
| Método | Rota | Auth |
|--------|------|------|
| GET | `/` | Auto |
| GET/POST | `/login` | Não |
| GET/POST | `/cadastro` | Não |
| GET | `/verificar-email/{token}` | Não |
| GET/POST | `/definir-senha/{token}` | Não |
| GET/POST | `/esqueci-minha-senha` | Não |
| GET/POST | `/redefinir-senha/{token}` | Não |
| POST | `/sair` | Não |
| GET | `/area` | Sim |
| GET | `/termos-de-uso` | Não |
| GET | `/politica-de-privacidade` | Não |

### Manager (`manager.leggo.local`)
| Método | Rota | Auth |
|--------|------|------|
| GET | `/`, `/admin` | Sim |
| GET/POST | `/login` | Não |
| POST | `/sair` | Não |
| GET/POST | `/cadastro` | Sim |
| GET/POST | `/definir-senha/{token}` | Não |
| GET/POST | `/usuarios` | Sim |

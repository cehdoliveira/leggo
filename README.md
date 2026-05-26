# Leggo — Whitelabel Starter

Stack PHP 8.4 + MySQL 8.0 com framework próprio **LEGGO**, rodando em Docker. Dois ambientes: **Manager** (painel admin) e **Site** (frontend público).

## Requisitos

- Docker e Docker Compose
- 4 GB de RAM disponível (MySQL + Redis + Kafka + Nginx)

## Setup rápido

```bash
# 1. Clone e entre no projeto
git clone <repo-url> meu-projeto && cd meu-projeto

# 2. Copie as configs de kernel (gitignored — nunca commite credenciais)
cp manager/app/inc/kernel.php.example manager/app/inc/kernel.php
cp site/app/inc/kernel.php.example site/app/inc/kernel.php
# Edite ambos, preenchendo SMTP/DB. As credenciais padrão de DB já funcionam no Docker.

# 3. Inicie os containers
docker compose -f docker/docker-compose.yml up -d --build

# 4. Acesse
# Manager: http://manager.leggo.local
# Site:    http://leggo.local
# Kafka UI: http://localhost:8080
```

O entrypoint executa `composer install` automaticamente nos dois ambientes, inicia o cron de migrations e os workers de email.

## Estrutura

```
manager/               ← Painel admin (manager.leggo.local)
  app/inc/
    controller/        ← Lógica das rotas (auth, dashboard)
    lib/               ← Framework LEGGO (Dispatcher, ORM, PDO, Redis, Kafka)
    model/             ← Models (users, profiles, messages)
    kernel.php         ← Config sensível (gitignored, copiar do .example)
  public_html/         ← Raiz web (index.php, assets, templates)
  tests/               ← Testes PHPUnit

site/                  ← Site público (leggo.local)
  app/inc/             ← Mesma estrutura do manager
  public_html/         ← Raiz web
  tests/               ← Testes PHPUnit (idênticos ao manager)

migrations/            ← Migrations SQL (compartilhadas entre ambientes)
docker/                ← Dockerfile, vhosts, php.ini, entrypoint
```

## Comandos

```bash
# Análise estática — PHPStan nível 3
cd manager && php app/inc/lib/vendor/bin/phpstan analyse
cd site && php app/inc/lib/vendor/bin/phpstan analyse

# Testes (ambos os ambientes)
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

## Personalização

### Nome e marca
Altere as constantes nos `kernel.php` de cada ambiente:
```php
define("cTitle", "Meu Projeto");          // Nome do site
define("mail_from_name", "Meu Projeto");  // Remetente de emails
```
Substitua `public_html/assets/img/logo.png` e `favicon.svg` pela sua marca.

### Rotas
Adicione rotas nos `public_html/index.php` de cada ambiente:
```php
$dispatcher->add_route("GET", "/minha-rota", "meu_controller:meu_metodo", $authGuard, $params);
```

### Banco de dados
Crie arquivos `.sql` em `migrations/` seguindo a nomenclatura numérica (`006_*.sql`). Executam automaticamente.

### Email
O envio é assíncrono via Kafka. Configure as constantes `mail_from_*` no `kernel.php`. Se o Kafka estiver indisponível, o `EmailProducer` faz fallback silencioso.

### Redis
Usado para rate limiting de login (`login_attempts:{ip}`, 60s, max 5) e forgot password (`forgot_pwd:{ip}`, 300s, max 3). Fail-open — se o Redis cair, a app continua funcionando.

## Framework LEGGO

O projeto roda sobre um framework próprio, não Laravel/Symfony:

| Componente | Arquivo | Função |
|-----------|---------|--------|
| Router | `Dispatcher.php` | `add_route(METHOD, pattern, "controller:method", guard, args)` |
| ORM | `DOLModel.php` | Active record com soft-delete, attach/join, `populate()`/`save()`/`remove()` |
| Database | `localPDO.php` | Wrapper PDO com `select()`, `insert()`, `update()`, `executePrepared()` |
| Cache | `RedisCache.php` | Singleton Redis com TTL, serialização, prefixo por ambiente |
| Email | `EmailProducer.php` | Producer Kafka assíncrono (com fallback sem rdkafka) |
| Migrations | `MigrationRunner.php` | Runner idempotente de arquivos .sql |
| Auth | `auth_controller.php` | Login com bcrypt + migração MD5, CSRF, rate limit |
| Util | `CommonFunctions.php` | `generate_slug()`, `sanitize_string()`, `basic_redir()`, etc |

### Convenções
- PHP 8.4. Classes: `PascalCase` em arquivos `snake_case`. Variáveis: `snake_case`.
- Models estendem `DOLModel`, definem `$field` e `$filter` como arrays de SQL cru.
- Filtros SQL usam `set_filter()` com placeholders `?` e valores como segundo parâmetro.
- Soft-delete universal: `active = 'yes'/'no'` — nunca `DELETE FROM`.
- CSRF one-time-use: tokens consumidos via `validate_csrf()`, regenerados a cada página.
- Sessão: `$_SESSION[cAppKey]["credential"]` — chave diferente por ambiente.
- Testes com banco usam `DBTestCase` que isola via transações (rollback automático).
- Filtros SQL usam `real_escape_string()` — **sempre** escapamos valores.
- Soft-delete universal: `active = 'yes'/'no'` — nunca `DELETE FROM`.
- CSRF one-time-use: tokens consumidos via `validate_csrf()`, regenerados a cada página.
- Sessão: `$_SESSION[cAppKey]["credential"]` — chave diferente por ambiente.

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
| GET | `/sair` | Não |
| GET | `/area` | Sim |
| GET | `/termos-de-uso` | Não |
| GET | `/politica-de-privacidade` | Não |

### Manager (`manager.leggo.local`)
| Método | Rota | Auth |
|--------|------|------|
| GET | `/`, `/admin` | Sim |
| GET/POST | `/login` | Não |
| GET | `/sair` | Não |
| GET/POST | `/cadastro` | Não |
| GET | `/usuarios` | Sim |

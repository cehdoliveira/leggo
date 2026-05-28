# Changelog

## [1.4.1.2] - 2026-05-27

### Fixed
- Verificação de e-mail agora é idempotente: clicar no link de verificação
  mais de uma vez não mostra mais "Link inválido, expirado ou já utilizado".
  O token permanece válido até a senha ser definida, e o segundo acesso
  redireciona amigavelmente para a página de definir senha.

## [1.4.1.1] - 2026-05-27

### Changed
- Rate limit fail-open documentado e monitorado: `check_and_increment_rate_limit()`
  agora loga warnings estruturados via Logger quando o fallback de arquivo (mkdir,
  fopen, flock) falha. O comportamento fail-open (não bloqueia) foi mantido e
  documentado no docblock como escolha intencional de disponibilidade sobre
  segurança durante outages de infraestrutura.

## [1.4.1.0] - 2026-05-27

### Fixed
- Tokens CSRF agora têm grace period de 10 segundos após o primeiro uso. Isso
  resolve o erro "Requisição inválida" que ocorria quando o usuário pressionava
  F5 após submeter um formulário — o token consumido permanecia inválido e o
  browser reenviava o POST. Com o grace period, o mesmo token é aceito por até
  10 segundos antes de expirar definitivamente.

## [1.4.0.3] - 2026-05-27

### Changed
- `basic_redir()` agora gerencia commit/rollback da transação automaticamente.
  Sucesso (`basic_redir($url)`) faz commit; erro (`basic_redir($url, rollback: true)`)
  faz rollback. O `__destruct()` do `localPDO` faz rollback de segurança se nenhum
  redirect explícito ocorrer — garantindo que transações nunca commitam sem passar
  pelo gate do `basic_redir()`.
- Controllers de registro (manager e site) e `users_action` (manager) agora usam
  `rollback: true` nos catch blocks para reverter operações parciais em caso de erro.

## [1.4.0.2] - 2026-05-27

### Fixed
- Constantes do `site/app/inc/kernel.php.example` estavam copiadas do manager
  (`REDIS_PREFIX`, `KAFKA_TOPIC_EMAIL`, `cAppKey`, `cTitle`, `UPLOAD_DIR`,
  `MANAGER_CANONICAL_URL`). Corrigidas para os valores corretos do ambiente site,
  alinhando com o que o código do site de fato referencia (`SITE_CANONICAL_URL`
  em vez de `MANAGER_CANONICAL_URL`, entre outros).

## [1.4.0.1] - 2026-05-27

### Removed
- Rotas duplicadas de /termos-de-uso e /politica-de-privacidade no
  site/public_html/index.php (dead code, nunca executadas).

## [1.4.0.0] - 2026-05-27

### Added
- Transação global automática: `localPDO` agora é singleton por request,
  iniciando transação no primeiro uso e commit no __destruct(). Todos os
  models compartilham a mesma conexão, garantindo atomicidade em operações
  multi-statement. `DOLModel` ganha `beginTransaction()`, `commit()`,
  `rollback()` e `getCon()`.

### Fixed
- Race condition no cadastro (Site e Manager): check-then-act de unicidade
  mail/login agora opera dentro da transação, prevenindo inserts duplicados
  em cenário de requests concorrentes.

## [1.3.4.3] - 2026-05-27

### Fixed
- forgot_password: agora alerta o usuario quando o envio de email falha
  (antes mostrava mensagem generica de sucesso mesmo com falha).
  Mantem protecao contra user enumeration para emails inexistentes.

## [1.3.4.2] - 2026-05-27

### Fixed
- Manager register: valida tamanho mínimo de senha (6 caracteres), alinhado
  com o Site que já exige em set_password() e reset_password().

## [1.3.4.1] - 2026-05-27

### Fixed
- Audit trail (messages): todos os envios de email agora registram na tabela
  messages. Adicionado em forgot_password (site), register (manager) e
  reset-senha (manager).

## [1.3.4.0] - 2026-05-27

### Fixed
- Intelephense: corrigidos warnings P1009, P1010, P1011, P1075, P1114, P1116, P1132, P1133
  em 14 arquivos. Inclui null safety, operator precedence, by-reference pass,
  type hints no Dispatcher, stubs para extensoes rdkafka/Redis, e correcoes
  no kafka_email_worker.php.

## [1.3.3.0] - 2026-05-27

### Changed
- Type hints adicionados em todo o código: parâmetros (string, int, mixed, ?string),
  retornos (bool, void, never, array), e propriedades tipadas em 26 arquivos.
  PHPStan nível 3 limpo em ambos os ambientes. Resolve warnings do Intelephense.

## [1.3.2.0] - 2026-05-27

### Fixed
- Rate limit de login e forgot password agora tem fallback via arquivo quando Redis
  está indisponível. `check_and_increment_rate_limit()` usa `flock()` em
  `/tmp/leggo_ratelimit/` para manter a proteção contra brute force mesmo sem Redis.
  `reset_rate_limit()` substitui `$redis->del()` direto nos controllers.

## [1.3.1.0] - 2026-05-27

### Changed
- `DOLModel::attach()`, `join()` e `attach_son()` convertidos para prepared statements.
  Substitui `real_escape_string()` e string interpolation por `executePrepared()` com
  placeholders `?`. Tokens `#IDX#` e `%s` do parâmetro `$options` convertidos internamente
  para `?` com bind seguro. Colunas do parâmetro `$fw_key` em `join()` validadas com regex.

## [1.3.0.11] - 2026-05-27

### Fixed
- `DOLModel::populate()` não ignora mais valores falsy como string `"0"`.
  A condição `if (strtolower($data[$key]))` fazia com que valores legítimos
  fossem silenciosamente descartados (`strtolower("0")` retorna `"0"`, falsy em PHP).
  Substituído por `if ($data[$key] !== '')` — `isset()` já filtra null.

## [1.3.0.6] - 2026-05-26

### Added

## [1.3.0.7] - 2026-05-26

### Changed
- Rewritten README.md for clarity and completeness
- Updated AGENTS.md with Logger documentation
- Fixed CHANGELOG.md empty entry
- Structured `Logger` class with levels (debug/info/warning/error) and JSON output
- `LOG_LEVEL` constant in kernel.php to control log verbosity

## [1.3.0.5] - 2026-05-26

### Added
- Pre-commit hook: runs PHPStan + PHPUnit on staged PHP files before commit

## [1.3.0.4] - 2026-05-26

### Changed
- PHP `memory_limit` increased from 128M to 512M in Docker container (needed for PHPStan and large spreadsheets)

## [1.3.0.3] - 2026-05-26

### Changed
- `composer.lock` files now tracked in git for reproducible builds

## [1.3.0.2] - 2026-05-26

### Added
- `.editorconfig` for consistent indentation and encoding across editors

## [1.3.0.1] - 2026-05-26

### Fixed
- Replace deprecated `utf8_decode()` with `mb_convert_encoding()` for PHP 8.4 compatibility
- Zero `utf8_decode` or `utf8_encode` calls remain in codebase

## [1.3.0.0] - 2026-05-26

### Added
- Test isolation via DBTestCase with automatic transaction rollback
- 5 new DB-dependent tests
- PHPStan static analysis at level 3
- Docker volumes for tests/ and config files

### Changed
- Test bootstrap supports test helper autoloading
- Updated README with PHPStan, prepared statements, and test conventions
## [1.2.0.0] - 2026-05-26

### Added
- PHPStan static analysis at level 3 (`php app/inc/lib/vendor/bin/phpstan analyse`)
- `@method` annotations on rootOBJ and DOLModel for all magic methods
### Changed
- ORM now uses prepared statements for all write operations (insert, update, delete)
- `set_filter()` accepts an optional second parameter for value binding with `?` placeholders
- Controllers migrated from `real_escape_string()` to `set_filter([], [params])` — zero manual escaping

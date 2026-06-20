# Changelog

## [1.8.1.0] - 2026-06-20

### Fixed
- `DOLModel::save()` UPDATE com filtros parametrizados — ordem dos parâmetros invertida escrevia dados errados nas linhas erradas. Corrigido `array_merge()` para alinhar SET antes de WHERE.
- `DOLModel::remove()` soft-delete — mesmo bug de ordenação de parâmetros. `$userId` agora precede `filterParams` no bind.
- `migrations.php` (manager e site) — referência a `local_pdo.php` (minúsculo) corrigida para `localPDO.php`. A UI web de migrations agora funciona.
- `kafka_email_worker.php` (manager e site) — constantes SMTP (`mail_from_host`, `mail_from_user`, etc.) agora usam `defined()` com fallback seguro, evitando warnings em PHP 8.4.
- `MigrationRunner::executeMigration()` — cada arquivo de migration agora executa dentro de uma transação (`beginTransaction/commit/rollback`), garantindo atomicidade.

### Changed
- Logout alterado de GET para POST com validação CSRF em ambos os ambientes. Header renderiza `<form>` com token oculto em vez de `<a href>`.
- Pre-commit hook agora roda apenas PHPStan (rápido, sem banco). PHPUnit movido para hook pre-push.
- Credenciais MySQL extraídas do `docker-compose.yml` para `docker/.env` (gitignored). Template `.env.example` commitado.
- CSP adicionado no nginx (`default.conf`) permitindo `cdn.jsdelivr.net`, `fonts.googleapis.com`, `fonts.gstatic.com`. Headers `X-XSS-Protection` e `Access-Control-Allow-Origin: *` removidos.
- `localPDO::fields_config()` agora cacheia schema por tabela em propriedade estática — elimina queries `SHOW COLUMNS` repetidas por request.
- `localPDO` não loga mais queries SQL completas em erros — previne vazamento de PII em logs.

### Performance
- `DOLModel::join()` agora faz batch query com `IN()` em vez de N+1 queries por linha, quando sem opções `#IDX#` por linha.

### Removed
- `phpoffice/phpspreadsheet` removido do `composer.json` (nunca referenciado no código — ~50MB a menos no vendor).

### Changed
- Função `random_token()` adicionada como wrapper para
  `bin2hex(random_bytes())`. Substitui 14 ocorrências manuais nos
  controllers de ambos os ambientes, padronizando a geração de tokens.

## [1.8.0.1] - 2026-05-28

### Added
- Função `json_response()` em CommonFunctions.php: helper para respostas
  JSON padronizadas com http_response_code, Content-Type, cache headers,
  a_walk para encoding UTF-8, e fallback para erro 500 se json_encode falhar.

## [1.8.0.0] - 2026-05-28

### Added
- Função `array_to_csv()` em CommonFunctions.php: exporta arrays para CSV
  com download forçado via headers Content-Type e Content-Disposition.
  Delimitador `;` (padrão Excel PT-BR), headers automáticos ou customizados.
- Botão "Exportar CSV" no dashboard do Manager e ação `export-csv` no
  users_action. O admin pode baixar a lista completa de usuários em CSV.

## [1.7.0.2] - 2026-05-28

### Added
- Função `old()` em CommonFunctions.php: helper para repopular campos
  de formulário após erro de validação. Busca em `$_POST` com fallback
  para default e aplica htmlspecialchars automaticamente.

## [1.7.0.1] - 2026-05-28

### Added
- Função `str_limit()` em CommonFunctions.php: trunca strings no limite
  de caracteres com sufixo configurável (default "..."), usando mb_substr
  para suporte a UTF-8 e strip_tags automático para segurança.

## [1.7.0.0] - 2026-05-28

### Added
- Função `time_ago()` em CommonFunctions.php: exibe datas no formato
  relativo em PT-BR ("há 5 minutos", "ontem às 14:30", "há 3 semanas").
  Suporta datas passadas e futuras, com fallback "—" para valores
  vazios ou inválidos. Aplicada no dashboard do Manager na coluna
  "Último login".

## [1.6.0.0] - 2026-05-28

### Added
- Função `handle_upload()` em CommonFunctions.php para upload de arquivos
  com validação automática de MIME (via finfo), extensão e tamanho. Suporte
  a redimensionamento proporcional e conversão de imagens para WebP/AVIF
  via GD. Cria diretórios automaticamente, gera nomes únicos com slug +
  timestamp, e retorna o caminho relativo do arquivo. Tipos suportados:
  jpg, png, gif, webp, avif, pdf, doc, docx, xls, xlsx, csv. Uploads
  persistidos via volume Docker mapeado no docker-compose.yml.

## [1.5.0.0] - 2026-05-28

### Added
- Cadastro de novos usuários admin no painel Manager: rotas GET/POST /cadastro
  (autenticadas) com formulário de nome, email e login. Senha não é mais
  definida pelo admin — o novo usuário recebe um email com link para definir
  sua própria senha via /definir-senha/{token}.
- Rotas públicas GET/POST /definir-senha/{token} no Manager para o fluxo de
  ativação de conta.
- Novos métodos no auth_controller do Manager: display_set_password e
  set_password, validando token diretamente no banco (sem dependência de
  sessão).
- Template set_password.php no Manager.

### Changed
- Template new_admin_credentials.php: botão agora é "Definir minha senha"
  com link para o fluxo de set-password, em vez de "Acessar o painel".
  Copy do email corrigida — não menciona mais "senha temporária".

### Fixed
- Toggle de tema no Manager: adicionado seletor CSS [data-theme="light"] com
  variáveis de tema claro. Botão movido do floating button quebrado para o
  header, ao lado do botão Sair. Função injectFloatingThemeToggle removida.

## [1.4.2.0] - 2026-05-27

### Fixed
- Links em emails agora usam o helper `canonical_url()` que protege contra
  Host Header Injection. Se `CANONICAL_URL` não está definida, o fallback para
  `cFrontend` só ocorre após validação contra `ALLOWED_HOSTS`. Sem nenhuma
  proteção configurada, um warning é logado. Substitui 9 padrões manuais de
  composição de URL em controllers e templates de email.

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

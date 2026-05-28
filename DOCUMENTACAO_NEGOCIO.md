# Documentação de Regras de Negócio
> Gerado em: 2026-05-27
> Atualizado em: 2026-05-28 — correções aplicadas (ver seção "Histórico de correções")
> Ferramenta: OpenCode + DeepSeek V4 Pro
> Versão do sistema: 1.8.0.2

```
SISTEMA: Leggo — Whitelabel Starter PHP 8.4 + MySQL 8.0
│
├── ENTRADA (30 rotas — 13 manager, 17 site)
│   ├── Manager (admin — manager.leggo.local)
│   │   ├── GET  / (e /index.*)             → function:basic_redir (→ $home_url)
│   │   ├── GET  /login(.*)?                → auth_controller:display
│   │   ├── POST /login(.*)?                → auth_controller:login
│   │   ├── GET  /sair                      → auth_controller:logout
│   │   ├── GET  /cadastro(.*)?             → auth_controller:display_register   (AUTH)
│   │   ├── POST /cadastro(.*)?             → auth_controller:register           (AUTH)
│   │   ├── GET  /definir-senha/{token}     → auth_controller:display_set_password
│   │   ├── POST /definir-senha/{token}     → auth_controller:set_password
│   │   ├── GET  /?, /admin, /usuarios      → site_controller:dashboard        (AUTH)
│   │   └── POST /usuarios                  → site_controller:users_action     (AUTH)
│   │
│   └── Site (público — leggo.local)
│       ├── GET  / (e /index.*)             → function:basic_redir (→ $home_url)
│       ├── GET  /login(.*)?                → auth_controller:display
│       ├── POST /login(.*)?                → auth_controller:login
│       ├── GET  /cadastro(.*)?             → auth_controller:display_register
│       ├── POST /cadastro(.*)?             → auth_controller:register
│       ├── GET  /verificar-email/{token}   → auth_controller:verify_email
│       ├── GET  /definir-senha/{token}     → auth_controller:display_set_password
│       ├── POST /definir-senha/{token}     → auth_controller:set_password
│       ├── GET  /esqueci-minha-senha       → auth_controller:display_forgot_password
│       ├── POST /esqueci-minha-senha       → auth_controller:forgot_password
│       ├── GET  /redefinir-senha/{token}   → auth_controller:display_reset_password
│       ├── POST /redefinir-senha/{token}   → auth_controller:reset_password
│       ├── GET  /sair                      → auth_controller:logout
│       ├── GET  /?                         → site_controller:home
│       ├── GET  /termos-de-uso(.*)?        → site_controller:terms
│       ├── GET  /politica-de-privacidade(.*)? → site_controller:privacy
│       └── GET  /area(.*)?                 → site_controller:home              (AUTH)
│
├── MÓDULO: AUTENTICAÇÃO (auth_controller — ambos os ambientes)
│   ├── REGRAS DE NEGÓCIO
│   │   ├── RN-001: Sessão verificada via `$_SESSION[cAppKey]["credential"]["idx"]` — se ausente, usuário não autenticado
│   │   ├── RN-002: Login aceita e-mail OU login no mesmo campo (WHERE ? IN (mail,login))
│   │   ├── RN-003: Apenas usuários com `enabled = 'yes'` podem autenticar
│   │   ├── RN-004: Rate limit de login: 5 tentativas / 60s por IP (Redis; fallback: arquivo com flock). Bloqueia na 6ª tentativa
│   │   ├── RN-005: Timing attack prevention: se usuário não encontrado, roda password_verify contra hash inválido para manter tempo constante
│   │   ├── RN-006: Senhas bcrypt (PASSWORD_BCRYPT). Legados MD5 são auto-migrados para bcrypt no login bem-sucedido (verify_password_with_migration)
│   │   ├── RN-007: session_regenerate_id(true) no login bem-sucedido — proteção contra session fixation
│   │   ├── RN-008: last_login atualizado no login bem-sucedido (users_model.save)
│   │   ├── RN-009: Rate limit resetado no login bem-sucedido (Redis/Fallback)
│   │   ├── RN-010: Logout destrói $_SESSION, invalida cookie de sessão (tempo -42000), session_destroy(), redir para login_url
│   │   ├── RN-011: Login page redireciona para home_url (manager) ou area_url (site) se usuário já autenticado
│   │   ├── RN-012: [MANAGER] Apenas usuários com perfil `adm = 'yes'` podem acessar o painel admin — verificado pós-login
│   │   │            (RN-012a) Se usuário autenticado mas não admin → redirecionado ao login com erro "Acesso não autorizado"
│   │   └── RN-013: [SITE] Login bem-sucedido redireciona para area_url (≠ manager que vai para home_url)
│   │
│   ├── VALIDAÇÕES
│   │   ├── Campo: _csrf_token → Regra: hash_equals contra $_SESSION['_csrf_token'] (one-time-use, consumido no uso)
│   │   ├── Campo: login (POST) → Regra: required (não vazio)
│   │   ├── Campo: password (POST) → Regra: required (não vazio)
│   │   ├── Campo: password (POST) → Regra: strlen <= 1024 (em verify_password_with_migration, modo MD5 legado)
│   │   └── Falha: redirect com mensagem flash em $_SESSION["messages_app"]["danger"] para a URL de origem
│   │
│   ├── FLUXO DE DADOS — LOGIN
│   │   ├── 1. Request POST → Dispatcher → auth_controller:login
│   │   ├── 2. validate_csrf($token) — consome token CSRF
│   │   ├── 3. Valida campos obrigatórios (login, password)
│   │   ├── 4. check_and_increment_rate_limit (Redis/file) — 5/60s
│   │   ├── 5. users_model.load_data() → WHERE enabled='yes' AND ? IN (mail,login)
│   │   ├── 6. users_model.attach(["profiles"]) → busca profiles via junction table users_profiles
│   │   ├── 7. verify_password_with_migration() → bcrypt verify ou MD5 hash_equals + auto-migração
│   │   ├── 8. [MANAGER] Verifica se usuário é admin (profiles_attach[].adm === 'yes')
│   │   ├── 9. session_regenerate_id(true) + armazena credential (sem password) em $_SESSION
│   │   ├── 10. reset_rate_limit()
│   │   └── 11. UPDATE users SET last_login = NOW() WHERE idx = ?
│   │
│   └── DEPENDÊNCIAS EXTERNAS
│       └── Redis (rate limit — fail-open: se Redis offline, usa arquivo em /tmp/leggo_ratelimit/ com flock)
│
├── MÓDULO: CADASTRO (REGISTRO) — exclusivo do ambiente SITE
│   ├── REGRAS DE NEGÓCIO
│   │   ├── RN-101: Campos obrigatórios: name, mail, login (não inclui password)
│   │   ├── RN-102: Email/logins duplicados são rejeitados: busca por mail OU login com active='yes'
│   │   ├── RN-103: Senha gerada automaticamente com random_bytes(32) — usuário NÃO define senha no cadastro
│   │   ├── RN-104: Usuário criado com enabled='no' — conta inativa até verificação de e-mail
│   │   ├── RN-105: Token de verificação (email_token) gerado com random_bytes(32) (64 hex chars)
│   │   ├── RN-106: Token expira em +72 horas (email_token_expires_at)
│   │   ├── RN-107: Perfil padrão atribuído via constante DEFAULT_USER_PROFILE_ID (valor=2, perfil "Usuário")
│   │   ├── RN-108: Email de verificação enviado via Kafka (EmailProducer) ou fallback sync (se rdkafka ausente)
│   │   ├── RN-109: Se email NÃO for enviado → usuário é criado mas recebe mensagem de erro pedindo contato com suporte
│   │   ├── RN-110: Log de e-mail salvo na tabela messages (to_mail, subject, body, sent_at)
│   │   ├── RN-111: Relacionamento users↔profiles salvo via save_attach na tabela users_profiles
│   │   └── RN-112: Sucesso → redireciona para login_url com mensagem "Verifique seu e-mail para ativar sua conta"
│   │
│   ├── VALIDAÇÕES
│   │   ├── Campo: _csrf_token → Regra: hash_equals (one-time-use)
│   │   ├── Campo: name  → Regra: required (não vazio)
│   │   ├── Campo: mail  → Regra: required
│   │   ├── Campo: login → Regra: required
│   │   ├── Campo: mail + login → Regra: unique (não pode existir combinação ativa com mesmo mail OU login)
│   │   └── Falha: redirect com flash message para register_url
│   │
│   ├── FLUXO DE DADOS — REGISTRO
│   │   ├── 1. POST /cadastro → auth_controller:register
│   │   ├── 2. validate_csrf()
│   │   ├── 3. Valida required (name, mail, login)
│   │   ├── 4. users_model.load_data() → WHERE active='yes' AND (mail=? OR login=?)
│   │   ├── 5. Se duplicado → redir com erro
│   │   ├── 6. Gera token (random_bytes 32), password aleatória (bcrypt), enabled='no'
│   │   ├── 7. users_model.populate() + save() → INSERT em users
│   │   ├── 8. save_attach(["profiles"]) → INSERT/UPDATE em users_profiles
│   │   ├── 9. EmailProducer.send() → Kafka (tópico leggo_site_emails)
│   │   ├── 10. messages_model.populate() + save() → INSERT em messages (log)
│   │   └── 11. Se email enviado → redir login_url / senão → redir register_url com erro
│   │
│   └── DEPENDÊNCIAS EXTERNAS
│       ├── Kafka (rdkafka) → envio assíncrono de email de verificação
│       └── SMTP (via worker kafka_email_worker.php)
│
├── MÓDULO: VERIFICAÇÃO DE E-MAIL — exclusivo do ambiente SITE
│   ├── REGRAS DE NEGÓCIO
│   │   ├── RN-201: Token recebido via URL (/verificar-email/{token})
│   │   ├── RN-202: Busca usuário com active='yes', enabled='no', email_token = token, email_token_expires_at > NOW()
│   │   ├── RN-203: Se token inválido/expirado/já usado → redireciona login_url com erro "Link inválido, expirado ou já utilizado"
│   │   ├── RN-204: Token válido → limpa email_token (seta NULL) no banco e armazena pending_set_password_idx na sessão
│   │   ├── RN-205: Redireciona para /definir-senha/{token} com mensagem "Agora defina sua senha"
│   │   └── RN-206: Não autentica o usuário — apenas valida o e-mail e prepara para definir senha
│   │
│   ├── VALIDAÇÕES
│   │   ├── Campo: token (URL param) → Regra: required, deve corresponder a email_token ativo + não expirado
│   │   └── Falha: redirect para login_url com flash message de erro
│   │
│   ├── FLUXO DE DADOS
│   │   ├── 1. GET /verificar-email/{token} → auth_controller:verify_email
│   │   ├── 2. users_model.load_data() → WHERE active='yes' AND enabled='no' AND email_token=? AND email_token_expires_at > NOW()
│   │   ├── 3. Se não encontrado → redir login_url com erro
│   │   ├── 4. users_model.populate(["email_token" => null]) + save() → UPDATE users
│   │   └── 5. $_SESSION['pending_set_password_idx'] = idx + redir /definir-senha/{token}
│   │
│   └── DEPENDÊNCIAS EXTERNAS
│       └── Nenhuma
│
├── MÓDULO: DEFINIÇÃO DE SENHA (SET PASSWORD) — exclusivo do ambiente SITE
│   ├── REGRAS DE NEGÓCIO
│   │   ├── RN-301: Requer pending_set_password_idx na sessão (definido por verify_email)
│   │   ├── RN-302: Se sessão expirada (sem pending_set_password_idx) → redir login_url
│   │   ├── RN-303: Re-verifica que usuário ainda existe com active='yes' e enabled='no' no banco
│   │   ├── RN-304: Senha deve ter ≥ 6 caracteres
│   │   ├── RN-305: Senha e confirmação devem ser idênticas (password === password_confirm)
│   │   ├── RN-306: Ao definir senha: enabled → 'yes', email_verified_at → NOW(), password → bcrypt, email_token → NULL
│   │   └── RN-307: Após sucesso, limpa pending_set_password_idx da sessão e redireciona para login_url
│   │
│   ├── VALIDAÇÕES
│   │   ├── Campo: _csrf_token → Regra: hash_equals
│   │   ├── Campo: password → Regra: required, min:6
│   │   ├── Campo: password_confirm → Regra: required, must match password
│   │   └── Falha: redirect para set_password_url(token) com flash message
│   │
│   ├── FLUXO DE DADOS
│   │   ├── 1. POST /definir-senha/{token} → auth_controller:set_password
│   │   ├── 2. validate_csrf()
│   │   ├── 3. Verifica pending_set_password_idx na sessão
│   │   ├── 4. Valida password ≥ 6 chars e password === password_confirm
│   │   ├── 5. users_model.load_data() → WHERE active='yes' AND enabled='no' AND idx=?
│   │   ├── 6. Se não encontrado → limpa sessão, redir login_url
│   │   └── 7. users_model.populate({enabled:'yes', email_verified_at:NOW(), password:bcrypt, email_token:null}) + save()
│   │
│   └── DEPENDÊNCIAS EXTERNAS
│       └── Nenhuma
│
├── MÓDULO: RECUPERAÇÃO DE SENHA (FORGOT PASSWORD) — exclusivo do ambiente SITE
│   ├── REGRAS DE NEGÓCIO
│   │   ├── RN-401: Busca usuário por e-mail com active='yes'
│   │   ├── RN-402: Rate limit: 3 tentativas / 300s (5 min) por IP
│   │   ├── RN-403: Mensagem genérica independente de e-mail existir ou não — "Se o e-mail informado estiver cadastrado..."
│   │   ├── RN-404: Se usuário enabled='no' (não verificou e-mail) → reenvia email de verificação com token de 72h
│   │   ├── RN-405: Se usuário enabled='yes' → gera token de reset com expiração de 2 horas
│   │   ├── RN-406: Token persiste nos campos email_token / email_token_expires_at da tabela users
│   │   ├── RN-407: Email enviado via Kafka (EmailProducer). Se falhar → redir forgot_password_url com erro
│   │   ├── RN-408: Log de e-mail salvo na tabela messages
│   │   └── RN-409: Sucesso → redireciona para login_url (não revela se e-mail existe)
│   │
│   ├── VALIDAÇÕES
│   │   ├── Campo: _csrf_token → Regra: hash_equals
│   │   ├── Campo: mail → Regra: required (não vazio)
│   │   └── Falha: redirect para forgot_password_url ou login_url com flash message
│   │
│   ├── FLUXO DE DADOS
│   │   ├── 1. POST /esqueci-minha-senha → auth_controller:forgot_password
│   │   ├── 2. validate_csrf() + rate limit check (3/300s)
│   │   ├── 3. users_model.load_data() → WHERE active='yes' AND mail=?
│   │   ├── 4. Se encontrado: gera token, define expiração (72h se enabled='no', 2h se enabled='yes')
│   │   ├── 5. users_model.populate({email_token, email_token_expires_at}) + save()
│   │   ├── 6. Monta email: verify_email.php se enabled='no', reset_password.php se enabled='yes'
│   │   ├── 7. EmailProducer.send() via Kafka
│   │   ├── 8. messages_model.save() (log)
│   │   ├── 9a. Se email falhou → redir forgot_password_url com erro
│   │   └── 9b. Sucesso (ou e-mail não encontrado) → redir login_url com msg genérica
│   │
│   └── DEPENDÊNCIAS EXTERNAS
│       ├── Redis (rate limit forgot_pwd:{IP})
│       ├── Kafka → envio assíncrono de email
│       └── SMTP (worker)
│
├── MÓDULO: REDEFINIÇÃO DE SENHA (RESET PASSWORD) — exclusivo do ambiente SITE
│   ├── REGRAS DE NEGÓCIO
│   │   ├── RN-501: Token recebido via URL (/redefinir-senha/{token})
│   │   ├── RN-502: Busca usuário com active='yes', enabled='yes', email_token = token, email_token_expires_at > NOW()
│   │   ├── RN-503: Ao validar token: email_token é limpo (NULL) e pending_reset_idx é armazenado na sessão
│   │   ├── RN-504: Se sessão já tem pending_reset_idx, re-verifica que usuário ainda existe e é válido
│   │   ├── RN-505: Senha ≥ 6 caracteres, password === password_confirm
│   │   ├── RN-506: Ao redefinir: password → bcrypt, email_token_expires_at → NULL
│   │   ├── RN-507: session_regenerate_id(true) após redefinição bem-sucedida
│   │   └── RN-508: pending_reset_idx removido da sessão; redireciona para login_url
│   │
│   ├── VALIDAÇÕES
│   │   ├── Campo: _csrf_token → Regra: hash_equals
│   │   ├── Campo: token (URL param + sessão) → Regra: required, válido e não expirado
│   │   ├── Campo: password → Regra: required, min:6
│   │   ├── Campo: password_confirm → Regra: required, must match password
│   │   └── Falha: redirect para reset_password_url(token) ou login_url ou forgot_password_url
│   │
│   ├── FLUXO DE DADOS
│   │   ├── 1A. GET /redefinir-senha/{token} → auth_controller:display_reset_password
│   │   │   ├── Se já tem pending_reset_idx → re-verifica no banco
│   │   │   └── Senão → valida token, consome (seta NULL), armazena idx na sessão
│   │   └── 1B. POST /redefinir-senha/{token} → auth_controller:reset_password
│   │       ├── 2. validate_csrf()
│   │       ├── 3. Verifica pending_reset_idx na sessão
│   │       ├── 4. Valida password ≥ 6 e password === password_confirm
│   │       ├── 5. users_model.load_data() → WHERE active='yes' AND enabled='yes' AND idx=?
│   │       ├── 6. users_model.populate({password: bcrypt, email_token_expires_at: null}) + save()
│   │       ├── 7. unset($_SESSION['pending_reset_idx']) + session_regenerate_id(true)
│   │       └── 8. redir login_url com mensagem de sucesso
│   │
│   └── DEPENDÊNCIAS EXTERNAS
│       └── Nenhuma
│
├── MÓDULO: ADMIN — PAINEL DE USUÁRIOS (manager/site_controller)
│   ├── REGRAS DE NEGÓCIO
│   │   ├── RN-601: Lista todos os usuários (idx > 0) ordenados por created_at DESC
│   │   ├── RN-602: Dashboard calcula: total_users, active_users (active='yes'), enabled_users, removed_users
│   │   ├── RN-603: Ações sobre usuários: inativar (enabled='no'), ativar (enabled='yes'), remover (soft-delete), editar (name/mail), reset-senha
│   │   ├── RN-604: Admin não pode remover a si mesmo (action='remover' && idx === adminId → redireciona sem ação)
│   │   ├── RN-605: ID inválido (idx <= 0) → redireciona sem ação
│   │   ├── RN-606: Reset de senha via admin: gera token de 2h, envia email com link de /redefinir-senha/
│   │   │            (RN-606a) Usa SITE_CANONICAL_URL para compor o link do site público
│   │   ├── RN-607: Log de email salvo na tabela messages após envio de reset
│   │   ├── RN-608: Erros em users_action logados via Logger::getInstance()->error()
│   │   └── RN-609: Ao final de cada ação, redireciona para users_url
│   │
│   ├── VALIDAÇÕES
│   │   ├── Campo: _csrf_token → Regra: hash_equals
│   │   ├── Campo: idx (POST) → Regra: required, > 0
│   │   ├── Campo: action → Regra: deve ser um de ['inativar', 'ativar', 'remover', 'editar', 'reset-senha']
│   │   ├── Campo: name, mail (editar) → Regra: ambos não vazios
│   │   └── Falha: redirect silencioso para users_url (sem flash message)
│   │
│   ├── FLUXO DE DADOS — DASHBOARD
│   │   ├── 1. GET /, /admin, /usuarios → site_controller:dashboard (authGuard)
│   │   ├── 2. users_model.load_data() → WHERE idx > 0 ORDER BY created_at DESC
│   │   └── 3. Renderiza view dashboard.php com $users, $total_users, $active_users, $enabled_users, $removed_users
│   │
│   ├── FLUXO DE DADOS — USERS_ACTION
│   │   ├── 1. POST /usuarios → site_controller:users_action (authGuard)
│   │   ├── 2. validate_csrf()
│   │   ├── 3. Verifica idx > 0
│   │   ├── 4. Proteção: admin não remove a si mesmo
│   │   ├── 5. Switch action:
│   │   │   ├── inativar → users_model.populate({enabled:'no'}) + save()
│   │   │   ├── ativar   → users_model.populate({enabled:'yes'}) + save()
│   │   │   ├── remover  → users_model.remove() (soft-delete: active='no', removed_at, removed_by)
│   │   │   ├── editar   → users_model.populate({name, mail}) + save()
│   │   │   └── reset-senha → gera token, salva no DB, envia email via Kafka, log em messages
│   │   └── 6. basic_redir(users_url)
│   │
│   └── DEPENDÊNCIAS EXTERNAS
│       ├── Kafka (EmailProducer) — envio de email de reset de senha iniciado pelo admin
│       └── SMTP (worker)
│
├── MÓDULO: CADASTRO ADMIN (manager/auth_controller) — exclusivo MANAGER
│   ├── REGRAS DE NEGÓCIO
│   │   ├── RN-701: Campos obrigatórios: name, mail, login (não inclui password — senha é gerada automaticamente)
│   │   ├── RN-702: Email/logins duplicados são rejeitados (active='yes', mail=? OR login=?)
│   │   ├── RN-703: Senha aleatória gerada com random_bytes(32), hasheada com bcrypt
│   │   ├── RN-704: Usuário criado com enabled='no' — conta inativa até definir senha
│   │   ├── RN-705: Token email_token gerado com random_bytes(32), expira em +72h
│   │   ├── RN-706: Perfil padrão DEFAULT_USER_PROFILE_ID atribuído (valor=2)
│   │   ├── RN-707: Email enviado com link para /definir-senha/{token} no Manager (usa canonical_url('MANAGER_CANONICAL_URL'))
│   │   ├── RN-708: Template new_admin_credentials.php: botão "Definir minha senha" em vez de "Acessar o painel"
│   │   ├── RN-709: Log de email salvo na tabela messages
│   │   ├── RN-710: Sucesso → redireciona para login_url
│   │   ├── RN-711: display_set_password valida token diretamente no DB (sem dependência de sessão)
│   │   ├── RN-712: set_password: senha ≥ 6 caracteres, password === password_confirm, enabled → 'yes', email_token → null
│   │   └── RN-713: Rotas /cadastro são AUTH-guarded (apenas admins logados podem cadastrar). Rotas /definir-senha/{token} são públicas.
│   │
│   ├── VALIDAÇÕES
│   │   ├── Campo: _csrf_token → Regra: hash_equals
│   │   ├── Campo: name → Regra: required
│   │   ├── Campo: mail → Regra: required
│   │   ├── Campo: login → Regra: required
│   │   ├── Campo: mail + login → Regra: unique (active='yes')
│   │   └── Falha: redirect com flash message para register_url
│   │
│   ├── FLUXO DE DADOS — REGISTRO
│   │   ├── 1. GET /cadastro → auth_controller:display_register (authGuard)
│   │   ├── 2. POST /cadastro → auth_controller:register (authGuard)
│   │   ├── 3. validate_csrf() + valida required (name, mail, login)
│   │   ├── 4. users_model.load_data() → verifica duplicidade
│   │   ├── 5. Gera token + senha aleatória (bcrypt), enabled='no'
│   │   ├── 6. users_model.populate() + save() + save_attach(["profiles"])
│   │   ├── 7. EmailProducer.send() → email com link /definir-senha/{token}
│   │   ├── 8. messages_model.save() (log)
│   │   └── 9. redir login_url com mensagem de sucesso
│   │
│   ├── FLUXO DE DADOS — DEFINIR SENHA
│   │   ├── 1. Usuário clica no link do email → GET /definir-senha/{token}
│   │   ├── 2. display_set_password: valida token no DB (active='yes', enabled='no', email_token=?, não expirado)
│   │   ├── 3. Renderiza formulário de definir senha (set_password.php)
│   │   ├── 4. POST /definir-senha/{token} → set_password: validate_csrf, valida senha ≥ 6 e confirm
│   │   ├── 5. Busca usuário pelo token, atualiza: enabled='yes', email_verified_at=NOW(), password=bcrypt, email_token=null
│   │   ├── 6. session_regenerate_id(true)
│   │   └── 7. redir login_url com mensagem de sucesso
│   │
│   └── DEPENDÊNCIAS EXTERNAS
│       ├── Kafka (EmailProducer) — envio de email com link de set-password
│       └── SMTP (worker)
│
├── MÓDULO: PÁGINAS PÚBLICAS (site/site_controller)
│   ├── REGRAS DE NEGÓCIO
│   │   ├── RN-801: Home page renderiza sem restrição de auth (detecta login internamente para UI condicional)
│   │   ├── RN-802: /area requer autenticação (authGuard) — mesma action home() mas com guard
│   │   ├── RN-803: Termos de Uso e Política de Privacidade são páginas estáticas públicas sem lógica de negócio
│   │   └── RN-804: Páginas não exigem CSRF token (apenas GET, sem formulários)
│   │
│   ├── VALIDAÇÕES
│   │   └── Nenhuma validação de entrada (páginas somente leitura)
│   │
│   └── DEPENDÊNCIAS EXTERNAS
│       └── Nenhuma
│
├── CAMADA DE PERSISTÊNCIA (Modelos)
│   ├── users_model → tabela `users`
│   │   ├── Campos padrão (default $field): idx, name, mail, login
│   │   ├── Filtro padrão (default $filter): active = 'yes'
│   │   ├── PK: idx (auto_increment)
│   │   ├── UNIQUE: mail
│   │   ├── Campos notáveis: password (bcrypt), enabled (yes/no), email_token, email_verified_at, email_token_expires_at, last_login
│   │   ├── Relacionamento: N↔N com profiles via junction table users_profiles (método attach/join/save_attach)
│   │   └── Soft-delete: active='yes'/'no', removed_at, removed_by
│   │
│   ├── messages_model → tabela `messages`
│   │   ├── Sem $field definido (herda SELECT *)
│   │   ├── Filtro padrão: active = 'yes'
│   │   ├── PK: idx (auto_increment)
│   │   └── Finalidade: log de emails enviados (to_mail, subject, body, sent_at)
│   │
│   ├── profiles_model → tabela `profiles`
│   │   ├── Campos padrão: idx, name, editabled, slug, adm, parent
│   │   ├── Filtro padrão: active = 'yes'
│   │   ├── PK: idx (auto_increment)
│   │   ├── Seeds: Administrador (idx=1, adm='yes', slug='admin'), Usuário (idx=2, adm='no', slug='user')
│   │   └── Hierarquia: campo parent (0 = raiz)
│   │
│   └── DOLModel (base)
│       ├── save(): INSERT se sem filtro explícito; UPDATE se filtro definido (≠ default active='yes')
│       │   ├── INSERT: adiciona created_at=NOW(), created_by=userId
│       │   └── UPDATE: adiciona modified_at=NOW(), modified_by=userId
│       ├── remove(): soft-delete — UPDATE SET active='no', removed_at=NOW(), removed_by=userId
│       ├── attach(): N↔N via junction table {table}_{class} — exemplo: users_profiles
│       ├── join(): 1→N via foreign key
│       ├── save_attach(): gerencia N↔N — soft-deleta relações antigas + INSERT/UPDATE com ON DUPLICATE KEY
│       ├── load_data(): SELECT com prepared statements (filterParams) ou string interpolation (legado)
│       ├── populate(): mapeia array associativo → colunas do schema da tabela
│       ├── execute_raw_prepared(): prepared statement manual
│       └── Transação: herdada de localPDO (auto-begin no singleton, auto-commit no destruct, rollback em erro SQL)
│
├── INFRAESTRUTURA
│   ├── localPDO (singleton)
│   │   ├── Conexão MySQL via PDO com charset utf8mb4, ERRMODE_EXCEPTION, emulated prepares=false
│   │   ├── Transação automática: beginTransaction() na primeira getInstance(). `basic_redir()` é o gate de commit/rollback — `basic_redir($url)` commita, `basic_redir($url, rollback: true)` reverte. `__destruct()` faz safety rollback se nenhum redirect explícito ocorrer.
│   │   ├── Rollback automático em erros SQL (my_query, executePrepared)
│   │   ├── executePrepared(): query parametrizada com bind de parâmetros
│   │   └── fields_config(): introspecção do schema (SHOW COLUMNS) para detectar PK, UNIQUE, tipos
│   │
│   ├── Dispatcher
│   │   ├── Apenas GET e POST aceitos (PUT/PATCH/DELETE ignorados)
│   │   ├── URI matching via regex (aninhada com ^ e $)
│   │   ├── Guard: callable executado antes do controller — se false, redireciona para login_url
│   │   ├── normalize: trailing slash → redirect para versão sem slash
│   │   └── Fallback: se nenhuma rota der match → exec() retorna false → basic_redir(home_url)
│   │
│   ├── RedisCache (singleton)
│   │   ├── Conexão fail-open: se offline, aplicação continua sem cache/rate limit
│   │   ├── Serialização automática (PHP serialize)
│   │   ├── Prefixo de namespace por ambiente (REDIS_PREFIX)
│   │   └── Métodos: get, set, setex, incr, expire, del, keys, flushDB, etc.
│   │
│   ├── EmailProducer (singleton condicional)
│   │   ├── Se rdkafka carregado: producer Kafka real → envia JSON para KAFKA_TOPIC_EMAIL
│   │   │   └── Retry: até 30 tentativas com flush(1000ms), 100ms entre tentativas
│   │   └── Se rdkafka ausente: stub → loga erro, retorna false (fail-open para não quebrar app)
│   │
│   ├── Logger (singleton)
│   │   ├── Níveis: DEBUG(0), INFO(1), WARNING(2), ERROR(3)
│   │   ├── Threshold: controlado por LOG_LEVEL no kernel.php
│   │   └── Output: JSON via error_log() com timestamp ISO8601, channel, level, message, context
│   │
│   ├── CommonFunctions (utilitários)
│   │   ├── time_ago($datetime) → data relativa PT-BR ("há 5 minutos", "ontem às 14:30")
│   │   ├── str_limit($value, $limit) → trunca texto com "..."
│   │   ├── old($key, $default) → repopula campo de formulário com htmlspecialchars
│   │   ├── array_to_csv($data, $filename, $headers) → exporta array para CSV
│   │   ├── json_response($data, $code) → resposta JSON padronizada
│   │   ├── random_token($bytes) → bin2hex(random_bytes()), default 32 bytes
│   │   ├── handle_upload($file, $subDir, $options) → upload com validação/resize/conversão
│   │   ├── canonical_url($constant) → URL canônica para links em emails
│   │   ├── generate_slug($text) → slug amigável para URLs
│   │   └── sanitize_string($value, $digitsOnly) → limpeza de string para formulários
│   │
│   └── Sessão + Segurança
│       ├── Session: cookie_httponly=true, cookie_secure=dinâmico (HTTPS), cookie_samesite=Lax, use_only_cookies=true
│       ├── Headers: X-Frame-Options:DENY, X-Content-Type-Options:nosniff, Referrer-Policy:strict-origin-when-cross-origin, Permissions-Policy restritiva
│       ├── CSRF: tokens com grace period de 10s após primeiro uso (armazenados em _csrf_used com timestamp), regenerados na próxima page load. Tokens expirados são limpos automaticamente.
│       ├── Host Header Injection: validação via ALLOWED_HOSTS (se definido)
│       ├── Session fixation: session_regenerate_id(true) em todo login bem-sucedido
│       ├── Password: bcrypt sempre, MD5 auto-migrado
│       └── Rate limiting: Redis com fallback para arquivo (flock), ambas as camadas fail-open
│
└── ESQUEMA DO BANCO DE DADOS
    ├── users (idx PK, mail UNIQUE, login, password, name, last_login, enabled, email_token, email_verified_at, email_token_expires_at, phone, genre, + auditoria)
    ├── profiles (idx PK, name, editabled, slug, adm, parent, + auditoria)
    ├── users_profiles (idx PK, users_id FK, profiles_id FK, + auditoria) — junction table N↔N
    ├── messages (idx PK, to_mail, subject, body, sent_at, + auditoria)
    └── migrations_log (id PK, migration_name UNIQUE, executed_at, status, error_message)
```

---

```
SUMÁRIO DE REGRAS DE NEGÓCIO
─────────────────────────────
Total de módulos:           9 (Autenticação, Cadastro Site, Verificação Email, Definição Senha,
                                Recuperação Senha, Redefinição Senha, Admin/Painel, Cadastro Admin Manager,
                                Páginas Públicas)
Total de regras identificadas: 73 (RN-001 a RN-013, RN-101 a RN-112, RN-201 a RN-206,
                                   RN-301 a RN-307, RN-401 a RN-409, RN-501 a RN-508,
                                   RN-601 a RN-609, RN-701 a RN-713, RN-801 a RN-804)
Total de pontos de validação: 27 (campos validados em todos os fluxos POST)
Total de modelos:            3 (users_model, messages_model, profiles_model)
Total de relacionamentos:    1 N↔N (users ↔ profiles via users_profiles)
Total de tabelas:            5 (users, profiles, users_profiles, messages, migrations_log)
Total de rotas:              30 (13 manager, 17 site)

Dependências externas:
  - Redis (rate limiting + cache — fail-open)
  - Kafka / rdkafka (email assíncrono — fallback para stub)
  - SMTP (envio real de email via worker kafka_email_worker.php)
  - MySQL 8.0

Histórico de correções (2026-05-28):
  ✅ CSRF: adicionado grace period de 10s — tokens em _csrf_used com timestamp
  ✅ Transação: basic_redir() agora gerencia commit/rollback; destructor faz safety rollback
  ✅ CANONICAL_URL: helper canonical_url() protege contra Host Header Injection
  ✅ Rate limit: logging de warning nos pontos de bypass fail-open
  ✅ Verificação de email: idempotente — token preservado até set_password
  ✅ Cadastro Manager: senha auto-gerada, email com link /definir-senha/{token}
  ✅ Tema: toggle de tema claro/escuro funcional no header do Manager
  ✅ Úteis: time_ago(), str_limit(), old(), array_to_csv(), json_response(), random_token(), handle_upload()
  ✅ Dashboard: time_ago() na coluna Último login, botão Exportar CSV
  ✅ time_ago(): corrigido bug com datas MySQL zero (0000-00-00 → '—')

Pontos de atenção remanescentes:
  Nenhum. Todos os 8 pontos identificados na auditoria original foram corrigidos
  ou documentados como decisões de design intencionais.
```
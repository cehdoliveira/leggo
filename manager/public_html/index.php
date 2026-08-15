<?php

/**
 * Front Controller Principal
 * PHP 8.3+ com PDO e MySQL 8.0
 *
 * Este arquivo é o ponto de entrada da aplicação
 * Gerencia sessões, rotas e despacho de requisições
 */

// ob_start() ANTES de qualquer output garante que header() e Set-Cookie
// funcionem mesmo que algum include gere bytes acidentais (espaços, BOM, etc.)
ob_start();

// Iniciar sessão com configurações seguras para PHP 8.4
// cookie_secure: força envio do cookie apenas sobre HTTPS (alinhado ao php.ini)
// cookie_samesite Lax: permite cookies em redirects GET de topo (pós-login)
// use_only_cookies: impede que o session_id seja passado via URL
// use_strict_mode REMOVIDO: conflita com session_write_close() explícito no phpredis —
//   sessões ficam como "não inicializadas" e são rejeitadas na próxima requisição.
//   Proteção contra session fixation é feita via session_regenerate_id(true) no login.
$isHttpsRequest = (
	(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
	(!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
	(!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
);

session_start([
	'cookie_httponly'  => true,
	'cookie_secure'    => $isHttpsRequest,
	'cookie_samesite'  => 'Lax',
	'use_only_cookies' => true,
]);

header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), camera=(), microphone=()");

// Configurações de erro — controladas pelo php.ini em produção
// ini_set('display_errors', 1) foi REMOVIDO: em produção erros não devem ser exibidos

// Carregar dependências principais
require_once($_SERVER["DOCUMENT_ROOT"] . "/../app/inc/main.php");

// CSP com nonce por request — precisa ser gerada em PHP (nginx não pode variar por
// resposta). Exposta via $GLOBALS pois head.php é incluído dentro do escopo local dos
// métodos de controller, não no escopo global deste arquivo.
$GLOBALS["cspNonce"] = random_token(16);
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . $GLOBALS["cspNonce"] . "' 'unsafe-eval' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data:; object-src 'none'; base-uri 'self'");

// Parâmetros da requisição (PHP 8.4 compatível)
$params = [
	"sr" => isset($_GET["sr"]) && (int)$_GET["sr"] > 1 ? (int)$_GET["sr"] : 0,
	"format" => ".html",
	"post" => $_POST ?? null,
	"get" => $_GET ?? null,
];

$dispatcher = new Dispatcher(true);
// Guard por capacidade. O Dispatcher avalia qualquer callable no 4o argumento
// de add_route() (Dispatcher::evaluateCheck), entao o guard e so um valor —
// nenhuma mudanca no roteador. O slug e literal aqui no codigo; a decisao real
// mora em auth_controller::routeGuard(), testavel isoladamente.
$can = fn(string $capability): callable => fn(): bool => auth_controller::routeGuard($capability);

// "Minha conta" nao e permissao de administracao: qualquer usuario logado
// acessa a propria. Guard e so sessao, nao capacidade.
$authGuard = fn(): bool => auth_controller::check_login();

$dispatcher->add_route("GET", "/(index(\.json|\.xml|\.html)).*?", "function:basic_redir", null, $home_url);

// Login
$dispatcher->add_route("GET",  "/login(\.json|\.xml|\.html)?", "auth_controller:display", null, $params);
$dispatcher->add_route("POST", "/login(\.json|\.xml|\.html)?", "auth_controller:login",   null, $params);

// Logout
$dispatcher->add_route("POST", "/sair", "auth_controller:logout", null, $params);

// Cadastro de novo usuário admin (requer autenticação)
$dispatcher->add_route("GET",  "/cadastro(\.json|\.xml|\.html)?", "auth_controller:display_register", $can('usuarios.escrever'), $params);
$dispatcher->add_route("POST", "/cadastro(\.json|\.xml|\.html)?", "auth_controller:register",         $can('usuarios.escrever'), $params);

// Definição de senha para novos usuários (público — usuário ainda não autenticado)
$dispatcher->add_route("GET",  "/definir-senha/([a-zA-Z0-9]+)", "auth_controller:display_set_password", null, $params);
$dispatcher->add_route("POST", "/definir-senha/([a-zA-Z0-9]+)", "auth_controller:set_password",         null, $params);

// Recuperação de senha (público — usuário não consegue entrar)
$dispatcher->add_route("GET",  "/esqueci-minha-senha",            "auth_controller:display_forgot_password", null, $params);
$dispatcher->add_route("POST", "/esqueci-minha-senha",            "auth_controller:forgot_password",         null, $params);
$dispatcher->add_route("GET",  "/redefinir-senha/([a-zA-Z0-9]+)", "auth_controller:display_reset_password",  null, $params);
$dispatcher->add_route("POST", "/redefinir-senha/([a-zA-Z0-9]+)", "auth_controller:reset_password",          null, $params);

// Minha conta — qualquer usuário logado edita a própria
$dispatcher->add_route("GET",  "/minha-conta",        "auth_controller:display_account", $authGuard, $params);
$dispatcher->add_route("POST", "/minha-conta",        "auth_controller:save_account",    $authGuard, $params);
$dispatcher->add_route("POST", "/minha-conta/senha",  "auth_controller:change_password", $authGuard, $params);

// Usuários — padrão display/form/save/remove (requer autenticação)
$dispatcher->add_route("GET",  "/?",                              "users_controller:display", $can('usuarios.ler'), $params);
$dispatcher->add_route("GET",  "/admin",                          "users_controller:display", $can('usuarios.ler'), $params);
$dispatcher->add_route("GET",  "/usuarios(\.json|\.html)?",       "users_controller:display", $can('usuarios.ler'), $params);
$dispatcher->add_route("POST", "/usuarios",                       "users_controller:action",  $can('usuarios.escrever'), $params);
$dispatcher->add_route("GET",  "/novo-usuario",                   "users_controller:form",    $can('usuarios.escrever'), $params);
$dispatcher->add_route("POST", "/novo-usuario",                   "users_controller:save",    $can('usuarios.escrever'), $params);
$dispatcher->add_route("GET",  "/usuario/([a-z0-9_-]+)",          "users_controller:form",    $can('usuarios.escrever'), $params);
$dispatcher->add_route("POST", "/usuario/([a-z0-9_-]+)",          "users_controller:save",    $can('usuarios.escrever'), $params);
$dispatcher->add_route("POST", "/usuario/([a-z0-9_-]+)/remover",  "users_controller:remove",  $can('usuarios.escrever'), $params);

// Import de usuários por CSV — fatia 1 do plans/018-DESIGN.md (requer autenticação)
$dispatcher->add_route("GET",  "/importar-usuarios",                     "usersimports_controller:display", $can('usuarios.ler'), $params);
$dispatcher->add_route("GET",  "/importar-usuarios/novo",                "usersimports_controller:form",    $can('usuarios.escrever'), $params);
$dispatcher->add_route("POST", "/importar-usuarios/novo",                "usersimports_controller:save",    $can('usuarios.escrever'), $params);
$dispatcher->add_route("GET",  "/importar-usuarios/([0-9]+)",            "usersimports_controller:form",    $can('usuarios.ler'), $params);
$dispatcher->add_route("POST", "/importar-usuarios",                     "usersimports_controller:action",  $can('usuarios.escrever'), $params);
$dispatcher->add_route("POST", "/importar-usuarios/([0-9]+)/remover",    "usersimports_controller:remove",  $can('usuarios.escrever'), $params);

// Outbox de e-mails — somente leitura (requer autenticação)
$dispatcher->add_route("GET", "/emails(\.json|\.html)?", "emails_controller:display", $can('emails.ler'), $params);

// Perfis — padrão display/form/save/remove (requer autenticação)
$dispatcher->add_route("GET",  "/perfis(\.json|\.html)?",        "profiles_controller:display", $can('perfis.ler'), $params);
$dispatcher->add_route("GET",  "/novo-perfil",                   "profiles_controller:form",    $can('perfis.escrever'), $params);
$dispatcher->add_route("POST", "/novo-perfil",                   "profiles_controller:save",    $can('perfis.escrever'), $params);
$dispatcher->add_route("GET",  "/perfil/([a-z0-9_-]+)",          "profiles_controller:form",    $can('perfis.escrever'), $params);
$dispatcher->add_route("POST", "/perfil/([a-z0-9_-]+)",          "profiles_controller:save",    $can('perfis.escrever'), $params);
$dispatcher->add_route("POST", "/perfil/([a-z0-9_-]+)/remover",  "profiles_controller:remove",  $can('perfis.escrever'), $params);

// Executar dispatcher e tratar falhas
if (!$dispatcher->exec()) {
	render_error_page(
		404,
		"Página não encontrada",
		"O endereço acessado não existe ou foi movido. Confira o link ou volte ao início."
	);
}

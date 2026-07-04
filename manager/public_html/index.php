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

// Parâmetros da requisição (PHP 8.4 compatível)
$params = [
	"sr" => isset($_GET["sr"]) && (int)$_GET["sr"] > 1 ? (int)$_GET["sr"] : 0,
	"format" => ".html",
	"post" => $_POST ?? null,
	"get" => $_GET ?? null,
];

$dispatcher = new Dispatcher(true);
$authGuard  = fn() => auth_controller::check_login();

$dispatcher->add_route("GET", "/(index(\.json|\.xml|\.html)).*?", "function:basic_redir", null, $home_url);

// Login
$dispatcher->add_route("GET",  "/login(\.json|\.xml|\.html)?", "auth_controller:display", null, $params);
$dispatcher->add_route("POST", "/login(\.json|\.xml|\.html)?", "auth_controller:login",   null, $params);

// Logout
$dispatcher->add_route("POST", "/sair", "auth_controller:logout", null, $params);

// Cadastro de novo usuário admin (requer autenticação)
$dispatcher->add_route("GET",  "/cadastro(\.json|\.xml|\.html)?", "auth_controller:display_register", $authGuard, $params);
$dispatcher->add_route("POST", "/cadastro(\.json|\.xml|\.html)?", "auth_controller:register",         $authGuard, $params);

// Definição de senha para novos usuários (público — usuário ainda não autenticado)
$dispatcher->add_route("GET",  "/definir-senha/([a-zA-Z0-9]+)", "auth_controller:display_set_password", null, $params);
$dispatcher->add_route("POST", "/definir-senha/([a-zA-Z0-9]+)", "auth_controller:set_password",         null, $params);

// Admin (requer autenticação)
$dispatcher->add_route("GET",  "/?",     "site_controller:dashboard", $authGuard, $params);
$dispatcher->add_route("GET",  "/admin", "site_controller:dashboard", $authGuard, $params);

// Usuários (requer autenticação)
$dispatcher->add_route("GET",  "/usuarios", "site_controller:dashboard",    $authGuard, $params);
$dispatcher->add_route("POST", "/usuarios", "site_controller:users_action", $authGuard, $params);

// Executar dispatcher e tratar falhas
if (!$dispatcher->exec()) {
	basic_redir($home_url);
}

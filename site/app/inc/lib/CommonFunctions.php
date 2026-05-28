<?php

/**
 * Autoload de classes
 * Carrega automaticamente classes de controller, lib ou model quando são instanciadas
 */
function m_autoload(string $name): bool
{
  // Ignorar classes do namespace ou do Composer
  if (strpos($name, '\\') !== false || strpos($name, 'Composer') !== false) {
    return false;
  }

  // Verificar se constante necessária existe (modo CLI pode não ter)
  if (!defined('cRootServer_APP')) {
    return false;
  }

  $file_name = false;
  try {
    foreach (["controller", "lib", "model"] as $dir) {
      $file = sprintf(
        "%s/inc/%s/%s.php",
        constant("cRootServer_APP"),
        $dir,
        $name
      );

      if (file_exists($file)) {
        $file_name = $file;
        if (! class_exists($name, false)) {
          require_once($file);
        }
        return true;
      }
    }
    if ($file_name === false) {
      throw new Exception('Class ' . $name . ' not exists');
    }
  } catch (Exception $e) {
    error_log("Autoload Error: " . $e->getMessage());
    return false;
  }
  return false;
}
spl_autoload_register('m_autoload');

/**
 * Gera uma chave aleatoria criptograficamente segura.
 * Retorna uma string hexadecimal de tamanho definido (padrao: 10 caracteres)
 */
function generate_key(int $size = 10): string
{
  return substr(bin2hex(random_bytes((int)ceil($size / 2))), 0, $size);
}

/**
 * Exibe array formatado para debug
 * Mostra o conteúdo de uma variável com print_r em formato legível
 */
function print_pre(mixed $data, bool $stop = false): void
{
  print("<pre>");
  print_r($data);
  print("</pre>");
  if ($stop) {
    exit();
  }
}

/**
 * Exibe variável com var_dump formatado
 * Mostra detalhes completos da variável incluindo tipos para debug
 */
function var_pre(mixed $data, bool $stop = false): void
{
  print("<pre>");
  var_dump($data);
  print("</pre>");
  if ($stop) {
    exit();
  }
}

/**
 * Converte entidades HTML para caracteres acentuados
 * Transforma &aacute; em á, &ccedil; em ç, etc
 */
function html_accents(?string $text = null): string
{
  return strtr($text ?? '',  array_flip($GLOBALS["html_entities_list"]));
}

/**
 * Converte texto para maiúsculas mantendo acentos
 * Transforma "josé" em "JOSÉ" preservando acentuação
 */
function up_accents(?string $text = null): string
{
  return strtoupper(strtr($text ?? '', $GLOBALS["accents_lists"]));
}

/**
 * Converte texto para minúsculas mantendo acentos
 * Transforma "JOSÉ" em "josé" preservando acentuação
 */
function down_accents(?string $text = null): string
{
  return strtolower(strtr($text ?? '', array_flip($GLOBALS["accents_lists"])));
}

/**
 * Remove todos os acentos de um texto
 * Transforma "José" em "Jose", "São" em "Sao", etc
 */
function remove_accents(?string $text = null): string
{
  return  strtr($text ?? '', $GLOBALS["withoutaccents_lists"]);
}

/**
 * Gera slug amigável para URLs
 * Converte "São Paulo" em "sao-paulo", remove acentos e caracteres especiais
 */
function generate_slug(?string $text = null): string
{
  $text = strtolower(remove_accents($text ?? ''));
  $text = preg_replace("/[^0-9A-z]+/", "_", $text);
  $text = preg_replace("/\s+?|_+|-+/", "-", $text);
  return $text;
}

/**
 * Monta URL com parâmetros GET
 * Adiciona ou substitui parâmetros na query string da URL
 */
function set_url(string $url = "", array $params = []): string
{
  $tmp = preg_split('/\?/', $url);
  if ($tmp === false) {
    return $url;
  }
  $url = $tmp[0];
  $p = "";
  if (isset($tmp[1])) {
    $p .= "?";
    foreach (explode("&", $tmp[1]) as $tmp_params) {
      list($kp, $vp) = explode("=", $tmp_params);
      if (! in_array($kp, $params)) {
        $p .= $kp . "=" . $vp . "&";
      }
    }
  }
  foreach ($params as $kp => $vp) {
    if ($p == "") {
      $p = "?";
    }
    $p .= $kp . "=" . $vp . "&";
  }
  $p = preg_replace("/\&$/", "", $p);
  return $url . $p;
}

/**
 * Redireciona para outra URL
 * Realiza redirecionamento com código HTTP (302 temporário por padrão).
 *
 * IMPORTANTE: usa header("Location: ...") em vez de JavaScript para garantir
 * que o cookie de sessão seja enviado corretamente e que não haja race condition
 * entre a escrita da sessão no Redis e o próximo request do browser.
 */
function basic_redir(string|array $url, int $code = 302, bool $use_html = false, bool $rollback = false): never
{
  if (is_array($url)) {
    $url = $url[0];
  }

  try {
    if ($rollback) {
      localPDO::getInstance()->rollback();
    } else {
      localPDO::getInstance()->commit();
    }
  } catch (\Throwable) {
    // localPDO might not be initialized if no DB ops occurred
  }

  // Cache-Control: no-store impede que o browser cache 302s.
  // Sem isso, um redirect para /login poderia ser cacheado e reproduzido
  // em requisições futuras mesmo com o usuário já autenticado.
  if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
  }

  if ($use_html) {
    $dir = constant("AppLayout");
    ob_start();
    switch ($code) {
      case 301:
        require($dir . "301.html");
        break;
      case 404:
        require($dir . "404.html");
        break;
      default:
        require($dir . "302.html");
        break;
    }
    $out = ob_get_contents();
    ob_end_clean();
    print str_replace("{location}", $url, $out);
  } else {
    header("Location: " . $url, true, $code);
  }
  exit();
}

/**
 * Retorna URL completa do servidor
 * Monta http://exemplo.com ou https://exemplo.com com porta se necessário
 */
function get_request_server(string|false $auth = false, ?bool $https = null): string
{
  $server_name = substr(addslashes(stripslashes(strip_tags(getenv("SERVER_NAME")))), 0, 255);
  $server_protocol = substr(addslashes(stripslashes(strip_tags(getenv("SERVER_PROTOCOL")))), 0, 255);
  $server_port = substr(addslashes(stripslashes(strip_tags(getenv("SERVER_PORT")))), 0, 255);

  if (strtoupper(getenv("HTTPS")) == strtoupper("on") || (isset($https) && $https == true)) {
    $server_protocol = "https";
    $server_port = "443";
  }

  if (isset($https) && $https === false) {
    $server_protocol = "http";
    if ($server_port == "443") {
      $server_port = "80";
    }
  }

  list($server_protocol,) = explode("/", $server_protocol);
  $server_protocol = strtolower($server_protocol);

  if ($auth !== false) {
    $request_server = $server_protocol . "://" . $auth . "@" . $server_name;
  } else {
    $request_server = $server_protocol . "://" . $server_name;
  }

  if ($server_port != "80" && $server_protocol == "http") {
    $request_server .= ":" . $server_port;
  }

  if ($server_port != "443" && $server_protocol == "https") {
    $request_server .= ":" . $server_port;
  }
  return $request_server;
}

/**
 * Converte array para UTF-8 recursivamente
 * Percorre todo o array e garante encoding UTF-8 em todos os valores
 */
function a_walk(array &$array): array
{
  if (is_array($array)) {
    foreach ($array as $k => $v) {
      if (is_array($v)) {
        $array[$k] = a_walk($v);
      } else {
        $array[$k] = toUtf8($v);
      }
    }
  }
  return $array;
}

/**
 * Converte string para UTF-8 se necessário
 * Detecta se já está em UTF-8, caso contrário converte
 */
function toUtf8(string $item): string
{
  return preg_match('%(?:
        [\xC2-\xDF][\x80-\xBF]        # non-overlong 2-byte
        |\xE0[\xA0-\xBF][\x80-\xBF]               # excluding overlongs
        |[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}      # straight 3-byte
        |\xED[\x80-\x9F][\x80-\xBF]               # excluding surrogates
        |\xF0[\x90-\xBF][\x80-\xBF]{2}    # planes 1-3
        |[\xF1-\xF3][\x80-\xBF]{3}                  # planes 4-15
        |\xF4[\x80-\x8F][\x80-\xBF]{2}    # plane 16
        )+%xs', $item) == 1 ? $item : mb_convert_encoding($item, 'UTF-8', 'ISO-8859-1');
}

/**
 * Identifica se o acesso é de dispositivo móvel
 * Detecta smartphones e tablets pelo User Agent
 */
function identifyDevice(): bool
{
  return preg_match("/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i", $_SERVER['HTTP_USER_AGENT']) || preg_match("/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i", substr($_SERVER['HTTP_USER_AGENT'], 0, 4));
}

/**
 * Valida e-mail verificando DNS do domínio
 * Checa formato e se o domínio possui registros MX ou A válidos
 */
function json_domainmail(string $mail): bool
{
  return preg_match('/^([a-zA-Z0-9\._-])*([@])([a-z0-9]).([a-z]{2,3})/', $mail) && (checkdnsrr(preg_replace("/[^@\s]*@(.+)$/", "$1", $mail), 'A') || checkdnsrr(preg_replace("/[^@\s]*@(.+)$/", "$1", $mail), 'MX'));
}

/**
 * Desserializa dados corrigindo tamanho de strings UTF-8
 * Útil para dados serializados que podem ter problemas de encoding
 */
function utf8_unserialize(string $data): mixed
{
  return unserialize(preg_replace_callback('/s:([0-9]+):\"(.*?)\";/', function ($matches) {
    return "s:" . strlen($matches[2]) . ':"' . $matches[2] . '";';
  }, $data));
}

/**
 * Busca arquivo recursivamente em um diretório
 * Procura um arquivo .php específico dentro de uma pasta e suas subpastas
 */
function findfile(string $path, string $name = "x"): string|false
{
  $dir_iterator = new RecursiveDirectoryIterator($path);
  $iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);
  foreach ($iterator as $file) {
    if ($file->isFile()) {
      if (basename($file->getPathname()) == $name . ".php") {
        return $file->getPathname();
      }
    }
  }
  return false;
}

// Variáveis de ambiente do servidor
$remote_address = substr(addslashes(stripslashes(strip_tags(getenv("REMOTE_ADDR")))), 0, 15);
$http_user_agent = substr(addslashes(stripslashes(strip_tags(getenv("HTTP_USER_AGENT")))), 0, 255);
$referrer = substr(addslashes(stripslashes(strip_tags(getenv("HTTP_REFERER")))), 0, 255);
$request_uri = substr(addslashes(stripslashes(strip_tags(getenv("SCRIPT_NAME")))), 0, 255);
$request_server = get_request_server();
$path_info = getenv("PATH_INFO");

/**
 * Exibe notificações armazenadas na sessão
 * Mostra mensagens de sucesso, erro, etc em formato Bootstrap alert
 */
function html_notification_print(): void
{
  if (isset($_SESSION["messages_app"])) {
    foreach ($_SESSION["messages_app"] as $type => $context) {
      $safeType = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
      print('<div class="text-center alert alert-' . $safeType . '" role="alert">');
      print(implode("<br>", array_map(fn($m) => htmlspecialchars($m, ENT_QUOTES, 'UTF-8'), $context)));
      print('</div>');
    }
    unset($_SESSION["messages_app"]);
  }
}

/**
 * Sanitiza uma string removendo acentos e caracteres indesejados.
 * Se `$digitsOnly` for true, retorna apenas os dígitos (útil para campos numéricos).
 * Pode ser reutilizada para limpar outros campos antes de salvar ou comparar.
 */
function sanitize_string(mixed $value, bool $digitsOnly = false): ?string
{
  if (!isset($value) || $value === null) {
    return null;
  }

  // Garantir string
  $value = trim((string)$value);

  // Remover entidades HTML e acentos
  $value = html_accents($value);
  $value = remove_accents($value);

  if ($digitsOnly) {
    // Retornar apenas números
    return preg_replace('/\D+/', '', $value);
  }

  // Remover caracteres não alfanuméricos (manter letras e números)
  $value = preg_replace('/[^\p{L}0-9]+/u', '', $value);

  return $value;
}

function render_xml(array $data, string $root): void
{
  $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><' . $root . '/>');
  array_to_xml($data, $xml);
  echo $xml->asXML();
}

function array_to_xml(array $data, SimpleXMLElement &$xml): void
{
  foreach ($data as $key => $value) {
    $key = is_int($key) ? 'item' : $key;
    if (is_array($value)) {
      $child = $xml->addChild($key);
      array_to_xml($value, $child);
    } else {
      $xml->addChild($key, htmlspecialchars((string)$value, ENT_XML1, 'UTF-8'));
    }
  }
}

function validate_csrf(?string $token, string $redirectUrl): void
{
  $now = time();
  $graceSeconds = 10;
  $validTokens = [];

  if (isset($_SESSION['_csrf_token'])) {
    $validTokens[] = $_SESSION['_csrf_token'];
  }

  if (!empty($_SESSION['_csrf_used']) && is_array($_SESSION['_csrf_used'])) {
    foreach ($_SESSION['_csrf_used'] as $usedToken => $usedAt) {
      if ($now - $usedAt <= $graceSeconds) {
        $validTokens[] = $usedToken;
      } else {
        unset($_SESSION['_csrf_used'][$usedToken]);
      }
    }
  }

  $isValid = false;
  foreach ($validTokens as $validToken) {
    if (!empty($token) && hash_equals($validToken, $token)) {
      $isValid = true;
      break;
    }
  }

  if (!$isValid) {
    $_SESSION["messages_app"]["danger"] = ["Requisição inválida. Tente novamente."];
    basic_redir($redirectUrl);
    exit();
  }

  if (isset($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_used'][$_SESSION['_csrf_token']] = $now;
    unset($_SESSION['_csrf_token']);
  }
}

function canonical_url(string $canonicalConstant): string
{
  if (defined($canonicalConstant) && constant($canonicalConstant) !== '') {
    return rtrim(constant($canonicalConstant), '/');
  }

  if (defined('ALLOWED_HOSTS') && constant('ALLOWED_HOSTS') !== '') {
    return rtrim(constant('cFrontend'), '/');
  }

  Logger::getInstance()->warning("Canonical URL falling back to cFrontend without ALLOWED_HOSTS", [
    "constant" => $canonicalConstant,
    "host"     => $_SERVER['HTTP_HOST'] ?? 'unknown',
  ]);

  return rtrim(constant('cFrontend'), '/');
}

function check_rate_limit(?object $redis, string $key, int $max): bool
{
  if (!$redis) return false;
  return (int)($redis->get($key) ?? 0) >= $max;
}

function increment_rate_limit(?object $redis, string $key, int $window): void
{
  if (!$redis) return;
  $count = $redis->incr($key);
  if ($count === 1) {
    $redis->expire($key, $window);
  }
}

function ratelimit_fallback_dir(): string
{
  if (defined('RATELIMIT_FALLBACK_DIR') && constant('RATELIMIT_FALLBACK_DIR') !== '') {
    return rtrim(constant('RATELIMIT_FALLBACK_DIR'), '/');
  }
  return sys_get_temp_dir() . '/leggo_ratelimit';
}

// Atomic check+increment: increments first to prevent race condition bypass.
// Returns true if the new count exceeds $max (i.e., request should be blocked).
// Falls back to file-based locking when Redis is unavailable.
//
// Design choice — fail-open: if both Redis AND the file fallback are unavailable,
// the function returns false (does not block). This prioritizes availability over
// rate-limit enforcement during infrastructure outages. A warning is logged so
// operators can detect when rate limiting is completely bypassed.
function check_and_increment_rate_limit(?object $redis, string $key, int $max, int $window): bool
{
  if ($redis) {
    $count = $redis->incr($key);
    if ($count === 1) {
      $redis->expire($key, $window);
    }
    return $count > $max;
  }

  $dir = ratelimit_fallback_dir();
  if (!is_dir($dir)) {
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
      Logger::getInstance()->warning("Rate limit fallback dir unavailable", [
        "dir"    => $dir,
        "key"    => $key,
      ]);
      return false;
    }
  }
  $file = $dir . '/' . md5($key) . '.lock';

  $fp = @fopen($file, 'c+');
  if (!$fp) {
    Logger::getInstance()->warning("Rate limit fallback file open failed", [
      "file"   => $file,
      "key"    => $key,
    ]);
    return false;
  }

  if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    Logger::getInstance()->warning("Rate limit fallback lock failed", [
      "file"   => $file,
      "key"    => $key,
    ]);
    return false;
  }

  $raw = '';
  while (!feof($fp)) {
    $raw .= fread($fp, 8192);
  }
  $data = @json_decode($raw ?: '{}', true) ?: [];
  $now = time();

  if (!isset($data['window_start']) || ($now - $data['window_start']) > $window) {
    $data = ['count' => 1, 'window_start' => $now];
  } else {
    $data['count']++;
  }

  $blocked = $data['count'] > $max;

  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($data));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  return $blocked;
}

function reset_rate_limit(?object $redis, string $key): void
{
  if ($redis) {
    $redis->del($key);
    return;
  }

  $dir = ratelimit_fallback_dir();
  $file = $dir . '/' . md5($key) . '.lock';
  @unlink($file);
}

function verify_password_with_migration(string $storedHash, string $inputPassword, string $userId): bool
{
  if (password_get_info($storedHash)['algoName'] !== 'unknown') {
    return password_verify($inputPassword, $storedHash);
  }
  if (strlen($inputPassword) > 1024) return false;
  $authenticated = hash_equals($storedHash, md5($inputPassword));
  if ($authenticated) {
    $m = new users_model();
    $m->set_filter(["idx = ?"], [$userId]);
    $m->populate(["password" => password_hash($inputPassword, PASSWORD_BCRYPT)]);
    $m->save();
  }
  return $authenticated;
}

function handle_upload(array $file, string $subDir, array $options = []): string|false
{
  if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    Logger::getInstance()->warning("Upload error", ["error_code" => $file['error'] ?? 'no_file']);
    return false;
  }

  if (!is_uploaded_file($file['tmp_name'])) {
    Logger::getInstance()->warning("Upload bypass attempt", ["name" => $file['name'] ?? 'unknown']);
    return false;
  }

  $subDir = trim($subDir, '/');
  if ($subDir === '' || str_contains($subDir, '..') || str_contains($subDir, '\\')) {
    Logger::getInstance()->warning("Upload invalid subDir", ["subDir" => $subDir]);
    return false;
  }

  $allowedStr = $options['allowed_types']
    ?? (defined('UPLOAD_ALLOWED_TYPES') ? constant('UPLOAD_ALLOWED_TYPES') : 'jpg,jpeg,png,gif,pdf');
  $allowedTypes = array_map('trim', explode(',', strtolower($allowedStr)));
  $maxSize  = ($options['max_size_mb'] ?? (defined('UPLOAD_MAX_SIZE') ? constant('UPLOAD_MAX_SIZE') : 10)) * 1024 * 1024;
  $convert    = $options['convert'] ?? null;
  $maxWidth   = $options['max_width'] ?? 0;
  $maxHeight  = $options['max_height'] ?? 0;
  $quality    = $options['quality'] ?? 80;

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);

  $mimeMap = [
    'image/jpeg'    => 'jpg',
    'image/png'     => 'png',
    'image/gif'     => 'gif',
    'image/webp'    => 'webp',
    'image/avif'    => 'avif',
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'text/csv'      => 'csv',
  ];

  $realExt = $mimeMap[$mime] ?? null;
  if (!$realExt || !in_array($realExt, $allowedTypes)) {
    Logger::getInstance()->warning("Upload invalid type", [
      "mime"        => $mime,
      "allowed"     => $allowedTypes,
      "name"        => $file['name'] ?? '',
    ]);
    return false;
  }

  if ($file['size'] > $maxSize) {
    Logger::getInstance()->warning("Upload size exceeded", [
      "size"    => $file['size'],
      "max"     => $maxSize,
      "name"    => $file['name'] ?? '',
    ]);
    return false;
  }

  $uploadBase = defined('UPLOAD_DIR') ? rtrim(constant('UPLOAD_DIR'), '/') : sys_get_temp_dir() . '/leggo_upload';
  $uploadDir  = $uploadBase . '/' . $subDir;

  if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    Logger::getInstance()->error("Upload dir creation failed", ["dir" => $uploadDir]);
    return false;
  }

  $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
  $originalName = generate_slug($originalName);
  $originalName = substr($originalName, 0, 80) ?: 'arquivo';

  $isImage     = str_starts_with($mime, 'image/');
  $shouldConvert = $convert && $isImage && extension_loaded('gd');
  $targetExt   = $shouldConvert ? $convert : $realExt;
  $filename    = sprintf('%s_%s.%s', $originalName, date('Y-m-d_H-i-s'), $targetExt);
  $destPath    = $uploadDir . '/' . $filename;

  if ($shouldConvert) {
    try {
      $srcImage = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => imagecreatefrompng($file['tmp_name']),
        'image/gif'  => imagecreatefromgif($file['tmp_name']),
        'image/webp' => imagecreatefromwebp($file['tmp_name']),
        'image/avif' => function_exists('imagecreatefromavif') ? imagecreatefromavif($file['tmp_name']) : false,
        default      => false,
      };

      if (!$srcImage) {
        move_uploaded_file($file['tmp_name'], $destPath);
        return '/assets/upload/' . $subDir . '/' . $filename;
      }

      $origW = imagesx($srcImage);
      $origH = imagesy($srcImage);

      if ($maxWidth > 0 || $maxHeight > 0) {
        $targetW = $maxWidth > 0 ? min($maxWidth, $origW) : $origW;
        $targetH = $maxHeight > 0 ? min($maxHeight, $origH) : $origH;
        $ratio   = min($targetW / $origW, $targetH / $origH);
        $newW    = (int)($origW * $ratio);
        $newH    = (int)($origH * $ratio);

        $dstImage = imagecreatetruecolor($newW, $newH);
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);

        imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($srcImage);
        $srcImage = $dstImage;
      }

      if ($convert === 'webp') {
        imagewebp($srcImage, $destPath, $quality);
      } elseif ($convert === 'avif' && function_exists('imageavif')) {
        imageavif($srcImage, $destPath, $quality);
      } else {
        imagejpeg($srcImage, $destPath, $quality);
      }

      imagedestroy($srcImage);
      return '/assets/upload/' . $subDir . '/' . $filename;
    } catch (\Throwable $e) {
      Logger::getInstance()->error("Image conversion failed", [
        "error" => $e->getMessage(),
        "name"  => $file['name'] ?? '',
      ]);
      @unlink($destPath);
      return false;
    }
  }

  if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    Logger::getInstance()->error("Upload move failed", ["dest" => $destPath]);
    return false;
  }

  return '/assets/upload/' . $subDir . '/' . $filename;
}

function time_ago(?string $datetime): string
{
  if (empty($datetime)) {
    return '—';
  }

  $now  = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
  $then = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime, new DateTimeZone('America/Sao_Paulo'));

  if (!$then) {
    return '—';
  }

  $diff = $now->diff($then);
  $seconds = $diff->s + $diff->i * 60 + $diff->h * 3600
              + $diff->days * 86400 + $diff->m * 2592000 + $diff->y * 31536000;

  if ($diff->invert === 0) {
    $seconds = -$seconds;
  }

  $isFuture = $seconds < 0;
  $seconds = abs($seconds);

  $prefix = $isFuture ? 'em ' : '';

  if ($seconds < 60) {
    return $isFuture ? 'agora mesmo' : 'agora mesmo';
  }

  $minutes = (int)($seconds / 60);
  if ($minutes < 60) {
    if ($minutes === 1) {
      return $prefix . '1 minuto';
    }
    return $prefix . $minutes . ' minutos';
  }

  $hours = (int)($minutes / 60);
  if ($hours < 24) {
    if ($hours === 1) {
      return $isFuture ? 'em 1 hora' : 'há 1 hora';
    }
    return $isFuture ? "em {$hours} horas" : "há {$hours} horas";
  }

  $days = (int)($hours / 24);
  if ($days === 1) {
    $time = $then->format('H:i');
    return $isFuture ? "amanhã às {$time}" : "ontem às {$time}";
  }

  if ($days < 7) {
    $time = $then->format('H:i');
    return $isFuture ? "em {$days} dias" : "há {$days} dias";
  }

  if ($days < 30) {
    $weeks = (int)($days / 7);
    if ($weeks === 1) {
      return $prefix . '1 semana';
    }
    return $prefix . $weeks . ' semanas';
  }

  if ($days < 365) {
    $months = (int)($days / 30);
    if ($months === 1) {
      return $prefix . '1 mês';
    }
    return $prefix . $months . ' meses';
  }

  $years = (int)($days / 365);
  if ($years === 1) {
    return $prefix . '1 ano';
  }
  return $prefix . $years . ' anos';
}

function str_limit(?string $value, int $limit = 100, string $end = '...'): string
{
  if ($value === null || $value === '') {
    return '';
  }

  $value = strip_tags($value);

  if (mb_strlen($value) <= $limit) {
    return $value;
  }

  return mb_substr($value, 0, $limit) . $end;
}

function old(string $key, mixed $default = ''): string
{
  $value = $_POST[$key] ?? $default;

  if (is_string($value)) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  }

  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function array_to_csv(array $data, string $filename = 'export.csv', ?array $headers = null): never
{
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
  header('Cache-Control: no-store, no-cache');
  header('Pragma: no-cache');

  $output = fopen('php://output', 'w');

  if (empty($data)) {
    fclose($output);
    exit();
  }

  if ($headers === null) {
    $headers = array_keys(reset($data));
  }

  fputcsv($output, $headers, ';', '"', '\\');

  foreach ($data as $row) {
    $csvRow = [];
    foreach ($headers as $key) {
      $csvRow[] = $row[$key] ?? '';
    }
    fputcsv($output, $csvRow, ';', '"', '\\');
  }

  fclose($output);
  exit();
}

function json_response(mixed $data, int $code = 200): never
{
  http_response_code($code);
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store, no-cache');
  header('Pragma: no-cache');

  $data = is_array($data) ? $data : ['data' => $data];
  $json = json_encode(
    a_walk($data),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  );

  if ($json === false) {
    http_response_code(500);
    echo json_encode(['error' => 'JSON encoding failed']);
    exit();
  }

  echo $json;
  exit();
}



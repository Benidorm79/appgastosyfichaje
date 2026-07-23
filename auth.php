<?php
require_once "config.php";

$isHttps = (
  (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
  (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
);

if (session_status() === PHP_SESSION_NONE) {
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
  ]);

  session_start();
}

include "db.php";
require_once "includes/functions.php";
require_once "includes/auditoria.php";

header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

function getClientIpLogin() {
  if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    return $_SERVER['HTTP_CLIENT_IP'];
  }

  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    return trim($ips[0]);
  }

  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function registerLoginAttempt($conn, $username, $userId, $status, $message) {
  $ip = getClientIpLogin();
  $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

  $stmt = $conn->prepare("INSERT INTO login_attempts (username, user_id, ip_address, user_agent, status, message) VALUES (?, ?, ?, ?, ?, ?)");

  if ($stmt) {
    $stmt->bind_param("sissss", $username, $userId, $ip, $userAgent, $status, $message);
    $stmt->execute();
  }

  $tipoEvento = $status === 'success' ? 'seguridad' : 'seguridad';
  $accion = 'login_' . preg_replace('/[^a-z0-9_]+/i', '_', (string)$status);
  $estadoNuevo = $status === 'success' ? 'correcto' : 'fallido';

  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => $tipoEvento,
    'entidad' => 'login',
    'entidad_id' => $userId,
    'accion' => $accion,
    'descripcion' => $message,
    'usuario_id' => $userId,
    'username' => $username,
    'estado_nuevo' => $estadoNuevo,
    'ip' => $ip,
    'user_agent' => $userAgent,
    'datos' => [
      'status' => $status,
      'username_introducido' => $username
    ]
  ]);
}

function isLoginBlocked($conn, $username) {
  $ip = getClientIpLogin();

  $sql = "SELECT COUNT(*) AS total
          FROM login_attempts
          WHERE status IN ('failed', 'inactive', 'csrf_error')
            AND created_at >= (NOW() - INTERVAL 15 MINUTE)
            AND (
              ip_address = ?
              OR username = ?
            )";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return false;
  }

  $stmt->bind_param("ss", $ip, $username);
  $stmt->execute();

  $row = $stmt->get_result()->fetch_assoc();
  $total = intval($row['total'] ?? 0);

  return $total >= 5;
}

function redirectLogin($error, $redirect) {
  header("Location: login.php?error=" . urlencode($error) . "&redirect=" . urlencode($redirect));
  exit;
}

$username = trim($_POST['username'] ?? '');
$password = (string)($_POST['password'] ?? '');
$redirect = sanitizeRedirect($_POST['redirect'] ?? 'home.php');
$csrfToken = $_POST['csrf_token'] ?? '';

if ($username === '' || $password === '') {
  registerLoginAttempt($conn, $username, null, 'failed', 'Campos vacíos');
  redirectLogin('1', $redirect);
}

if (isLoginBlocked($conn, $username)) {
  registerLoginAttempt($conn, $username, null, 'blocked', 'Bloqueo temporal por exceso de intentos');
  redirectLogin('blocked', $redirect);
}

/*
  Comprobación CSRF.
  Si por algún motivo la sesión no conserva token, devolvemos error csrf.
*/
if (
  empty($_SESSION['login_csrf_token']) ||
  empty($csrfToken) ||
  !hash_equals((string)$_SESSION['login_csrf_token'], (string)$csrfToken)
) {
  registerLoginAttempt($conn, $username, null, 'csrf_error', 'Token CSRF no válido');
  redirectLogin('csrf', $redirect);
}

$sql = "SELECT id, username, password, comercial, role, activo
        FROM users
        WHERE username = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
  registerLoginAttempt($conn, $username, null, 'failed', 'Error preparando consulta de usuario');
  redirectLogin('1', $redirect);
}

$stmt->bind_param("s", $username);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {
  registerLoginAttempt($conn, $username, null, 'failed', 'Usuario no encontrado');
  redirectLogin('1', $redirect);
}

$user = $result->fetch_assoc();
$userId = intval($user['id']);

if ((int)$user['activo'] !== 1) {
  registerLoginAttempt($conn, $username, $userId, 'inactive', 'Usuario desactivado');
  redirectLogin('inactive', $redirect);
}

if (!appPasswordVerify($password, $user['password'])) {
  registerLoginAttempt($conn, $username, $userId, 'failed', 'Contraseña incorrecta');
  redirectLogin('1', $redirect);
}

if (appPasswordNeedsUpgrade($user['password'])) {
  $upgradedHash = appPasswordHash($password);
  if (is_string($upgradedHash) && $upgradedHash !== '') {
    $upgradeStmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
    if ($upgradeStmt) {
      $upgradeStmt->bind_param('si', $upgradedHash, $userId);
      if (!$upgradeStmt->execute()) appLogError('No se pudo actualizar el formato de contraseña', $upgradeStmt->error);
    }
  }
}

session_regenerate_id(true);

$_SESSION['user_id'] = $user['id'];
$_SESSION['user'] = $user['username'];
$_SESSION['comercial'] = $user['comercial'];
$_SESSION['role'] = $user['role'] ?? 'user';
$_SESSION['last_activity'] = time();

unset($_SESSION['login_csrf_token']);

$sqlUpdate = "UPDATE users SET ultimo_login = NOW() WHERE id = ?";
$stmtUpdate = $conn->prepare($sqlUpdate);

if ($stmtUpdate) {
  $stmtUpdate->bind_param("i", $user['id']);
  $stmtUpdate->execute();
}

registerLoginAttempt($conn, $username, $userId, 'success', 'Login correcto');

header("Location: " . $redirect);
exit;
?>

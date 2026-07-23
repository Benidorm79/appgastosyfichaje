<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/functions.php";

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

header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

$currentPage = basename($_SERVER['PHP_SELF']);
$currentUri = $_SERVER['REQUEST_URI'] ?? 'home.php';

$publicPages = [
  'login.php',
  'auth.php',
  'recuperar_password.php',
  'cambiar_password.php'
];

if (!in_array($currentPage, $publicPages, true)) {
  if (!isset($_SESSION['user_id'])) {
    $redirect = sanitizeRedirect($currentUri);
    header("Location: /login.php?redirect=" . urlencode($redirect));
    exit;
  }

  $timeoutSeconds = defined('SESSION_TIMEOUT_SECONDS') ? SESSION_TIMEOUT_SECONDS : 900;

  if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeoutSeconds) {
    $timeoutUserId = $_SESSION['user_id'] ?? null;
    $timeoutUsername = $_SESSION['user'] ?? null;
    $timeoutComercial = $_SESSION['comercial'] ?? null;
    $timeoutRole = $_SESSION['role'] ?? null;

    require_once __DIR__ . "/db.php";
    require_once __DIR__ . "/includes/auditoria.php";

    auditoriaRegistrarSeguro($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'login',
      'entidad_id' => $timeoutUserId,
      'accion' => 'logout_automatico_inactividad',
      'descripcion' => 'Cierre automático de sesión por inactividad.',
      'usuario_id' => $timeoutUserId,
      'username' => $timeoutUsername,
      'comercial' => $timeoutComercial,
      'rol' => $timeoutRole,
      'estado_nuevo' => 'timeout',
      'datos' => [
        'timeout_seconds' => $timeoutSeconds,
        'last_activity' => $_SESSION['last_activity'] ?? null,
        'current_uri' => $currentUri
      ]
    ]);

    session_unset();
    session_destroy();

    $redirect = sanitizeRedirect($currentUri);
    header("Location: /login.php?timeout=1&redirect=" . urlencode($redirect));
    exit;
  }

  if (!defined('SESSION_NO_ACTIVITY_TOUCH') || SESSION_NO_ACTIVITY_TOUCH !== true) {
    $_SESSION['last_activity'] = time();
  }
}
?>
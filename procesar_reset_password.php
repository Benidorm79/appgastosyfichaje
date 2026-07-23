<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/auditoria.php";
require_once __DIR__ . "/includes/functions.php";

$token = $_POST['token'] ?? '';
$password_nueva = $_POST['password_nueva'] ?? '';
$password_confirmar = $_POST['password_confirmar'] ?? '';

if ($password_nueva !== $password_confirmar) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'usuario',
    'accion' => 'reset_password_no_coincide',
    'descripcion' => 'Intento de restablecer contraseña con confirmación no coincidente.',
    'estado_nuevo' => 'error'
  ]);

  header("Location: reset_password.php?token=" . urlencode($token) . "&nomatch=1");
  exit;
}

if (!appPasswordMeetsPolicy($password_nueva)) {
  header("Location: reset_password.php?token=" . urlencode($token) . "&weak=1");
  exit;
}

$tokenHash = hash('sha256', $token);
$sql = "SELECT id, username, comercial, role FROM users WHERE reset_token=? AND reset_expires > NOW() AND activo=1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $tokenHash);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'usuario',
    'accion' => 'reset_password_token_no_valido',
    'descripcion' => 'Intento de restablecer contraseña con token no válido, caducado o de usuario inactivo.',
    'estado_nuevo' => 'error'
  ]);

  header("Location: reset_password.php?error=1");
  exit;
}

$user = $result->fetch_assoc();
$password_hash = appPasswordHash($password_nueva);
if (!is_string($password_hash) || $password_hash === '') {
  header("Location: reset_password.php?token=" . urlencode($token) . "&error=1");
  exit;
}

$sql = "UPDATE users SET password=?, reset_token=NULL, reset_expires=NULL WHERE id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $password_hash, $user['id']);

if ($stmt->execute()) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'usuario',
    'entidad' => 'usuario',
    'entidad_id' => (int)$user['id'],
    'accion' => 'password_restablecida',
    'descripcion' => 'Contraseña restablecida correctamente mediante enlace de recuperación.',
    'usuario_id' => (int)$user['id'],
    'username' => $user['username'] ?? null,
    'comercial' => $user['comercial'] ?? null,
    'rol' => $user['role'] ?? null,
    'estado_nuevo' => 'restablecida',
    'datos' => [
      'origen' => 'reset_password'
    ]
  ]);

  header("Location: login.php?password_changed=1");
  exit;
}

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'sistema',
  'entidad' => 'usuario',
  'entidad_id' => (int)$user['id'],
  'accion' => 'error_reset_password',
  'descripcion' => 'No se pudo restablecer la contraseña del usuario.',
  'usuario_id' => (int)$user['id'],
  'username' => $user['username'] ?? null,
  'estado_nuevo' => 'error',
  'datos' => [
    'mysql_error' => $stmt->error
  ]
]);

header("Location: reset_password.php?token=" . urlencode($token) . "&error=1");
exit;
?>

<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/auditoria.php";
require_once __DIR__ . "/includes/functions.php";

$username = $_POST['username'] ?? '';
$password_actual = $_POST['password_actual'] ?? '';
$password_nueva = $_POST['password_nueva'] ?? '';
$password_confirmar = $_POST['password_confirmar'] ?? '';

if ($password_nueva !== $password_confirmar) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'usuario',
    'accion' => 'cambio_password_no_coincide',
    'descripcion' => 'Intento de cambio de contraseña con confirmación no coincidente.',
    'username' => $username,
    'estado_nuevo' => 'error'
  ]);

  header("Location: cambiar_password.php?nomatch=1");
  exit;
}

if (!appPasswordMeetsPolicy($password_nueva)) {
  header("Location: cambiar_password.php?weak=1");
  exit;
}

$sql = "SELECT id, username, comercial, role, password FROM users WHERE username=? AND activo=1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'usuario',
    'accion' => 'cambio_password_usuario_no_valido',
    'descripcion' => 'Intento de cambio de contraseña para un usuario inexistente o inactivo.',
    'username' => $username,
    'estado_nuevo' => 'error'
  ]);

  header("Location: cambiar_password.php?wrong=1");
  exit;
}

$user = $result->fetch_assoc();

if (!appPasswordVerify($password_actual, $user['password'])) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'usuario',
    'entidad_id' => (int)$user['id'],
    'accion' => 'cambio_password_actual_incorrecta',
    'descripcion' => 'Intento de cambio de contraseña con contraseña actual incorrecta.',
    'usuario_id' => (int)$user['id'],
    'username' => $user['username'] ?? $username,
    'comercial' => $user['comercial'] ?? null,
    'rol' => $user['role'] ?? null,
    'estado_nuevo' => 'error'
  ]);

  header("Location: cambiar_password.php?wrong=1");
  exit;
}

$password_nueva_hash = appPasswordHash($password_nueva);
if (!is_string($password_nueva_hash) || $password_nueva_hash === '') {
  header("Location: cambiar_password.php?error=1");
  exit;
}

$sql = "UPDATE users SET password=?, reset_token=NULL, reset_expires=NULL WHERE id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $password_nueva_hash, $user['id']);

if ($stmt->execute()) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'usuario',
    'entidad' => 'usuario',
    'entidad_id' => (int)$user['id'],
    'accion' => 'password_cambiada',
    'descripcion' => 'Contraseña cambiada correctamente por el usuario.',
    'usuario_id' => (int)$user['id'],
    'username' => $user['username'] ?? $username,
    'comercial' => $user['comercial'] ?? null,
    'rol' => $user['role'] ?? null,
    'estado_nuevo' => 'modificada',
    'datos' => [
      'origen' => 'cambio_password_usuario'
    ]
  ]);

  header("Location: cambiar_password.php?ok=1");
  exit;
}

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'sistema',
  'entidad' => 'usuario',
  'entidad_id' => (int)$user['id'],
  'accion' => 'error_cambiar_password',
  'descripcion' => 'No se pudo actualizar la contraseña del usuario.',
  'usuario_id' => (int)$user['id'],
  'username' => $user['username'] ?? $username,
  'estado_nuevo' => 'error',
  'datos' => [
    'mysql_error' => $stmt->error
  ]
]);

header("Location: cambiar_password.php?error=1");
exit;
?>

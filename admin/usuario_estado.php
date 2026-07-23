<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auditoria.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

function usuarioEstadoRedirect($type, $message) {
  header("Location: usuarios.php?type=" . urlencode($type) . "&msg=" . urlencode($message));
  exit;
}

function usuarioEstadoFetchUsuario($conn, $id) {
  $sql = "SELECT id, username, comercial, email, role, activo
          FROM users
          WHERE id = ?
          LIMIT 1";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return null;
  }

  $stmt->bind_param("i", $id);
  $stmt->execute();

  $result = $stmt->get_result();

  if (!$result) {
    return null;
  }

  return $result->fetch_assoc();
}

function usuarioEstadoAuditar($conn, $data = []) {
  if (!function_exists('auditoriaRegistrar')) {
    return;
  }

  try {
    auditoriaRegistrar($conn, $data);
  } catch (Throwable $e) {
    error_log("No se pudo registrar auditoría en usuario_estado.php: " . $e->getMessage());
  }
}

function usuarioEstadoTexto($activo) {
  return (int)$activo === 1 ? 'activo' : 'inactivo';
}

$id = intval($_GET['id'] ?? 0);
$estado = intval($_GET['estado'] ?? -1);
$rolSesion = $_SESSION['role'] ?? 'user';
$esMasterSesion = $rolSesion === 'master';
$usuarioSesionId = (int)($_SESSION['user_id'] ?? 0);

if ($id <= 0 || !in_array($estado, [0, 1], true)) {
  usuarioEstadoAuditar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'usuario',
    'entidad_id' => $id,
    'accion' => 'intento_cambio_estado_usuario_no_valido',
    'descripcion' => 'Intento de cambiar el estado de un usuario con parámetros no válidos.',
    'estado_nuevo' => (string)$estado,
    'datos' => [
      'get' => $_GET
    ]
  ]);

  usuarioEstadoRedirect('error', 'Datos de usuario no válidos');
}

if ($usuarioSesionId === $id) {
  usuarioEstadoAuditar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'usuario',
    'entidad_id' => $id,
    'accion' => 'intento_desactivar_usuario_actual',
    'descripcion' => 'Intento bloqueado de cambiar el estado del propio usuario.',
    'estado_nuevo' => (string)$estado,
    'datos' => [
      'usuario_id' => $id,
      'estado_solicitado' => $estado
    ]
  ]);

  usuarioEstadoRedirect('error', 'No puedes cambiar el estado de tu propio usuario');
}

$usuarioAnterior = usuarioEstadoFetchUsuario($conn, $id);

if (!$usuarioAnterior) {
  usuarioEstadoAuditar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'usuario',
    'entidad_id' => $id,
    'accion' => 'intento_cambio_estado_usuario_inexistente',
    'descripcion' => 'Intento de cambiar el estado de un usuario inexistente.',
    'estado_nuevo' => (string)$estado
  ]);

  usuarioEstadoRedirect('error', 'No se encontró el usuario');
}

$rolObjetivo = $usuarioAnterior['role'] ?? 'user';

if ($rolObjetivo === 'master') {
  usuarioEstadoAuditar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'usuario',
    'entidad_id' => $id,
    'accion' => 'intento_cambiar_estado_usuario_master',
    'descripcion' => 'Intento bloqueado de cambiar el estado del usuario Máster.',
    'estado_nuevo' => usuarioEstadoTexto($estado),
    'datos' => [
      'usuario' => $usuarioAnterior,
      'modificado_por' => $usuarioSesionId
    ]
  ]);

  usuarioEstadoRedirect('error', 'El usuario Máster no puede activarse ni desactivarse desde este listado.');
}

if (!$esMasterSesion && $rolObjetivo === 'admin') {
  usuarioEstadoAuditar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'usuario',
    'entidad_id' => $id,
    'accion' => 'intento_admin_cambiar_estado_admin',
    'descripcion' => 'Intento bloqueado de un administrador para cambiar el estado de otro administrador.',
    'estado_nuevo' => usuarioEstadoTexto($estado),
    'datos' => [
      'usuario' => $usuarioAnterior,
      'modificado_por' => $usuarioSesionId
    ]
  ]);

  usuarioEstadoRedirect('error', 'Solo el usuario Máster puede activar o desactivar administradores.');
}

$estadoAnterior = (int)($usuarioAnterior['activo'] ?? 0);

if ($estadoAnterior === $estado) {
  usuarioEstadoAuditar($conn, [
    'tipo_evento' => 'usuario',
    'entidad' => 'usuario',
    'entidad_id' => $id,
    'accion' => 'cambio_estado_usuario_sin_cambios',
    'descripcion' => 'Se solicitó cambiar el estado de un usuario, pero el usuario ya tenía ese estado.',
    'estado_anterior' => usuarioEstadoTexto($estadoAnterior),
    'estado_nuevo' => usuarioEstadoTexto($estado),
    'datos' => [
      'usuario' => $usuarioAnterior
    ]
  ]);

  usuarioEstadoRedirect('success', 'El usuario ya tenía ese estado');
}

$sqlUpdate = "UPDATE users
              SET activo = ?
              WHERE id = ?
              LIMIT 1";

$stmtUpdate = $conn->prepare($sqlUpdate);

if (!$stmtUpdate) {
  usuarioEstadoAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'usuario',
    'entidad_id' => $id,
    'accion' => 'error_preparar_cambio_estado_usuario',
    'descripcion' => 'No se pudo preparar la actualización del estado del usuario.',
    'estado_anterior' => usuarioEstadoTexto($estadoAnterior),
    'estado_nuevo' => usuarioEstadoTexto($estado),
    'datos' => [
      'mysql_error' => $conn->error
    ]
  ]);

  usuarioEstadoRedirect('error', 'No se pudo preparar el cambio de estado del usuario');
}

$stmtUpdate->bind_param("ii", $estado, $id);

if (!$stmtUpdate->execute()) {
  usuarioEstadoAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'usuario',
    'entidad_id' => $id,
    'accion' => 'error_ejecutar_cambio_estado_usuario',
    'descripcion' => 'No se pudo ejecutar la actualización del estado del usuario.',
    'estado_anterior' => usuarioEstadoTexto($estadoAnterior),
    'estado_nuevo' => usuarioEstadoTexto($estado),
    'datos' => [
      'mysql_error' => $stmtUpdate->error
    ]
  ]);

  usuarioEstadoRedirect('error', 'No se pudo actualizar el estado del usuario');
}

$accion = $estado === 1 ? 'usuario_activado' : 'usuario_desactivado';
$descripcion = $estado === 1
  ? 'Usuario activado desde el panel de administración.'
  : 'Usuario desactivado desde el panel de administración.';

usuarioEstadoAuditar($conn, [
  'tipo_evento' => 'usuario',
  'entidad' => 'usuario',
  'entidad_id' => $id,
  'accion' => $accion,
  'descripcion' => $descripcion,
  'estado_anterior' => usuarioEstadoTexto($estadoAnterior),
  'estado_nuevo' => usuarioEstadoTexto($estado),
  'datos' => [
    'usuario_id' => $id,
    'username' => $usuarioAnterior['username'] ?? '',
    'comercial' => $usuarioAnterior['comercial'] ?? '',
    'email' => $usuarioAnterior['email'] ?? '',
    'role' => $usuarioAnterior['role'] ?? '',
    'activo_anterior' => $estadoAnterior,
    'activo_nuevo' => $estado,
    'modificado_por' => $usuarioSesionId
  ]
]);

$mensaje = $estado === 1 ? 'Usuario activado correctamente' : 'Usuario desactivado correctamente';

usuarioEstadoRedirect('success', $mensaje);
?>

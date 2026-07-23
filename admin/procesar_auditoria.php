<?php
require_once __DIR__ . "/../admin_guard.php";
requireMasterAccess();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auditoria.php";

$id = intval($_POST['id'] ?? 0);
$estadoRevision = trim($_POST['estado_revision'] ?? '');
$notasRevision = trim($_POST['notas_revision'] ?? '');

$permitidos = ['normal', 'revisado', 'corregido', 'anulado'];

if ($id <= 0 || !in_array($estadoRevision, $permitidos, true)) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'auditoria',
    'entidad_id' => $id,
    'accion' => 'revision_auditoria_no_valida',
    'descripcion' => 'Intento de revisar evento de auditoría con datos no válidos.',
    'estado_nuevo' => 'error',
    'datos' => [
      'post' => $_POST
    ]
  ]);

  header("Location: auditoria.php?type=error&msg=" . urlencode("Datos de revisión no válidos"));
  exit;
}

$sqlEvento = "SELECT *
              FROM auditoria_eventos
              WHERE id = ?
              LIMIT 1";

$stmtEvento = $conn->prepare($sqlEvento);

if (!$stmtEvento) {
  header("Location: auditoria.php?type=error&msg=" . urlencode("No se pudo preparar la revisión del evento"));
  exit;
}

$stmtEvento->bind_param("i", $id);
$stmtEvento->execute();

$eventoAnterior = $stmtEvento->get_result()->fetch_assoc();

if (!$eventoAnterior) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'auditoria',
    'entidad_id' => $id,
    'accion' => 'revision_auditoria_evento_inexistente',
    'descripcion' => 'Intento de revisar un evento de auditoría inexistente.',
    'estado_nuevo' => 'error'
  ]);

  header("Location: auditoria.php?type=error&msg=" . urlencode("No se encontró el evento de auditoría"));
  exit;
}

$resultado = auditoriaActualizarRevision(
  $conn,
  $id,
  $estadoRevision,
  $notasRevision,
  $_SESSION['user_id'] ?? null
);

if (empty($resultado['ok'])) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'auditoria',
    'entidad_id' => $id,
    'accion' => 'error_revision_evento_auditoria',
    'descripcion' => 'No se pudo guardar la revisión de un evento de auditoría.',
    'estado_anterior' => $eventoAnterior['estado_revision'] ?? 'normal',
    'estado_nuevo' => $estadoRevision,
    'datos' => [
      'message' => $resultado['message'] ?? 'Error desconocido'
    ]
  ]);

  header("Location: editar_auditoria.php?id=" . urlencode((string)$id) . "&type=error&msg=" . urlencode($resultado['message'] ?? 'No se pudo actualizar la revisión'));
  exit;
}

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'auditoria',
  'entidad' => 'auditoria',
  'entidad_id' => $id,
  'accion' => 'revision_evento_auditoria',
  'descripcion' => 'Se revisó un evento de auditoría.',
  'estado_anterior' => $eventoAnterior['estado_revision'] ?? 'normal',
  'estado_nuevo' => $estadoRevision,
  'estado_revision' => $estadoRevision === 'normal' ? 'normal' : 'revisado',
  'datos' => [
    'evento_revisado_id' => $id,
    'tipo_evento_original' => $eventoAnterior['tipo_evento'] ?? '',
    'accion_original' => $eventoAnterior['accion'] ?? '',
    'notas_revision' => $notasRevision
  ]
]);

header("Location: auditoria.php?type=success&msg=" . urlencode("Evento de auditoría actualizado correctamente"));
exit;
?>

<?php
require "session.php";
include "db.php";
require_once "config.php";
require_once "includes/functions.php";
require_once "includes/auditoria.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

function normalizarMotivo($motivo) {
  $motivo = trim((string)$motivo);

  if ($motivo === 'Otro') {
    return 'Otros';
  }

  return $motivo;
}

function normalizarImporteEditar($importe) {
  $importe = trim((string)$importe);

  if ($importe === '') {
    return null;
  }

  $importe = str_replace(' ', '', $importe);

  if (strpos($importe, ',') !== false && strpos($importe, '.') !== false) {
    $importe = str_replace('.', '', $importe);
    $importe = str_replace(',', '.', $importe);
  } elseif (strpos($importe, ',') !== false) {
    $importe = str_replace(',', '.', $importe);
  }

  if (!is_numeric($importe)) {
    return false;
  }

  return number_format((float)$importe, 2, '.', '');
}

function redirectEditarError($id, $message) {
  header("Location: ver_gasto.php?id=" . urlencode((string)$id) . "&edit_error=1&msg=" . urlencode($message));
  exit;
}

$id = intval($_POST['id'] ?? 0);
$returnUrl = sanitizeRedirect($_POST['return'] ?? ('ver_gasto.php?id=' . $id));

$gastoAnterior = getGastoByIdForCurrentUser($conn, $id);

if (!$gastoAnterior || $gastoAnterior['deleted_at'] !== null || $gastoAnterior['estado'] === 'eliminado') {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'intento_editar_gasto_no_permitido',
    'descripcion' => 'Intento de editar un gasto inexistente, eliminado o sin permisos.',
    'estado_nuevo' => 'bloqueado'
  ]);

  die("Gasto no encontrado o sin permisos.");
}

$userIdGasto = (int)($gastoAnterior['user_id'] ?? 0);
$userIdSesion = (int)($_SESSION['user_id'] ?? 0);

if ($userIdGasto <= 0) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'error_identificar_propietario_gasto',
    'descripcion' => 'No se pudo identificar el usuario propietario del gasto al editar.',
    'estado_nuevo' => 'error'
  ]);

  redirectEditarError($id, "No se pudo identificar el usuario propietario del gasto.");
}

$viaje = trim($_POST['viaje'] ?? '');
$motivo = normalizarMotivo($_POST['motivo'] ?? '');
$comentarios = trim($_POST['comentarios'] ?? '');
$importe = normalizarImporteEditar($_POST['importe_detectado'] ?? '');
$fechaTicket = trim($_POST['fecha_ticket'] ?? '');
$fechaImputacion = trim($_POST['fecha_imputacion'] ?? '');
$motivoEdicion = trim($_POST['motivo_edicion'] ?? '');

if ($viaje === '') {
  redirectEditarError($id, "El viaje es obligatorio.");
}

if ($motivo === '') {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'intento_editar_gasto_motivo_vacio',
    'descripcion' => 'Intento de editar un gasto sin motivo.',
    'estado_nuevo' => 'error'
  ]);

  redirectEditarError($id, "El motivo es obligatorio.");
}

if ($motivoEdicion === '') {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'intento_editar_gasto_sin_motivo_edicion',
    'descripcion' => 'Intento de editar un gasto sin indicar motivo de edición.',
    'estado_nuevo' => 'error'
  ]);

  redirectEditarError($id, "Debes indicar el motivo de edición.");
}

if ($importe === false) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'intento_editar_gasto_importe_no_valido',
    'descripcion' => 'Intento de editar un gasto con importe no válido.',
    'estado_nuevo' => 'error',
    'datos' => [
      'importe_recibido' => $_POST['importe_detectado'] ?? ''
    ]
  ]);

  redirectEditarError($id, "El importe no es válido.");
}

if ($fechaTicket === '') {
  $fechaTicket = null;
}

if ($fechaImputacion === '') {
  $fechaImputacion = null;
}

$fechaImputacionAnterior = $gastoAnterior['fecha_imputacion'] ?? null;
$fechaTicketAnterior = $gastoAnterior['fecha_ticket'] ?? null;
$createdAtAnterior = $gastoAnterior['created_at'] ?? null;

$fechaPeriodoAnterior = getFechaPeriodoGasto($fechaImputacionAnterior, $fechaTicketAnterior, $createdAtAnterior);
$fechaPeriodoNueva = getFechaPeriodoGasto($fechaImputacion, $fechaTicket, $createdAtAnterior);

try {
  if ($fechaPeriodoAnterior !== null) {
    bloquearSiPeriodoCerrado($conn, $userIdGasto, $fechaPeriodoAnterior);
  }

  if ($fechaPeriodoNueva !== null) {
    bloquearSiPeriodoCerrado($conn, $userIdGasto, $fechaPeriodoNueva);
  }
} catch (Exception $e) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'intento_editar_gasto_periodo_cerrado',
    'descripcion' => 'Intento bloqueado de editar un gasto perteneciente a un periodo cerrado.',
    'estado_anterior' => $gastoAnterior['estado'] ?? null,
    'estado_nuevo' => 'bloqueado',
    'datos' => [
      'mensaje' => $e->getMessage(),
      'fecha_periodo_anterior' => $fechaPeriodoAnterior,
      'fecha_periodo_nueva' => $fechaPeriodoNueva
    ]
  ]);

  redirectEditarError($id, $e->getMessage());
}

$duplicados = detectarGastosDuplicados($conn, $userIdGasto, $importe, $fechaTicket, $motivo, $viaje, $id);

if (count($duplicados) > 0) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'gasto',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'posible_duplicado_al_editar_gasto',
    'descripcion' => 'Se detectaron posibles gastos duplicados al editar un gasto.',
    'estado_anterior' => $gastoAnterior['estado'] ?? null,
    'estado_nuevo' => $gastoAnterior['estado'] ?? null,
    'datos' => [
      'gasto_uid' => $gastoAnterior['gasto_uid'] ?? '',
      'duplicados' => $duplicados,
      'importe' => $importe,
      'fecha_ticket' => $fechaTicket,
      'motivo' => $motivo,
      'viaje' => $viaje
    ]
  ]);
}

$ticket = getTicketPrincipalByGasto($conn, $gastoAnterior['id'], $gastoAnterior['gasto_uid']);

$gastoPropuesto = $gastoAnterior;
$gastoPropuesto['viaje'] = $viaje;
$gastoPropuesto['motivo'] = $motivo;
$gastoPropuesto['comentarios'] = $comentarios;
$gastoPropuesto['importe_detectado'] = $importe;
$gastoPropuesto['fecha_ticket'] = $fechaTicket;
$gastoPropuesto['fecha_imputacion'] = $fechaImputacion;
$gastoPropuesto['estado'] = 'editado';

$payload = buildGastoSyncPayload($gastoPropuesto, $ticket, 'editar');
$payload['user_id'] = $userIdGasto;
$payload['session_user_id'] = $userIdSesion;

$payload['periodo_control'] = [
  'user_id' => $userIdGasto,
  'fecha_periodo_anterior' => $fechaPeriodoAnterior,
  'fecha_periodo_nueva' => $fechaPeriodoNueva,
  'periodo_anterior_cerrado' => false,
  'periodo_nuevo_cerrado' => false
];

$payload['valores_anteriores'] = [
  'user_id' => $userIdGasto,
  'viaje' => $gastoAnterior['viaje'],
  'motivo' => $gastoAnterior['motivo'] === 'Otro' ? 'Otros' : $gastoAnterior['motivo'],
  'comentarios' => $gastoAnterior['comentarios'],
  'importe_detectado' => $gastoAnterior['importe_detectado'],
  'fecha_ticket' => $gastoAnterior['fecha_ticket'],
  'fecha_imputacion' => $gastoAnterior['fecha_imputacion'] ?? null
];

$payload['valores_nuevos'] = [
  'user_id' => $userIdGasto,
  'viaje' => $viaje,
  'motivo' => $motivo,
  'comentarios' => $comentarios,
  'importe_detectado' => $importe,
  'fecha_ticket' => $fechaTicket,
  'fecha_imputacion' => $fechaImputacion
];

$payload['motivo_edicion'] = $motivoEdicion;
$payload['posibles_duplicados'] = $duplicados;

$makeResult = callMakeWebhook(MAKE_WEBHOOK_EDITAR_GASTO, $payload);
$makeResponse = json_encode($makeResult, JSON_UNESCAPED_UNICODE);

if ($makeResponse === false) {
  $makeResponse = 'No se pudo guardar el detalle de la actualización.';
}

if (!$makeResult['ok']) {
  $sql = "UPDATE gastos
          SET sync_status = 'error_sync',
              make_update_response = ?
          WHERE id = ?";

  $stmt = $conn->prepare($sql);

  if ($stmt) {
    $stmt->bind_param("si", $makeResponse, $id);
    $stmt->execute();
  }

  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'gasto',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'error_make_editar_gasto',
    'descripcion' => 'No se pudo completar la actualización del gasto.',
    'estado_anterior' => $gastoAnterior['sync_status'] ?? null,
    'estado_nuevo' => 'error_sync',
    'datos' => [
      'gasto_uid' => $gastoAnterior['gasto_uid'] ?? '',
      'valores_anteriores' => $payload['valores_anteriores'],
      'valores_nuevos' => $payload['valores_nuevos'],
      'motivo_edicion' => $motivoEdicion,
      'make_result' => $makeResult
    ]
  ]);

  header("Location: ver_gasto.php?id=" . urlencode((string)$id) . "&edit_error=1&msg=" . urlencode('No se ha podido guardar el cambio. Inténtalo de nuevo.'));
  exit;
}

$localNow = date('Y-m-d H:i:s');
$fechaImputacionExiste = columnExists($conn, 'gastos', 'fecha_imputacion');

if ($fechaImputacionExiste) {
  if (isAdmin()) {
    $sql = "UPDATE gastos
            SET viaje = ?,
                motivo = ?,
                comentarios = ?,
                importe_detectado = ?,
                fecha_ticket = ?,
                fecha_imputacion = ?,
                estado = 'editado',
                sync_status = 'sincronizado',
                last_sync_at = ?,
                make_update_response = ?,
                updated_at = ?
            WHERE id = ?";
  } else {
    $sql = "UPDATE gastos
            SET viaje = ?,
                motivo = ?,
                comentarios = ?,
                importe_detectado = ?,
                fecha_ticket = ?,
                fecha_imputacion = ?,
                estado = 'editado',
                sync_status = 'sincronizado',
                last_sync_at = ?,
                make_update_response = ?,
                updated_at = ?
            WHERE id = ? AND user_id = ?";
  }
} else {
  if (isAdmin()) {
    $sql = "UPDATE gastos
            SET viaje = ?,
                motivo = ?,
                comentarios = ?,
                importe_detectado = ?,
                fecha_ticket = ?,
                estado = 'editado',
                sync_status = 'sincronizado',
                last_sync_at = ?,
                make_update_response = ?,
                updated_at = ?
            WHERE id = ?";
  } else {
    $sql = "UPDATE gastos
            SET viaje = ?,
                motivo = ?,
                comentarios = ?,
                importe_detectado = ?,
                fecha_ticket = ?,
                estado = 'editado',
                sync_status = 'sincronizado',
                last_sync_at = ?,
                make_update_response = ?,
                updated_at = ?
            WHERE id = ? AND user_id = ?";
  }
}

$stmt = $conn->prepare($sql);

if (!$stmt) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'error_preparar_editar_gasto',
    'descripcion' => 'Error SQL preparando edición de gasto.',
    'estado_nuevo' => 'error',
    'datos' => ['mysql_error' => $conn->error]
  ]);

  appLogError('No se pudo preparar la edición del gasto', $conn->error);
  die(appPublicError());
}

if ($fechaImputacionExiste) {
  if (isAdmin()) {
    $stmt->bind_param("sssssssssi", $viaje, $motivo, $comentarios, $importe, $fechaTicket, $fechaImputacion, $localNow, $makeResponse, $localNow, $id);
  } else {
    $stmt->bind_param("sssssssssii", $viaje, $motivo, $comentarios, $importe, $fechaTicket, $fechaImputacion, $localNow, $makeResponse, $localNow, $id, $userIdSesion);
  }
} else {
  if (isAdmin()) {
    $stmt->bind_param("ssssssssi", $viaje, $motivo, $comentarios, $importe, $fechaTicket, $localNow, $makeResponse, $localNow, $id);
  } else {
    $stmt->bind_param("ssssssssii", $viaje, $motivo, $comentarios, $importe, $fechaTicket, $localNow, $makeResponse, $localNow, $id, $userIdSesion);
  }
}

if (!$stmt->execute()) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'error_ejecutar_editar_gasto',
    'descripcion' => 'Error SQL ejecutando edición de gasto.',
    'estado_nuevo' => 'error',
    'datos' => ['mysql_error' => $stmt->error]
  ]);

  appLogError('No se pudo actualizar el gasto', $stmt->error);
  die(appPublicError());
}

$nuevoAudit = [
  'viaje' => $viaje,
  'motivo' => $motivo,
  'comentarios' => $comentarios,
  'importe_detectado' => $importe,
  'fecha_ticket' => $fechaTicket,
  'fecha_imputacion' => $fechaImputacion,
  'estado' => 'editado'
];

$anteriorAudit = [
  'viaje' => $gastoAnterior['viaje'] ?? null,
  'motivo' => $gastoAnterior['motivo'] ?? null,
  'comentarios' => $gastoAnterior['comentarios'] ?? null,
  'importe_detectado' => $gastoAnterior['importe_detectado'] ?? null,
  'fecha_ticket' => $gastoAnterior['fecha_ticket'] ?? null,
  'fecha_imputacion' => $gastoAnterior['fecha_imputacion'] ?? null,
  'estado' => $gastoAnterior['estado'] ?? null
];

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'gasto',
  'entidad' => 'gasto',
  'entidad_id' => $id,
  'accion' => 'gasto_editado',
  'descripcion' => 'Gasto editado desde la aplicación.',
  'estado_anterior' => $gastoAnterior['estado'] ?? null,
  'estado_nuevo' => 'editado',
  'datos' => [
    'gasto_uid' => $gastoAnterior['gasto_uid'] ?? '',
    'motivo_edicion' => $motivoEdicion,
    'cambios' => auditoriaCalcularCambios($anteriorAudit, $nuevoAudit),
    'fecha_periodo_anterior' => $fechaPeriodoAnterior,
    'fecha_periodo_nueva' => $fechaPeriodoNueva,
    'posibles_duplicados' => $duplicados,
    'make_result' => $makeResult
  ]
]);

header("Location: ver_gasto.php?id=" . urlencode((string)$id) . "&updated=1");
exit;
?>

<?php
require "session.php";
include "db.php";
require_once "includes/functions.php";
require_once "includes/auditoria.php";

$id = intval($_GET['id'] ?? 0);

$gasto = getGastoByIdForCurrentUser($conn, $id);

if (!$gasto || $gasto['deleted_at'] !== null || $gasto['estado'] === 'eliminado') {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'intento_eliminar_gasto_no_permitido',
    'descripcion' => 'Intento de eliminar un gasto inexistente, eliminado o sin permisos.',
    'estado_nuevo' => 'bloqueado'
  ]);

  die("Gasto no encontrado o sin permisos.");
}

$ticket = getTicketPrincipalByGasto($conn, $gasto['id'], $gasto['gasto_uid']);

$payload = buildGastoSyncPayload($gasto, $ticket, 'eliminar');

$makeResult = callMakeWebhook(MAKE_WEBHOOK_ELIMINAR_GASTO, $payload);

$makeResponse = json_encode($makeResult, JSON_UNESCAPED_UNICODE);

if (!$makeResult['ok']) {
  $sql = "UPDATE gastos
          SET sync_status = 'error_eliminar',
              make_update_response = ?
          WHERE id = ?";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("si", $makeResponse, $id);
  $stmt->execute();

  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'gasto',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'error_eliminar_gasto_make',
    'descripcion' => 'No se pudo completar la eliminación del gasto.',
    'estado_anterior' => $gasto['estado'] ?? null,
    'estado_nuevo' => 'error_eliminar',
    'datos' => [
      'gasto_uid' => $gasto['gasto_uid'] ?? '',
      'make_result' => $makeResult
    ]
  ]);

  header("Location: ver_gasto.php?id=" . $id . "&delete_error=1");
  exit;
}

$userId = $_SESSION['user_id'];
$syncStatus = $makeResult['skipped'] ? 'eliminado_webhook_no_configurado' : 'eliminado_sincronizado';

$sql = "UPDATE gastos
        SET estado = 'eliminado',
            deleted_at = NOW(),
            deleted_by = ?,
            sync_status = ?,
            last_sync_at = NOW(),
            make_update_response = ?
        WHERE id = ? AND user_id = ?";

$stmt = $conn->prepare($sql);

if (!$stmt) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'error_preparar_eliminar_gasto',
    'descripcion' => 'Error SQL preparando eliminación lógica de gasto.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mysql_error' => $conn->error
    ]
  ]);

  appLogError('No se pudo preparar la eliminación del gasto', $conn->error);
  die(appPublicError());
}

$stmt->bind_param("issii", $userId, $syncStatus, $makeResponse, $id, $userId);

if (!$stmt->execute()) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'error_ejecutar_eliminar_gasto',
    'descripcion' => 'Error SQL ejecutando eliminación lógica de gasto.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mysql_error' => $stmt->error
    ]
  ]);

  appLogError('No se pudo eliminar el gasto', $stmt->error);
  die(appPublicError());
}

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'gasto',
  'entidad' => 'gasto',
  'entidad_id' => $id,
  'accion' => 'gasto_eliminado',
  'descripcion' => 'Gasto eliminado desde la aplicación.',
  'estado_anterior' => $gasto['estado'] ?? null,
  'estado_nuevo' => 'eliminado',
  'datos' => [
    'gasto_uid' => $gasto['gasto_uid'] ?? '',
    'importe_detectado' => $gasto['importe_detectado'] ?? null,
    'fecha_ticket' => $gasto['fecha_ticket'] ?? null,
    'fecha_imputacion' => $gasto['fecha_imputacion'] ?? null,
    'sync_status' => $syncStatus,
    'make_result' => $makeResult
  ]
]);

header("Location: gestionar_gastos.php?deleted=1");
exit;
?>

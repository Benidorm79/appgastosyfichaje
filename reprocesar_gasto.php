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
    'accion' => 'intento_reprocesar_gasto_no_permitido',
    'descripcion' => 'Intento de resincronizar un gasto inexistente, eliminado o sin permisos.',
    'estado_nuevo' => 'bloqueado'
  ]);

  die("Gasto no encontrado o sin permisos.");
}

$ticket = getTicketPrincipalByGasto($conn, $gasto['id'], $gasto['gasto_uid']);

$payload = buildGastoSyncPayload($gasto, $ticket, 'resincronizar');

$makeResult = callMakeWebhook(MAKE_WEBHOOK_RESINCRONIZAR_GASTO ?: MAKE_WEBHOOK_EDITAR_GASTO, $payload);

$makeResponse = json_encode($makeResult, JSON_UNESCAPED_UNICODE);

if ($makeResult['ok']) {
  $syncStatus = $makeResult['skipped'] ? 'webhook_no_configurado' : 'sincronizado';

  $sql = "UPDATE gastos
          SET sync_status = ?,
              last_sync_at = NOW(),
              make_update_response = ?
          WHERE id = ?";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ssi", $syncStatus, $makeResponse, $id);
  $stmt->execute();

  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'gasto',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'gasto_resincronizado',
    'descripcion' => 'Actualización manual del gasto completada.',
    'estado_anterior' => $gasto['sync_status'] ?? null,
    'estado_nuevo' => $syncStatus,
    'datos' => [
      'gasto_uid' => $gasto['gasto_uid'] ?? '',
      'make_result' => $makeResult
    ]
  ]);

  header("Location: ver_gasto.php?id=" . $id . "&resync=1");
  exit;
}

$sql = "UPDATE gastos
        SET sync_status = 'error_sync',
            make_update_response = ?
        WHERE id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $makeResponse, $id);
$stmt->execute();

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'gasto',
  'entidad' => 'gasto',
  'entidad_id' => $id,
  'accion' => 'error_resincronizar_gasto',
  'descripcion' => 'No se pudo completar la actualización manual del gasto.',
  'estado_anterior' => $gasto['sync_status'] ?? null,
  'estado_nuevo' => 'error_sync',
  'datos' => [
    'gasto_uid' => $gasto['gasto_uid'] ?? '',
    'make_result' => $makeResult
  ]
]);

header("Location: ver_gasto.php?id=" . $id . "&sync_error=1");
exit;
?>

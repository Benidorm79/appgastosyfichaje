<?php
require "session.php";
include "db.php";
require_once "includes/functions.php";
require_once "includes/auditoria.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

$id = intval($_GET['id'] ?? 0);
$gasto = getGastoByIdForCurrentUser($conn, $id);

if (!$gasto || $gasto['deleted_at'] !== null || $gasto['estado'] === 'eliminado') {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'intento_descartar_gasto_error_no_permitido',
    'descripcion' => 'Intento de descartar un gasto inexistente, eliminado o sin permisos.',
    'estado_nuevo' => 'bloqueado'
  ]);

  die("Gasto no encontrado o sin permisos.");
}

$fechaPeriodo = getFechaPeriodoGasto($gasto['fecha_imputacion'] ?? null, $gasto['fecha_ticket'] ?? null, $gasto['created_at'] ?? null);

try {
  if ($fechaPeriodo !== null) {
    bloquearSiPeriodoCerrado($conn, (int)$gasto['user_id'], $fechaPeriodo);
  }
} catch (Exception $e) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'intento_descartar_gasto_periodo_cerrado',
    'descripcion' => 'Intento bloqueado de descartar un gasto de un periodo cerrado.',
    'estado_anterior' => $gasto['estado'] ?? null,
    'estado_nuevo' => 'bloqueado',
    'datos' => ['mensaje' => $e->getMessage(), 'fecha_periodo' => $fechaPeriodo]
  ]);

  header("Location: gestionar_gastos.php?type=error&msg=" . urlencode($e->getMessage()));
  exit;
}

$localNow = date('Y-m-d H:i:s');

$sql = "UPDATE gastos
        SET estado = 'eliminado',
            deleted_at = ?,
            sync_status = 'descartado',
            updated_at = ?
        WHERE id = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
  header("Location: gestionar_gastos.php?type=error&msg=" . urlencode("No se pudo preparar el descarte del gasto"));
  exit;
}

$stmt->bind_param("ssi", $localNow, $localNow, $id);
$stmt->execute();

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'gasto',
  'entidad' => 'gasto',
  'entidad_id' => $id,
  'accion' => 'gasto_error_descartado',
  'descripcion' => 'Gasto con error descartado lógicamente desde la gestión de gastos.',
  'estado_anterior' => $gasto['estado'] ?? null,
  'estado_nuevo' => 'eliminado',
  'datos' => [
    'gasto_uid' => $gasto['gasto_uid'] ?? '',
    'sync_status_anterior' => $gasto['sync_status'] ?? '',
    'descartado_por' => (int)($_SESSION['user_id'] ?? 0),
    'descartado_at' => $localNow
  ]
]);

header("Location: gestionar_gastos.php?type=success&msg=" . urlencode("Gasto descartado correctamente"));
exit;
?>

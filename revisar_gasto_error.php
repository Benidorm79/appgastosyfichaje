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
    'accion' => 'intento_marcar_gasto_revisado_no_permitido',
    'descripcion' => 'Intento de marcar como revisado un gasto inexistente, eliminado o sin permisos.',
    'estado_nuevo' => 'bloqueado'
  ]);

  die("Gasto no encontrado o sin permisos.");
}

$localNow = date('Y-m-d H:i:s');

$sql = "UPDATE gastos
        SET sync_status = 'revisado',
            updated_at = ?
        WHERE id = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
  header("Location: gestionar_gastos.php?type=error&msg=" . urlencode("No se pudo preparar la revisión del gasto"));
  exit;
}

$stmt->bind_param("si", $localNow, $id);
$stmt->execute();

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'gasto',
  'entidad' => 'gasto',
  'entidad_id' => $id,
  'accion' => 'gasto_error_marcado_revisado',
  'descripcion' => 'Gasto con error marcado como revisado manualmente.',
  'estado_anterior' => $gasto['sync_status'] ?? null,
  'estado_nuevo' => 'revisado',
  'datos' => [
    'gasto_uid' => $gasto['gasto_uid'] ?? '',
    'estado_gasto' => $gasto['estado'] ?? '',
    'revisado_por' => (int)($_SESSION['user_id'] ?? 0),
    'revisado_at' => $localNow
  ]
]);

header("Location: gestionar_gastos.php?type=success&msg=" . urlencode("Gasto marcado como revisado"));
exit;
?>

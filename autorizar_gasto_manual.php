<?php
require "session.php";
include "db.php";
require_once "config.php";
require_once "includes/functions.php";
require_once "includes/auditoria.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

function autorizarGastoRedirect($return, $type, $message) {
  $return = sanitizeRedirect($return ?: 'gestionar_gastos.php');
  $separator = strpos($return, '?') === false ? '?' : '&';

  header("Location: " . $return . $separator . "type=" . urlencode($type) . "&msg=" . urlencode($message));
  exit;
}

$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$return = $_GET['return'] ?? $_POST['return'] ?? ('ver_gasto.php?id=' . $id);

if (!isAdmin()) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'intento_autorizar_gasto_manual_sin_permiso',
    'descripcion' => 'Intento bloqueado de autorizar un gasto manual sin justificante sin permisos de Administración.',
    'estado_nuevo' => 'bloqueado'
  ]);

  autorizarGastoRedirect($return, 'error', 'No tienes permisos para autorizar este gasto.');
}

$gasto = getGastoByIdForCurrentUser($conn, $id);

if (!$gasto || $gasto['deleted_at'] !== null || $gasto['estado'] === 'eliminado') {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'intento_autorizar_gasto_manual_no_encontrado',
    'descripcion' => 'Intento de autorizar un gasto inexistente, eliminado o sin permisos.',
    'estado_nuevo' => 'bloqueado'
  ]);

  autorizarGastoRedirect($return, 'error', 'Gasto no encontrado o sin permisos.');
}

$origen = trim((string)($gasto['origen'] ?? ''));

$sqlTickets = "SELECT COUNT(*) AS total FROM gasto_tickets WHERE gasto_id = ? AND gasto_uid = ?";
$stmtTickets = $conn->prepare($sqlTickets);
$totalTickets = 0;

if ($stmtTickets) {
  $stmtTickets->bind_param("is", $id, $gasto['gasto_uid']);
  $stmtTickets->execute();
  $resultTickets = $stmtTickets->get_result();
  $rowTickets = $resultTickets ? $resultTickets->fetch_assoc() : null;
  $totalTickets = (int)($rowTickets['total'] ?? 0);
}

if ($totalTickets > 0 || $origen === 'ticket') {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'intento_autorizar_gasto_con_justificante',
    'descripcion' => 'Intento bloqueado de autorizar como gasto sin justificante un gasto con ticket asociado.',
    'estado_anterior' => $gasto['estado'] ?? '',
    'estado_nuevo' => 'bloqueado',
    'datos' => [
      'gasto_uid' => $gasto['gasto_uid'] ?? '',
      'origen' => $origen,
      'total_tickets' => $totalTickets
    ]
  ]);

  autorizarGastoRedirect($return, 'error', 'Solo se pueden autorizar por esta vía los gastos sin justificante.');
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
    'accion' => 'intento_autorizar_gasto_periodo_cerrado',
    'descripcion' => 'Intento bloqueado de autorizar un gasto manual en un periodo cerrado.',
    'estado_anterior' => $gasto['estado'] ?? '',
    'estado_nuevo' => 'bloqueado',
    'datos' => [
      'mensaje' => $e->getMessage(),
      'fecha_periodo' => $fechaPeriodo
    ]
  ]);

  autorizarGastoRedirect($return, 'error', $e->getMessage());
}

$localNow = date('Y-m-d H:i:s');
$comentarioAutorizacion = 'Gasto sin justificante autorizado por administración el ' . $localNow . '.';
$comentariosActuales = trim((string)($gasto['comentarios'] ?? ''));
$comentariosNuevos = $comentariosActuales !== '' ? $comentariosActuales . "\n\n" . $comentarioAutorizacion : $comentarioAutorizacion;

$sql = "UPDATE gastos
        SET estado = 'procesado',
            sync_status = 'sincronizado',
            comentarios = ?,
            updated_at = ?
        WHERE id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
  $sql = "UPDATE gastos
          SET estado = 'procesado',
              comentarios = ?,
              updated_at = ?
          WHERE id = ?
          LIMIT 1";
  $stmt = $conn->prepare($sql);
}

if (!$stmt) {
  autorizarGastoRedirect($return, 'error', 'No se pudo preparar la autorización del gasto.');
}

$stmt->bind_param("ssi", $comentariosNuevos, $localNow, $id);

if (!$stmt->execute()) {
  autorizarGastoRedirect($return, 'error', 'No se pudo autorizar el gasto.');
}

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'gasto',
  'entidad' => 'gasto',
  'entidad_id' => $id,
  'accion' => 'gasto_autorizado_sin_justificante',
  'descripcion' => 'Gasto sin justificante autorizado por Admin/Máster.',
  'estado_anterior' => $gasto['estado'] ?? '',
  'estado_nuevo' => 'procesado',
  'datos' => [
    'gasto_uid' => $gasto['gasto_uid'] ?? '',
    'sync_status_anterior' => $gasto['sync_status'] ?? '',
    'sync_status_nuevo' => 'sincronizado',
    'autorizado_por' => (int)($_SESSION['user_id'] ?? 0),
    'autorizado_at' => $localNow
  ]
]);

autorizarGastoRedirect($return, 'success', 'Gasto autorizado correctamente.');
?>

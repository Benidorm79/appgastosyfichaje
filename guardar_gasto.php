<?php
require "session.php";
include "db.php";
require_once "includes/auditoria.php";

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents("php://input");
$payload = json_decode($raw, true);

if (!$payload) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'accion' => 'guardar_gasto_json_invalido',
    'descripcion' => 'Intento de guardar gasto con JSON inválido.',
    'estado_nuevo' => 'error',
    'datos' => [
      'raw' => $raw,
      'json_error' => json_last_error_msg()
    ]
  ]);

  echo json_encode(["ok" => false, "message" => "Revisa los datos enviados."]);
  exit;
}

function normalizarMotivo($motivo) {
  $motivo = trim($motivo);

  if ($motivo === 'Otro') {
    return 'Otros';
  }

  return $motivo;
}

$user_id   = $_SESSION['user_id'] ?? null;
$username  = $_SESSION['user'] ?? '';
$comercial = $_SESSION['comercial'] ?? '';

$viaje       = trim($payload['viaje'] ?? '');
$motivo      = normalizarMotivo($payload['motivo'] ?? '');
$comentarios = trim($payload['comentarios'] ?? '');

if (!$user_id || $username === '' || $comercial === '' || $viaje === '' || $motivo === '') {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'accion' => 'guardar_gasto_datos_obligatorios_incompletos',
    'descripcion' => 'Intento de guardar gasto con datos obligatorios incompletos.',
    'estado_nuevo' => 'error',
    'datos' => [
      'user_id' => $user_id,
      'username' => $username,
      'comercial' => $comercial,
      'viaje' => $viaje,
      'motivo' => $motivo
    ]
  ]);

  echo json_encode(["ok" => false, "message" => "Faltan datos obligatorios"]);
  exit;
}

$sql = "INSERT INTO gastos 
(user_id, username, comercial, viaje, motivo, comentarios, estado)
VALUES (?, ?, ?, ?, ?, ?, 'pendiente')";

$stmt = $conn->prepare($sql);

if (!$stmt) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'gasto',
    'accion' => 'error_preparar_guardar_gasto',
    'descripcion' => 'Error SQL preparando guardado de gasto.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mysql_error' => $conn->error
    ]
  ]);

  appLogError('No se pudo preparar el guardado del gasto', $conn->error);
  echo json_encode(["ok" => false, "message" => appPublicError()]);
  exit;
}

$stmt->bind_param("isssss", $user_id, $username, $comercial, $viaje, $motivo, $comentarios);

if (!$stmt->execute()) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'gasto',
    'accion' => 'error_ejecutar_guardar_gasto',
    'descripcion' => 'Error SQL creando gasto.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mysql_error' => $stmt->error
    ]
  ]);

  appLogError('No se pudo crear el gasto', $stmt->error);
  echo json_encode(["ok" => false, "message" => appPublicError()]);
  exit;
}

$gasto_id = $stmt->insert_id;
$gasto_uid = "GASTO-" . date("Ymd") . "-" . str_pad($gasto_id, 6, "0", STR_PAD_LEFT);

$sql = "UPDATE gastos SET gasto_uid=? WHERE id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $gasto_uid, $gasto_id);
$stmt->execute();

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'gasto',
  'entidad' => 'gasto',
  'entidad_id' => (int)$gasto_id,
  'accion' => 'gasto_creado_pendiente',
  'descripcion' => 'Gasto creado en estado pendiente.',
  'estado_nuevo' => 'pendiente',
  'datos' => [
    'gasto_uid' => $gasto_uid,
    'viaje' => $viaje,
    'motivo' => $motivo,
    'comentarios' => $comentarios
  ]
]);

echo json_encode([
  "ok" => true,
  "id" => $gasto_id,
  "gasto_uid" => $gasto_uid,
  "username" => $username,
  "comercial" => $comercial
], JSON_UNESCAPED_UNICODE);

exit;
?>

<?php
require "session.php";
include "db.php";
require_once "includes/auditoria.php";

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'accion' => 'descartar_gasto_json_invalido',
    'descripcion' => 'Intento de descartar gasto pendiente con JSON inválido.',
    'estado_nuevo' => 'error',
    'datos' => [
      'raw' => $raw,
      'json_error' => json_last_error_msg()
    ]
  ]);

  echo json_encode([
    "ok" => false,
    "message" => "Revisa los datos enviados."
  ]);
  exit;
}

$id = intval($data['registro_id'] ?? 0);
$gasto_uid = trim($data['gasto_uid'] ?? '');
$user_id = $_SESSION['user_id'] ?? 0;

if ($id <= 0 || $gasto_uid === '' || $user_id <= 0) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'descartar_gasto_datos_incompletos',
    'descripcion' => 'Intento de descartar gasto pendiente con datos incompletos.',
    'estado_nuevo' => 'error',
    'datos' => $data
  ]);

  echo json_encode([
    "ok" => false,
    "message" => "Faltan datos para descartar el gasto"
  ]);
  exit;
}

$sql = "DELETE FROM gastos
        WHERE id = ?
          AND gasto_uid = ?
          AND user_id = ?
          AND estado = 'pendiente'";

$stmt = $conn->prepare($sql);

if (!$stmt) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'gasto',
    'entidad_id' => $id,
    'accion' => 'error_preparar_descartar_gasto_pendiente',
    'descripcion' => 'Error SQL preparando descarte de gasto pendiente.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mysql_error' => $conn->error,
      'gasto_uid' => $gasto_uid
    ]
  ]);

  echo json_encode([
    "ok" => false,
    "message" => appPublicError()
  ]);
  exit;
}

$stmt->bind_param("isi", $id, $gasto_uid, $user_id);
$stmt->execute();

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'gasto',
  'entidad' => 'gasto',
  'entidad_id' => $id,
  'accion' => 'gasto_pendiente_descartado',
  'descripcion' => 'Gasto pendiente descartado desde la aplicación.',
  'estado_anterior' => 'pendiente',
  'estado_nuevo' => 'descartado',
  'datos' => [
    'gasto_uid' => $gasto_uid,
    'deleted_rows' => (int)$stmt->affected_rows
  ]
]);

echo json_encode([
  "ok" => true,
  "message" => "Gasto pendiente descartado",
  "deleted_rows" => $stmt->affected_rows
], JSON_UNESCAPED_UNICODE);

exit;
?>

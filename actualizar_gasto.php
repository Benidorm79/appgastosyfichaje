<?php
require "session.php";
include "db.php";
require_once "config.php";
require_once "includes/functions.php";
require_once "includes/auditoria.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

header('Content-Type: application/json; charset=utf-8');

function pickValue($data, $keys, $default = null) {
  foreach ($keys as $key) {
    if (isset($data[$key]) && $data[$key] !== '') {
      return $data[$key];
    }
  }

  return $default;
}

function actualizarGastoColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);

  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

  return $result && $result->num_rows > 0;
}

function normalizarImporteActualizar($valor) {
  if ($valor === null || $valor === '') {
    return null;
  }

  $valor = trim((string)$valor);
  $valor = str_replace(' ', '', $valor);

  if (strpos($valor, ',') !== false && strpos($valor, '.') !== false) {
    $valor = str_replace('.', '', $valor);
    $valor = str_replace(',', '.', $valor);
  } elseif (strpos($valor, ',') !== false) {
    $valor = str_replace(',', '.', $valor);
  }

  if (!is_numeric($valor)) {
    return null;
  }

  return number_format((float)$valor, 2, '.', '');
}

function normalizarFechaActualizar($fecha) {
  $fecha = trim((string)$fecha);

  if ($fecha === '' || $fecha === '0000-00-00' || $fecha === '0000-00-00 00:00:00') {
    return null;
  }

  $formatos = [
    'Y-m-d',
    'd-m-Y',
    'd/m/Y',
    'Y/m/d',
    'Y-m-d H:i:s',
    'd-m-Y H:i:s',
    'd/m/Y H:i:s'
  ];

  foreach ($formatos as $formato) {
    $date = DateTime::createFromFormat($formato, $fecha);

    if ($date instanceof DateTime) {
      return $date->format('Y-m-d');
    }
  }

  try {
    $date = new DateTime($fecha);
    return $date->format('Y-m-d');
  } catch (Exception $e) {
    return null;
  }
}

function responderActualizarGasto($ok, $message, $extra = []) {
  $message = appPublicMessage($message, $ok ? 'Gasto actualizado correctamente.' : 'No se ha podido actualizar el gasto. Inténtalo de nuevo.');
  if (!$ok) {
    $extra = [];
  }
  echo json_encode(array_merge([
    "ok" => $ok,
    "message" => $message
  ], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

function marcarGastoComoError($conn, $registroId, $gastoUid, $userId, $mensaje, $respuesta = null) {
  $payloadError = $respuesta;

  if ($payloadError === null) {
    $payloadError = [
      'ok' => false,
      'message' => $mensaje,
      'estado' => 'periodo_cerrado'
    ];
  }

  $makeResponse = json_encode($payloadError, JSON_UNESCAPED_UNICODE);

  if ($makeResponse === false) {
    $makeResponse = $mensaje;
  }

  $sql = "UPDATE gastos
          SET estado = 'error',
              make_response = ?
          WHERE id = ?
            AND gasto_uid = ?
            AND user_id = ?";

  $stmt = $conn->prepare($sql);

  if ($stmt) {
    $stmt->bind_param(
      "sisi",
      $makeResponse,
      $registroId,
      $gastoUid,
      $userId
    );

    $stmt->execute();
  }
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data || !is_array($data)) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'accion' => 'actualizar_gasto_json_invalido',
    'descripcion' => 'Intento de actualizar gasto con JSON inválido.',
    'estado_nuevo' => 'error',
    'datos' => [
      'raw' => $raw,
      'json_error' => json_last_error_msg()
    ]
  ]);

  responderActualizarGasto(false, "JSON inválido", [
    "raw" => $raw,
    "json_error" => json_last_error_msg()
  ]);
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'accion' => 'actualizar_gasto_sesion_no_valida',
    'descripcion' => 'Intento de actualizar gasto sin sesión válida.',
    'estado_nuevo' => 'error'
  ]);

  responderActualizarGasto(false, "Sesión no válida. Vuelve a iniciar sesión.");
}

$registro_id = intval(pickValue($data, ['registro_id', 'id', 'gasto_id'], 0));
$gasto_uid = trim((string)pickValue($data, ['gasto_uid', 'uid'], ''));

$importe = pickValue($data, ['importe', 'importe_detectado', 'total'], null);
$fecha_ticket = pickValue($data, ['fecha_ticket', 'fecha', 'ticket_date'], null);
$fecha_imputacion = pickValue($data, ['fecha_imputacion', 'fecha_liquidacion', 'fecha_periodo'], null);

$drive_folder_id = pickValue($data, ['drive_folder_id', 'folder_id'], null);
$drive_folder_url = pickValue($data, ['drive_folder_url', 'folder_url'], null);

$excel_file_id = pickValue($data, ['excel_file_id', 'excel_id', 'spreadsheet_id', 'file_id'], null);
$excel_file_url = pickValue($data, ['excel_file_url', 'excel_url', 'spreadsheet_url', 'google_sheet_url', 'webViewLink', 'web_view_link'], null);
$excel_sheet_name = pickValue($data, ['excel_sheet_name', 'sheet_name', 'hoja'], null);
$excel_row_id = pickValue($data, ['excel_row_id', 'row_id', 'row_number', 'fila'], null);

$tickets = $data['tickets'] ?? [];

if ($registro_id <= 0 || $gasto_uid === '') {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'entidad_id' => $registro_id,
    'accion' => 'actualizar_gasto_datos_incompletos',
    'descripcion' => 'Intento de actualizar gasto sin registro_id o gasto_uid.',
    'estado_nuevo' => 'error',
    'datos' => $data
  ]);

  responderActualizarGasto(false, "Falta registro_id o gasto_uid", [
    "data" => $data
  ]);
}

$sqlGasto = "SELECT *
             FROM gastos
             WHERE id = ?
               AND gasto_uid = ?
               AND user_id = ?
             LIMIT 1";

$stmtGasto = $conn->prepare($sqlGasto);

if (!$stmtGasto) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'gasto',
    'entidad_id' => $registro_id,
    'accion' => 'error_preparar_comprobar_gasto_actualizar',
    'descripcion' => 'No se pudo preparar la comprobación del gasto pendiente.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mysql_error' => $conn->error,
      'gasto_uid' => $gasto_uid
    ]
  ]);

  responderActualizarGasto(false, "No se pudo comprobar el gasto pendiente.");
}

$stmtGasto->bind_param("isi", $registro_id, $gasto_uid, $user_id);
$stmtGasto->execute();

$gastoActual = $stmtGasto->get_result()->fetch_assoc();

if (!$gastoActual) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'entidad_id' => $registro_id,
    'accion' => 'actualizar_gasto_no_encontrado',
    'descripcion' => 'No se encontró el gasto pendiente para actualizar o no pertenece al usuario.',
    'estado_nuevo' => 'error',
    'datos' => [
      'gasto_uid' => $gasto_uid,
      'user_id' => $user_id
    ]
  ]);

  responderActualizarGasto(false, "No se encontró el gasto pendiente para actualizar o no pertenece al usuario.");
}

$importe = normalizarImporteActualizar($importe);
$fecha_ticket = normalizarFechaActualizar($fecha_ticket);
$fecha_imputacion = normalizarFechaActualizar($fecha_imputacion);

$fechaPeriodo = getFechaPeriodoGasto(
  $fecha_imputacion,
  $fecha_ticket,
  $gastoActual['created_at'] ?? null
);

if ($fechaPeriodo !== null) {
  $infoPeriodo = getPeriodoCerradoInfo($conn, $user_id, $fechaPeriodo);

  if ($infoPeriodo['cerrado'] === true) {
    $mensajePeriodo = $infoPeriodo['mensaje'] ?: 'Este periodo ya está validado y no admite modificaciones.';

    marcarGastoComoError($conn, $registro_id, $gasto_uid, $user_id, $mensajePeriodo, [
      'ok' => false,
      'message' => $mensajePeriodo,
      'bloqueado_por_periodo_validado' => true,
      'fecha_periodo' => $fechaPeriodo,
      'data_make' => $data
    ]);

    auditoriaRegistrarSeguro($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'gasto',
      'entidad_id' => $registro_id,
      'accion' => 'actualizar_gasto_bloqueado_periodo_cerrado',
      'descripcion' => 'Actualización de gasto bloqueada porque el periodo está cerrado.',
      'estado_anterior' => $gastoActual['estado'] ?? null,
      'estado_nuevo' => 'error',
      'datos' => [
        'gasto_uid' => $gasto_uid,
        'fecha_periodo' => $fechaPeriodo,
        'mensaje' => $mensajePeriodo,
        'data_make' => $data
      ]
    ]);

    responderActualizarGasto(false, $mensajePeriodo, [
      "bloqueado_por_periodo_validado" => true,
      "fecha_periodo" => $fechaPeriodo,
      "registro_id" => $registro_id,
      "gasto_uid" => $gasto_uid,
      "user_id" => $user_id
    ]);
  }
}

$posiblesDuplicados = detectarGastosDuplicados(
  $conn,
  $user_id,
  $importe,
  $fecha_ticket,
  $gastoActual['motivo'] ?? '',
  $gastoActual['viaje'] ?? '',
  $registro_id
);

if (count($posiblesDuplicados) > 0) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'gasto',
    'entidad' => 'gasto',
    'entidad_id' => $registro_id,
    'accion' => 'posible_duplicado_gasto_ticket_detectado',
    'descripcion' => 'Se detectaron posibles gastos duplicados al actualizar un gasto con datos.',
    'estado_anterior' => $gastoActual['estado'] ?? null,
    'estado_nuevo' => $gastoActual['estado'] ?? null,
    'datos' => [
      'gasto_uid' => $gasto_uid,
      'importe' => $importe,
      'fecha_ticket' => $fecha_ticket,
      'duplicados' => $posiblesDuplicados
    ]
  ]);
}

$make_response = json_encode($data, JSON_UNESCAPED_UNICODE);

if ($make_response === false) {
  $make_response = $raw;
}

$localNow = date('Y-m-d H:i:s');

$fechaImputacionExiste = actualizarGastoColumnExists($conn, 'gastos', 'fecha_imputacion');

if ($fechaImputacionExiste) {
  $sql = "UPDATE gastos
          SET importe_detectado = ?,
              fecha_ticket = ?,
              fecha_imputacion = ?,
              drive_folder_id = ?,
              drive_folder_url = ?,
              excel_file_id = ?,
              excel_file_url = ?,
              excel_sheet_name = ?,
              excel_row_id = ?,
              make_response = ?,
              sync_status = 'sincronizado',
              last_sync_at = ?,
              estado = 'procesado'
          WHERE id = ? 
            AND gasto_uid = ?
            AND user_id = ?";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    auditoriaRegistrarSeguro($conn, [
      'tipo_evento' => 'sistema',
      'entidad' => 'gasto',
      'entidad_id' => $registro_id,
      'accion' => 'error_preparar_actualizar_gasto',
      'descripcion' => 'Error SQL preparando actualización de gasto desde servicio automatización.',
      'estado_nuevo' => 'error',
      'datos' => [
        'mysql_error' => $conn->error,
        'gasto_uid' => $gasto_uid
      ]
    ]);

    responderActualizarGasto(false, "Error SQL: " . $conn->error);
  }

  $stmt->bind_param(
    "sssssssssssisi",
    $importe,
    $fecha_ticket,
    $fecha_imputacion,
    $drive_folder_id,
    $drive_folder_url,
    $excel_file_id,
    $excel_file_url,
    $excel_sheet_name,
    $excel_row_id,
    $make_response,
    $localNow,
    $registro_id,
    $gasto_uid,
    $user_id
  );
} else {
  $sql = "UPDATE gastos
          SET importe_detectado = ?,
              fecha_ticket = ?,
              drive_folder_id = ?,
              drive_folder_url = ?,
              excel_file_id = ?,
              excel_file_url = ?,
              excel_sheet_name = ?,
              excel_row_id = ?,
              make_response = ?,
              sync_status = 'sincronizado',
              last_sync_at = ?,
              estado = 'procesado'
          WHERE id = ? 
            AND gasto_uid = ?
            AND user_id = ?";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    auditoriaRegistrarSeguro($conn, [
      'tipo_evento' => 'sistema',
      'entidad' => 'gasto',
      'entidad_id' => $registro_id,
      'accion' => 'error_preparar_actualizar_gasto',
      'descripcion' => 'Error SQL preparando actualización de gasto desde servicio de automatización.',
      'estado_nuevo' => 'error',
      'datos' => [
        'mysql_error' => $conn->error,
        'gasto_uid' => $gasto_uid
      ]
    ]);

    responderActualizarGasto(false, "Error SQL: " . $conn->error);
  }

  $stmt->bind_param(
    "ssssssssssisi",
    $importe,
    $fecha_ticket,
    $drive_folder_id,
    $drive_folder_url,
    $excel_file_id,
    $excel_file_url,
    $excel_sheet_name,
    $excel_row_id,
    $make_response,
    $localNow,
    $registro_id,
    $gasto_uid,
    $user_id
  );
}

if (!$stmt->execute()) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'gasto',
    'entidad_id' => $registro_id,
    'accion' => 'error_ejecutar_actualizar_gasto',
    'descripcion' => 'Error SQL ejecutando actualización de gasto desde servicio de automatización.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mysql_error' => $stmt->error,
      'gasto_uid' => $gasto_uid
    ]
  ]);

  responderActualizarGasto(false, "No se pudo actualizar gasto: " . $stmt->error);
}

if ($stmt->affected_rows === 0) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'gasto',
    'entidad' => 'gasto',
    'entidad_id' => $registro_id,
    'accion' => 'actualizar_gasto_sin_filas_afectadas',
    'descripcion' => 'La actualización del gasto no afectó filas.',
    'estado_nuevo' => 'sin_cambios',
    'datos' => [
      'gasto_uid' => $gasto_uid,
      'user_id' => $user_id
    ]
  ]);

  responderActualizarGasto(false, "No se encontró el gasto pendiente para actualizar o no pertenece al usuario.");
}

if (is_array($tickets) && count($tickets) > 0) {
  $sqlDelete = "DELETE FROM gasto_tickets WHERE gasto_id = ? AND gasto_uid = ?";
  $stmtDelete = $conn->prepare($sqlDelete);

  if ($stmtDelete) {
    $stmtDelete->bind_param("is", $registro_id, $gasto_uid);
    $stmtDelete->execute();
  }

  $orden = 1;

  foreach ($tickets as $ticket) {
    $filename = $ticket['filename'] ?? $ticket['name'] ?? "ticket_" . $orden . ".jpeg";
    $mime_type = $ticket['mime_type'] ?? $ticket['type'] ?? "image/jpeg";
    $drive_file_id = $ticket['drive_file_id'] ?? $ticket['file_id'] ?? null;
    $drive_file_url = $ticket['drive_file_url'] ?? $ticket['file_url'] ?? $ticket['webViewLink'] ?? null;

    $sqlTicket = "INSERT INTO gasto_tickets
                  (gasto_id, gasto_uid, filename, mime_type, drive_file_id, drive_file_url, orden)
                  VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmtTicket = $conn->prepare($sqlTicket);

    if ($stmtTicket) {
      $stmtTicket->bind_param(
        "isssssi",
        $registro_id,
        $gasto_uid,
        $filename,
        $mime_type,
        $drive_file_id,
        $drive_file_url,
        $orden
      );

      $stmtTicket->execute();
    }

    $orden++;
  }
}

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'gasto',
  'entidad' => 'gasto',
  'entidad_id' => $registro_id,
  'accion' => 'gasto_actualizado_desde_make',
  'descripcion' => 'Gasto actualizado correctamente con los datos devueltos por servicio de automatización.',
  'estado_anterior' => $gastoActual['estado'] ?? null,
  'estado_nuevo' => 'procesado',
  'datos' => [
    'gasto_uid' => $gasto_uid,
    'user_id' => $user_id,
    'importe_detectado' => $importe,
    'fecha_ticket' => $fecha_ticket,
    'fecha_imputacion' => $fecha_imputacion,
    'excel_file_id' => $excel_file_id,
    'excel_file_url' => $excel_file_url,
    'total_tickets' => is_array($tickets) ? count($tickets) : 0
  ]
]);

echo json_encode([
  "ok" => true,
  "message" => "Gasto actualizado correctamente",
  "id" => $registro_id,
  "motivo" => $motivo ?? null,
  "fecha_ticket" => $fecha_ticket ?? null,
  "importe_detectado" => $importe ?? null,
  "registro_id" => $registro_id,
  "gasto_uid" => $gasto_uid,
  "user_id" => $user_id,
  "excel_file_url" => $excel_file_url,
  "posibles_duplicados" => count($posiblesDuplicados)
], JSON_UNESCAPED_UNICODE);

exit;
?>

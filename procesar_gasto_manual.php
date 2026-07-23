<?php
require "session.php";
include "db.php";
require_once "config.php";
require_once "includes/functions.php";
require_once "includes/auditoria.php";
require_once "includes/security.php";

securitySendHeaders();
requirePostMethod();

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

function responderManual($ok, $message, $extra = []) {
  $message = appPublicMessage($message, $ok ? 'Gasto registrado correctamente.' : 'No se ha podido registrar el gasto. Inténtalo de nuevo.');
  if (!$ok) {
    $extra = [];
  }
  $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
  $acceptJson = stripos($contentType, 'application/json') !== false;

  if ($acceptJson) {
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(array_merge([
      "ok" => $ok,
      "message" => $message
    ], $extra), JSON_UNESCAPED_UNICODE);

    exit;
  }

  if ($ok) {
    $_SESSION['manual_success'] = $message;
    if (!empty($extra['confirmacion'])) { $_SESSION['manual_confirmacion'] = $extra['confirmacion']; }
  } else {
    $_SESSION['manual_error'] = $message;
  }

  header("Location: gasto_manual.php");
  exit;
}

function normalizarImporteManual($valor) {
  $valor = trim((string)$valor);

  if ($valor === '') {
    return null;
  }

  $valor = str_replace(' ', '', $valor);

  if (strpos($valor, ',') !== false && strpos($valor, '.') !== false) {
    $valor = str_replace('.', '', $valor);
    $valor = str_replace(',', '.', $valor);
    return $valor;
  }

  if (strpos($valor, ',') !== false) {
    $valor = str_replace(',', '.', $valor);
    return $valor;
  }

  return $valor;
}

function columnaExisteManual($conn, $tabla, $columna) {
  $tabla = $conn->real_escape_string($tabla);
  $columna = $conn->real_escape_string($columna);

  $result = $conn->query("SHOW COLUMNS FROM `$tabla` LIKE '$columna'");

  return $result && $result->num_rows > 0;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== false) {
  $raw = file_get_contents("php://input");
  $payload = json_decode($raw, true);

  if (!$payload || !is_array($payload)) {
    auditoriaRegistrarSeguro($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'gasto',
      'accion' => 'gasto_manual_json_invalido',
      'descripcion' => 'Intento de registrar gasto manual con JSON inválido.',
      'estado_nuevo' => 'error',
      'datos' => [
        'raw' => $raw,
        'json_error' => json_last_error_msg()
      ]
    ]);

    responderManual(false, "JSON inválido");
  }
} else {
  $payload = $_POST;
}

requireCsrfFromRequest($payload);

$user_id = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['user'] ?? '';
$comercial = $_SESSION['comercial'] ?? '';

if ($user_id <= 0 || $username === '' || $comercial === '') {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'accion' => 'gasto_manual_sesion_no_valida',
    'descripcion' => 'Intento de registrar gasto manual sin sesión válida.',
    'estado_nuevo' => 'error'
  ]);

  responderManual(false, "Sesión no válida. Vuelve a iniciar sesión.");
}

$viaje = trim($payload['viaje'] ?? '');
$motivo = trim($payload['motivo'] ?? '');
$comentarios = trim($payload['comentarios'] ?? '');

$importe = normalizarImporteManual($payload['importe_detectado'] ?? $payload['importe'] ?? '');
$fecha_ticket = trim($payload['fecha_ticket'] ?? '');
$fecha_imputacion = trim($payload['fecha_imputacion'] ?? '');

if ($viaje === '' || $motivo === '') {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'accion' => 'gasto_manual_datos_obligatorios_incompletos',
    'descripcion' => 'Intento de registrar gasto manual sin viaje o motivo.',
    'estado_nuevo' => 'error',
    'datos' => [
      'viaje' => $viaje,
      'motivo' => $motivo
    ]
  ]);

  responderManual(false, "Faltan datos obligatorios del gasto.");
}

if ($importe === null || !is_numeric($importe) || (float)$importe <= 0) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'accion' => 'gasto_manual_importe_no_valido',
    'descripcion' => 'Intento de registrar gasto manual con importe no válido.',
    'estado_nuevo' => 'error',
    'datos' => [
      'importe' => $payload['importe_detectado'] ?? $payload['importe'] ?? ''
    ]
  ]);

  responderManual(false, "El importe del gasto manual no es válido.");
}

if ($fecha_ticket === '' || $fecha_imputacion === '') {
  responderManual(false, "La fecha real del gasto y la fecha de imputación son obligatorias.");
}

$fechaPeriodo = getFechaPeriodoGasto($fecha_imputacion, $fecha_ticket, null);

if ($fechaPeriodo === null) {
  responderManual(false, "No se pudo determinar el periodo del gasto manual.");
}

try {
  bloquearSiPeriodoCerrado($conn, $user_id, $fechaPeriodo);
} catch (Exception $e) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'gasto',
    'accion' => 'gasto_manual_bloqueado_periodo_cerrado',
    'descripcion' => 'Intento bloqueado de registrar gasto manual en un periodo cerrado.',
    'estado_nuevo' => 'bloqueado',
    'datos' => [
      'mensaje' => $e->getMessage(),
      'fecha_periodo' => $fechaPeriodo,
      'fecha_ticket' => $fecha_ticket,
      'fecha_imputacion' => $fecha_imputacion,
      'importe' => $importe
    ]
  ]);

  responderManual(false, $e->getMessage());
}

$confirmarDuplicado = intval($payload['confirmar_duplicado'] ?? 0) === 1;
$posiblesDuplicados = detectarGastosDuplicados($conn, $user_id, $importe, $fecha_ticket, $motivo, $viaje, 0);

if (!$confirmarDuplicado && count($posiblesDuplicados) > 0) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'gasto',
    'entidad' => 'gasto',
    'accion' => 'posible_duplicado_gasto_manual_bloqueado',
    'descripcion' => 'Se detectó un posible gasto manual duplicado y se pidió confirmación al usuario.',
    'estado_nuevo' => 'pendiente_confirmacion',
    'datos' => [
      'viaje' => $viaje,
      'motivo' => $motivo,
      'importe' => $importe,
      'fecha_ticket' => $fecha_ticket,
      'duplicados' => $posiblesDuplicados
    ]
  ]);

  $_SESSION['manual_payload'] = [
    'viaje' => $viaje,
    'motivo' => $motivo,
    'importe_detectado' => $importe,
    'fecha_ticket' => $fecha_ticket,
    'fecha_imputacion' => $fecha_imputacion,
    'comentarios' => $comentarios
  ];

  responderManual(false, "Ya existe un gasto similar para este día. Si estás seguro de que quieres registrarlo, marca la casilla de confirmación y vuelve a guardar.");
}

if ($confirmarDuplicado && count($posiblesDuplicados) > 0) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'gasto',
    'entidad' => 'gasto',
    'accion' => 'posible_duplicado_gasto_manual_confirmado',
    'descripcion' => 'El usuario confirmó el registro de un gasto manual con posibles duplicados.',
    'estado_nuevo' => 'confirmado',
    'datos' => [
      'viaje' => $viaje,
      'motivo' => $motivo,
      'importe' => $importe,
      'fecha_ticket' => $fecha_ticket,
      'duplicados' => $posiblesDuplicados
    ]
  ]);
}

$tieneOrigen = columnaExisteManual($conn, 'gastos', 'origen');

if ($tieneOrigen) {
  $sql = "INSERT INTO gastos 
          (user_id, username, comercial, viaje, motivo, comentarios, estado, origen)
          VALUES (?, ?, ?, ?, ?, ?, 'pendiente', 'manual')";
} else {
  $sql = "INSERT INTO gastos 
          (user_id, username, comercial, viaje, motivo, comentarios, estado)
          VALUES (?, ?, ?, ?, ?, ?, 'pendiente')";
}

$stmt = $conn->prepare($sql);

if (!$stmt) {
  responderManual(false, "Error al preparar el guardado del gasto: " . $conn->error);
}

$stmt->bind_param(
  "isssss",
  $user_id,
  $username,
  $comercial,
  $viaje,
  $motivo,
  $comentarios
);

if (!$stmt->execute()) {
  responderManual(false, "Error al guardar gasto: " . $stmt->error);
}

$registro_id = (int)$stmt->insert_id;
$gasto_uid = "GASTO-" . date("Ymd") . "-" . str_pad((string)$registro_id, 6, "0", STR_PAD_LEFT);

if ($tieneOrigen) {
  $stmt = $conn->prepare("UPDATE gastos SET gasto_uid = ?, origen = 'manual' WHERE id = ?");
} else {
  $stmt = $conn->prepare("UPDATE gastos SET gasto_uid = ? WHERE id = ?");
}

if ($stmt) {
  $stmt->bind_param("si", $gasto_uid, $registro_id);
  $stmt->execute();
}

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'gasto',
  'entidad' => 'gasto',
  'entidad_id' => $registro_id,
  'accion' => 'gasto_manual_creado_pendiente',
  'descripcion' => 'Gasto manual creado y pendiente de completar.',
  'estado_nuevo' => 'pendiente',
  'datos' => [
    'gasto_uid' => $gasto_uid,
    'viaje' => $viaje,
    'motivo' => $motivo,
    'importe' => $importe,
    'fecha_ticket' => $fecha_ticket,
    'fecha_imputacion' => $fecha_imputacion
  ]
]);

/*
  Payload conservador para no romper el escenario existente de Make.
  No se añaden campos nuevos como origen, requiere_ia, tiene_ticket o periodo_control.
*/

$payload['user_id'] = $user_id;
$payload['registro_id'] = $registro_id;
$payload['gasto_uid'] = $gasto_uid;
$payload['username'] = $username;
$payload['comercial'] = $comercial;

$payload['viaje'] = $viaje;
$payload['motivo'] = $motivo;
$payload['comentarios'] = $comentarios;
$payload['importe_detectado'] = $importe;
$payload['importe'] = $importe;
$payload['fecha_ticket'] = $fecha_ticket;
$payload['fecha_imputacion'] = $fecha_imputacion;

$payload_make = json_encode($payload, JSON_UNESCAPED_UNICODE);

if ($payload_make === false) {
  $stmt = $conn->prepare("UPDATE gastos SET estado = 'error', make_response = ? WHERE id = ?");
  appLogError('No se pudo preparar el gasto manual', json_last_error_msg());
  $error_msg = "No se pudo preparar la información del gasto.";

  if ($stmt) {
    $stmt->bind_param("si", $error_msg, $registro_id);
    $stmt->execute();
  }

  responderManual(false, $error_msg);
}

$webhook = defined('MAKE_WEBHOOK_GASTO_MANUAL') ? MAKE_WEBHOOK_GASTO_MANUAL : '';

if (trim((string)$webhook) === '') {
  $stmt = $conn->prepare("UPDATE gastos SET estado = 'error', make_response = ? WHERE id = ?");
  appLogError('La actualización del gasto manual no está configurada');
  $error_msg = "Esta operación no está disponible en este momento.";

  if ($stmt) {
    $stmt->bind_param("si", $error_msg, $registro_id);
    $stmt->execute();
  }

  responderManual(false, $error_msg);
}

$ch = curl_init($webhook);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  "Content-Type: application/json",
  "Accept: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload_make);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 180);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

$make_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($make_response === false || $http_code < 200 || $http_code >= 300) {
  $stmt = $conn->prepare("UPDATE gastos SET estado = 'error', make_response = ? WHERE id = ?");
  appLogError('No se pudo completar el gasto manual', $curl_error ?: 'Código ' . $http_code);
  $error_msg = "No se ha podido completar el gasto manual. Inténtalo de nuevo.";

  if ($stmt) {
    $stmt->bind_param("si", $error_msg, $registro_id);
    $stmt->execute();
  }

  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'gasto',
    'entidad' => 'gasto',
    'entidad_id' => $registro_id,
    'accion' => 'error_make_gasto_manual',
    'descripcion' => 'No se pudo completar el procesamiento del gasto manual.',
    'estado_anterior' => 'pendiente',
    'estado_nuevo' => 'error',
    'datos' => [
      'gasto_uid' => $gasto_uid,
      'http_code' => $http_code,
      'curl_error' => $curl_error
    ]
  ]);

  responderManual(false, $error_msg);
}

$dataMake = json_decode($make_response, true);

if (!$dataMake || !is_array($dataMake)) {
  $stmt = $conn->prepare("UPDATE gastos SET estado = 'error', make_response = ? WHERE id = ?");
  appLogError('La respuesta del gasto manual no era válida');
  $error_msg = "No se ha podido completar el gasto manual. Inténtalo de nuevo.";

  if ($stmt) {
    $stmt->bind_param("si", $make_response, $registro_id);
    $stmt->execute();
  }

  responderManual(false, $error_msg, [
    "raw" => $make_response
  ]);
}

if (empty($dataMake['ok'])) {
  $make_response_json = json_encode($dataMake, JSON_UNESCAPED_UNICODE);

  if ($make_response_json === false) {
    $make_response_json = $make_response;
  }

  $stmt = $conn->prepare("UPDATE gastos SET estado = 'error', make_response = ? WHERE id = ?");

  if ($stmt) {
    $stmt->bind_param("si", $make_response_json, $registro_id);
    $stmt->execute();
  }

  responderManual(false, $dataMake['message'] ?? "No se ha podido completar el gasto manual.", [
    "raw" => $dataMake
  ]);
}

$importeMake = $dataMake['importe'] ?? $dataMake['importe_detectado'] ?? $importe;
$fechaTicketMake = $dataMake['fecha_ticket'] ?? $fecha_ticket;
$fechaImputacionMake = $dataMake['fecha_imputacion'] ?? $fecha_imputacion;
$drive_folder_id = $dataMake['drive_folder_id'] ?? null;
$drive_folder_url = $dataMake['drive_folder_url'] ?? null;
$tickets = $dataMake['tickets'] ?? [];

if ($importeMake !== null) {
  $importeMake = normalizarImporteManual($importeMake);
}

$make_response_json = json_encode($dataMake, JSON_UNESCAPED_UNICODE);

if ($make_response_json === false) {
  $make_response_json = $make_response;
}

$fechaImputacionExiste = columnaExisteManual($conn, 'gastos', 'fecha_imputacion');

if ($fechaImputacionExiste && $tieneOrigen) {
  $sql = "UPDATE gastos 
          SET importe_detectado = ?,
              fecha_ticket = ?,
              fecha_imputacion = ?,
              drive_folder_id = ?,
              drive_folder_url = ?,
              make_response = ?,
              estado = 'procesado',
              origen = 'manual'
          WHERE id = ?";
} elseif ($fechaImputacionExiste) {
  $sql = "UPDATE gastos 
          SET importe_detectado = ?,
              fecha_ticket = ?,
              fecha_imputacion = ?,
              drive_folder_id = ?,
              drive_folder_url = ?,
              make_response = ?,
              estado = 'procesado'
          WHERE id = ?";
} elseif ($tieneOrigen) {
  $sql = "UPDATE gastos 
          SET importe_detectado = ?,
              fecha_ticket = ?,
              drive_folder_id = ?,
              drive_folder_url = ?,
              make_response = ?,
              estado = 'procesado',
              origen = 'manual'
          WHERE id = ?";
} else {
  $sql = "UPDATE gastos 
          SET importe_detectado = ?,
              fecha_ticket = ?,
              drive_folder_id = ?,
              drive_folder_url = ?,
              make_response = ?,
              estado = 'procesado'
          WHERE id = ?";
}

$stmt = $conn->prepare($sql);

if (!$stmt) {
  responderManual(false, "No se pudo preparar la actualización del gasto: " . $conn->error);
}

if ($fechaImputacionExiste) {
  $stmt->bind_param(
    "ssssssi",
    $importeMake,
    $fechaTicketMake,
    $fechaImputacionMake,
    $drive_folder_id,
    $drive_folder_url,
    $make_response_json,
    $registro_id
  );
} else {
  $stmt->bind_param(
    "sssssi",
    $importeMake,
    $fechaTicketMake,
    $drive_folder_id,
    $drive_folder_url,
    $make_response_json,
    $registro_id
  );
}

if (!$stmt->execute()) {
  appLogError('No se pudo guardar el gasto manual', $stmt->error);
  responderManual(false, "No se ha podido guardar el gasto manual. Inténtalo de nuevo.");
}

if (is_array($tickets)) {
  foreach ($tickets as $index => $ticket) {
    $filename = $ticket['filename'] ?? 'ticket_' . ($index + 1) . '.jpeg';
    $mime_type = $ticket['mime_type'] ?? 'image/jpeg';
    $drive_file_id = $ticket['drive_file_id'] ?? null;
    $drive_file_url = $ticket['drive_file_url'] ?? null;
    $orden = $index + 1;

    $sql = "INSERT INTO gasto_tickets
            (gasto_id, gasto_uid, filename, mime_type, drive_file_id, drive_file_url, orden)
            VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmtTicket = $conn->prepare($sql);

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
  }
}

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'gasto',
  'entidad' => 'gasto',
  'entidad_id' => $registro_id,
  'accion' => 'gasto_manual_procesado',
  'descripcion' => 'Gasto manual registrado y procesado correctamente.',
  'estado_anterior' => 'pendiente',
  'estado_nuevo' => 'procesado',
  'datos' => [
    'gasto_uid' => $gasto_uid,
    'importe_detectado' => $importeMake,
    'fecha_ticket' => $fechaTicketMake,
    'fecha_imputacion' => $fechaImputacionMake,
    'drive_folder_id' => $drive_folder_id,
    'drive_folder_url' => $drive_folder_url,
    'total_tickets' => is_array($tickets) ? count($tickets) : 0
  ]
]);

responderManual(true, "Gasto manual registrado correctamente", [
  "registro_id" => $registro_id,
  "gasto_uid" => $gasto_uid,
  "user_id" => $user_id,
  "confirmacion" => ["id"=>$registro_id,"tipo"=>"Gasto manual","motivo"=>$motivo,"fecha"=>$fechaTicketMake,"importe"=>$importeMake]
]);
?>

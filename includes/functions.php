<?php

function h($value) {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function appPasswordHash($password) {
  return password_hash((string)$password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function appPasswordVerify($password, $storedHash) {
  $storedHash = (string)$storedHash;
  $info = password_get_info($storedHash);
  if (!empty($info['algo'])) {
    return password_verify((string)$password, $storedHash);
  }

  return preg_match('/^[a-f0-9]{64}$/i', $storedHash) === 1
    && hash_equals(strtolower($storedHash), hash('sha256', (string)$password));
}

function appPasswordNeedsUpgrade($storedHash) {
  $info = password_get_info((string)$storedHash);
  return empty($info['algo']) || password_needs_rehash((string)$storedHash, PASSWORD_BCRYPT, ['cost' => 12]);
}

function appPasswordMeetsPolicy($password) {
  return strlen((string)$password) >= 10;
}

function isMaster() {
  return isset($_SESSION['role']) && $_SESSION['role'] === 'master';
}

function isAdmin() {
  return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'master'], true);
}

function isAdminRole($role) {
  return in_array((string)$role, ['admin', 'master'], true);
}

function isMasterRole($role) {
  return (string)$role === 'master';
}

function formatRoleWeb($role) {
  if ($role === 'master') {
    return 'Máster';
  }

  if ($role === 'admin') {
    return 'Administrador';
  }

  return 'Usuario';
}

function getAllowedRedirects() {
  return [
    'home.php',

    'nota_gastos.php',
    'gasto_manual.php',
    'cierre_mensual.php',
    'descargar_nota.php',
    'fichaje.php',
    'gestion_fichajes.php',
    'efectivo_kms.php',
    'asistente_tecnico.php',

    'gestionar_gastos.php',
    'ver_gasto.php',
    'editar_gasto.php',
    'ver_efectivo_km.php',

    'admin/index.php',
    'admin/usuarios.php',
    'admin/usuario_nuevo.php',
    'admin/usuario_editar.php',
    'admin/dashboard.php',
    'admin/incidencias.php',
    'admin/cierres_mensuales.php',
    'admin/auditoria.php',
    'admin/detalle_auditoria.php',
    'admin/editar_auditoria.php',
    'admin/integridad.php',
    'admin/backup_mensual.php',
    'admin/envios.php',
    'admin/centro_control.php',
    'admin/efectivo_kms.php',
    'admin/asistente.php'
  ];
}

function sanitizeRedirect($redirect) {
  $redirect = trim((string)$redirect);

  if ($redirect === '') {
    return 'home.php';
  }

  if (preg_match('/^https?:\/\//i', $redirect)) {
    return 'home.php';
  }

  if (strpos($redirect, '//') !== false) {
    return 'home.php';
  }

  $redirect = ltrim($redirect, '/');

  if (strpos($redirect, '..') !== false) {
    return 'home.php';
  }

  if (stripos($redirect, 'home/vol') !== false || stripos($redirect, 'htdocs') !== false) {
    return 'home.php';
  }

  $parts = parse_url($redirect);

  if ($parts === false) {
    return 'home.php';
  }

  $path = $parts['path'] ?? '';

  if ($path === '') {
    return 'home.php';
  }

  $path = ltrim($path, '/');

  $allowed = getAllowedRedirects();

  if (!in_array($path, $allowed, true)) {
    return 'home.php';
  }

  $query = '';

  if (isset($parts['query']) && $parts['query'] !== '') {
    $query = '?' . $parts['query'];
  }

  return $path . $query;
}

function formatEstadoWeb($estado) {
  $estado = trim((string)$estado);

  if ($estado === '') {
    return '—';
  }

  $traducciones = [
    'published' => 'Publicado',
    'inactive' => 'Inactivo',
    'deleted' => 'Retirado',
    'processing' => 'Preparando',
    'uploading' => 'Cargando',
    'needs_ocr' => 'Necesita reconocimiento de texto',
  ];

  if (isset($traducciones[$estado])) {
    return $traducciones[$estado];
  }

  $estado = str_replace('_', ' ', $estado);
  $estado = mb_strtolower($estado, 'UTF-8');

  return mb_strtoupper(mb_substr($estado, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($estado, 1, null, 'UTF-8');
}

function formatFechaWeb($fecha, $conHora = false) {
  if ($fecha === null || $fecha === '' || $fecha === '0000-00-00' || $fecha === '0000-00-00 00:00:00') {
    return 'Pendiente';
  }

  try {
    $timezone = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

    if ($conHora) {
      $date = new DateTime($fecha, $timezone);
      return $date->format('d-m-Y H:i');
    }

    $date = new DateTime($fecha);
    return $date->format('d-m-Y');

  } catch (Exception $e) {
    return h($fecha);
  }
}

function getFechaPeriodoGasto($fechaImputacion = null, $fechaTicket = null, $createdAt = null) {
  $candidatas = [
    $fechaImputacion,
    $fechaTicket,
    $createdAt
  ];

  foreach ($candidatas as $fecha) {
    $fecha = trim((string)$fecha);

    if ($fecha === '' || $fecha === '0000-00-00' || $fecha === '0000-00-00 00:00:00') {
      continue;
    }

    try {
      $date = new DateTime($fecha);
      return $date->format('Y-m-d');
    } catch (Exception $e) {
      continue;
    }
  }

  return null;
}

function getPeriodoCerradoInfo($conn, $userId, $fechaPeriodo) {
  $userId = (int)$userId;
  $fechaPeriodo = trim((string)$fechaPeriodo);

  if ($userId <= 0 || $fechaPeriodo === '') {
    return [
      'cerrado' => false,
      'cierre' => null,
      'mensaje' => ''
    ];
  }

  try {
    $date = new DateTime($fechaPeriodo);
    $mes = (int)$date->format('n');
    $anio = (int)$date->format('Y');
  } catch (Exception $e) {
    return [
      'cerrado' => false,
      'cierre' => null,
      'mensaje' => ''
    ];
  }

  $sql = "SELECT *
          FROM cierres_mensuales
          WHERE user_id = ?
            AND mes = ?
            AND anio = ?
            AND estado IN ('validado', 'con_diferencia', 'rechazado')
          LIMIT 1";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return [
      'cerrado' => false,
      'cierre' => null,
      'mensaje' => ''
    ];
  }

  $stmt->bind_param("iii", $userId, $mes, $anio);
  $stmt->execute();

  $result = $stmt->get_result();

  if (!$result || $result->num_rows === 0) {
    return [
      'cerrado' => false,
      'cierre' => null,
      'mensaje' => ''
    ];
  }

  $cierre = $result->fetch_assoc();

  return [
    'cerrado' => true,
    'cierre' => $cierre,
    'mensaje' => 'El periodo ' . str_pad((string)$mes, 2, '0', STR_PAD_LEFT) . '/' . $anio . ' ya ha sido revisado por dirección y no admite modificaciones.'
  ];
}

function isPeriodoCerrado($conn, $userId, $fechaPeriodo) {
  $info = getPeriodoCerradoInfo($conn, $userId, $fechaPeriodo);

  return $info['cerrado'] === true;
}

function bloquearSiPeriodoCerrado($conn, $userId, $fechaPeriodo, $redirect = null) {
  $info = getPeriodoCerradoInfo($conn, $userId, $fechaPeriodo);

  if ($info['cerrado'] !== true) {
    return false;
  }

  $mensaje = $info['mensaje'] ?: 'Este periodo ya está validado y no admite modificaciones.';

  if ($redirect !== null && trim((string)$redirect) !== '') {
    $redirect = sanitizeRedirect($redirect);
    $separator = strpos($redirect, '?') === false ? '?' : '&';

    header("Location: " . $redirect . $separator . "type=error&msg=" . urlencode($mensaje));
    exit;
  }

  throw new Exception($mensaje);
}

function appEnsureMysqlConnection(&$conn) {
  try {
    if ($conn instanceof mysqli && @$conn->ping()) {
      return true;
    }
  } catch (Throwable $e) {
    // La conexión puede haber caducado durante una llamada larga a un webhook.
  }

  try {
    $newConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($newConn->connect_errno) {
      return false;
    }

    $newConn->set_charset('utf8mb4');

    $timezoneName = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid';
    $timezone = new DateTimeZone($timezoneName);
    $now = new DateTime('now', $timezone);
    $offsetSeconds = $timezone->getOffset($now);
    $offsetHours = intdiv($offsetSeconds, 3600);
    $offsetMinutes = abs(intdiv($offsetSeconds % 3600, 60));
    $mysqlOffset = sprintf('%+03d:%02d', $offsetHours, $offsetMinutes);

    $newConn->query("SET time_zone = '" . $newConn->real_escape_string($mysqlOffset) . "'");
    $conn = $newConn;

    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function callMakeWebhook($url, $payload, $timeout = 120) {
  if (trim((string)$url) === '') {
    return [
      'ok' => false,
      'skipped' => true,
      'http_code' => null,
      'response_raw' => null,
      'response_json' => null,
      'message' => 'Esta operación no está disponible en este momento.',
      'internal_message' => 'Webhook no configurado'
    ];
  }

  if (!function_exists('curl_init')) {
    return [
      'ok' => false,
      'skipped' => false,
      'http_code' => null,
      'response_raw' => null,
      'response_json' => null,
      'message' => 'No se ha podido completar la operación. Inténtalo de nuevo.',
      'internal_message' => 'cURL no está disponible en este servidor'
    ];
  }

  $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

  if ($jsonPayload === false) {
    return [
      'ok' => false,
      'skipped' => false,
      'http_code' => null,
      'response_raw' => null,
      'response_json' => null,
      'message' => 'No se ha podido completar la operación. Inténtalo de nuevo.',
      'internal_message' => 'No se pudo codificar el payload JSON: ' . json_last_error_msg()
    ];
  }

  $ch = curl_init($url);

  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
  ]);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);
  $curlErrno = curl_errno($ch);

  curl_close($ch);

  if ($response === false) {
    return [
      'ok' => false,
      'skipped' => false,
      'http_code' => $httpCode,
      'curl_errno' => $curlErrno,
      'response_raw' => null,
      'response_json' => null,
      'message' => 'No se ha podido completar la operación. Inténtalo de nuevo.',
      'internal_message' => $curlError ?: 'Error desconocido llamando a Make'
    ];
  }

  $decoded = null;

  if ($response !== '') {
    $decoded = json_decode($response, true);
  }

  if ($httpCode < 200 || $httpCode >= 300) {
    return [
      'ok' => false,
      'skipped' => false,
      'http_code' => $httpCode,
      'response_raw' => $response,
      'response_json' => $decoded,
      'message' => 'No se ha podido completar la operación. Inténtalo de nuevo.',
      'internal_message' => 'Make devolvió HTTP ' . $httpCode
    ];
  }

  if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
    return [
      'ok' => false,
      'skipped' => false,
      'http_code' => $httpCode,
      'response_raw' => $response,
      'response_json' => $decoded,
      'message' => 'No se ha podido completar la operación. Inténtalo de nuevo.',
      'internal_message' => $decoded['message'] ?? 'Make devolvió ok:false'
    ];
  }

  return [
    'ok' => true,
    'skipped' => false,
    'http_code' => $httpCode,
    'response_raw' => $response,
    'response_json' => $decoded,
    'message' => 'Operación completada correctamente.',
    'internal_message' => 'Webhook ejecutado correctamente'
  ];
}


function makeWebhookRespuestaOkExplicita($result) {
  if (!is_array($result) || empty($result['ok'])) {
    return false;
  }

  $json = $result['response_json'] ?? null;

  if (!is_array($json) || !array_key_exists('ok', $json)) {
    return false;
  }

  return $json['ok'] === true || $json['ok'] === 1 || $json['ok'] === 'true' || $json['ok'] === '1';
}

function makeWebhookExigirOkExplicita($result, $contexto = 'proceso') {
  if (makeWebhookRespuestaOkExplicita($result)) {
    $result['ok'] = true;
    $result['confirmed'] = true;
    return $result;
  }

  $mensaje = 'No se ha podido completar la operación. Inténtalo de nuevo.';
  $mensajeInterno = is_array($result) ? trim((string)($result['internal_message'] ?? $result['message'] ?? '')) : '';
  $raw = is_array($result) ? trim((string)($result['response_raw'] ?? '')) : '';

  if ($mensajeInterno === '' || $mensajeInterno === 'Webhook ejecutado correctamente') {
    $mensajeInterno = 'El webhook de ' . $contexto . ' respondió sin la confirmación JSON {"ok":true}.';
  }

  if ($raw !== '' && $raw !== 'Accepted') {
    $mensajeInterno .= ' Respuesta recibida: ' . mb_substr($raw, 0, 300);
  }

  return [
    'ok' => false,
    'confirmed' => false,
    'skipped' => is_array($result) ? (bool)($result['skipped'] ?? false) : false,
    'http_code' => is_array($result) ? ($result['http_code'] ?? null) : null,
    'response_raw' => is_array($result) ? ($result['response_raw'] ?? null) : null,
    'response_json' => is_array($result) ? ($result['response_json'] ?? null) : null,
    'message' => $mensaje,
    'internal_message' => $mensajeInterno
  ];
}


function columnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);

  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

  return $result && $result->num_rows > 0;
}

function gastoEstadoClass($estado) {
  $estado = trim((string)$estado);

  if ($estado === 'procesado') return 'estado-procesado';
  if ($estado === 'editado') return 'estado-editado';
  if ($estado === 'error' || strpos($estado, 'error') === 0) return 'estado-error';
  if ($estado === 'pendiente' || strpos($estado, 'pendiente') === 0) return 'estado-pendiente';
  if ($estado === 'eliminado') return 'estado-eliminado';

  return 'estado-neutro';
}

function detectarGastosDuplicados($conn, $userId, $importe, $fechaTicket, $motivo = '', $viaje = '', $excludeId = 0) {
  $userId = (int)$userId;
  $excludeId = (int)$excludeId;
  $importe = trim((string)$importe);
  $fechaTicket = trim((string)$fechaTicket);
  $motivo = trim((string)$motivo);
  $viaje = trim((string)$viaje);

  if ($userId <= 0 || $importe === '' || $fechaTicket === '') {
    return [];
  }

  $where = [
    "user_id = ?",
    "deleted_at IS NULL",
    "estado IN ('pendiente','procesado','editado','error')",
    "fecha_ticket = ?",
    "ABS(COALESCE(importe_detectado, 0) - ?) < 0.005"
  ];

  $params = [$userId, $fechaTicket, (float)$importe];
  $types = 'isd';

  if ($excludeId > 0) {
    $where[] = "id <> ?";
    $params[] = $excludeId;
    $types .= 'i';
  }

  if ($motivo !== '') {
    $where[] = "motivo = ?";
    $params[] = $motivo;
    $types .= 's';
  }

  if ($viaje !== '') {
    $where[] = "viaje = ?";
    $params[] = $viaje;
    $types .= 's';
  }

  $whereSql = implode(' AND ', $where);

  $sql = "SELECT id, gasto_uid, viaje, motivo, importe_detectado, fecha_ticket, estado
          FROM gastos
          WHERE $whereSql
          ORDER BY id DESC
          LIMIT 10";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return [];
  }

  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $result = $stmt->get_result();

  if (!$result) {
    return [];
  }

  $duplicados = [];

  while ($row = $result->fetch_assoc()) {
    $duplicados[] = $row;
  }

  return $duplicados;
}

function getGastoByIdForCurrentUser($conn, $id) {
  $id = intval($id);
  $userId = $_SESSION['user_id'] ?? 0;

  if (isAdmin()) {
    $sql = "SELECT * FROM gastos WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
      return null;
    }

    $stmt->bind_param("i", $id);
  } else {
    $sql = "SELECT * FROM gastos WHERE id = ? AND user_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
      return null;
    }

    $stmt->bind_param("ii", $id, $userId);
  }

  $stmt->execute();
  $result = $stmt->get_result();

  if ($result->num_rows === 0) {
    return null;
  }

  return $result->fetch_assoc();
}

function getTicketPrincipalByGasto($conn, $gastoId, $gastoUid) {
  $sql = "SELECT * FROM gasto_tickets 
          WHERE gasto_id = ? AND gasto_uid = ?
          ORDER BY orden ASC, id ASC
          LIMIT 1";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return null;
  }

  $stmt->bind_param("is", $gastoId, $gastoUid);
  $stmt->execute();

  $result = $stmt->get_result();

  if ($result->num_rows === 0) {
    return null;
  }

  return $result->fetch_assoc();
}

function buildGastoSyncPayload($gasto, $ticket = null, $accion = 'editar') {
  return [
    'accion' => $accion,

    'gasto' => [
      'registro_id' => (int)$gasto['id'],
      'gasto_uid' => $gasto['gasto_uid'],
      'user_id' => (int)$gasto['user_id'],
      'username' => $gasto['username'],
      'comercial' => $gasto['comercial'],
      'viaje' => $gasto['viaje'],
      'motivo' => $gasto['motivo'],
      'comentarios' => $gasto['comentarios'],
      'importe_detectado' => $gasto['importe_detectado'],
      'fecha_ticket' => $gasto['fecha_ticket'],
      'fecha_imputacion' => $gasto['fecha_imputacion'] ?? null,
      'estado' => $gasto['estado'],
      'origen' => $gasto['origen'] ?? null
    ],

    'excel' => [
      'excel_file_id' => $gasto['excel_file_id'] ?? null,
      'excel_file_url' => $gasto['excel_file_url'] ?? null,
      'excel_sheet_name' => $gasto['excel_sheet_name'] ?? null,
      'excel_row_id' => $gasto['excel_row_id'] ?? null
    ],

    'drive' => [
      'drive_folder_id' => $gasto['drive_folder_id'] ?? null,
      'drive_folder_url' => $gasto['drive_folder_url'] ?? null,
      'ticket_file_id' => $ticket['drive_file_id'] ?? null,
      'ticket_file_url' => $ticket['drive_file_url'] ?? null
    ],

    'busqueda_recomendada' => [
      'registro_id' => (int)$gasto['id'],
      'gasto_uid' => $gasto['gasto_uid'],
      'importe_detectado' => $gasto['importe_detectado'],
      'fecha_ticket' => $gasto['fecha_ticket'],
      'fecha_imputacion' => $gasto['fecha_imputacion'] ?? null
    ]
  ];
}
?>

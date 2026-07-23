<?php
/**
 * Helper autónomo para firmas digitales técnicas de cierres mensuales.
 *
 * Este módulo es aditivo: no bloquea el flujo principal de cierres si la tabla
 * no existe, si el webhook no está configurado o si Make devuelve error.
 */

function cierreFirmasNow() {
  date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');
  return date('Y-m-d H:i:s');
}

function cierreFirmasEnsureTable($conn) {
  $sql = "CREATE TABLE IF NOT EXISTS cierre_firmas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cierre_id INT NOT NULL,
    fase ENUM('comercial', 'admin', 'contabilidad') NOT NULL,
    firma VARCHAR(100) NOT NULL,
    firmado_por_user_id INT NULL,
    firmado_por_username VARCHAR(100) NULL,
    firmado_por_comercial VARCHAR(150) NULL,
    firmado_por_rol VARCHAR(50) NULL,
    firmado_at DATETIME NOT NULL,
    webhook_status ENUM('pendiente', 'enviado', 'error') NOT NULL DEFAULT 'pendiente',
    webhook_response LONGTEXT NULL,
    ultimo_error TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_cierre_fase (cierre_id, fase),
    INDEX idx_cierre_id (cierre_id),
    INDEX idx_fase (fase),
    INDEX idx_webhook_status (webhook_status),
    INDEX idx_firmado_at (firmado_at)
  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";

  return (bool)$conn->query($sql);
}

function cierreFirmasTableExists($conn) {
  $result = $conn->query("SHOW TABLES LIKE 'cierre_firmas'");

  if ($result && $result->num_rows > 0) {
    return true;
  }

  return cierreFirmasEnsureTable($conn);
}

function cierreFirmasFetchCierre($conn, $cierreId) {
  $cierreId = (int)$cierreId;

  if ($cierreId <= 0) {
    return null;
  }

  $sql = "SELECT c.*, u.email AS user_email
          FROM cierres_mensuales c
          LEFT JOIN users u ON u.id = c.user_id
          WHERE c.id = ?
          LIMIT 1";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return null;
  }

  $stmt->bind_param("i", $cierreId);
  $stmt->execute();
  $result = $stmt->get_result();

  if (!$result) {
    return null;
  }

  return $result->fetch_assoc();
}

function cierreFirmasNormalizarFase($fase) {
  $fase = trim((string)$fase);
  $permitidas = ['comercial', 'admin', 'contabilidad'];

  return in_array($fase, $permitidas, true) ? $fase : '';
}

function cierreFirmasPrefijo($fase) {
  if ($fase === 'comercial') {
    return 'CIAL';
  }

  if ($fase === 'admin') {
    return 'JDTO';
  }

  if ($fase === 'contabilidad') {
    return 'CONT';
  }

  return 'CIE';
}

function cierreFirmasSecret() {
  if (defined('CIERRE_SIGNATURE_SECRET') && trim((string)CIERRE_SIGNATURE_SECRET) !== '') {
    return (string)CIERRE_SIGNATURE_SECRET;
  }

  if (defined('FICHAJE_SIGNATURE_SECRET') && trim((string)FICHAJE_SIGNATURE_SECRET) !== '') {
    return 'cierre|' . (string)FICHAJE_SIGNATURE_SECRET;
  }

  throw new RuntimeException('Esta operación no está disponible en este momento.');
}

function cierreFirmasGenerarFirma($fase, $cierre, $actor, $firmadoAt) {
  $fase = cierreFirmasNormalizarFase($fase);
  $prefijo = cierreFirmasPrefijo($fase);

  $base = implode('|', [
    'cierre_firma',
    $fase,
    (int)($cierre['id'] ?? 0),
    (int)($cierre['user_id'] ?? 0),
    (string)($cierre['username'] ?? ''),
    (string)($cierre['comercial'] ?? ''),
    (int)($cierre['mes'] ?? 0),
    (int)($cierre['anio'] ?? 0),
    (int)($actor['user_id'] ?? 0),
    (string)($actor['username'] ?? ''),
    (string)($actor['rol'] ?? ''),
    (string)$firmadoAt,
    cierreFirmasSecret()
  ]);

  return $prefijo . '-' . strtoupper(substr(hash('sha256', $base), 0, 16));
}

function cierreFirmasActorDesdeSesion() {
  return [
    'user_id' => (int)($_SESSION['user_id'] ?? 0),
    'username' => $_SESSION['user'] ?? '',
    'comercial' => $_SESSION['comercial'] ?? ($_SESSION['user'] ?? ''),
    'rol' => $_SESSION['role'] ?? ''
  ];
}

function cierreFirmasMesNombre($mes) {
  $mes = (int)$mes;

  $nombres = [
    1 => 'Enero',
    2 => 'Febrero',
    3 => 'Marzo',
    4 => 'Abril',
    5 => 'Mayo',
    6 => 'Junio',
    7 => 'Julio',
    8 => 'Agosto',
    9 => 'Septiembre',
    10 => 'Octubre',
    11 => 'Noviembre',
    12 => 'Diciembre'
  ];

  return $nombres[$mes] ?? (string)$mes;
}

function cierreFirmasGetByFase($conn, $cierreId, $fase) {
  if (!cierreFirmasTableExists($conn)) {
    return null;
  }

  $cierreId = (int)$cierreId;
  $fase = cierreFirmasNormalizarFase($fase);

  if ($cierreId <= 0 || $fase === '') {
    return null;
  }

  $sql = "SELECT * FROM cierre_firmas WHERE cierre_id = ? AND fase = ? LIMIT 1";
  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return null;
  }

  $stmt->bind_param("is", $cierreId, $fase);
  $stmt->execute();
  $result = $stmt->get_result();

  if (!$result) {
    return null;
  }

  return $result->fetch_assoc();
}

function cierreFirmasInsertar($conn, $cierre, $fase, $actor) {
  $fase = cierreFirmasNormalizarFase($fase);
  $firmadoAt = cierreFirmasNow();
  $firma = cierreFirmasGenerarFirma($fase, $cierre, $actor, $firmadoAt);
  $createdAt = $firmadoAt;

  $cierreId = (int)($cierre['id'] ?? 0);
  $actorUserId = (int)($actor['user_id'] ?? 0);
  $actorUsername = (string)($actor['username'] ?? '');
  $actorComercial = (string)($actor['comercial'] ?? '');
  $actorRol = (string)($actor['rol'] ?? '');

  $sql = "INSERT INTO cierre_firmas
          (
            cierre_id,
            fase,
            firma,
            firmado_por_user_id,
            firmado_por_username,
            firmado_por_comercial,
            firmado_por_rol,
            firmado_at,
            webhook_status,
            created_at,
            updated_at
          )
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?, ?)";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return [
      'ok' => false,
      'message' => 'No se pudo preparar la firma del cierre: ' . $conn->error
    ];
  }

  $stmt->bind_param(
    "ississssss",
    $cierreId,
    $fase,
    $firma,
    $actorUserId,
    $actorUsername,
    $actorComercial,
    $actorRol,
    $firmadoAt,
    $createdAt,
    $createdAt
  );

  if (!$stmt->execute()) {
    return [
      'ok' => false,
      'message' => 'No se pudo guardar la firma del cierre: ' . $stmt->error
    ];
  }

  return cierreFirmasGetByFase($conn, $cierreId, $fase);
}

function cierreFirmasPayload($cierre, $firmaRow, $actor, $extras = []) {
  $mes = (int)($cierre['mes'] ?? 0);
  $anio = (int)($cierre['anio'] ?? 0);
  $periodo = str_pad((string)$mes, 2, '0', STR_PAD_LEFT) . '/' . $anio;
  $periodKey = (int)($cierre['user_id'] ?? 0) . '_' . str_pad((string)$mes, 2, '0', STR_PAD_LEFT) . '_' . $anio;
  $firmadoAt = (string)($firmaRow['firmado_at'] ?? cierreFirmasNow());
  $fechaFirma = '';
  $horaFirma = '';

  if ($firmadoAt !== '') {
    $timestamp = strtotime($firmadoAt);

    if ($timestamp !== false) {
      $fechaFirma = date('d-m-Y', $timestamp);
      $horaFirma = date('H:i', $timestamp);
    }
  }

  return [
    'tipo' => 'firma_cierre',
    'tipo_cierre' => 'visa',
    'fase' => $firmaRow['fase'] ?? '',
    'cierre_id' => (int)($cierre['id'] ?? 0),

    'user_id' => (int)($cierre['user_id'] ?? 0),
    'username' => $cierre['username'] ?? '',
    'comercial' => $cierre['comercial'] ?? '',
    'email_comercial' => $cierre['user_email'] ?? '',

    'mes' => $mes,
    'anio' => $anio,
    'periodo' => $periodo,
    'periodo_nombre' => cierreFirmasMesNombre($mes) . ' ' . $anio,
    'period_key' => $periodKey,

    'estado_cierre' => $cierre['estado'] ?? '',
    'importe_app' => isset($cierre['importe_app']) ? (float)$cierre['importe_app'] : 0,
    'importe_banco' => isset($cierre['importe_banco']) ? (float)$cierre['importe_banco'] : 0,
    'diferencia' => isset($cierre['diferencia']) ? (float)$cierre['diferencia'] : 0,

    'firma' => $firmaRow['firma'] ?? '',
    'firmado_por_user_id' => (int)($firmaRow['firmado_por_user_id'] ?? 0),
    'firmado_por_username' => $firmaRow['firmado_por_username'] ?? '',
    'firmado_por_comercial' => $firmaRow['firmado_por_comercial'] ?? '',
    'firmado_por_rol' => $firmaRow['firmado_por_rol'] ?? '',
    'firmado_at' => $firmadoAt,
    'fecha_firma' => $fechaFirma,
    'hora_firma' => $horaFirma,

    'actor' => [
      'user_id' => (int)($actor['user_id'] ?? 0),
      'username' => $actor['username'] ?? '',
      'comercial' => $actor['comercial'] ?? '',
      'rol' => $actor['rol'] ?? ''
    ],

    'extras' => $extras,
    'origen' => 'app_gastos'
  ];
}

function cierreFirmasActualizarWebhook($conn, $firmaId, $status, $response, $error = null) {
  $firmaId = (int)$firmaId;

  if ($firmaId <= 0) {
    return false;
  }

  $now = cierreFirmasNow();
  $status = in_array($status, ['pendiente', 'enviado', 'error'], true) ? $status : 'error';
  $responseJson = $response !== null ? json_encode($response, JSON_UNESCAPED_UNICODE) : null;
  $errorText = $error !== null ? (string)$error : null;

  $sql = "UPDATE cierre_firmas
          SET webhook_status = ?,
              webhook_response = ?,
              ultimo_error = ?,
              updated_at = ?
          WHERE id = ?
          LIMIT 1";

  try {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
      return false;
    }

    $stmt->bind_param("ssssi", $status, $responseJson, $errorText, $now, $firmaId);

    return $stmt->execute();
  } catch (Throwable $e) {
    return false;
  }
}

function cierreFirmasEnviarWebhook(&$conn, $cierre, $firmaRow, $actor, $extras = []) {
  if (!defined('MAKE_WEBHOOK_FIRMA_CIERRE') || trim((string)MAKE_WEBHOOK_FIRMA_CIERRE) === '') {
    cierreFirmasActualizarWebhook($conn, (int)($firmaRow['id'] ?? 0), 'error', null, 'MAKE_WEBHOOK_FIRMA_CIERRE no configurado');

    return [
      'ok' => false,
      'skipped' => true,
      'message' => 'No se ha podido completar la firma.'
    ];
  }

  if (!function_exists('callMakeWebhook')) {
    cierreFirmasActualizarWebhook($conn, (int)($firmaRow['id'] ?? 0), 'error', null, 'La función callMakeWebhook no está disponible');

    return [
      'ok' => false,
      'skipped' => false,
      'message' => 'No se ha podido completar la firma.'
    ];
  }

  $payload = cierreFirmasPayload($cierre, $firmaRow, $actor, $extras);
  $result = callMakeWebhook(MAKE_WEBHOOK_FIRMA_CIERRE, $payload, 180);
  $result = makeWebhookExigirOkExplicita($result, 'firma de cierre');

  if (!appEnsureMysqlConnection($conn)) {
    return [
      'ok' => false,
      'confirmed' => !empty($result['ok']),
      'skipped' => false,
      'http_code' => $result['http_code'] ?? null,
      'response_raw' => $result['response_raw'] ?? null,
      'response_json' => $result['response_json'] ?? null,
      'message' => 'La firma se ha guardado, pero no se ha podido completar la actualización.'
    ];
  }

  if (!empty($result['ok'])) {
    cierreFirmasActualizarWebhook($conn, (int)($firmaRow['id'] ?? 0), 'enviado', $result, null);
  } else {
    cierreFirmasActualizarWebhook($conn, (int)($firmaRow['id'] ?? 0), 'error', $result, $result['message'] ?? 'Error desconocido al enviar firma de cierre');
  }

  return $result;
}

function cierreFirmasGenerarYEnviar(&$conn, $fase, $cierre, $actor = null, $extras = []) {
  $fase = cierreFirmasNormalizarFase($fase);

  if ($fase === '') {
    return [
      'ok' => false,
      'skipped' => true,
      'message' => 'Fase de firma no válida'
    ];
  }

  if (!cierreFirmasTableExists($conn)) {
    return [
      'ok' => false,
      'skipped' => true,
      'message' => 'Esta operación no está disponible en este momento.'
    ];
  }

  if (!$cierre || (int)($cierre['id'] ?? 0) <= 0) {
    return [
      'ok' => false,
      'skipped' => true,
      'message' => 'Cierre no válido para firma'
    ];
  }

  if ($actor === null) {
    $actor = cierreFirmasActorDesdeSesion();
  }

  $firmaRow = cierreFirmasGetByFase($conn, (int)$cierre['id'], $fase);

  if (!$firmaRow) {
    $firmaRow = cierreFirmasInsertar($conn, $cierre, $fase, $actor);

    if (!$firmaRow || empty($firmaRow['id'])) {
      return [
        'ok' => false,
        'skipped' => false,
        'message' => is_array($firmaRow) ? ($firmaRow['message'] ?? 'No se pudo crear la firma') : 'No se pudo crear la firma'
      ];
    }
  }

  $forzarEnvio = !empty($extras['forzar_envio_webhook']);

  if (array_key_exists('forzar_envio_webhook', $extras)) {
    unset($extras['forzar_envio_webhook']);
  }

  if (($firmaRow['webhook_status'] ?? '') === 'enviado' && !$forzarEnvio) {
    return [
      'ok' => true,
      'skipped' => true,
      'message' => 'La firma ya estaba generada y enviada',
      'firma' => $firmaRow['firma'] ?? ''
    ];
  }

  if ($forzarEnvio) {
    cierreFirmasActualizarWebhook(
      $conn,
      (int)($firmaRow['id'] ?? 0),
      'pendiente',
      null,
      null
    );
  }

  $webhookResult = cierreFirmasEnviarWebhook($conn, $cierre, $firmaRow, $actor, $extras);

  return [
    'ok' => !empty($webhookResult['ok']),
    'skipped' => !empty($webhookResult['skipped']),
    'message' => $webhookResult['message'] ?? '',
    'firma' => $firmaRow['firma'] ?? '',
    'webhook_result' => $webhookResult
  ];
}
?>

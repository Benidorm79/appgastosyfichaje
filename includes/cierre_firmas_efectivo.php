<?php

function cierreEfectivoFirmasNow()
{
    date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

    return date('Y-m-d H:i:s');
}

function cierreEfectivoFirmasEnsureTable($conn)
{
    $sql = "CREATE TABLE IF NOT EXISTS cierre_firmas_efectivo (
      id INT AUTO_INCREMENT PRIMARY KEY,
      cierre_id INT NOT NULL,
      fase ENUM('comercial','admin','contabilidad') NOT NULL,
      firma VARCHAR(100) NOT NULL,
      firmado_por_user_id INT NULL,
      firmado_por_username VARCHAR(100) NULL,
      firmado_por_comercial VARCHAR(150) NULL,
      firmado_por_rol VARCHAR(50) NULL,
      firmado_at DATETIME NOT NULL,
      webhook_status ENUM('pendiente','enviado','error') NOT NULL DEFAULT 'pendiente',
      webhook_response LONGTEXT NULL,
      ultimo_error TEXT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NULL,
      UNIQUE KEY uniq_cierre_efectivo_fase (cierre_id, fase),
      INDEX idx_cierre_firma_efectivo_cierre (cierre_id),
      INDEX idx_cierre_firma_efectivo_status (webhook_status)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";

    return (bool)$conn->query($sql);
}

function cierreEfectivoFirmasTableExists($conn)
{
    $result = $conn->query("SHOW TABLES LIKE 'cierre_firmas_efectivo'");

    if ($result && $result->num_rows > 0) {
        return true;
    }

    return cierreEfectivoFirmasEnsureTable($conn);
}

function cierreEfectivoFirmasFetchCierre($conn, $cierreId)
{
    $stmt = $conn->prepare(
        "SELECT c.*, u.email AS user_email
         FROM cierres_mensuales_efectivo c
         LEFT JOIN users u ON u.id = c.user_id
         WHERE c.id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $cierreId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

function cierreEfectivoFirmasFase($fase)
{
    return in_array($fase, ['comercial', 'admin', 'contabilidad'], true) ? $fase : '';
}

function cierreEfectivoFirmasPrefijo($fase)
{
    return [
        'comercial' => 'CIAL',
        'admin' => 'JDTO',
        'contabilidad' => 'CONT'
    ][$fase] ?? 'CIEE';
}

function cierreEfectivoFirmasGenerar($fase, $cierre, $actor, $fecha)
{
    $secret = defined('CIERRE_SIGNATURE_SECRET') ? trim((string)CIERRE_SIGNATURE_SECRET) : '';
    if ($secret === '') throw new RuntimeException('Esta operación no está disponible en este momento.');

    $base = implode('|', [
        'cierre_efectivo',
        $fase,
        (int)($cierre['id'] ?? 0),
        (int)($cierre['user_id'] ?? 0),
        (string)($cierre['username'] ?? ''),
        (int)($cierre['mes'] ?? 0),
        (int)($cierre['anio'] ?? 0),
        (int)($actor['user_id'] ?? 0),
        (string)($actor['username'] ?? ''),
        (string)$fecha,
        $secret
    ]);

    return cierreEfectivoFirmasPrefijo($fase) . '-' . strtoupper(substr(hash('sha256', $base), 0, 16));
}

function cierreEfectivoFirmasGet($conn, $cierreId, $fase)
{
    if (!cierreEfectivoFirmasTableExists($conn)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT *
         FROM cierre_firmas_efectivo
         WHERE cierre_id = ? AND fase = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('is', $cierreId, $fase);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

function cierreEfectivoFirmasGuardar($conn, $fase, $cierre, $actor)
{
    $existente = cierreEfectivoFirmasGet($conn, (int)$cierre['id'], $fase);

    if ($existente) {
        return $existente;
    }

    $fecha = cierreEfectivoFirmasNow();
    $firma = cierreEfectivoFirmasGenerar($fase, $cierre, $actor, $fecha);
    $actorId = (int)($actor['user_id'] ?? 0);
    $actorUsername = (string)($actor['username'] ?? '');
    $actorComercial = (string)($actor['comercial'] ?? '');
    $actorRol = (string)($actor['rol'] ?? '');
    $cierreId = (int)$cierre['id'];

    $stmt = $conn->prepare(
        "INSERT INTO cierre_firmas_efectivo
         (
           cierre_id, fase, firma, firmado_por_user_id,
           firmado_por_username, firmado_por_comercial, firmado_por_rol,
           firmado_at, webhook_status, created_at, updated_at
         )
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?, ?)"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        'ississssss',
        $cierreId,
        $fase,
        $firma,
        $actorId,
        $actorUsername,
        $actorComercial,
        $actorRol,
        $fecha,
        $fecha,
        $fecha
    );

    if (!$stmt->execute()) {
        return null;
    }

    return cierreEfectivoFirmasGet($conn, $cierreId, $fase);
}

function cierreEfectivoFirmasActualizar($conn, $id, $status, $response, $error)
{
    $now = cierreEfectivoFirmasNow();
    $responseJson = $response !== null
        ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;

    try {
        $stmt = $conn->prepare(
            "UPDATE cierre_firmas_efectivo
             SET webhook_status = ?, webhook_response = ?, ultimo_error = ?, updated_at = ?
             WHERE id = ?
             LIMIT 1"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ssssi', $status, $responseJson, $error, $now, $id);

        return $stmt->execute();
    } catch (Throwable $e) {
        return false;
    }
}

function cierreEfectivoFirmasGenerarYEnviar(&$conn, $fase, $cierre, $actor, $extras = [])
{
    $fase = cierreEfectivoFirmasFase($fase);

    if ($fase === '' || !cierreEfectivoFirmasTableExists($conn)) {
        return ['ok' => false, 'skipped' => true];
    }

    $firma = cierreEfectivoFirmasGuardar($conn, $fase, $cierre, $actor);

    if (!$firma) {
        return ['ok' => false, 'message' => 'No se pudo guardar la firma de Efectivo y Kilometraje.'];
    }

    $forzarEnvio = !empty($extras['forzar_envio_webhook']);

    if (array_key_exists('forzar_envio_webhook', $extras)) {
        unset($extras['forzar_envio_webhook']);
    }

    if (($firma['webhook_status'] ?? '') === 'enviado' && !$forzarEnvio) {
        return [
            'ok' => true,
            'skipped' => true,
            'message' => 'La firma ya estaba generada y enviada',
            'firma' => (string)($firma['firma'] ?? '')
        ];
    }

    if ($forzarEnvio) {
        cierreEfectivoFirmasActualizar(
            $conn,
            (int)$firma['id'],
            'pendiente',
            null,
            null
        );
    }

    if (!defined('MAKE_WEBHOOK_FIRMA_CIERRE') || trim((string)MAKE_WEBHOOK_FIRMA_CIERRE) === '') {
        cierreEfectivoFirmasActualizar(
            $conn,
            (int)$firma['id'],
            'error',
            null,
            'MAKE_WEBHOOK_FIRMA_CIERRE no configurado'
        );

        return ['ok' => false, 'skipped' => true];
    }

    $firmadoAt = (string)$firma['firmado_at'];
    $timestamp = strtotime($firmadoAt);
    $mes = (int)$cierre['mes'];
    $anio = (int)$cierre['anio'];
    $periodKey = (int)$cierre['user_id'] . '_' . str_pad((string)$mes, 2, '0', STR_PAD_LEFT) . '_' . $anio . '_efectivo';

    $payload = [
        'tipo' => 'firma_cierre',
        'tipo_cierre' => 'efectivo',
        'fase' => $fase,
        'cierre_id' => (int)$cierre['id'],
        'user_id' => (int)$cierre['user_id'],
        'username' => (string)$cierre['username'],
        'comercial' => (string)$cierre['comercial'],
        'email_comercial' => (string)($cierre['user_email'] ?? ''),
        'mes' => $mes,
        'anio' => $anio,
        'period_key' => $periodKey,
        'estado_cierre' => (string)$cierre['estado'],
        'importe_app' => (float)$cierre['importe_app'],
        'importe_banco' => (float)$cierre['importe_banco'],
        'diferencia' => (float)$cierre['diferencia'],
        'firma' => (string)$firma['firma'],
        'firmado_por_user_id' => (int)$firma['firmado_por_user_id'],
        'firmado_por_username' => (string)$firma['firmado_por_username'],
        'firmado_por_comercial' => (string)$firma['firmado_por_comercial'],
        'firmado_por_rol' => (string)$firma['firmado_por_rol'],
        'firmado_at' => $firmadoAt,
        'fecha_firma' => $timestamp ? date('d-m-Y', $timestamp) : '',
        'hora_firma' => $timestamp ? date('H:i', $timestamp) : '',
        'extras' => $extras,
        'origen' => 'app_gastos'
    ];

    $result = callMakeWebhook(MAKE_WEBHOOK_FIRMA_CIERRE, $payload, 180);
    $result = makeWebhookExigirOkExplicita($result, 'firma de cierre de Efectivo y Kilometraje');

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
        cierreEfectivoFirmasActualizar($conn, (int)$firma['id'], 'enviado', $result, null);
    } else {
        cierreEfectivoFirmasActualizar(
            $conn,
            (int)$firma['id'],
            'error',
            $result,
            (string)($result['message'] ?? 'Error al enviar la firma')
        );
    }

    return $result;
}

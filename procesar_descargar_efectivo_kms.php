<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';

securitySendHeaders();
requirePostMethod();

$payload = safeJsonBody();
requireCsrfFromRequest($payload);

header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = trim((string)($_SESSION['user'] ?? $_SESSION['username'] ?? ''));
$comercial = trim((string)($_SESSION['comercial'] ?? ''));
$mes = (int)($payload['mes'] ?? 0);
$anio = (int)($payload['anio'] ?? 0);

if ($userId <= 0 || $mes < 1 || $mes > 12 || $anio < 2020 || $anio > 2100) {
    echo json_encode([
        'ok' => false,
        'message' => 'No se pudo identificar el usuario o el periodo seleccionado no es válido.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (
    !defined('MAKE_WEBHOOK_DESCARGAR_EFECTIVO_KMS')
    || trim((string)MAKE_WEBHOOK_DESCARGAR_EFECTIVO_KMS) === ''
) {
    echo json_encode([
        'ok' => false,
        'message' => 'Esta descarga no está disponible en este momento.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$totalKms = 0.0;
$totalGastosEfectivo = 0.0;
$totalGastosKms = 0.0;
$resumenConJustificante = 0;
$tickets = [];

/*
 * 1. Resumen de gastos en efectivo procesados.
 */
$stmt = $conn->prepare(
    "SELECT
        COALESCE(SUM(importe), 0) AS total_efectivo,
        COALESCE(SUM(
            CASE
                WHEN (
                    (drive_file_id IS NOT NULL AND drive_file_id <> '')
                    OR (drive_file_url IS NOT NULL AND drive_file_url <> '')
                ) THEN 1
                ELSE 0
            END
        ), 0) AS con_justificante
     FROM efectivo_gastos
     WHERE user_id = ?
       AND estado = 'procesado'
       AND MONTH(fecha) = ?
       AND YEAR(fecha) = ?"
);

if (!$stmt) {
    echo json_encode([
        'ok' => false,
        'message' => 'No se pudo preparar el resumen de gastos en efectivo.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt->bind_param('iii', $userId, $mes, $anio);
$stmt->execute();
$resultadoEfectivo = $stmt->get_result();
$filaEfectivo = $resultadoEfectivo ? $resultadoEfectivo->fetch_assoc() : null;
$stmt->close();

if ($filaEfectivo) {
    $totalGastosEfectivo = round((float)($filaEfectivo['total_efectivo'] ?? 0), 2);
    $resumenConJustificante = (int)($filaEfectivo['con_justificante'] ?? 0);
}

/*
 * 2. Listado de tickets de efectivo para que Make pueda mapearlos,
 *    descargarlos desde Drive y generar el documento correspondiente.
 */
$stmt = $conn->prepare(
    "SELECT
        id,
        user_id,
        username,
        comercial,
        fecha,
        motivo,
        importe,
        drive_file_id,
        drive_file_url,
        nombre_archivo,
        mime_type
     FROM efectivo_gastos
     WHERE user_id = ?
       AND estado = 'procesado'
       AND MONTH(fecha) = ?
       AND YEAR(fecha) = ?
       AND (
            (drive_file_id IS NOT NULL AND drive_file_id <> '')
            OR (drive_file_url IS NOT NULL AND drive_file_url <> '')
       )
     ORDER BY fecha ASC, id ASC"
);

if (!$stmt) {
    echo json_encode([
        'ok' => false,
        'message' => 'No se pudo preparar el listado de justificantes de efectivo.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt->bind_param('iii', $userId, $mes, $anio);
$stmt->execute();
$resultadoTickets = $stmt->get_result();

while ($row = $resultadoTickets->fetch_assoc()) {
    $tickets[] = [
        'efectivo_id' => (int)$row['id'],
        'gasto_id' => (int)$row['id'],
        'tipo_gasto' => 'efectivo',
        'user_id' => (int)$row['user_id'],
        'username' => (string)$row['username'],
        'comercial' => (string)$row['comercial'],
        'motivo' => (string)$row['motivo'],
        'importe' => round((float)$row['importe'], 2),
        'fecha_ticket' => (string)$row['fecha'],
        'fecha_ticket_web' => formatFechaWeb((string)$row['fecha']),
        'filename' => (string)($row['nombre_archivo'] ?? ''),
        'nombre_archivo' => (string)($row['nombre_archivo'] ?? ''),
        'mime_type' => (string)($row['mime_type'] ?? ''),
        'drive_file_id' => (string)($row['drive_file_id'] ?? ''),
        'drive_file_url' => (string)($row['drive_file_url'] ?? '')
    ];
}

$stmt->close();

/*
 * 3. Resumen de kilometraje procesado.
 */
$stmt = $conn->prepare(
    "SELECT
        COALESCE(SUM(kilometros), 0) AS total_kms,
        COALESCE(SUM(importe), 0) AS total_importe_kms
     FROM kilometrajes
     WHERE user_id = ?
       AND estado = 'procesado'
       AND MONTH(fecha) = ?
       AND YEAR(fecha) = ?"
);

if (!$stmt) {
    echo json_encode([
        'ok' => false,
        'message' => 'No se pudo preparar el resumen de kilometraje.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt->bind_param('iii', $userId, $mes, $anio);
$stmt->execute();
$resultadoKms = $stmt->get_result();
$filaKms = $resultadoKms ? $resultadoKms->fetch_assoc() : null;
$stmt->close();

if ($filaKms) {
    $totalKms = round((float)($filaKms['total_kms'] ?? 0), 2);
    $totalGastosKms = round((float)($filaKms['total_importe_kms'] ?? 0), 2);
}

$fechaGeneracion = date('Y-m-d H:i:s');

/*
 * Se conservan todos los campos añadidos anteriormente y se incorpora
 * el array tickets sin renombrar ninguna variable existente.
 */
$webhookPayload = [
    'user_id' => $userId,
    'username' => $username,
    'comercial' => $comercial,
    'mes' => $mes,
    'anio' => $anio,
    'total_kms' => $totalKms,
    'total_gastos_efectivo' => $totalGastosEfectivo,
    'total_gastos_kms' => $totalGastosKms,
    'fecha_generacion' => $fechaGeneracion,
    'resumen_gastos_con_justificante' => $resumenConJustificante,
    'total_tickets' => count($tickets),
    'tickets' => $tickets
];

$respuestaMake = callMakeWebhook(
    MAKE_WEBHOOK_DESCARGAR_EFECTIVO_KMS,
    $webhookPayload,
    180
);

if (empty($respuestaMake['ok'])) {
    echo json_encode([
        'ok' => false,
        'message' => 'No se ha podido preparar la descarga. Inténtalo de nuevo.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$respuestaJson = is_array($respuestaMake['response_json'] ?? null)
    ? $respuestaMake['response_json']
    : [];

$fileUrl = $respuestaJson['file_url']
    ?? $respuestaJson['excel_file_url']
    ?? $respuestaJson['pdf_file_url']
    ?? $respuestaJson['webViewLink']
    ?? $respuestaJson['web_view_link']
    ?? null;

echo json_encode([
    'ok' => true,
    'message' => 'La descarga está preparada.',
    'file_url' => $fileUrl,
    'resumen' => [
        'total_kms' => $totalKms,
        'total_gastos_efectivo' => $totalGastosEfectivo,
        'total_gastos_kms' => $totalGastosKms,
        'fecha_generacion' => $fechaGeneracion,
        'resumen_gastos_con_justificante' => $resumenConJustificante,
        'total_tickets' => count($tickets)
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

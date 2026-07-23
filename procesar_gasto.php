<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/auditoria.php';

securitySendHeaders();
requirePostMethod();
$payload = safeJsonBody();
requireCsrfFromRequest($payload);

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = trim((string)($_SESSION['user'] ?? $_SESSION['username'] ?? ''));
$comercial = trim((string)($_SESSION['comercial'] ?? ''));
$viaje = trim((string)($payload['viaje'] ?? ''));
$motivo = trim((string)($payload['motivo'] ?? ''));
$comentarios = trim((string)($payload['comentarios'] ?? ''));
$foto = is_array($payload['foto'] ?? null) ? $payload['foto'] : [];

if ($userId <= 0 || $username === '' || $comercial === '') {
    appJson(['ok' => false, 'message' => 'Tu sesión ha caducado. Vuelve a iniciar sesión.'], 401);
}

if ($viaje === '' || $motivo === '' || empty($foto['data'])) {
    appJson(['ok' => false, 'message' => 'Completa los datos obligatorios y selecciona la imagen del ticket.'], 422);
}

if (strlen((string)$foto['data']) > 16 * 1024 * 1024) {
    appJson(['ok' => false, 'message' => 'La imagen seleccionada es demasiado grande.'], 413);
}

$stmt = $conn->prepare(
    "INSERT INTO gastos (user_id, username, comercial, viaje, motivo, comentarios, estado)
     VALUES (?, ?, ?, ?, ?, ?, 'pendiente')"
);

if (!$stmt) {
    appLogError('No se pudo preparar un gasto', $conn->error);
    appJson(['ok' => false, 'message' => appPublicError()], 500);
}

$stmt->bind_param('isssss', $userId, $username, $comercial, $viaje, $motivo, $comentarios);

if (!$stmt->execute()) {
    appLogError('No se pudo crear un gasto', $stmt->error);
    appJson(['ok' => false, 'message' => appPublicError()], 500);
}

$registroId = (int)$stmt->insert_id;
$gastoUid = 'GASTO-' . date('Ymd') . '-' . str_pad((string)$registroId, 6, '0', STR_PAD_LEFT);
$stmt = $conn->prepare('UPDATE gastos SET gasto_uid = ? WHERE id = ?');
if ($stmt) {
    $stmt->bind_param('si', $gastoUid, $registroId);
    $stmt->execute();
}

$fail = static function (string $internalMessage, int $status = 502) use ($conn, $registroId, $gastoUid): void {
    appLogError('No se completó el gasto ' . $gastoUid, $internalMessage);
    $stmtError = $conn->prepare("UPDATE gastos SET estado = 'error', make_response = ? WHERE id = ?");
    if ($stmtError) {
        $stmtError->bind_param('si', $internalMessage, $registroId);
        $stmtError->execute();
    }
    auditoriaRegistrarSeguro($conn, [
        'tipo_evento' => 'gasto',
        'entidad' => 'gasto',
        'entidad_id' => $registroId,
        'accion' => 'gasto_ticket_no_completado',
        'descripcion' => 'El gasto con ticket no pudo completarse.',
        'estado_anterior' => 'pendiente',
        'estado_nuevo' => 'error',
        'datos' => ['detalle_interno' => $internalMessage]
    ]);
    appJson(['ok' => false, 'message' => 'No se ha podido registrar el gasto. Inténtalo de nuevo.'], $status);
};

if (trim(MAKE_WEBHOOK_NUEVO_GASTO) === '') {
    $fail('Integración de nuevo gasto no configurada', 503);
}

$externalPayload = [
    'user_id' => $userId,
    'registro_id' => $registroId,
    'gasto_uid' => $gastoUid,
    'username' => $username,
    'comercial' => $comercial,
    'viaje' => $viaje,
    'motivo' => $motivo,
    'comentarios' => $comentarios,
    'origen' => 'ticket',
    'foto' => $foto
];

$result = callMakeWebhook(MAKE_WEBHOOK_NUEVO_GASTO, $externalPayload, 180);
$result = makeWebhookExigirOkExplicita($result, 'nuevo gasto');

if (empty($result['ok']) || !is_array($result['response_json'] ?? null)) {
    $fail((string)($result['message'] ?? 'Respuesta externa no válida'));
}

$data = $result['response_json'];
$importe = isset($data['importe']) ? str_replace(',', '.', (string)$data['importe']) : null;
$fechaTicket = $data['fecha_ticket'] ?? null;
$fechaImputacion = $data['fecha_imputacion'] ?? null;
$driveFolderId = $data['drive_folder_id'] ?? null;
$driveFolderUrl = $data['drive_folder_url'] ?? null;
$tickets = is_array($data['tickets'] ?? null) ? $data['tickets'] : [];
$technicalResponse = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

$hasFechaImputacion = false;
$columnResult = $conn->query("SHOW COLUMNS FROM gastos LIKE 'fecha_imputacion'");
if ($columnResult && $columnResult->num_rows > 0) $hasFechaImputacion = true;

if ($hasFechaImputacion) {
    $stmt = $conn->prepare(
        "UPDATE gastos SET importe_detectado = ?, fecha_ticket = ?, fecha_imputacion = ?,
         drive_folder_id = ?, drive_folder_url = ?, make_response = ?, estado = 'procesado' WHERE id = ?"
    );
    if ($stmt) $stmt->bind_param('ssssssi', $importe, $fechaTicket, $fechaImputacion, $driveFolderId, $driveFolderUrl, $technicalResponse, $registroId);
} else {
    $stmt = $conn->prepare(
        "UPDATE gastos SET importe_detectado = ?, fecha_ticket = ?, drive_folder_id = ?,
         drive_folder_url = ?, make_response = ?, estado = 'procesado' WHERE id = ?"
    );
    if ($stmt) $stmt->bind_param('sssssi', $importe, $fechaTicket, $driveFolderId, $driveFolderUrl, $technicalResponse, $registroId);
}

if (!$stmt || !$stmt->execute()) {
    $fail('No se pudo finalizar el registro local', 500);
}

foreach ($tickets as $index => $ticket) {
    if (!is_array($ticket)) continue;
    $filename = (string)($ticket['filename'] ?? ('ticket_' . ($index + 1) . '.jpeg'));
    $mimeType = (string)($ticket['mime_type'] ?? 'image/jpeg');
    $fileId = $ticket['drive_file_id'] ?? null;
    $fileUrl = $ticket['drive_file_url'] ?? null;
    $order = $index + 1;
    $ticketStmt = $conn->prepare(
        'INSERT INTO gasto_tickets (gasto_id, gasto_uid, filename, mime_type, drive_file_id, drive_file_url, orden)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if ($ticketStmt) {
        $ticketStmt->bind_param('isssssi', $registroId, $gastoUid, $filename, $mimeType, $fileId, $fileUrl, $order);
        $ticketStmt->execute();
    }
}

auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'gasto',
    'entidad' => 'gasto',
    'entidad_id' => $registroId,
    'accion' => 'gasto_ticket_procesado',
    'descripcion' => 'Gasto con ticket registrado correctamente.',
    'estado_anterior' => 'pendiente',
    'estado_nuevo' => 'procesado',
    'datos' => ['gasto_uid' => $gastoUid, 'total_tickets' => count($tickets)]
]);

appJson([
    'ok' => true,
    'message' => 'Gasto registrado correctamente.',
    'registro_id' => $registroId,
    'gasto_uid' => $gastoUid,
    'motivo' => $motivo,
    'fecha_ticket' => $fechaTicket,
    'importe' => $importe
]);

<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';

securitySendHeaders();
requirePostMethod();
$payload = safeJsonBody();
requireCsrfFromRequest($payload);

$id = (int)($payload['id'] ?? 0);
$fecha = trim((string)($payload['fecha_ticket'] ?? ''));
$importe = normalizeMoney($payload['importe_detectado'] ?? '');

if ($id <= 0 || !validateDateYmd($fecha) || $importe === null) {
    appJson(['ok' => false, 'message' => 'Los datos de la corrección no son válidos.'], 422);
}

$gasto = getGastoByIdForCurrentUser($conn, $id);
if (!$gasto) {
    appJson(['ok' => false, 'message' => 'No tienes acceso a este gasto.'], 403);
}

$actualizado = $gasto;
$actualizado['fecha_ticket'] = $fecha;
$actualizado['importe_detectado'] = $importe;
$actualizado['estado'] = 'editado';
$ticket = getTicketPrincipalByGasto($conn, $gasto['id'], $gasto['gasto_uid']);
$syncPayload = buildGastoSyncPayload($actualizado, $ticket, 'editar');
$syncPayload['valores_anteriores'] = [
    'fecha_ticket' => $gasto['fecha_ticket'],
    'importe_detectado' => $gasto['importe_detectado']
];
$syncPayload['valores_nuevos'] = [
    'fecha_ticket' => $fecha,
    'importe_detectado' => $importe
];
$syncPayload['motivo_edicion'] = 'Corrección inmediata tras procesamiento';

$result = callMakeWebhook(MAKE_WEBHOOK_EDITAR_GASTO, $syncPayload);
if (empty($result['ok'])) {
    appLogError('No se pudo completar la corrección rápida', $result['internal_message'] ?? null);
    appJson(['ok' => false, 'message' => 'No se ha podido guardar la corrección. Inténtalo de nuevo.'], 502);
}

$now = date('Y-m-d H:i:s');
$responseJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
$stmt = $conn->prepare(
    "UPDATE gastos
     SET fecha_ticket = ?, importe_detectado = ?, estado = 'editado', sync_status = 'sincronizado',
         make_update_response = ?, updated_at = ?
     WHERE id = ? AND user_id = ?"
);
if (!$stmt) {
    appLogError('No se pudo preparar la corrección rápida', $conn->error);
    appJson(['ok' => false, 'message' => appPublicError()], 500);
}

$userId = (int)$_SESSION['user_id'];
$stmt->bind_param('sdssii', $fecha, $importe, $responseJson, $now, $id, $userId);
if (!$stmt->execute()) {
    appLogError('No se pudo guardar la corrección rápida', $stmt->error);
    appJson(['ok' => false, 'message' => appPublicError()], 500);
}

appJson(['ok' => true, 'message' => 'Corrección guardada correctamente.']);

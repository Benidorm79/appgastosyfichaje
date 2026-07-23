<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/efectivo_kms.php';
require_once __DIR__ . '/includes/gastos_unificados.php';

securitySendHeaders();
requirePostMethod();
$payload = safeJsonBody();
requireCsrfFromRequest($payload);

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = trim((string)($_SESSION['user'] ?? ''));
$comercial = trim((string)($_SESSION['comercial'] ?? ''));
$fecha = trim((string)($payload['fecha'] ?? ''));
$motivo = trim((string)($payload['motivo'] ?? ''));
$importe = normalizeMoney($payload['importe'] ?? '');
$imagen = is_array($payload['imagen'] ?? null) ? $payload['imagen'] : [];

if ($userId <= 0 || !validateDateYmd($fecha) || $motivo === '' || mb_strlen($motivo) > 180 || $importe === null) {
    appJson(['ok' => false, 'message' => 'Revisa los datos obligatorios.'], 422);
}

$bloqueo = gastosUnificadosPeriodoEfectivoBloqueado($conn, $userId, $fecha);
if (!empty($bloqueo['bloqueado'])) appJson(['ok' => false, 'message' => (string)$bloqueo['motivo']], 409);

$filename = basename((string)($imagen['name'] ?? ''));
$mime = (string)($imagen['type'] ?? '');
$dataUrl = (string)($imagen['data'] ?? '');
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowed, true) || !preg_match('#^data:image/(jpeg|png|webp);base64,#', $dataUrl)) {
    appJson(['ok' => false, 'message' => 'El justificante debe ser JPG, PNG o WEBP.'], 422);
}

$binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
if ($binary === false || strlen($binary) > EFECTIVO_MAX_FILE_BYTES) {
    appJson(['ok' => false, 'message' => 'La imagen no es válida o supera el tamaño permitido.'], 422);
}

$now = date('Y-m-d H:i:s');
$stmt = $conn->prepare(
    "INSERT INTO efectivo_gastos
     (user_id, username, comercial, fecha, motivo, importe, nombre_archivo, mime_type, estado, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?)"
);
if (!$stmt) {
    appLogError('No se pudo preparar el gasto en efectivo', $conn->error);
    appJson(['ok' => false, 'message' => appPublicError()], 500);
}
$stmt->bind_param('issssdsss', $userId, $username, $comercial, $fecha, $motivo, $importe, $filename, $mime, $now);
if (!$stmt->execute()) {
    appLogError('No se pudo crear el gasto en efectivo', $stmt->error);
    appJson(['ok' => false, 'message' => appPublicError()], 500);
}
$id = (int)$stmt->insert_id;

$result = efectivoKmsCallWebhook([
    'tipo' => 'efectivo', 'registro_id' => $id, 'user_id' => $userId, 'username' => $username,
    'comercial' => $comercial, 'fecha' => $fecha, 'motivo' => $motivo, 'importe' => $importe,
    'imagen' => ['name' => $filename, 'type' => $mime, 'data' => $dataUrl]
]);
$technical = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
$response = is_array($result['response_json'] ?? null) ? $result['response_json'] : [];
$fileUrl = $response['drive_file_url'] ?? $response['file_url'] ?? null;
$fileId = $response['drive_file_id'] ?? $response['file_id'] ?? null;
$status = !empty($result['ok']) ? 'procesado' : 'error';
$stmt = $conn->prepare('UPDATE efectivo_gastos SET drive_file_id = ?, drive_file_url = ?, estado = ?, make_response = ?, updated_at = ? WHERE id = ?');
if ($stmt) { $stmt->bind_param('sssssi', $fileId, $fileUrl, $status, $technical, $now, $id); $stmt->execute(); }

if (empty($result['ok'])) {
    appLogError('No se completó el gasto en efectivo ' . $id, $result['internal_message'] ?? $result['message'] ?? '');
    appJson(['ok' => false, 'message' => 'No se ha podido registrar el gasto. Inténtalo de nuevo.'], 502);
}

appJson(['ok' => true, 'message' => 'Gasto en efectivo registrado correctamente.', 'id' => $id, 'file_url' => $fileUrl]);

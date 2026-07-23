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
$origen = trim((string)($payload['origen'] ?? ''));
$destino = trim((string)($payload['destino'] ?? ''));
$rutaUrl = trim((string)($payload['ruta_url'] ?? ''));
$kilometros = normalizeMoney($payload['kilometros'] ?? '');
$duracion = max(0, (int)($payload['duracion_minutos'] ?? 0));
$polyline = trim((string)($payload['ruta_polyline'] ?? ''));
$paradas = json_decode((string)($payload['paradas_json'] ?? '[]'), true);
if (!is_array($paradas)) $paradas = [];
$paradas = array_values(array_slice(array_filter(array_map(static function ($value): string {
    $value = trim((string)$value);
    return mb_strlen($value) <= 255 ? $value : '';
}, $paradas)), 0, 8));
$paradasJson = json_encode($paradas, JSON_UNESCAPED_UNICODE) ?: '[]';

if ($userId <= 0 || !validateDateYmd($fecha) || $motivo === '' || mb_strlen($motivo) > 180 || $kilometros === null) {
    appJson(['ok' => false, 'message' => 'Revisa la fecha, el motivo y los kilómetros.'], 422);
}
$bloqueo = gastosUnificadosPeriodoEfectivoBloqueado($conn, $userId, $fecha);
if (!empty($bloqueo['bloqueado'])) appJson(['ok' => false, 'message' => (string)$bloqueo['motivo']], 409);
if ($rutaUrl !== '' && !filter_var($rutaUrl, FILTER_VALIDATE_URL)) appJson(['ok' => false, 'message' => 'El enlace de la ruta no es válido.'], 422);

$price = (float)KILOMETRAJE_EUR_KM;
$importe = round((float)$kilometros * $price, 2);
$calculo = $origen !== '' && $destino !== '' ? 'routes' : 'manual';
$now = date('Y-m-d H:i:s');
$stmt = $conn->prepare(
    "INSERT INTO kilometrajes
     (user_id, username, comercial, fecha, motivo, origen, destino, paradas_json, ruta_url, kilometros,
      duracion_minutos, ruta_polyline, precio_km, importe, calculo_origen, estado, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?)"
);
if (!$stmt) {
    appLogError('No se pudo preparar el kilometraje', $conn->error);
    appJson(['ok' => false, 'message' => appPublicError()], 500);
}
$stmt->bind_param('issssssssdisddss', $userId, $username, $comercial, $fecha, $motivo, $origen, $destino, $paradasJson, $rutaUrl, $kilometros, $duracion, $polyline, $price, $importe, $calculo, $now);
if (!$stmt->execute()) {
    appLogError('No se pudo crear el kilometraje', $stmt->error);
    appJson(['ok' => false, 'message' => appPublicError()], 500);
}
$id = (int)$stmt->insert_id;
$result = efectivoKmsCallWebhook([
    'tipo' => 'kilometraje', 'registro_id' => $id, 'user_id' => $userId, 'username' => $username,
    'comercial' => $comercial, 'fecha' => $fecha, 'motivo' => $motivo, 'origen' => $origen,
    'destino' => $destino, 'ruta_url' => $rutaUrl, 'kilometros' => $kilometros, 'precio_km' => $price,
    'importe' => $importe, 'calculo_origen' => $calculo, 'paradas' => $paradas,
    'duracion_minutos' => $duracion, 'ruta_polyline' => $polyline
]);
$technical = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
$status = !empty($result['ok']) ? 'procesado' : 'error';
$stmt = $conn->prepare('UPDATE kilometrajes SET estado = ?, make_response = ?, updated_at = ? WHERE id = ?');
if ($stmt) { $stmt->bind_param('sssi', $status, $technical, $now, $id); $stmt->execute(); }

if (empty($result['ok'])) {
    appLogError('No se completó el kilometraje ' . $id, $result['internal_message'] ?? $result['message'] ?? '');
    appJson(['ok' => false, 'message' => 'No se ha podido registrar el kilometraje. Inténtalo de nuevo.'], 502);
}

appJson(['ok' => true, 'message' => 'Kilometraje registrado correctamente.', 'id' => $id, 'importe' => $importe]);

<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';

securitySendHeaders();
requirePostMethod();
$payload = safeJsonBody();
requireCsrfFromRequest($payload);

$userId = (int)($_SESSION['user_id'] ?? 0);
$month = (int)($payload['mes'] ?? 0);
$year = (int)($payload['anio'] ?? $payload['año'] ?? 0);

if ($userId <= 0 || $month < 1 || $month > 12 || $year < 2020 || $year > 2100) {
    appJson(['ok' => false, 'message' => 'Revisa el periodo seleccionado.'], 422);
}

if (trim(MAKE_WEBHOOK_DESCARGAR_NOTA) === '') {
    appJson(['ok' => false, 'message' => 'Esta descarga no está disponible en este momento.'], 503);
}

$result = callMakeWebhook(MAKE_WEBHOOK_DESCARGAR_NOTA, [
    'user_id' => $userId,
    'comercial' => (string)($_SESSION['comercial'] ?? ''),
    'mes' => $month,
    'anio' => $year,
    'año' => $year
], 180);

if (empty($result['ok'])) {
    appLogError('No se pudo preparar la descarga de gastos', $result['message'] ?? '');
    appJson(['ok' => false, 'message' => 'No se ha podido preparar la descarga. Inténtalo de nuevo.'], 502);
}

$data = is_array($result['response_json'] ?? null) ? $result['response_json'] : [];
if (($data['ok'] ?? true) === false) {
    appJson(['ok' => false, 'message' => 'No hay información disponible para el periodo seleccionado.'], 404);
}

appJson([
    'ok' => true,
    'message' => 'La descarga está preparada.',
    'file_url' => $data['excel_file_url'] ?? $data['file_url'] ?? $data['webViewLink'] ?? $data['web_view_link'] ?? null
]);

<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/efectivo_kms.php';

securitySendHeaders();
requirePostMethod();

$payload = safeJsonBody();
requireCsrfFromRequest($payload);

header('Content-Type: application/json; charset=utf-8');

function normalizarPuntoRuta($value)
{
    if (is_array($value)) {
        $address = trim((string)($value['address'] ?? $value['direccion'] ?? ''));
        $placeId = trim((string)($value['place_id'] ?? $value['placeId'] ?? ''));
    } else {
        $address = trim((string)$value);
        $placeId = '';
    }

    return [
        'address' => $address,
        'place_id' => $placeId
    ];
}

$origen = normalizarPuntoRuta($payload['origen'] ?? '');
$destino = normalizarPuntoRuta($payload['destino'] ?? '');
$paradasRecibidas = $payload['paradas'] ?? [];

if (!is_array($paradasRecibidas)) {
    $paradasRecibidas = [];
}

$paradas = [];

foreach ($paradasRecibidas as $paradaRecibida) {
    $parada = normalizarPuntoRuta($paradaRecibida);

    if ($parada['address'] === '' && $parada['place_id'] === '') {
        continue;
    }

    if (
        mb_strlen($parada['address']) > 255
        || mb_strlen($parada['place_id']) > 255
    ) {
        echo json_encode([
            'ok' => false,
            'message' => 'Una de las paradas supera la longitud permitida.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $paradas[] = $parada;
}

if (
    ($origen['address'] === '' && $origen['place_id'] === '')
    || ($destino['address'] === '' && $destino['place_id'] === '')
    || mb_strlen($origen['address']) > 255
    || mb_strlen($destino['address']) > 255
    || mb_strlen($origen['place_id']) > 255
    || mb_strlen($destino['place_id']) > 255
    || count($paradas) > 8
) {
    echo json_encode([
        'ok' => false,
        'message' => 'Origen, destino o paradas no válidos.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$resultado = efectivoKmsGoogleRoute(
    $origen,
    $destino,
    $paradas
);

echo json_encode(
    $resultado,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
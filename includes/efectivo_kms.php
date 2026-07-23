<?php

function efectivoKmsTableExists($conn, $table)
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$safe'");

    return $result && $result->num_rows > 0;
}

function efectivoKmsCallWebhook($payload)
{
    if (
        !defined('MAKE_WEBHOOK_EFECTIVO_KMS')
        || trim((string)MAKE_WEBHOOK_EFECTIVO_KMS) === ''
    ) {
        return [
            'ok' => false,
            'message' => 'Esta operación no está disponible en este momento.',
            'internal_message' => 'El webhook de Efectivo y Kms no está configurado.'
        ];
    }

    return callMakeWebhook(
        MAKE_WEBHOOK_EFECTIVO_KMS,
        $payload,
        120
    );
}

function efectivoKmsNormalizeWaypoint($value)
{
    if (is_array($value)) {
        $address = trim((string)($value['address'] ?? $value['direccion'] ?? ''));
        $placeId = trim((string)($value['place_id'] ?? $value['placeId'] ?? ''));
    } else {
        $address = trim((string)$value);
        $placeId = '';
    }

    if ($placeId !== '') {
        return [
            'placeId' => $placeId
        ];
    }

    if ($address !== '') {
        return [
            'address' => $address
        ];
    }

    return null;
}

function efectivoKmsGoogleRoute($origin, $destination, $stops = [])
{
    if (
        !defined('GOOGLE_MAPS_ROUTES_API_KEY')
        || trim((string)GOOGLE_MAPS_ROUTES_API_KEY) === ''
    ) {
        return [
            'ok' => false,
            'message' => 'El cálculo automático no está disponible. Introduce los kilómetros manualmente.'
        ];
    }

    $originWaypoint = efectivoKmsNormalizeWaypoint($origin);
    $destinationWaypoint = efectivoKmsNormalizeWaypoint($destination);

    if ($originWaypoint === null || $destinationWaypoint === null) {
        return [
            'ok' => false,
            'message' => 'Debes indicar un origen y un destino válidos.'
        ];
    }

    $intermediates = [];
    $stopLabels = [];

    foreach ((array)$stops as $stop) {
        $waypoint = efectivoKmsNormalizeWaypoint($stop);

        if ($waypoint === null) {
            continue;
        }

        $intermediates[] = $waypoint;

        if (is_array($stop)) {
            $stopLabels[] = trim((string)($stop['address'] ?? $stop['direccion'] ?? $stop['place_id'] ?? $stop['placeId'] ?? ''));
        } else {
            $stopLabels[] = trim((string)$stop);
        }
    }

    $request = [
        'origin' => $originWaypoint,
        'destination' => $destinationWaypoint,
        'travelMode' => 'DRIVE',
        'routingPreference' => 'TRAFFIC_UNAWARE',
        'languageCode' => 'es-ES',
        'regionCode' => 'es',
        'units' => 'METRIC',
        'computeAlternativeRoutes' => empty($intermediates)
    ];

    if (!empty($intermediates)) {
        $request['intermediates'] = $intermediates;
    }

    $body = json_encode(
        $request,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($body === false) {
        return [
            'ok' => false,
            'message' => 'No se han podido preparar los datos de la ruta.'
        ];
    }

    $curl = curl_init(
        'https://routes.googleapis.com/directions/v2:computeRoutes'
    );

    if ($curl === false) {
        return [
            'ok' => false,
            'message' => 'No se ha podido calcular la ruta. Introduce los kilómetros manualmente.'
        ];
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . GOOGLE_MAPS_ROUTES_API_KEY,
            'X-Goog-FieldMask: routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline,routes.description,routes.routeLabels'
        ]
    ]);

    $rawResponse = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($rawResponse === false || $curlError !== '') {
        return [
            'ok' => false,
            'message' => 'No se ha podido calcular la ruta. Revisa las direcciones o introduce los kilómetros manualmente.'
        ];
    }

    $response = json_decode($rawResponse, true);

    if ($httpStatus < 200 || $httpStatus >= 300) {
        $googleMessage = '';

        if (
            is_array($response)
            && isset($response['error']['message'])
        ) {
            $googleMessage = trim(
                (string)$response['error']['message']
            );
        }

        return [
            'ok' => false,
            'message' => 'No se ha podido calcular la ruta. Revisa las direcciones o introduce los kilómetros manualmente.'
        ];
    }

    if (!is_array($response)) {
        return [
            'ok' => false,
            'message' => 'No se ha podido calcular la ruta. Inténtalo de nuevo.'
        ];
    }

    $routes = $response['routes'] ?? [];

    if (!is_array($routes) || empty($routes)) {
        return [
            'ok' => false,
            'message' => !empty($intermediates)
                ? 'No se ha encontrado una ruta que pase por todas las paradas. Revisa las direcciones.'
                : 'No se ha encontrado una ruta válida para esas direcciones.'
        ];
    }

    $alternatives = [];

    foreach (array_slice($routes, 0, 3) as $index => $route) {
        if (!is_array($route)) {
            continue;
        }

        $distanceMeters = (int)($route['distanceMeters'] ?? 0);

        if ($distanceMeters <= 0) {
            continue;
        }

        $durationText = trim(
            (string)($route['duration'] ?? '0s')
        );

        $durationSeconds = (float)rtrim($durationText, 's');
        $durationMinutes = (int)round($durationSeconds / 60);

        $routeLabels = $route['routeLabels'] ?? [];

        if (!is_array($routeLabels)) {
            $routeLabels = [];
        }

        $alternatives[] = [
            'index' => $index,
            'kilometros' => round(
                $distanceMeters / 1000,
                2
            ),
            'distancia_metros' => $distanceMeters,
            'duracion_minutos' => $durationMinutes,
            'polyline' => (string)(
                $route['polyline']['encodedPolyline'] ?? ''
            ),
            'descripcion' => (string)(
                $route['description'] ?? ''
            ),
            'route_labels' => $routeLabels
        ];
    }

    if (empty($alternatives)) {
        return [
            'ok' => false,
            'message' => 'No se ha obtenido una distancia válida. Introduce los kilómetros manualmente.'
        ];
    }

    $mainRoute = $alternatives[0];

    return [
        'ok' => true,
        'kilometros' => $mainRoute['kilometros'],
        'distancia_metros' => $mainRoute['distancia_metros'],
        'duracion_minutos' => $mainRoute['duracion_minutos'],
        'polyline' => $mainRoute['polyline'],
        'paradas' => array_values($stopLabels),
        'numero_paradas' => count($intermediates),
        'alternativas' => $alternatives
    ];
}

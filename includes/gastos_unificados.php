<?php

function gastosUnificadosTableExists($conn, $table)
{
    $table = $conn->real_escape_string((string)$table);
    $result = $conn->query("SHOW TABLES LIKE '$table'");

    return $result && $result->num_rows > 0;
}

function gastosUnificadosFetchOne($conn, $sql, $types = '', $params = [])
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return null;
    }

    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

function gastosUnificadosTotalEfectivo($conn, $userId, $mes, $anio)
{
    $userId = (int)$userId;
    $mes = (int)$mes;
    $anio = (int)$anio;

    $resultado = [
        'total_efectivo' => 0.0,
        'total_kilometraje' => 0.0,
        'total_importe' => 0.0,
        'total_gastos_efectivo' => 0,
        'total_kilometrajes' => 0,
        'total_registros' => 0
    ];

    if (gastosUnificadosTableExists($conn, 'efectivo_gastos')) {
        $row = gastosUnificadosFetchOne(
            $conn,
            "SELECT COUNT(*) AS total, COALESCE(SUM(importe), 0) AS importe
             FROM efectivo_gastos
             WHERE user_id = ?
               AND estado = 'procesado'
               AND MONTH(fecha) = ?
               AND YEAR(fecha) = ?",
            'iii',
            [$userId, $mes, $anio]
        );

        $resultado['total_gastos_efectivo'] = (int)($row['total'] ?? 0);
        $resultado['total_efectivo'] = (float)($row['importe'] ?? 0);
    }

    if (gastosUnificadosTableExists($conn, 'kilometrajes')) {
        $row = gastosUnificadosFetchOne(
            $conn,
            "SELECT COUNT(*) AS total, COALESCE(SUM(importe), 0) AS importe
             FROM kilometrajes
             WHERE user_id = ?
               AND estado = 'procesado'
               AND MONTH(fecha) = ?
               AND YEAR(fecha) = ?",
            'iii',
            [$userId, $mes, $anio]
        );

        $resultado['total_kilometrajes'] = (int)($row['total'] ?? 0);
        $resultado['total_kilometraje'] = (float)($row['importe'] ?? 0);
    }

    $resultado['total_importe'] = round(
        $resultado['total_efectivo'] + $resultado['total_kilometraje'],
        2
    );
    $resultado['total_registros'] =
        $resultado['total_gastos_efectivo'] + $resultado['total_kilometrajes'];

    return $resultado;
}

function gastosUnificadosCierreEfectivo($conn, $userId, $mes, $anio)
{
    if (!gastosUnificadosTableExists($conn, 'cierres_mensuales_efectivo')) {
        return null;
    }

    return gastosUnificadosFetchOne(
        $conn,
        "SELECT *
         FROM cierres_mensuales_efectivo
         WHERE user_id = ? AND mes = ? AND anio = ?
         LIMIT 1",
        'iii',
        [(int)$userId, (int)$mes, (int)$anio]
    );
}

function gastosUnificadosCierreEfectivoContabilizado($conn, $cierreId)
{
    if (
        (int)$cierreId <= 0 ||
        !gastosUnificadosTableExists($conn, 'envios_integraciones')
    ) {
        return false;
    }

    $cierreId = abs((int)$cierreId);
    $tieneTipoCierre = function_exists('integracionesColumnExists')
        ? integracionesColumnExists($conn, 'envios_integraciones', 'tipo_cierre')
        : false;

    if ($tieneTipoCierre) {
        $row = gastosUnificadosFetchOne(
            $conn,
            "SELECT id FROM envios_integraciones
             WHERE entidad = 'cierre' AND entidad_id = ?
               AND tipo_cierre = 'efectivo' AND estado = 'enviado' LIMIT 1",
            'i',
            [$cierreId]
        );

        if (!empty($row)) {
            return true;
        }
    }

    $legacyEntityId = -$cierreId;
    $row = gastosUnificadosFetchOne(
        $conn,
        "SELECT id FROM envios_integraciones
         WHERE entidad = 'cierre' AND entidad_id = ?
           AND estado = 'enviado' LIMIT 1",
        'i',
        [$legacyEntityId]
    );

    return !empty($row);
}

function gastosUnificadosPeriodoEfectivoBloqueado($conn, $userId, $fecha)
{
    $timestamp = strtotime((string)$fecha);

    if ($timestamp === false) {
        return [
            'bloqueado' => false,
            'motivo' => '',
            'cierre' => null
        ];
    }

    $mes = (int)date('n', $timestamp);
    $anio = (int)date('Y', $timestamp);
    $cierre = gastosUnificadosCierreEfectivo($conn, (int)$userId, $mes, $anio);

    if (!$cierre) {
        return [
            'bloqueado' => false,
            'motivo' => '',
            'cierre' => null
        ];
    }

    if (gastosUnificadosCierreEfectivoContabilizado($conn, (int)$cierre['id'])) {
        return [
            'bloqueado' => true,
            'motivo' => 'El cierre de Efectivo y Kilometraje de este periodo ya está contabilizado.',
            'cierre' => $cierre
        ];
    }

    if (in_array((string)$cierre['estado'], ['validado', 'con_diferencia', 'rechazado'], true)) {
        return [
            'bloqueado' => true,
            'motivo' => 'El cierre de Efectivo y Kilometraje de este periodo ya ha sido revisado por dirección.',
            'cierre' => $cierre
        ];
    }

    return [
        'bloqueado' => false,
        'motivo' => '',
        'cierre' => $cierre
    ];
}

function gastosUnificadosGetEfectivoKmRecord($conn, $tipo, $id, $userId, $esAdmin)
{
    $tipo = trim((string)$tipo);
    $id = (int)$id;
    $userId = (int)$userId;

    if ($id <= 0 || !in_array($tipo, ['efectivo', 'kilometraje'], true)) {
        return null;
    }

    $table = $tipo === 'efectivo' ? 'efectivo_gastos' : 'kilometrajes';

    if (!gastosUnificadosTableExists($conn, $table)) {
        return null;
    }

    $sql = "SELECT * FROM `$table` WHERE id = ?";
    $types = 'i';
    $params = [$id];

    if (!$esAdmin) {
        $sql .= " AND user_id = ?";
        $types .= 'i';
        $params[] = $userId;
    }

    $sql .= " LIMIT 1";

    return gastosUnificadosFetchOne($conn, $sql, $types, $params);
}

function gastosUnificadosTipoTexto($tipo)
{
    if ($tipo === 'visa') {
        return 'VISA';
    }

    if ($tipo === 'efectivo') {
        return 'Efectivo';
    }

    if ($tipo === 'kilometraje') {
        return 'Kilometraje';
    }

    return ucfirst((string)$tipo);
}

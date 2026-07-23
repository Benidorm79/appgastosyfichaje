<?php
if (!function_exists('integracionesNow')) {
  function integracionesNow() {
    date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');
    return date('Y-m-d H:i:s');
  }
}

if (!function_exists('integracionesTableExists')) {
  function integracionesTableExists($conn) {
    $result = $conn->query("SHOW TABLES LIKE 'envios_integraciones'");
    return $result && $result->num_rows > 0;
  }
}


if (!function_exists('integracionesColumnExists')) {
  function integracionesColumnExists($conn, $table, $column) {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
  }
}

if (!function_exists('integracionesEnsureTipoCierreColumn')) {
  function integracionesEnsureTipoCierreColumn($conn) {
    if (!integracionesTableExists($conn)) {
      return false;
    }

    if (integracionesColumnExists($conn, 'envios_integraciones', 'tipo_cierre')) {
      return true;
    }

    try {
      $conn->query("ALTER TABLE envios_integraciones ADD COLUMN tipo_cierre VARCHAR(20) NULL AFTER entidad_id");
    } catch (Throwable $e) {
      return false;
    }

    return integracionesColumnExists($conn, 'envios_integraciones', 'tipo_cierre');
  }
}

if (!function_exists('integracionesRegistrar')) {
  function integracionesRegistrar($conn, $data = []) {
    if (!integracionesTableExists($conn)) {
      return [
        'ok' => false,
        'message' => 'Este apartado todavía no está disponible.'
      ];
    }

    $tipoDestino = $data['tipo_destino'] ?? 'otro';
    $entidad = $data['entidad'] ?? 'otro';
    $entidadId = isset($data['entidad_id']) && $data['entidad_id'] !== '' ? (int)$data['entidad_id'] : null;
    $tipoCierre = trim((string)($data['tipo_cierre'] ?? ''));
    if (!in_array($tipoCierre, ['visa', 'efectivo'], true)) {
      $tipoCierre = null;
    }
    $referencia = $data['referencia'] ?? null;
    $descripcion = $data['descripcion'] ?? null;
    $estado = $data['estado'] ?? 'pendiente';
    $creadoPor = isset($data['creado_por']) && $data['creado_por'] !== '' ? (int)$data['creado_por'] : null;

    $payload = $data['payload'] ?? ($data['payload_json'] ?? null);
    $respuesta = $data['respuesta'] ?? ($data['respuesta_json'] ?? null);
    $ultimoError = $data['ultimo_error'] ?? null;

    if (is_array($payload)) {
      $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    } else {
      $payloadJson = $payload;
    }

    if (is_array($respuesta)) {
      $respuestaJson = json_encode($respuesta, JSON_UNESCAPED_UNICODE);
    } else {
      $respuestaJson = $respuesta;
    }

    $permitidosDestino = ['email', 'make', 'a3', 'erp', 'verifactu', 'contabilidad', 'otro'];
    $permitidosEntidad = ['gasto', 'cierre', 'justificante', 'usuario', 'sistema', 'otro'];
    $permitidosEstado = ['pendiente', 'enviado', 'error', 'omitido'];

    if (!in_array($tipoDestino, $permitidosDestino, true)) {
      $tipoDestino = 'otro';
    }

    if (!in_array($entidad, $permitidosEntidad, true)) {
      $entidad = 'otro';
    }

    if (!in_array($estado, $permitidosEstado, true)) {
      $estado = 'pendiente';
    }

    $now = integracionesNow();

    $tieneTipoCierre = integracionesEnsureTipoCierreColumn($conn);

    if ($tieneTipoCierre) {
      $sql = "INSERT INTO envios_integraciones
              (tipo_destino, entidad, entidad_id, tipo_cierre, referencia, descripcion, estado,
               payload_json, respuesta_json, ultimo_error, intentos, creado_por,
               created_at, updated_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)";
    } else {
      $sql = "INSERT INTO envios_integraciones
              (tipo_destino, entidad, entidad_id, referencia, descripcion, estado,
               payload_json, respuesta_json, ultimo_error, intentos, creado_por,
               created_at, updated_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)";
    }

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
      return [
        'ok' => false,
        'message' => 'No se pudo preparar el registro de integración: ' . $conn->error
      ];
    }

    if ($tieneTipoCierre) {
      $stmt->bind_param(
        "ssissssssisss",
        $tipoDestino,
        $entidad,
        $entidadId,
        $tipoCierre,
        $referencia,
        $descripcion,
        $estado,
        $payloadJson,
        $respuestaJson,
        $ultimoError,
        $creadoPor,
        $now,
        $now
      );
    } else {
      $stmt->bind_param(
        "ssissssssiss",
        $tipoDestino,
        $entidad,
        $entidadId,
        $referencia,
        $descripcion,
        $estado,
        $payloadJson,
        $respuestaJson,
        $ultimoError,
        $creadoPor,
        $now,
        $now
      );
    }

    if (!$stmt->execute()) {
      return [
        'ok' => false,
        'message' => 'No se pudo guardar el registro de integración: ' . $stmt->error
      ];
    }

    return [
      'ok' => true,
      'id' => (int)$stmt->insert_id
    ];
  }
}

if (!function_exists('integracionesActualizarEstado')) {
  function integracionesActualizarEstado($conn, $id, $estado, $respuesta = null, $ultimoError = null, $enviadoPor = null) {
    if (!integracionesTableExists($conn)) {
      return [
        'ok' => false,
        'message' => 'Este apartado todavía no está disponible.'
      ];
    }

    $id = (int)$id;

    if ($id <= 0) {
      return [
        'ok' => false,
        'message' => 'ID de integración no válido.'
      ];
    }

    $permitidosEstado = ['pendiente', 'enviado', 'error', 'omitido'];

    if (!in_array($estado, $permitidosEstado, true)) {
      return [
        'ok' => false,
        'message' => 'Estado de integración no válido.'
      ];
    }

    if (is_array($respuesta)) {
      $respuestaJson = json_encode($respuesta, JSON_UNESCAPED_UNICODE);
    } else {
      $respuestaJson = $respuesta;
    }

    $now = integracionesNow();
    $enviadoAt = $estado === 'enviado' ? $now : null;
    $enviadoPor = $enviadoPor !== null ? (int)$enviadoPor : null;

    $sql = "UPDATE envios_integraciones
            SET estado = ?,
                respuesta_json = ?,
                ultimo_error = ?,
                intentos = intentos + 1,
                enviado_por = ?,
                enviado_at = ?,
                updated_at = ?
            WHERE id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
      return [
        'ok' => false,
        'message' => 'No se pudo preparar la actualización de integración: ' . $conn->error
      ];
    }

    $stmt->bind_param(
      "sssissi",
      $estado,
      $respuestaJson,
      $ultimoError,
      $enviadoPor,
      $enviadoAt,
      $now,
      $id
    );

    if (!$stmt->execute()) {
      return [
        'ok' => false,
        'message' => 'No se pudo actualizar la integración: ' . $stmt->error
      ];
    }

    return [
      'ok' => true
    ];
  }
}
?>

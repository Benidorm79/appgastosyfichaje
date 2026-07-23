<?php
if (!function_exists('auditoriaNow')) {
  function auditoriaNow() {
    date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');
    return date('Y-m-d H:i:s');
  }
}

if (!function_exists('auditoriaTableExists')) {
  function auditoriaTableExists($conn) {
    $result = $conn->query("SHOW TABLES LIKE 'auditoria_eventos'");
    return $result && $result->num_rows > 0;
  }
}

if (!function_exists('auditoriaColumnExists')) {
  function auditoriaColumnExists($conn, $column) {
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM auditoria_eventos LIKE '$column'");
    return $result && $result->num_rows > 0;
  }
}

if (!function_exists('auditoriaGetIp')) {
  function auditoriaGetIp() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
      return $_SERVER['HTTP_CLIENT_IP'];
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
      return trim($parts[0]);
    }

    return $_SERVER['REMOTE_ADDR'] ?? '';
  }
}

if (!function_exists('auditoriaRegistrar')) {
  function auditoriaRegistrar($conn, $data = []) {
    if (!auditoriaTableExists($conn)) {
      return [
        'ok' => false,
        'message' => 'Este apartado todavía no está disponible.'
      ];
    }

    $tipoEvento = trim((string)($data['tipo_evento'] ?? 'sistema'));
    $entidad = trim((string)($data['entidad'] ?? ''));
    $entidadId = isset($data['entidad_id']) && $data['entidad_id'] !== '' ? (int)$data['entidad_id'] : null;

    $accion = trim((string)($data['accion'] ?? 'accion_sistema'));
    $descripcion = trim((string)($data['descripcion'] ?? ''));

    $usuarioId = isset($data['usuario_id']) && $data['usuario_id'] !== '' ? (int)$data['usuario_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
    $username = $data['username'] ?? ($_SESSION['user'] ?? null);
    $comercial = $data['comercial'] ?? ($_SESSION['comercial'] ?? null);
    $rol = $data['rol'] ?? ($_SESSION['role'] ?? null);

    $estadoAnterior = $data['estado_anterior'] ?? null;
    $estadoNuevo = $data['estado_nuevo'] ?? null;

    $ip = $data['ip'] ?? auditoriaGetIp();
    $userAgent = $data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');

    $datos = $data['datos'] ?? ($data['datos_json'] ?? null);

    if (is_array($datos)) {
      $datosJson = json_encode($datos, JSON_UNESCAPED_UNICODE);
    } else {
      $datosJson = $datos;
    }

    $createdAt = auditoriaNow();

    if ($tipoEvento === '') {
      $tipoEvento = 'sistema';
    }

    if ($accion === '') {
      $accion = 'accion_sistema';
    }

    $entidadDb = $entidad !== '' ? $entidad : null;
    $descripcionDb = $descripcion !== '' ? $descripcion : null;
    $usernameDb = $username !== '' ? $username : null;
    $comercialDb = $comercial !== '' ? $comercial : null;
    $rolDb = $rol !== '' ? $rol : null;
    $ipDb = $ip !== '' ? $ip : null;
    $userAgentDb = $userAgent !== '' ? $userAgent : null;

    $tieneEstadoRevision = auditoriaColumnExists($conn, 'estado_revision');
    $tieneUpdatedAt = auditoriaColumnExists($conn, 'updated_at');

    if ($tieneEstadoRevision && $tieneUpdatedAt) {
      $estadoRevision = $data['estado_revision'] ?? 'normal';

      $permitidosRevision = ['normal', 'revisado', 'corregido', 'anulado'];

      if (!in_array($estadoRevision, $permitidosRevision, true)) {
        $estadoRevision = 'normal';
      }

      $updatedAt = null;

      $sql = "INSERT INTO auditoria_eventos
              (tipo_evento, entidad, entidad_id, accion, descripcion,
               usuario_id, username, comercial, rol,
               estado_anterior, estado_nuevo,
               ip, user_agent, datos_json,
               estado_revision, created_at, updated_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

      $stmt = $conn->prepare($sql);

      if (!$stmt) {
        return [
          'ok' => false,
          'message' => 'No se pudo preparar el registro de auditoría: ' . $conn->error
        ];
      }

      $stmt->bind_param(
        "ssississsssssssss",
        $tipoEvento,
        $entidadDb,
        $entidadId,
        $accion,
        $descripcionDb,
        $usuarioId,
        $usernameDb,
        $comercialDb,
        $rolDb,
        $estadoAnterior,
        $estadoNuevo,
        $ipDb,
        $userAgentDb,
        $datosJson,
        $estadoRevision,
        $createdAt,
        $updatedAt
      );
    } else {
      $sql = "INSERT INTO auditoria_eventos
              (tipo_evento, entidad, entidad_id, accion, descripcion,
               usuario_id, username, comercial, rol,
               estado_anterior, estado_nuevo,
               ip, user_agent, datos_json, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

      $stmt = $conn->prepare($sql);

      if (!$stmt) {
        return [
          'ok' => false,
          'message' => 'No se pudo preparar el registro de auditoría: ' . $conn->error
        ];
      }

      $stmt->bind_param(
        "ssississsssssss",
        $tipoEvento,
        $entidadDb,
        $entidadId,
        $accion,
        $descripcionDb,
        $usuarioId,
        $usernameDb,
        $comercialDb,
        $rolDb,
        $estadoAnterior,
        $estadoNuevo,
        $ipDb,
        $userAgentDb,
        $datosJson,
        $createdAt
      );
    }

    if (!$stmt->execute()) {
      return [
        'ok' => false,
        'message' => 'No se pudo guardar el registro de auditoría: ' . $stmt->error
      ];
    }

    return [
      'ok' => true,
      'id' => (int)$stmt->insert_id
    ];
  }
}

if (!function_exists('auditoriaActualizarRevision')) {
  function auditoriaActualizarRevision($conn, $id, $estadoRevision, $notasRevision, $revisadoPor = null) {
    if (!auditoriaTableExists($conn)) {
      return [
        'ok' => false,
        'message' => 'Este apartado todavía no está disponible.'
      ];
    }

    if (
      !auditoriaColumnExists($conn, 'estado_revision') ||
      !auditoriaColumnExists($conn, 'notas_revision') ||
      !auditoriaColumnExists($conn, 'revisado_por') ||
      !auditoriaColumnExists($conn, 'revisado_at') ||
      !auditoriaColumnExists($conn, 'updated_at')
    ) {
      return [
        'ok' => false,
        'message' => 'Faltan columnas de revisión en auditoria_eventos.'
      ];
    }

    $id = (int)$id;

    if ($id <= 0) {
      return [
        'ok' => false,
        'message' => 'ID de auditoría no válido.'
      ];
    }

    $permitidos = ['normal', 'revisado', 'corregido', 'anulado'];

    if (!in_array($estadoRevision, $permitidos, true)) {
      return [
        'ok' => false,
        'message' => 'Estado de revisión no válido.'
      ];
    }

    $now = auditoriaNow();
    $revisadoPor = $revisadoPor !== null ? (int)$revisadoPor : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
    $notasRevision = trim((string)$notasRevision);
    $notasDb = $notasRevision !== '' ? $notasRevision : null;

    $sql = "UPDATE auditoria_eventos
            SET estado_revision = ?,
                notas_revision = ?,
                revisado_por = ?,
                revisado_at = ?,
                updated_at = ?
            WHERE id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
      return [
        'ok' => false,
        'message' => 'No se pudo preparar la actualización de auditoría: ' . $conn->error
      ];
    }

    $stmt->bind_param(
      "ssissi",
      $estadoRevision,
      $notasDb,
      $revisadoPor,
      $now,
      $now,
      $id
    );

    if (!$stmt->execute()) {
      return [
        'ok' => false,
        'message' => 'No se pudo actualizar la auditoría: ' . $stmt->error
      ];
    }

    return [
      'ok' => true
    ];
  }
}

if (!function_exists('auditoriaRegistrarSeguro')) {
  function auditoriaRegistrarSeguro($conn, $data = []) {
    if (!function_exists('auditoriaRegistrar')) {
      return false;
    }

    try {
      $resultado = auditoriaRegistrar($conn, $data);
      return !empty($resultado['ok']);
    } catch (Throwable $e) {
      error_log('Error registrando auditoría: ' . $e->getMessage());
      return false;
    }
  }
}

if (!function_exists('auditoriaCalcularCambios')) {
  function auditoriaCalcularCambios($anterior, $nuevo, $campos = []) {
    $cambios = [];

    if (!is_array($anterior)) {
      $anterior = [];
    }

    if (!is_array($nuevo)) {
      $nuevo = [];
    }

    if (empty($campos)) {
      $campos = array_unique(array_merge(array_keys($anterior), array_keys($nuevo)));
    }

    foreach ($campos as $campo) {
      $valorAnterior = $anterior[$campo] ?? null;
      $valorNuevo = $nuevo[$campo] ?? null;

      if ((string)$valorAnterior !== (string)$valorNuevo) {
        $cambios[$campo] = [
          'anterior' => $valorAnterior,
          'nuevo' => $valorNuevo
        ];
      }
    }

    return $cambios;
  }
}

?>

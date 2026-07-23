<?php
require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/fichaje.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

function fichajeAusenciaRedirect($params = []) {
  if (isset($params['error'])) $params['error'] = appPublicMessage($params['error']);
  if (isset($params['ok'])) $params['ok'] = appPublicMessage($params['ok'], 'Operación completada correctamente.');
  $query = http_build_query($params);
  header('Location: fichaje_ausencias.php' . ($query !== '' ? '?' . $query : ''));
  exit;
}

function fichajeAusenciaCsrfValido($token) {
  return !empty($_SESSION['fichaje_ausencias_csrf'])
    && is_string($token)
    && hash_equals((string)$_SESSION['fichaje_ausencias_csrf'], $token);
}

function fichajeAusenciaFechasSeleccionadas($raw) {
  $raw = trim((string)$raw);
  if ($raw === '') return [];

  $items = explode(',', $raw);
  $fechas = [];
  $timezone = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

  foreach ($items as $item) {
    $fecha = trim((string)$item);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
      throw new Exception('Una de las fechas seleccionadas no es válida.');
    }

    $date = DateTime::createFromFormat('!Y-m-d', $fecha, $timezone);
    if (!$date || $date->format('Y-m-d') !== $fecha) {
      throw new Exception('Una de las fechas seleccionadas no existe.');
    }

    $anio = (int)$date->format('Y');
    if ($anio < 2020 || $anio > 2100) {
      throw new Exception('Una de las fechas seleccionadas está fuera del rango permitido.');
    }

    $fechas[$fecha] = $fecha;
  }

  $fechas = array_values($fechas);
  sort($fechas, SORT_STRING);
  return $fechas;
}

function fichajeAusenciaAgruparFechasConsecutivas($fechas) {
  if (!$fechas) return [];

  $timezone = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');
  $grupos = [];
  $inicio = $fechas[0];
  $anterior = $fechas[0];

  for ($i = 1, $total = count($fechas); $i < $total; $i++) {
    $actual = $fechas[$i];
    $esperada = new DateTime($anterior, $timezone);
    $esperada->modify('+1 day');

    if ($esperada->format('Y-m-d') !== $actual) {
      $grupos[] = [$inicio, $anterior];
      $inicio = $actual;
    }

    $anterior = $actual;
  }

  $grupos[] = [$inicio, $anterior];
  return $grupos;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = trim((string)($_SESSION['user'] ?? ''));
$comercial = trim((string)($_SESSION['comercial'] ?? $username));
$role = trim((string)($_SESSION['role'] ?? 'user'));
$esPrivilegiado = in_array($role, ['admin', 'master'], true);
$accion = trim((string)($_POST['accion'] ?? ''));
$csrfToken = (string)($_POST['csrf_token'] ?? '');

if ($userId <= 0 || $username === '') {
  fichajeAusenciaRedirect(['error' => 'Sesión no válida.']);
}

if (!fichajeAusenciaCsrfValido($csrfToken)) {
  fichajeAusenciaRedirect(['error' => 'La sesión del formulario ha caducado. Vuelve a intentarlo.']);
}

if (!fichajeAusenciasTableExists($conn)) {
  fichajeAusenciaRedirect(['error' => 'Este apartado todavía no está disponible.']);
}

if (!fichajeAusenciasPeriodosTableExists($conn)) {
  fichajeAusenciaRedirect(['error' => 'Este apartado todavía no está disponible.']);
}

try {
  if ($accion === 'crear') {
    $fechaInicio = trim((string)($_POST['fecha_inicio'] ?? ''));
    $fechaFin = trim((string)($_POST['fecha_fin'] ?? $fechaInicio));
    $tipo = trim((string)($_POST['tipo'] ?? 'vacaciones'));
    $jornada = trim((string)($_POST['jornada'] ?? 'completa'));
    $descripcion = mb_substr(trim((string)($_POST['descripcion'] ?? '')), 0, 180, 'UTF-8');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
      throw new Exception('Las fechas no son válidas.');
    }

    if (!in_array($tipo, ['vacaciones', 'dia_libre'], true)) {
      throw new Exception('El tipo seleccionado no es válido.');
    }
    if (!in_array($jornada, ['completa','manana','tarde'], true)) {
      throw new Exception('La jornada seleccionada no es válida.');
    }

    $fechasSeleccionadas = fichajeAusenciaFechasSeleccionadas($_POST['fechas_seleccionadas'] ?? '');

    if ($fechasSeleccionadas) {
      if (count($fechasSeleccionadas) > 370) {
        throw new Exception('Has seleccionado demasiados días de una sola vez.');
      }

      $timezone = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');
      $primeraFecha = new DateTime($fechasSeleccionadas[0], $timezone);
      $ultimaFecha = new DateTime($fechasSeleccionadas[count($fechasSeleccionadas) - 1], $timezone);

      if ($primeraFecha->diff($ultimaFecha)->days > 370) {
        throw new Exception('La selección no puede abarcar más de 370 días.');
      }

      if (count($fechasSeleccionadas) > 1 && $jornada !== 'completa') {
        throw new Exception('La selección de varios días debe registrarse como días completos.');
      }

      if (count($fechasSeleccionadas) === 1 && (int)$primeraFecha->format('N') === 5 && $jornada !== 'completa') {
        throw new Exception('El viernes computa como un día completo y no admite medias jornadas.');
      }

      $gruposFechas = fichajeAusenciaAgruparFechasConsecutivas($fechasSeleccionadas);
      foreach ($gruposFechas as $grupo) {
        if (fichajeAusenciasExisteSolapamiento($conn, $userId, $grupo[0], $grupo[1])) {
          throw new Exception('Ya tienes vacaciones o días libres registrados en alguna de las fechas seleccionadas.');
        }
      }

      $fraccionPeriodo = $jornada === 'completa' ? 1.0 : 0.5;
      $now = fichajeNow()->format('Y-m-d H:i:s');
      $periodosSincronizar = [];
      $guardados = 0;
      $conn->begin_transaction();

      $stmtPeriodo = $conn->prepare("INSERT INTO fichaje_ausencias_periodos
        (periodo_uid, user_id, username, comercial, fecha_inicio, fecha_fin, tipo, jornada, fraccion, descripcion, activo, calendar_sync_status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'pendiente', ?, ?)");
      if (!$stmtPeriodo) throw new Exception('No se pudieron preparar los periodos seleccionados.');

      $stmtDia = $conn->prepare("INSERT INTO fichaje_ausencias_usuario
        (user_id, username, comercial, fecha, anio, mes, tipo, jornada, fraccion, descripcion, activo, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
        ON DUPLICATE KEY UPDATE
          username = VALUES(username), comercial = VALUES(comercial), anio = VALUES(anio), mes = VALUES(mes),
          tipo = VALUES(tipo), jornada = VALUES(jornada), fraccion = VALUES(fraccion), descripcion = VALUES(descripcion), activo = 1, updated_at = VALUES(updated_at)");
      if (!$stmtDia) throw new Exception('No se pudieron preparar los días seleccionados.');

      foreach ($gruposFechas as $grupo) {
        $fechaGrupoInicio = $grupo[0];
        $fechaGrupoFin = $grupo[1];
        $periodoUid = 'AUS-' . strtoupper(bin2hex(random_bytes(12)));

        $stmtPeriodo->bind_param('sisssssssdss', $periodoUid, $userId, $username, $comercial, $fechaGrupoInicio, $fechaGrupoFin, $tipo, $jornada, $fraccionPeriodo, $descripcion, $now, $now);
        if (!$stmtPeriodo->execute()) throw new Exception('No se pudo guardar uno de los periodos seleccionados.');

        $periodoId = (int)$conn->insert_id;
        $cursor = new DateTime($fechaGrupoInicio, $timezone);
        $finGrupo = new DateTime($fechaGrupoFin, $timezone);

        while ($cursor <= $finGrupo) {
          $fecha = $cursor->format('Y-m-d');
          $anioFecha = (int)$cursor->format('Y');
          $mesFecha = (int)$cursor->format('n');
          $fraccionDia = $fraccionPeriodo;
          if ((int)$cursor->format('N') >= 6 || fichajeEsFestivoBarcelona($conn, $fecha)) $fraccionDia = 0.0;

          $stmtDia->bind_param('isssiissdsss', $userId, $username, $comercial, $fecha, $anioFecha, $mesFecha, $tipo, $jornada, $fraccionDia, $descripcion, $now, $now);
          if (!$stmtDia->execute()) throw new Exception('No se pudo guardar uno de los días seleccionados.');

          fichajeRecalcularResumenExistente($conn, $userId, $fecha);
          $guardados++;
          $cursor->modify('+1 day');
        }

        $periodosSincronizar[] = [
          'id' => $periodoId,
          'periodo_uid' => $periodoUid,
          'user_id' => $userId,
          'username' => $username,
          'comercial' => $comercial,
          'fecha_inicio' => $fechaGrupoInicio,
          'fecha_fin' => $fechaGrupoFin,
          'tipo' => $tipo,
          'jornada' => $jornada,
          'fraccion' => $fraccionPeriodo,
          'descripcion' => $descripcion,
          'calendar_event_id' => ''
        ];
      }

      $conn->commit();

      $sincronizacionesPendientes = 0;
      foreach ($periodosSincronizar as $periodoSincronizar) {
        $sync = fichajeAusenciaPeriodoSincronizar($conn, $periodoSincronizar, 'crear');
        if (!($sync['ok'] ?? false)) $sincronizacionesPendientes++;
      }

      $mensaje = $guardados === 1
        ? 'Día guardado correctamente.'
        : $guardados . ' días seleccionados guardados correctamente.';

      if (count($periodosSincronizar) > 1) {
        $mensaje .= ' Se han creado ' . count($periodosSincronizar) . ' periodos independientes.';
      }

      if ($sincronizacionesPendientes > 0) {
        $mensaje .= ' La información queda guardada; alguna sincronización con el calendario compartido está pendiente.';
      }

      fichajeAusenciaRedirect([
        'ok' => $mensaje,
        'mes' => (int)$primeraFecha->format('n'),
        'anio' => (int)$primeraFecha->format('Y'),
        'tab' => 'calendario'
      ]);
    }

    $timezone = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');
    $inicio = new DateTime($fechaInicio, $timezone);
    $fin = new DateTime($fechaFin, $timezone);

    if ($fin < $inicio) {
      throw new Exception('La fecha fin no puede ser anterior a la fecha inicio.');
    }

    $diasPeriodo = $inicio->diff($fin)->days + 1;

    if ($diasPeriodo > 370) {
      throw new Exception('El rango seleccionado es demasiado amplio.');
    }
    if ($diasPeriodo > 1 && $jornada !== 'completa') {
      throw new Exception('Los rangos de varias fechas deben registrarse como días completos.');
    }
    if ($diasPeriodo === 1 && (int)$inicio->format('N') === 5 && $jornada !== 'completa') {
      throw new Exception('El viernes computa como un día completo y no admite medias jornadas.');
    }
    $fraccionPeriodo = $jornada === 'completa' ? 1.0 : 0.5;

    if (fichajeAusenciasExisteSolapamiento($conn, $userId, $fechaInicio, $fechaFin)) {
      throw new Exception('Ya tienes vacaciones o días libres registrados dentro de ese periodo.');
    }

    $periodoUid = 'AUS-' . strtoupper(bin2hex(random_bytes(12)));
    $now = fichajeNow()->format('Y-m-d H:i:s');
    $conn->begin_transaction();

    $stmtPeriodo = $conn->prepare("INSERT INTO fichaje_ausencias_periodos
      (periodo_uid, user_id, username, comercial, fecha_inicio, fecha_fin, tipo, jornada, fraccion, descripcion, activo, calendar_sync_status, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'pendiente', ?, ?)");

    if (!$stmtPeriodo) {
      throw new Exception('No se pudo preparar el periodo de vacaciones.');
    }

    $stmtPeriodo->bind_param('sisssssssdss', $periodoUid, $userId, $username, $comercial, $fechaInicio, $fechaFin, $tipo, $jornada, $fraccionPeriodo, $descripcion, $now, $now);

    if (!$stmtPeriodo->execute()) {
      throw new Exception('No se pudo guardar el periodo de vacaciones.');
    }

    $periodoId = (int)$conn->insert_id;
    $stmtDia = $conn->prepare("INSERT INTO fichaje_ausencias_usuario
      (user_id, username, comercial, fecha, anio, mes, tipo, jornada, fraccion, descripcion, activo, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
      ON DUPLICATE KEY UPDATE
        username = VALUES(username), comercial = VALUES(comercial), anio = VALUES(anio), mes = VALUES(mes),
        tipo = VALUES(tipo), jornada = VALUES(jornada), fraccion = VALUES(fraccion), descripcion = VALUES(descripcion), activo = 1, updated_at = VALUES(updated_at)");

    if (!$stmtDia) {
      throw new Exception('No se pudo preparar el guardado de los días.');
    }

    $guardados = 0;
    $fechaActual = clone $inicio;

    while ($fechaActual <= $fin) {
      $fecha = $fechaActual->format('Y-m-d');
      $anioFecha = (int)$fechaActual->format('Y');
      $mesFecha = (int)$fechaActual->format('n');
      $fraccionDia = $fraccionPeriodo;
      if ((int)$fechaActual->format('N') >= 6 || fichajeEsFestivoBarcelona($conn, $fecha)) $fraccionDia = 0.0;
      $stmtDia->bind_param('isssiissdsss', $userId, $username, $comercial, $fecha, $anioFecha, $mesFecha, $tipo, $jornada, $fraccionDia, $descripcion, $now, $now);

      if (!$stmtDia->execute()) {
        throw new Exception('No se pudo guardar uno de los días del periodo.');
      }

      fichajeRecalcularResumenExistente($conn, $userId, $fecha);
      $guardados++;
      $fechaActual->modify('+1 day');
    }

    $conn->commit();

    $periodo = [
      'id' => $periodoId,
      'periodo_uid' => $periodoUid,
      'user_id' => $userId,
      'username' => $username,
      'comercial' => $comercial,
      'fecha_inicio' => $fechaInicio,
      'fecha_fin' => $fechaFin,
      'tipo' => $tipo,
      'jornada' => $jornada,
      'fraccion' => $fraccionPeriodo,
      'descripcion' => $descripcion,
      'calendar_event_id' => ''
    ];

    $sync = fichajeAusenciaPeriodoSincronizar($conn, $periodo, 'crear');
    $mes = (int)$inicio->format('n');
    $anio = (int)$inicio->format('Y');
    $mensaje = $guardados === 1
      ? 'Día guardado correctamente.'
      : 'Periodo guardado correctamente: ' . $guardados . ' días registrados.';

    if (!($sync['ok'] ?? false)) {
      $mensaje .= ' La información queda guardada; la sincronización con el calendario compartido está pendiente.';
    }

    fichajeAusenciaRedirect(['ok' => $mensaje, 'mes' => $mes, 'anio' => $anio]);
  }


  if ($accion === 'editar_periodo') {
    $periodoId = (int)($_POST['periodo_id'] ?? 0);
    if ($periodoId <= 0) throw new Exception('Periodo no válido.');

    $periodo = fichajeAusenciaPeriodoPorId($conn, $periodoId);
    if (!$periodo) {
      throw new Exception('No se encontró el periodo.');
    }

    $targetUserId = (int)$periodo['user_id'];
    $targetUsername = (string)$periodo['username'];
    $targetComercial = (string)$periodo['comercial'];

    if ($targetUserId !== $userId && !$esPrivilegiado) {
      throw new Exception('No puedes modificar vacaciones de otro usuario.');
    }

    if ((string)$periodo['fecha_fin'] < date('Y-m-d') && !$esPrivilegiado) {
      throw new Exception('Los periodos vencidos solo pueden ser modificados por Admin o Máster.');
    }

    $fechaInicio = trim((string)($_POST['fecha_inicio'] ?? ''));
    $fechaFin = trim((string)($_POST['fecha_fin'] ?? $fechaInicio));
    $tipo = trim((string)($_POST['tipo'] ?? 'vacaciones'));
    $jornada = trim((string)($_POST['jornada'] ?? 'completa'));
    $descripcion = mb_substr(trim((string)($_POST['descripcion'] ?? '')), 0, 180, 'UTF-8');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
      throw new Exception('Las fechas no son válidas.');
    }
    if (!in_array($tipo, ['vacaciones', 'dia_libre'], true)) throw new Exception('El tipo seleccionado no es válido.');
    if (!in_array($jornada, ['completa','manana','tarde'], true)) throw new Exception('La jornada seleccionada no es válida.');

    $timezone = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');
    $inicio = new DateTime($fechaInicio, $timezone);
    $fin = new DateTime($fechaFin, $timezone);
    if ($fin < $inicio) throw new Exception('La fecha fin no puede ser anterior a la fecha inicio.');
    $diasPeriodo = $inicio->diff($fin)->days + 1;
    if ($diasPeriodo > 370) throw new Exception('El rango seleccionado es demasiado amplio.');
    if ($diasPeriodo > 1 && $jornada !== 'completa') throw new Exception('Los rangos de varias fechas deben registrarse como días completos.');
    if ($diasPeriodo === 1 && (int)$inicio->format('N') === 5 && $jornada !== 'completa') throw new Exception('El viernes computa como un día completo y no admite medias jornadas.');
    if (fichajeAusenciasExisteSolapamiento($conn, $targetUserId, $fechaInicio, $fechaFin, $periodoId)) throw new Exception('Ya tienes otro periodo registrado dentro de esas fechas.');

    $fraccionPeriodo = $jornada === 'completa' ? 1.0 : 0.5;
    $oldInicio = (string)$periodo['fecha_inicio'];
    $oldFin = (string)$periodo['fecha_fin'];
    $now = fichajeNow()->format('Y-m-d H:i:s');
    $conn->begin_transaction();

    $stmt = $conn->prepare("UPDATE fichaje_ausencias_periodos SET fecha_inicio=?, fecha_fin=?, tipo=?, jornada=?, fraccion=?, descripcion=?, calendar_sync_status='pendiente', updated_at=? WHERE id=? AND user_id=?");
    if (!$stmt) throw new Exception('No se pudo preparar la edición del periodo.');
    $stmt->bind_param('ssssdssii', $fechaInicio, $fechaFin, $tipo, $jornada, $fraccionPeriodo, $descripcion, $now, $periodoId, $targetUserId);
    if (!$stmt->execute()) throw new Exception('No se pudo actualizar el periodo.');

    $stmt = $conn->prepare("UPDATE fichaje_ausencias_usuario SET activo=0, updated_at=? WHERE user_id=? AND fecha>=? AND fecha<=?");
    if (!$stmt) throw new Exception('No se pudieron preparar los días anteriores.');
    $stmt->bind_param('siss', $now, $targetUserId, $oldInicio, $oldFin);
    $stmt->execute();

    $stmtDia = $conn->prepare("INSERT INTO fichaje_ausencias_usuario
      (user_id, username, comercial, fecha, anio, mes, tipo, jornada, fraccion, descripcion, activo, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
      ON DUPLICATE KEY UPDATE username=VALUES(username), comercial=VALUES(comercial), anio=VALUES(anio), mes=VALUES(mes), tipo=VALUES(tipo), jornada=VALUES(jornada), fraccion=VALUES(fraccion), descripcion=VALUES(descripcion), activo=1, updated_at=VALUES(updated_at)");
    if (!$stmtDia) throw new Exception('No se pudieron preparar los nuevos días.');

    $cursor = clone $inicio;
    while ($cursor <= $fin) {
      $fecha = $cursor->format('Y-m-d');
      $anioFecha = (int)$cursor->format('Y');
      $mesFecha = (int)$cursor->format('n');
      $fraccionDia = $fraccionPeriodo;
      if ((int)$cursor->format('N') >= 6 || fichajeEsFestivoBarcelona($conn, $fecha)) $fraccionDia = 0.0;
      $stmtDia->bind_param('isssiissdsss', $userId, $username, $comercial, $fecha, $anioFecha, $mesFecha, $tipo, $jornada, $fraccionDia, $descripcion, $now, $now);
      if (!$stmtDia->execute()) throw new Exception('No se pudo guardar uno de los días editados.');
      $cursor->modify('+1 day');
    }

    $recalcInicio = min($oldInicio, $fechaInicio);
    $recalcFin = max($oldFin, $fechaFin);
    $cursor = new DateTime($recalcInicio, $timezone);
    $recalcEnd = new DateTime($recalcFin, $timezone);
    while ($cursor <= $recalcEnd) {
      fichajeRecalcularResumenExistente($conn, $targetUserId, $cursor->format('Y-m-d'));
      $cursor->modify('+1 day');
    }

    $conn->commit();
    $periodoActualizado = array_merge($periodo, [
      'fecha_inicio'=>$fechaInicio,'fecha_fin'=>$fechaFin,'tipo'=>$tipo,'jornada'=>$jornada,
      'fraccion'=>$fraccionPeriodo,'descripcion'=>$descripcion
    ]);
    $sync = fichajeAusenciaPeriodoSincronizar($conn, $periodoActualizado, 'editar');
    $mensaje = 'Periodo actualizado correctamente.';
    if (!($sync['ok'] ?? false)) $mensaje .= ' La sincronización con el calendario compartido queda pendiente.';
    fichajeAusenciaRedirect(['ok'=>$mensaje,'mes'=>(int)$inicio->format('n'),'anio'=>(int)$inicio->format('Y'),'tab'=>'calendario']);
  }

  if ($accion === 'eliminar_periodo') {
    $periodoId = (int)($_POST['periodo_id'] ?? 0);

    if ($periodoId <= 0) {
      throw new Exception('Periodo no válido.');
    }

    $stmt = $conn->prepare("SELECT * FROM fichaje_ausencias_periodos WHERE id = ? AND activo = 1 LIMIT 1");
    if (!$stmt) throw new Exception('No se pudo leer el periodo.');
    $stmt->bind_param('i', $periodoId);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
      throw new Exception('No se encontró el periodo.');
    }

    $periodo = $result->fetch_assoc();
    $targetUserId = (int)$periodo['user_id'];

    if ($targetUserId !== $userId && !$esPrivilegiado) {
      throw new Exception('No puedes eliminar vacaciones de otro usuario.');
    }

    if ((string)$periodo['fecha_fin'] < date('Y-m-d') && !$esPrivilegiado) {
      throw new Exception('Los periodos vencidos solo pueden ser eliminados por Admin o Máster.');
    }

    $fechaInicio = (string)$periodo['fecha_inicio'];
    $fechaFin = (string)$periodo['fecha_fin'];
    $now = fichajeNow()->format('Y-m-d H:i:s');
    $conn->begin_transaction();

    $stmtPeriodo = $conn->prepare("UPDATE fichaje_ausencias_periodos SET activo = 0, updated_at = ? WHERE id = ? AND user_id = ?");
    if (!$stmtPeriodo) throw new Exception('No se pudo preparar la eliminación del periodo.');
    $stmtPeriodo->bind_param('sii', $now, $periodoId, $targetUserId);
    if (!$stmtPeriodo->execute()) throw new Exception('No se pudo eliminar el periodo.');

    $stmtDias = $conn->prepare("UPDATE fichaje_ausencias_usuario
                                SET activo = 0, updated_at = ?
                                WHERE user_id = ? AND fecha >= ? AND fecha <= ?");
    if (!$stmtDias) throw new Exception('No se pudo preparar la eliminación de los días.');
    $stmtDias->bind_param('siss', $now, $targetUserId, $fechaInicio, $fechaFin);
    if (!$stmtDias->execute()) throw new Exception('No se pudieron eliminar los días del periodo.');

    $fechaActual = new DateTime($fechaInicio, new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid'));
    $fin = new DateTime($fechaFin, new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid'));
    while ($fechaActual <= $fin) {
      fichajeRecalcularResumenExistente($conn, $targetUserId, $fechaActual->format('Y-m-d'));
      $fechaActual->modify('+1 day');
    }

    $conn->commit();
    $sync = fichajeAusenciaPeriodoSincronizar($conn, $periodo, 'eliminar');
    $date = new DateTime($fechaInicio, new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid'));
    $mensaje = 'Periodo eliminado correctamente.';

    if (!($sync['ok'] ?? false)) {
      $mensaje .= ' La eliminación del calendario compartido queda pendiente.';
    }

    fichajeAusenciaRedirect(['ok' => $mensaje, 'mes' => (int)$date->format('n'), 'anio' => (int)$date->format('Y')]);
  }


  if ($accion === 'crear_credito') {
    if (!fichajeVacacionesCreditosTableExists($conn)) throw new Exception('Falta la tabla de días trabajados compensables.');
    $fecha = trim((string)($_POST['fecha'] ?? ''));
    $descripcion = mb_substr(trim((string)($_POST['descripcion'] ?? '')), 0, 180, 'UTF-8');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) throw new Exception('La fecha no es válida.');
    $date = new DateTime($fecha, new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid'));
    $esFinSemana = (int)$date->format('N') >= 6;
    $esFestivo = fichajeEsFestivoBarcelona($conn, $fecha);
    if (!$esFinSemana && !$esFestivo) throw new Exception('Solo se pueden compensar días trabajados en festivos o fines de semana.');
    $fichajeVerificado = 0;
    $st=$conn->prepare("SELECT id, horas_realizadas FROM fichajes WHERE user_id=? AND fecha=? LIMIT 1");
    if($st){
      $st->bind_param('is',$userId,$fecha);$st->execute();$r=$st->get_result();$f=$r?$r->fetch_assoc():null;
      if($f && trim((string)$f['horas_realizadas'])!=='' && trim((string)$f['horas_realizadas'])!=='00:00') $fichajeVerificado = 1;
    }
    $tipoCredito=$esFestivo?'festivo':'fin_semana';$now=fichajeNow()->format('Y-m-d H:i:s');$dias=1.0;
    $tieneVerificado = function_exists('dbColumnExists') && dbColumnExists($conn, 'fichaje_vacaciones_creditos', 'fichaje_verificado');
    if ($tieneVerificado) {
      $st=$conn->prepare("INSERT INTO fichaje_vacaciones_creditos(user_id,username,comercial,fecha,tipo,dias_credito,descripcion,fichaje_verificado,created_at) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE tipo=VALUES(tipo),dias_credito=VALUES(dias_credito),descripcion=VALUES(descripcion),fichaje_verificado=VALUES(fichaje_verificado)");
      if(!$st) throw new Exception('No se pudo preparar el día compensable.');
      $st->bind_param('issssdsis',$userId,$username,$comercial,$fecha,$tipoCredito,$dias,$descripcion,$fichajeVerificado,$now);
    } else {
      $st=$conn->prepare("INSERT INTO fichaje_vacaciones_creditos(user_id,username,comercial,fecha,tipo,dias_credito,descripcion,created_at) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE tipo=VALUES(tipo),dias_credito=VALUES(dias_credito),descripcion=VALUES(descripcion)");
      if(!$st) throw new Exception('No se pudo preparar el día compensable.');
      $st->bind_param('issssdss',$userId,$username,$comercial,$fecha,$tipoCredito,$dias,$descripcion,$now);
    }
    if(!$st->execute()) throw new Exception('No se pudo guardar el día compensable.');
    $mensajeCredito = 'Día trabajado añadido: suma 1 día a tus vacaciones disponibles.';
    $mensajeCredito .= $fichajeVerificado ? ' El fichaje del día ha sido verificado.' : ' Se ha registrado sin fichaje asociado.';
    fichajeAusenciaRedirect(['ok'=>$mensajeCredito,'mes'=>(int)$date->format('n'),'anio'=>(int)$date->format('Y'),'tab'=>'saldo']);
  }

  if ($accion === 'eliminar_credito') {
    $creditoId=(int)($_POST['credito_id']??0);
    if($creditoId<=0) throw new Exception('Registro no válido.');
    $st=$conn->prepare("DELETE FROM fichaje_vacaciones_creditos WHERE id=? AND user_id=?");
    if(!$st) throw new Exception('No se pudo preparar la eliminación.');
    $st->bind_param('ii',$creditoId,$userId);$st->execute();
    fichajeAusenciaRedirect(['ok'=>'Día compensable eliminado.','anio'=>(int)($_POST['anio']??date('Y')),'mes'=>(int)($_POST['mes']??date('n')),'tab'=>'saldo']);
  }

  throw new Exception('Acción no válida.');
} catch (Throwable $e) {
  if (isset($conn) && $conn instanceof mysqli) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
  }
  $mes = intval($_POST['mes'] ?? date('n'));
  $anio = intval($_POST['anio'] ?? date('Y'));
  fichajeAusenciaRedirect(['error' => $e->getMessage(), 'mes' => $mes, 'anio' => $anio]);
}

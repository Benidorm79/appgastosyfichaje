<?php

function fichajeNow() {
  $timezone = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');
  return new DateTime('now', $timezone);
}

function fichajeDiaSemana($fecha) {
  $dias = [1=>'lunes',2=>'martes',3=>'miércoles',4=>'jueves',5=>'viernes',6=>'sábado',7=>'domingo'];
  try {
    $date = new DateTime($fecha, new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid'));
    return $dias[(int)$date->format('N')] ?? '';
  } catch (Exception $e) {
    return '';
  }
}

function fichajeObjetivoMinutos($fecha) {
  try {
    $date = new DateTime($fecha, new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid'));
    $n = (int)$date->format('N');
  } catch (Exception $e) {
    return 0;
  }
  if ($n >= 1 && $n <= 4) return 510;
  if ($n === 5) return 360;
  return 0;
}


function fichajeFestivosBarcelonaDefault($anio) {
  $anio = (int)$anio;
  $festivos = [
    2026 => [
      '2026-01-01' => 'Año Nuevo',
      '2026-01-06' => 'Epifanía del Señor',
      '2026-04-03' => 'Viernes Santo',
      '2026-04-06' => 'Lunes de Pascua',
      '2026-05-01' => 'Fiesta del Trabajo',
      '2026-05-25' => 'Lunes de Pascua Granada',
      '2026-06-24' => 'San Juan',
      '2026-08-15' => 'Asunción de la Virgen',
      '2026-09-11' => 'Diada Nacional de Cataluña',
      '2026-09-24' => 'La Mercè',
      '2026-10-12' => 'Fiesta Nacional Española',
      '2026-12-08' => 'Inmaculada Concepción',
      '2026-12-25' => 'Navidad',
      '2026-12-26' => 'San Esteban'
    ]
  ];

  return $festivos[$anio] ?? [];
}

function fichajeFestivosBarcelona($conn, $anio) {
  $anio = (int)$anio;
  $festivos = [];

  if ($conn && fichajeTableExists($conn, 'fichaje_festivos')) {
    $sql = "SELECT fecha, descripcion FROM fichaje_festivos WHERE anio = ? AND activo = 1 ORDER BY fecha ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
      $stmt->bind_param('i', $anio);
      $stmt->execute();
      $result = $stmt->get_result();
      while ($result && ($row = $result->fetch_assoc())) {
        $festivos[(string)$row['fecha']] = (string)$row['descripcion'];
      }
    }
  }

  if (count($festivos) === 0) {
    $festivos = fichajeFestivosBarcelonaDefault($anio);
  }

  return $festivos;
}

function fichajeEsFestivoBarcelona($conn, $fecha) {
  try {
    $date = new DateTime($fecha, new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid'));
  } catch (Exception $e) {
    return false;
  }

  $anio = (int)$date->format('Y');
  $festivos = fichajeFestivosBarcelona($conn, $anio);
  return isset($festivos[$date->format('Y-m-d')]);
}

function fichajeAusenciasTableExists($conn) {
  return $conn && fichajeTableExists($conn, 'fichaje_ausencias_usuario');
}

function fichajeAusenciasUsuarioMes($conn, $userId, $anio, $mes) {
  $userId = (int)$userId;
  $anio = (int)$anio;
  $mes = (int)$mes;
  $ausencias = [];

  if ($userId <= 0 || $mes < 1 || $mes > 12 || !fichajeAusenciasTableExists($conn)) {
    return $ausencias;
  }

  $desde = sprintf('%04d-%02d-01', $anio, $mes);
  $hasta = date('Y-m-d', strtotime($desde . ' +1 month'));
  $sql = "SELECT * FROM fichaje_ausencias_usuario WHERE user_id = ? AND fecha >= ? AND fecha < ? AND activo = 1 ORDER BY fecha ASC";
  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return $ausencias;
  }

  $stmt->bind_param('iss', $userId, $desde, $hasta);
  $stmt->execute();
  $result = $stmt->get_result();

  while ($result && ($row = $result->fetch_assoc())) {
    $ausencias[(string)$row['fecha']] = $row;
  }

  return $ausencias;
}

function fichajeAusenciasUsuariosMes($conn, $userIds, $anio, $mes) {
  $salida = [];

  if (!is_array($userIds)) {
    $userIds = [(int)$userIds];
  }

  foreach ($userIds as $userId) {
    $userId = (int)$userId;
    if ($userId > 0) {
      $salida[$userId] = fichajeAusenciasUsuarioMes($conn, $userId, $anio, $mes);
    }
  }

  return $salida;
}

function fichajeAusenciaUsuarioFecha($conn, $userId, $fecha) {
  $userId = (int)$userId;
  if ($userId <= 0 || !fichajeAusenciasTableExists($conn)) return null;
  $sql = "SELECT * FROM fichaje_ausencias_usuario WHERE user_id = ? AND fecha = ? AND activo = 1 LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) return null;
  $stmt->bind_param('is', $userId, $fecha);
  $stmt->execute();
  $result = $stmt->get_result();
  return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
}

function fichajeEsAusenciaUsuario($conn, $userId, $fecha) {
  return fichajeAusenciaUsuarioFecha($conn, $userId, $fecha) !== null;
}

function fichajeFraccionAusenciaUsuario($conn, $userId, $fecha) {
  $row = fichajeAusenciaUsuarioFecha($conn, $userId, $fecha);
  if (!$row) return 0.0;
  return isset($row['fraccion']) ? max(0.0, min(1.0, (float)$row['fraccion'])) : 1.0;
}

function fichajeObjetivoMinutosConCalendario($conn, $fecha, $userId = null) {
  try {
    $date = new DateTime($fecha, new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid'));
    $n = (int)$date->format('N');
    $fechaSql = $date->format('Y-m-d');
  } catch (Exception $e) {
    return 0;
  }

  if ($n >= 6) return 0;
  if (fichajeEsFestivoBarcelona($conn, $fechaSql)) return 0;
  $base = ($n >= 1 && $n <= 4) ? 510 : (($n === 5) ? 360 : 0);
  if ($userId !== null && (int)$userId > 0) {
    $fraccion = fichajeFraccionAusenciaUsuario($conn, (int)$userId, $fechaSql);
    if ($fraccion > 0) return max(0, (int)round($base * (1 - $fraccion)));
  }
  return $base;
  return 0;
}

function fichajeCalendarioLaboralMes($conn, $anio, $mes, $userIds = []) {
  $anio = (int)$anio;
  $mes = (int)$mes;
  $diasLaborables = 0;
  $minutosObjetivo = 0;
  $festivos = fichajeFestivosBarcelona($conn, $anio);

  if (!is_array($userIds)) {
    $userIds = [(int)$userIds];
  }

  $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), function ($id) {
    return $id > 0;
  })));

  if ($mes < 1 || $mes > 12 || $anio < 2020 || $anio > 2100) {
    return ['dias_laborables' => 0, 'minutos_objetivo' => 0, 'festivos' => [], 'ausencias' => [], 'ausencias_count' => 0];
  }

  $fecha = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $anio, $mes), new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid'));
  if (!$fecha) return ['dias_laborables' => 0, 'minutos_objetivo' => 0, 'festivos' => [], 'ausencias' => [], 'ausencias_count' => 0];

  $diasMes = (int)$fecha->format('t');
  $festivosMes = [];
  $ausenciasPorUsuario = fichajeAusenciasUsuariosMes($conn, $userIds, $anio, $mes);
  $ausenciasCount = 0;

  for ($dia = 1; $dia <= $diasMes; $dia++) {
    $actual = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    $date = new DateTime($actual, new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid'));
    $n = (int)$date->format('N');

    if (isset($festivos[$actual])) {
      $festivosMes[$actual] = $festivos[$actual];
    }

    if ($n >= 6) continue;
    if (isset($festivos[$actual])) continue;

    $diasLaborables++;

    if (count($userIds) === 0) {
      if ($n >= 1 && $n <= 4) $minutosObjetivo += 510;
      elseif ($n === 5) $minutosObjetivo += 360;
      continue;
    }

    foreach ($userIds as $userId) {
      $baseDia = ($n >= 1 && $n <= 4) ? 510 : (($n === 5) ? 360 : 0);
      $fraccion = 0.0;
      if (isset($ausenciasPorUsuario[$userId][$actual])) {
        $rowAusencia = $ausenciasPorUsuario[$userId][$actual];
        $fraccion = isset($rowAusencia['fraccion']) ? max(0.0, min(1.0, (float)$rowAusencia['fraccion'])) : 1.0;
        $ausenciasCount += $fraccion;
      }
      $minutosObjetivo += max(0, (int)round($baseDia * (1 - $fraccion)));
    }
  }

  return ['dias_laborables' => $diasLaborables, 'minutos_objetivo' => $minutosObjetivo, 'festivos' => $festivosMes, 'ausencias' => $ausenciasPorUsuario, 'ausencias_count' => $ausenciasCount];
}

function fichajeMinutosAHHMM($minutos) {
  $minutos = (int)$minutos;
  $signo = '';
  if ($minutos < 0) { $signo = '-'; $minutos = abs($minutos); }
  return $signo . str_pad((string)intdiv($minutos, 60), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)($minutos % 60), 2, '0', STR_PAD_LEFT);
}

function fichajeDiferenciaHHMM($minutos) {
  $minutos = (int)$minutos;
  $signo = $minutos < 0 ? '-' : '+';
  $minutos = abs($minutos);
  return $signo . str_pad((string)intdiv($minutos, 60), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)($minutos % 60), 2, '0', STR_PAD_LEFT);
}

function fichajeHoraAMinutos($hora) {
  $hora = trim((string)$hora);
  if (!preg_match('/^([0-2][0-9]):([0-5][0-9])$/', $hora, $m)) return 0;
  return ((int)$m[1] * 60) + (int)$m[2];
}

function fichajeFirma($userId, $username, $comercial, $fecha, $hora, $tipo, $createdAt) {
  $secret = defined('FICHAJE_SIGNATURE_SECRET') ? trim((string)FICHAJE_SIGNATURE_SECRET) : '';
  if ($secret === '') throw new Exception('Esta operación no está disponible en este momento.');
  $base = implode('|', [(int)$userId, (string)$username, (string)$comercial, (string)$fecha, (string)$hora, (string)$tipo, (string)$createdAt, $secret]);
  return 'FIC-' . strtoupper(substr(hash('sha256', $base), 0, 16));
}

function fichajeTableExists($conn, $table) {
  $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
  if ($table === '') return false;
  $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
  return $result && $result->num_rows > 0;
}

function fichajeGetResumen($conn, $userId, $fecha) {
  $sql = "SELECT * FROM fichajes WHERE user_id = ? AND fecha = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) return null;
  $stmt->bind_param('is', $userId, $fecha);
  $stmt->execute();
  $result = $stmt->get_result();
  return $result && $result->num_rows > 0 ? $result->fetch_assoc() : null;
}

function fichajeCrearResumenSiNoExiste($conn, $userId, $username, $comercial, $fecha) {
  $existente = fichajeGetResumen($conn, $userId, $fecha);
  if ($existente) return $existente;
  $diaMes = (int)(new DateTime($fecha))->format('j');
  $diaSemana = fichajeDiaSemana($fecha);
  $objetivoMinutos = fichajeObjetivoMinutosConCalendario($conn, $fecha, $userId);
  $objetivo = fichajeMinutosAHHMM($objetivoMinutos);
  $now = fichajeNow()->format('Y-m-d H:i:s');
  $diferenciaInicial = fichajeDiferenciaHHMM(0 - $objetivoMinutos);
  $sql = "INSERT INTO fichajes (user_id, username, comercial, fecha, dia_mes, dia_semana, horas_objetivo, horas_realizadas, diferencia, estado, auto_completado, sync_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, '00:00', ?, 'abierto', 0, 'pendiente', ?, ?)";
  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception('No se pudo preparar el registro de fichaje.');
  $stmt->bind_param('isssisssss', $userId, $username, $comercial, $fecha, $diaMes, $diaSemana, $objetivo, $diferenciaInicial, $now, $now);
  if (!$stmt->execute()) throw new Exception('No se pudo crear el registro de fichaje.');
  return fichajeGetResumen($conn, $userId, $fecha);
}

function fichajeGetMarcas($conn, $fichajeId) {
  $sql = "SELECT * FROM fichaje_marcas WHERE fichaje_id = ? ORDER BY fecha ASC, hora ASC, id ASC";
  $stmt = $conn->prepare($sql);
  if (!$stmt) return [];
  $stmt->bind_param('i', $fichajeId);
  $stmt->execute();
  $result = $stmt->get_result();
  $rows = [];
  while ($result && ($row = $result->fetch_assoc())) $rows[] = $row;
  return $rows;
}

function fichajeUltimaMarca($conn, $userId, $fecha = null) {
  if ($fecha !== null) {
    $sql = "SELECT * FROM fichaje_marcas WHERE user_id = ? AND fecha = ? ORDER BY fecha DESC, hora DESC, id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('is', $userId, $fecha);
  } else {
    $sql = "SELECT * FROM fichaje_marcas WHERE user_id = ? ORDER BY fecha DESC, hora DESC, id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('i', $userId);
  }
  $stmt->execute();
  $result = $stmt->get_result();
  return $result && $result->num_rows > 0 ? $result->fetch_assoc() : null;
}

function fichajeCalcularMinutosTrabajados($marcas) {
  $total = 0; $entrada = null;
  foreach ($marcas as $marca) {
    if (($marca['tipo'] ?? '') === 'entrada') {
      $entrada = fichajeHoraAMinutos($marca['hora'] ?? '00:00');
    } elseif (($marca['tipo'] ?? '') === 'salida' && $entrada !== null) {
      $salida = fichajeHoraAMinutos($marca['hora'] ?? '00:00');
      if ($salida > $entrada) $total += ($salida - $entrada);
      $entrada = null;
    }
  }
  return $total;
}

function fichajeClasificarResumenPrincipal($marcas, $fecha) {
  $entradaManana = ''; $salidaManana = ''; $entradaTarde = ''; $salidaTarde = '';
  $firmaEntradaManana = ''; $firmaSalidaManana = ''; $firmaEntradaTarde = ''; $firmaSalidaTarde = '';
  $esViernes = fichajeDiaSemana($fecha) === 'viernes';
  $salidaMananaIndex = null;
  foreach ($marcas as $idx => $marca) {
    if (($marca['tipo'] ?? '') === 'entrada' && $entradaManana === '') { $entradaManana = $marca['hora']; $firmaEntradaManana = $marca['firma']; }
    if (($marca['tipo'] ?? '') === 'salida') {
      $motivo = $marca['motivo'] ?? ''; $mins = fichajeHoraAMinutos($marca['hora']);
      if ($salidaManana === '' && ($esViernes || $motivo === 'comida' || $motivo === 'fin_jornada' || $mins >= 780)) {
        $salidaManana = $marca['hora']; $firmaSalidaManana = $marca['firma']; $salidaMananaIndex = $idx;
      }
    }
  }
  if ($salidaManana === '') {
    foreach ($marcas as $idx => $marca) {
      if (($marca['tipo'] ?? '') === 'salida') { $salidaManana = $marca['hora']; $firmaSalidaManana = $marca['firma']; $salidaMananaIndex = $idx; break; }
    }
  }
  if (!$esViernes && $salidaMananaIndex !== null) {
    for ($i = $salidaMananaIndex + 1; $i < count($marcas); $i++) if (($marcas[$i]['tipo'] ?? '') === 'entrada') { $entradaTarde = $marcas[$i]['hora']; $firmaEntradaTarde = $marcas[$i]['firma']; break; }
    for ($i = count($marcas) - 1; $i >= 0; $i--) if (($marcas[$i]['tipo'] ?? '') === 'salida' && $i > $salidaMananaIndex) { $salidaTarde = $marcas[$i]['hora']; $firmaSalidaTarde = $marcas[$i]['firma']; break; }
  }
  return ['entrada_manana'=>$entradaManana,'firma_entrada_manana'=>$firmaEntradaManana,'salida_manana'=>$salidaManana,'firma_salida_manana'=>$firmaSalidaManana,'entrada_tarde'=>$entradaTarde,'firma_entrada_tarde'=>$firmaEntradaTarde,'salida_tarde'=>$salidaTarde,'firma_salida_tarde'=>$firmaSalidaTarde];
}

function fichajeActualizarResumen($conn, $fichajeId, $forzarEstado = null, $autoCompletado = null) {
  $stmt = $conn->prepare("SELECT * FROM fichajes WHERE id = ? LIMIT 1");
  if (!$stmt) throw new Exception('No se pudo leer el resumen de fichaje.');
  $stmt->bind_param('i', $fichajeId); $stmt->execute(); $result = $stmt->get_result();
  if (!$result || $result->num_rows === 0) throw new Exception('No se encontró el resumen de fichaje.');
  $resumen = $result->fetch_assoc();
  $marcas = fichajeGetMarcas($conn, $fichajeId);
  $minutosRealizados = fichajeCalcularMinutosTrabajados($marcas);
  $objetivoMin = fichajeObjetivoMinutosConCalendario($conn, $resumen['fecha'], (int)$resumen['user_id']);
  $horasRealizadas = fichajeMinutosAHHMM($minutosRealizados);
  $diferencia = fichajeDiferenciaHHMM($minutosRealizados - $objetivoMin);
  $ultima = end($marcas);
  $estado = $forzarEstado ?: (($ultima && ($ultima['tipo'] ?? '') === 'entrada') ? 'abierto' : 'completo');
  $auto = $autoCompletado !== null ? (int)$autoCompletado : (int)$resumen['auto_completado'];
  $now = fichajeNow()->format('Y-m-d H:i:s');
  $sql = "UPDATE fichajes SET horas_realizadas = ?, diferencia = ?, estado = ?, auto_completado = ?, updated_at = ? WHERE id = ?";
  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception('No se pudo actualizar el resumen de fichaje.');
  $stmt->bind_param('sssisi', $horasRealizadas, $diferencia, $estado, $auto, $now, $fichajeId);
  if (!$stmt->execute()) throw new Exception('No se pudo guardar el resumen actualizado.');
  return fichajeGetResumen($conn, (int)$resumen['user_id'], $resumen['fecha']);
}

function fichajeRecalcularResumenExistente($conn, $userId, $fecha) {
  $resumen = fichajeGetResumen($conn, (int)$userId, $fecha);

  if (!$resumen) {
    return null;
  }

  $marcas = fichajeGetMarcas($conn, (int)$resumen['id']);
  $minutosRealizados = fichajeCalcularMinutosTrabajados($marcas);
  $objetivoMin = fichajeObjetivoMinutosConCalendario($conn, $resumen['fecha'], (int)$resumen['user_id']);
  $horasObjetivo = fichajeMinutosAHHMM($objetivoMin);
  $horasRealizadas = fichajeMinutosAHHMM($minutosRealizados);
  $diferencia = fichajeDiferenciaHHMM($minutosRealizados - $objetivoMin);
  $now = fichajeNow()->format('Y-m-d H:i:s');

  $stmt = $conn->prepare("UPDATE fichajes SET horas_objetivo = ?, horas_realizadas = ?, diferencia = ?, updated_at = ? WHERE id = ?");

  if (!$stmt) {
    return null;
  }

  $fichajeId = (int)$resumen['id'];
  $stmt->bind_param('ssssi', $horasObjetivo, $horasRealizadas, $diferencia, $now, $fichajeId);
  $stmt->execute();

  return fichajeGetResumen($conn, (int)$userId, $fecha);
}

function fichajeInsertarMarca($conn, $resumen, $tipo, $hora, $motivo = 'entrada', $nota = '', $auto = 0) {
  $createdAt = fichajeNow()->format('Y-m-d H:i:s');
  $firma = fichajeFirma($resumen['user_id'], $resumen['username'], $resumen['comercial'], $resumen['fecha'], $hora, $tipo . '_' . $motivo, $createdAt);
  $sql = "INSERT INTO fichaje_marcas (fichaje_id, user_id, username, comercial, fecha, dia_semana, tipo, hora, motivo, nota, firma, auto_completado, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception('No se pudo preparar la marca de fichaje.');
  $fichajeId=(int)$resumen['id']; $userId=(int)$resumen['user_id']; $username=(string)$resumen['username']; $comercial=(string)$resumen['comercial']; $fecha=(string)$resumen['fecha']; $diaSemana=(string)$resumen['dia_semana']; $auto=(int)$auto;
  $stmt->bind_param('iisssssssssis', $fichajeId, $userId, $username, $comercial, $fecha, $diaSemana, $tipo, $hora, $motivo, $nota, $firma, $auto, $createdAt);
  if (!$stmt->execute()) throw new Exception('No se pudo guardar la marca de fichaje.');
  return $conn->insert_id;
}

function fichajePayload($conn, $fichajeId, $accion = '') {
  $stmt = $conn->prepare("SELECT * FROM fichajes WHERE id = ? LIMIT 1");
  if (!$stmt) return [];
  $stmt->bind_param('i', $fichajeId); $stmt->execute(); $result = $stmt->get_result();
  if (!$result || $result->num_rows === 0) return [];
  $resumen = $result->fetch_assoc(); $marcas = fichajeGetMarcas($conn, $fichajeId); $principal = fichajeClasificarResumenPrincipal($marcas, $resumen['fecha']);
  $detalle = [];
  foreach ($marcas as $marca) $detalle[] = ['tipo'=>$marca['tipo'],'hora'=>$marca['hora'],'motivo'=>$marca['motivo'],'nota'=>$marca['nota'],'firma'=>$marca['firma'],'auto_completado'=>(int)$marca['auto_completado']];
  return array_merge(['tipo'=>'fichaje','accion'=>$accion,'fichaje_id'=>(int)$resumen['id'],'user_id'=>(int)$resumen['user_id'],'username'=>$resumen['username'],'comercial'=>$resumen['comercial'],'fecha'=>$resumen['fecha'],'dia_mes'=>str_pad((string)$resumen['dia_mes'],2,'0',STR_PAD_LEFT),'dia_semana'=>$resumen['dia_semana'],'horas_objetivo'=>$resumen['horas_objetivo'],'horas_realizadas'=>$resumen['horas_realizadas'],'diferencia'=>$resumen['diferencia'],'estado'=>$resumen['estado'],'auto_completado'=>(int)$resumen['auto_completado'],'origen'=>'app_fichaje','marcas_detalle'=>$detalle], $principal);
}

function fichajeSincronizar($conn, $fichajeId, $accion = '') {
  $webhook = defined('MAKE_WEBHOOK_FICHAJE') ? MAKE_WEBHOOK_FICHAJE : '';
  $payload = fichajePayload($conn, $fichajeId, $accion);
  $result = callMakeWebhook($webhook, $payload, 120);
  $now = fichajeNow()->format('Y-m-d H:i:s');
  $syncStatus = ($result['ok'] ?? false) ? 'sincronizado' : 'error';
  $response = json_encode($result, JSON_UNESCAPED_UNICODE);
  $error = ($result['ok'] ?? false) ? null : ($result['message'] ?? 'Error al sincronizar fichaje');
  $stmt = $conn->prepare("UPDATE fichajes SET sync_status = ?, webhook_response = ?, ultimo_error = ?, synced_at = ?, updated_at = ? WHERE id = ?");
  if ($stmt) { $stmt->bind_param('sssssi', $syncStatus, $response, $error, $now, $now, $fichajeId); $stmt->execute(); }
  return $result;
}

function fichajeCerrarDiaAbiertoAutomatico($conn, $marcaAbierta) {
  if (!$marcaAbierta || ($marcaAbierta['tipo'] ?? '') !== 'entrada') return null;
  $fecha = $marcaAbierta['fecha']; $horaCierre = fichajeDiaSemana($fecha) === 'viernes' ? '15:00' : '18:30';
  $resumen = fichajeGetResumen($conn, (int)$marcaAbierta['user_id'], $fecha);
  if (!$resumen) return null;
  fichajeInsertarMarca($conn, $resumen, 'salida', $horaCierre, 'auto_cierre', 'Cierre automático por fichaje posterior.', 1);
  $actualizado = fichajeActualizarResumen($conn, (int)$resumen['id'], 'auto_cerrado', 1);
  fichajeSincronizar($conn, (int)$resumen['id'], 'auto_cierre');
  return $actualizado;
}

function fichajeProcesarMarca($conn, $userId, $username, $comercial, $accion, $motivo = '', $nota = '') {
  if (!fichajeTableExists($conn, 'fichajes') || !fichajeTableExists($conn, 'fichaje_marcas')) throw new Exception('Este apartado todavía no está disponible.');
  $accion = trim((string)$accion);
  if (!in_array($accion, ['entrada','salida'], true)) throw new Exception('Acción de fichaje no válida.');
  $nowObj = fichajeNow(); $fecha = $nowObj->format('Y-m-d'); $hora = $nowObj->format('H:i');
  $ultimaGlobal = fichajeUltimaMarca($conn, $userId, null);
  if ($ultimaGlobal && ($ultimaGlobal['tipo'] ?? '') === 'entrada' && ($ultimaGlobal['fecha'] ?? '') !== $fecha && $accion === 'entrada') fichajeCerrarDiaAbiertoAutomatico($conn, $ultimaGlobal);
  $resumen = fichajeCrearResumenSiNoExiste($conn, $userId, $username, $comercial, $fecha);
  $ultimaHoy = fichajeUltimaMarca($conn, $userId, $fecha);
  if ($accion === 'entrada' && $ultimaHoy && ($ultimaHoy['tipo'] ?? '') === 'entrada') throw new Exception('Ya tienes una entrada abierta. Antes debes registrar una salida.');
  if ($accion === 'salida' && (!$ultimaHoy || ($ultimaHoy['tipo'] ?? '') !== 'entrada')) throw new Exception('No puedes registrar una salida porque no hay ninguna entrada abierta.');
  if ($accion === 'entrada') { $motivo = 'entrada'; $nota = ''; }
  else {
    $motivosPermitidos = ['comida','medico','personal','otro','fin_jornada'];
    if (!in_array($motivo, $motivosPermitidos, true)) throw new Exception('Debes seleccionar un motivo de salida.');
    $nota = mb_substr(trim((string)$nota), 0, 255, 'UTF-8');
  }
  fichajeInsertarMarca($conn, $resumen, $accion, $hora, $motivo, $nota, 0);
  $actualizado = fichajeActualizarResumen($conn, (int)$resumen['id']);
  $sync = fichajeSincronizar($conn, (int)$resumen['id'], $accion . '_' . $motivo);
  return ['ok'=>true,'message'=>$accion === 'entrada' ? 'Entrada registrada correctamente.' : 'Salida registrada correctamente.','resumen'=>$actualizado,'marcas'=>fichajeGetMarcas($conn, (int)$resumen['id']),'sync_ok'=>(bool)($sync['ok'] ?? false),'sync_message'=>$sync['message'] ?? ''];
}

function fichajeAccionSiguiente($conn, $userId) {
  if (!fichajeTableExists($conn, 'fichaje_marcas')) return 'entrada';
  $hoy = fichajeNow()->format('Y-m-d'); $ultima = fichajeUltimaMarca($conn, $userId, $hoy);
  if (!$ultima || ($ultima['tipo'] ?? '') === 'salida') return 'entrada';
  return 'salida';
}

function fichajeUsuariosConsulta($conn) {
  $rows = []; $result = $conn->query("SELECT id, username, comercial FROM users WHERE activo = 1 ORDER BY comercial ASC, username ASC");
  while ($result && ($row = $result->fetch_assoc())) $rows[] = $row;
  return $rows;
}

/* ==============================
   VACACIONES COMPARTIDAS / CALENDARIO OUTLOOK
============================== */

function fichajeAusenciasPeriodosTableExists($conn) {
  return $conn && fichajeTableExists($conn, 'fichaje_ausencias_periodos');
}

function fichajeAusenciasCompartidasMes($conn, $anio, $mes) {
  $anio = (int)$anio;
  $mes = (int)$mes;
  $rows = [];

  if ($mes < 1 || $mes > 12 || !fichajeAusenciasTableExists($conn)) {
    return $rows;
  }

  $desde = sprintf('%04d-%02d-01', $anio, $mes);
  $hasta = date('Y-m-d', strtotime($desde . ' +1 month'));

  try {
    $tieneJornada = function_exists('dbColumnExists') ? dbColumnExists($conn, 'fichaje_ausencias_usuario', 'jornada') : false;
    $tieneFraccion = function_exists('dbColumnExists') ? dbColumnExists($conn, 'fichaje_ausencias_usuario', 'fraccion') : false;

    $selectJornada = $tieneJornada ? 'jornada' : "'completa' AS jornada";
    $selectFraccion = $tieneFraccion ? 'fraccion' : '1.00 AS fraccion';

    $sql = "SELECT id, user_id, username, comercial, fecha, tipo, {$selectJornada}, {$selectFraccion}, descripcion
            FROM fichaje_ausencias_usuario
            WHERE fecha >= ? AND fecha < ? AND activo = 1
            ORDER BY fecha ASC, comercial ASC, username ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $rows;

    $stmt->bind_param('ss', $desde, $hasta);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($result && ($row = $result->fetch_assoc())) {
      $rows[] = $row;
    }
  } catch (Throwable $e) {
    return [];
  }

  return $rows;
}

function fichajeAusenciasPeriodosUsuario($conn, $userId, $anio, $mes) {
  $rows = [];
  $userId = (int)$userId;
  $anio = (int)$anio;
  $mes = (int)$mes;

  if ($userId <= 0 || $mes < 1 || $mes > 12 || !fichajeAusenciasPeriodosTableExists($conn)) {
    return $rows;
  }

  $desde = sprintf('%04d-%02d-01', $anio, $mes);
  $hasta = date('Y-m-d', strtotime($desde . ' +1 month'));
  $sql = "SELECT *
          FROM fichaje_ausencias_periodos
          WHERE user_id = ?
            AND fecha_inicio < ?
            AND fecha_fin >= ?
            AND activo = 1
          ORDER BY fecha_inicio ASC, id ASC";
  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return $rows;
  }

  $stmt->bind_param('iss', $userId, $hasta, $desde);
  $stmt->execute();
  $result = $stmt->get_result();

  while ($result && ($row = $result->fetch_assoc())) {
    $rows[] = $row;
  }

  return $rows;
}

function fichajeAusenciasExisteSolapamiento($conn, $userId, $fechaInicio, $fechaFin, $excludeId = 0) {
  $userId = (int)$userId;
  $excludeId = (int)$excludeId;

  if ($userId <= 0 || !fichajeAusenciasPeriodosTableExists($conn)) {
    return false;
  }

  $sql = "SELECT id
          FROM fichaje_ausencias_periodos
          WHERE user_id = ?
            AND activo = 1
            AND fecha_inicio <= ?
            AND fecha_fin >= ?";

  if ($excludeId > 0) {
    $sql .= " AND id <> ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('issi', $userId, $fechaFin, $fechaInicio, $excludeId);
  } else {
    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('iss', $userId, $fechaFin, $fechaInicio);
  }
  $stmt->execute();
  $result = $stmt->get_result();
  return $result && $result->num_rows > 0;
}

function fichajeAusenciaPeriodoPayload($periodo, $accion = 'crear') {
  $fechaInicio = (string)($periodo['fecha_inicio'] ?? '');
  $fechaFin = (string)($periodo['fecha_fin'] ?? '');
  $fechaFinExclusiva = $fechaFin !== '' ? date('Y-m-d', strtotime($fechaFin . ' +1 day')) : '';
  $tipo = (string)($periodo['tipo'] ?? 'vacaciones');
  $comercial = trim((string)($periodo['comercial'] ?? $periodo['username'] ?? 'Usuario'));
  $tituloTipo = $tipo === 'dia_libre' ? 'Día libre' : 'Vacaciones';
  $jornada = (string)($periodo['jornada'] ?? 'completa');
  $fraccion = isset($periodo['fraccion']) ? (float)$periodo['fraccion'] : ($jornada === 'completa' ? 1.0 : 0.5);
  $allDay = $jornada === 'completa';
  $startTime = $jornada === 'tarde' ? '14:00' : '09:00';
  $endTime = $jornada === 'manana' ? '14:00' : '18:30';

  return [
    'tipo' => 'vacaciones_calendario',
    'accion' => $accion,
    'periodo_id' => (int)($periodo['id'] ?? 0),
    'periodo_uid' => (string)($periodo['periodo_uid'] ?? ''),
    'user_id' => (int)($periodo['user_id'] ?? 0),
    'username' => (string)($periodo['username'] ?? ''),
    'comercial' => $comercial,
    'tipo_ausencia' => $tipo,
    'tipo_ausencia_texto' => $tituloTipo,
    'jornada' => $jornada,
    'fraccion' => $fraccion,
    'fecha_inicio' => $fechaInicio,
    'fecha_fin' => $fechaFin,
    'fecha_fin_exclusiva' => $fechaFinExclusiva,
    'descripcion' => (string)($periodo['descripcion'] ?? ''),
    'calendar_event_id' => (string)($periodo['calendar_event_id'] ?? ''),
    'calendar_subject' => $tituloTipo . ' - ' . $comercial,
    'calendar_body' => trim((string)($periodo['descripcion'] ?? '')),
    'calendar_all_day' => $allDay,
    'calendar_start_time' => $allDay ? '' : $startTime,
    'calendar_end_time' => $allDay ? '' : $endTime,
    'calendar_show_as' => 'oof',
    'calendar_timezone' => defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid',
    'calendar_target' => defined('VACACIONES_CALENDAR_TARGET') ? VACACIONES_CALENDAR_TARGET : '',
    'origen' => 'app_fichaje'
  ];
}

function fichajeAusenciaPeriodoActualizarSync($conn, $periodoId, $result) {
  $periodoId = (int)$periodoId;
  if ($periodoId <= 0 || !fichajeAusenciasPeriodosTableExists($conn)) return;

  $ok = (bool)($result['ok'] ?? false);
  $status = $ok ? 'sincronizado' : (($result['skipped'] ?? false) ? 'pendiente' : 'error');
  $response = json_encode($result, JSON_UNESCAPED_UNICODE);
  $error = $ok ? null : (string)($result['message'] ?? 'No se pudo sincronizar el calendario compartido.');
  $eventId = '';

  if (is_array($result['response_json'] ?? null)) {
    $json = $result['response_json'];
    $eventId = trim((string)($json['calendar_event_id'] ?? $json['event_id'] ?? $json['id'] ?? ''));
  }

  $now = fichajeNow()->format('Y-m-d H:i:s');
  $stmt = $conn->prepare("UPDATE fichaje_ausencias_periodos
                         SET calendar_sync_status = ?, calendar_event_id = CASE WHEN ? <> '' THEN ? ELSE calendar_event_id END,
                             calendar_response = ?, calendar_error = ?, calendar_synced_at = ?, updated_at = ?
                         WHERE id = ?");
  if (!$stmt) return;
  $stmt->bind_param('sssssssi', $status, $eventId, $eventId, $response, $error, $now, $now, $periodoId);
  $stmt->execute();
}

function fichajeAusenciaPeriodoSincronizar($conn, $periodo, $accion = 'crear') {
  $webhook = defined('MAKE_WEBHOOK_VACACIONES_CALENDARIO') ? trim((string)MAKE_WEBHOOK_VACACIONES_CALENDARIO) : '';
  $payload = fichajeAusenciaPeriodoPayload($periodo, $accion);
  $result = callMakeWebhook($webhook, $payload, 90);
  fichajeAusenciaPeriodoActualizarSync($conn, (int)($periodo['id'] ?? 0), $result);
  return $result;
}


function fichajeVacacionesSaldosTableExists($conn) {
  return $conn && fichajeTableExists($conn, 'fichaje_vacaciones_saldos');
}

function fichajeVacacionesCreditosTableExists($conn) {
  return $conn && fichajeTableExists($conn, 'fichaje_vacaciones_creditos');
}

function fichajeVacacionesSaldo($conn, $userId, $anio) {
  $userId = (int)$userId;
  $anio = (int)$anio;
  $asignados = 0.0;
  $disfrutados = 0.0;
  $creditos = 0.0;

  if ($userId <= 0) {
    return ['asignados' => 0, 'disfrutados' => 0, 'creditos' => 0, 'disponibles' => 0];
  }

  try {
    if (fichajeVacacionesSaldosTableExists($conn)) {
      $stmt = $conn->prepare("SELECT dias_asignados FROM fichaje_vacaciones_saldos WHERE user_id = ? AND anio = ? LIMIT 1");
      if ($stmt) {
        $stmt->bind_param('ii', $userId, $anio);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && ($row = $result->fetch_assoc())) {
          $asignados = (float)$row['dias_asignados'];
        }
      }
    }

    /*
      El saldo se calcula fecha a fecha para garantizar que sábados, domingos y
      festivos no descuenten vacaciones, incluso si existen registros antiguos
      creados antes de incorporar esta regla.
    */
    if (fichajeAusenciasTableExists($conn)) {
      $desde = sprintf('%04d-01-01', $anio);
      $hasta = sprintf('%04d-12-31', $anio);
      $tieneFraccion = function_exists('dbColumnExists') && dbColumnExists($conn, 'fichaje_ausencias_usuario', 'fraccion');
      $tieneJornada = function_exists('dbColumnExists') && dbColumnExists($conn, 'fichaje_ausencias_usuario', 'jornada');

      $campos = ['fecha'];
      if ($tieneFraccion) $campos[] = 'fraccion';
      if ($tieneJornada) $campos[] = 'jornada';

      $sql = "SELECT " . implode(', ', $campos) . "
              FROM fichaje_ausencias_usuario
              WHERE user_id = ?
                AND fecha BETWEEN ? AND ?
                AND tipo = 'vacaciones'
                AND activo = 1
              ORDER BY fecha ASC";
      $stmt = $conn->prepare($sql);

      if ($stmt) {
        $stmt->bind_param('iss', $userId, $desde, $hasta);
        $stmt->execute();
        $result = $stmt->get_result();
        $timezone = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

        while ($result && ($row = $result->fetch_assoc())) {
          $fecha = trim((string)($row['fecha'] ?? ''));
          if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) continue;

          $date = new DateTime($fecha, $timezone);
          $numeroDia = (int)$date->format('N');

          if ($numeroDia >= 6 || fichajeEsFestivoBarcelona($conn, $fecha)) {
            continue;
          }

          $fraccion = 1.0;
          if ($tieneFraccion && isset($row['fraccion'])) {
            $fraccion = max(0.0, min(1.0, (float)$row['fraccion']));
          } elseif ($tieneJornada && in_array((string)($row['jornada'] ?? ''), ['manana', 'tarde'], true)) {
            $fraccion = 0.5;
          }

          /* El viernes siempre computa como jornada completa. */
          if ($numeroDia === 5 && $fraccion > 0) {
            $fraccion = 1.0;
          }

          $disfrutados += $fraccion;
        }
      }
    }

    if (fichajeVacacionesCreditosTableExists($conn)) {
      $desde = sprintf('%04d-01-01', $anio);
      $hasta = sprintf('%04d-12-31', $anio);
      $stmt = $conn->prepare("SELECT COALESCE(SUM(dias_credito), 0) total
                              FROM fichaje_vacaciones_creditos
                              WHERE user_id = ? AND fecha BETWEEN ? AND ?");
      if ($stmt) {
        $stmt->bind_param('iss', $userId, $desde, $hasta);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && ($row = $result->fetch_assoc())) {
          $creditos = (float)$row['total'];
        }
      }
    }
  } catch (Throwable $e) {
    // La pantalla no debe caer si falta una ampliación de esquema todavía no ejecutada.
  }

  return [
    'asignados' => $asignados,
    'disfrutados' => $disfrutados,
    'creditos' => $creditos,
    'disponibles' => $asignados + $creditos - $disfrutados
  ];
}

function fichajeVacacionesCreditosUsuario($conn,$userId,$anio){
  $rows=[];$userId=(int)$userId;$anio=(int)$anio;
  if($userId<=0||!fichajeVacacionesCreditosTableExists($conn))return $rows;
  $desde=sprintf('%04d-01-01',$anio);$hasta=sprintf('%04d-12-31',$anio);
  $st=$conn->prepare("SELECT * FROM fichaje_vacaciones_creditos WHERE user_id=? AND fecha BETWEEN ? AND ? ORDER BY fecha DESC");
  if(!$st)return $rows;$st->bind_param('iss',$userId,$desde,$hasta);$st->execute();$r=$st->get_result();while($r&&($x=$r->fetch_assoc()))$rows[]=$x;return $rows;
}

function fichajeAusenciaPeriodoPorId($conn, $periodoId) {
  $periodoId = (int)$periodoId;
  if ($periodoId <= 0 || !fichajeAusenciasPeriodosTableExists($conn)) return null;
  try {
    $stmt = $conn->prepare("SELECT * FROM fichaje_ausencias_periodos WHERE id = ? AND activo = 1 LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $periodoId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_assoc() : null;
  } catch (Throwable $e) {
    return null;
  }
}

function fichajeAusenciaPeriodoPropioEnFecha($conn, $userId, $fecha) {
  $userId = (int)$userId;
  if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$fecha) || !fichajeAusenciasPeriodosTableExists($conn)) return null;
  try {
    $stmt = $conn->prepare("SELECT * FROM fichaje_ausencias_periodos WHERE user_id = ? AND activo = 1 AND fecha_inicio <= ? AND fecha_fin >= ? ORDER BY id DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('iss', $userId, $fecha, $fecha);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_assoc() : null;
  } catch (Throwable $e) {
    return null;
  }
}

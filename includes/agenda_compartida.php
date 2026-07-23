<?php

function agendaCompartidaTableExists($conn) {
  static $exists = null;
  if ($exists !== null) return $exists;
  try {
    $result = $conn->query("SHOW TABLES LIKE 'agenda_compartida'");
    $exists = $result && $result->num_rows > 0;
  } catch (Throwable $e) {
    $exists = false;
  }
  return $exists;
}

function agendaCompartidaEventosMes($conn, $anio, $mes) {
  $rows = [];
  $anio = (int)$anio;
  $mes = (int)$mes;
  if ($anio < 2020 || $mes < 1 || $mes > 12 || !agendaCompartidaTableExists($conn)) return $rows;

  $inicio = sprintf('%04d-%02d-01', $anio, $mes);
  $fin = (new DateTime($inicio, new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid')))->modify('+1 month')->format('Y-m-d');
  try {
    $stmt = $conn->prepare("SELECT * FROM agenda_compartida WHERE activo = 1 AND fecha >= ? AND fecha < ? ORDER BY fecha ASC, todo_el_dia DESC, hora_inicio ASC, id ASC");
    if (!$stmt) return $rows;
    $stmt->bind_param('ss', $inicio, $fin);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && ($row = $result->fetch_assoc())) $rows[] = $row;
  } catch (Throwable $e) {
    return [];
  }
  return $rows;
}

function agendaCompartidaEventosDia($conn, $fecha) {
  $rows = [];
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$fecha) || !agendaCompartidaTableExists($conn)) return $rows;
  try {
    $stmt = $conn->prepare("SELECT * FROM agenda_compartida WHERE activo = 1 AND fecha = ? ORDER BY todo_el_dia DESC, hora_inicio ASC, id ASC");
    if (!$stmt) return $rows;
    $stmt->bind_param('s', $fecha);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && ($row = $result->fetch_assoc())) $rows[] = $row;
  } catch (Throwable $e) {
    return [];
  }
  return $rows;
}

function agendaCompartidaWebhook($evento, $accion) {
  $url = defined('MAKE_WEBHOOK_AGENDA_CALENDARIO') ? trim((string)MAKE_WEBHOOK_AGENDA_CALENDARIO) : '';
  if ($url === '') return ['ok' => false, 'skipped' => true, 'message' => 'No se ha podido completar la actualización.'];

  $payload = [
    'accion' => $accion,
    'tipo' => 'agenda_compartida',
    'evento_id' => (int)($evento['id'] ?? 0),
    'evento_uid' => (string)($evento['evento_uid'] ?? ''),
    'user_id' => (int)($evento['user_id'] ?? 0),
    'username' => (string)($evento['username'] ?? ''),
    'comercial' => (string)($evento['comercial'] ?? ''),
    'fecha' => (string)($evento['fecha'] ?? ''),
    'hora_inicio' => (string)($evento['hora_inicio'] ?? ''),
    'hora_fin' => (string)($evento['hora_fin'] ?? ''),
    'todo_el_dia' => (int)($evento['todo_el_dia'] ?? 0),
    'titulo' => (string)($evento['titulo'] ?? ''),
    'descripcion' => (string)($evento['descripcion'] ?? ''),
    'ubicacion' => (string)($evento['ubicacion'] ?? ''),
    'categoria' => (string)($evento['categoria'] ?? 'actividad'),
    'calendar_event_id' => (string)($evento['calendar_event_id'] ?? ''),
    'calendar_target' => defined('VACACIONES_CALENDAR_TARGET') ? (string)VACACIONES_CALENDAR_TARGET : '',
    'calendar_timezone' => defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid',
    'origen' => 'app_agenda_compartida'
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 12,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
  ]);
  $response = curl_exec($ch);
  $error = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($response === false || $error !== '' || $status < 200 || $status >= 300) {
    return [
      'ok' => false,
      'message' => 'No se ha podido completar la actualización.',
      'internal_message' => $error !== '' ? $error : ('HTTP ' . $status),
      'response' => (string)$response
    ];
  }

  $json = json_decode((string)$response, true);
  $eventId = '';
  if (is_array($json)) $eventId = (string)($json['calendar_event_id'] ?? $json['event_id'] ?? $json['id'] ?? '');
  return ['ok' => true, 'response' => (string)$response, 'calendar_event_id' => $eventId];
}

function agendaCompartidaEventoPorId($conn, $eventoId) {
  $eventoId = (int)$eventoId;
  if ($eventoId <= 0 || !agendaCompartidaTableExists($conn)) return null;
  try {
    $stmt = $conn->prepare("SELECT * FROM agenda_compartida WHERE id = ? AND activo = 1 LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $eventoId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_assoc() : null;
  } catch (Throwable $e) {
    return null;
  }
}

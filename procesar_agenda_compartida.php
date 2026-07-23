<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/fichaje.php';
require_once __DIR__ . '/includes/agenda_compartida.php';

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

function agendaRedirect($params = []) {
  if (isset($params['error'])) $params['error'] = appPublicMessage($params['error']);
  if (isset($params['ok'])) $params['ok'] = appPublicMessage($params['ok'], 'Operación completada correctamente.');
  header('Location: fichaje_ausencias.php?' . http_build_query($params));
  exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = trim((string)($_SESSION['user'] ?? ''));
$comercial = trim((string)($_SESSION['comercial'] ?? $username));
$role = trim((string)($_SESSION['role'] ?? 'user'));
$accion = trim((string)($_POST['accion'] ?? ''));
$token = (string)($_POST['csrf_token'] ?? '');
$fechaRetorno = trim((string)($_POST['fecha_retorno'] ?? date('Y-m-d')));

if ($userId <= 0 || $username === '') agendaRedirect(['error' => 'Sesión no válida.', 'tab' => 'calendario']);
if (empty($_SESSION['fichaje_ausencias_csrf']) || !hash_equals((string)$_SESSION['fichaje_ausencias_csrf'], $token)) {
  agendaRedirect(['error' => 'La sesión del formulario ha caducado.', 'tab' => 'calendario']);
}
if (!agendaCompartidaTableExists($conn)) agendaRedirect(['error' => 'Este apartado todavía no está disponible.', 'tab' => 'calendario']);

try {
  if ($accion === 'crear') {
    $fecha = trim((string)($_POST['fecha'] ?? ''));
    $titulo = mb_substr(trim((string)($_POST['titulo'] ?? '')), 0, 150, 'UTF-8');
    $descripcion = mb_substr(trim((string)($_POST['descripcion'] ?? '')), 0, 1000, 'UTF-8');
    $ubicacion = mb_substr(trim((string)($_POST['ubicacion'] ?? '')), 0, 180, 'UTF-8');
    $categoria = trim((string)($_POST['categoria'] ?? 'actividad'));
    $todoElDia = isset($_POST['todo_el_dia']) ? 1 : 0;
    $horaInicio = trim((string)($_POST['hora_inicio'] ?? ''));
    $horaFin = trim((string)($_POST['hora_fin'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) throw new Exception('La fecha no es válida.');
    if ($titulo === '') throw new Exception('Indica un título para la actividad.');
    if (!in_array($categoria, ['actividad','reunion','visita','formacion','otro'], true)) $categoria = 'actividad';
    if (!$todoElDia) {
      if (!preg_match('/^\d{2}:\d{2}$/', $horaInicio) || !preg_match('/^\d{2}:\d{2}$/', $horaFin)) throw new Exception('Indica hora de inicio y fin.');
      if ($horaFin <= $horaInicio) throw new Exception('La hora de fin debe ser posterior a la hora de inicio.');
    } else {
      $horaInicio = '';
      $horaFin = '';
    }

    $uid = 'AGE-' . strtoupper(bin2hex(random_bytes(12)));
    $now = fichajeNow()->format('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO agenda_compartida
      (evento_uid,user_id,username,comercial,fecha,hora_inicio,hora_fin,todo_el_dia,titulo,descripcion,ubicacion,categoria,activo,calendar_sync_status,created_at,updated_at)
      VALUES (?,?,?,?,?,NULLIF(?,''),NULLIF(?,''),?,?,?,?,?,1,'pendiente',?,?)");
    if (!$stmt) throw new Exception('No se pudo preparar la actividad.');
    $stmt->bind_param('sisssssissssss', $uid, $userId, $username, $comercial, $fecha, $horaInicio, $horaFin, $todoElDia, $titulo, $descripcion, $ubicacion, $categoria, $now, $now);
    if (!$stmt->execute()) throw new Exception('No se pudo guardar la actividad.');
    $id = (int)$conn->insert_id;

    $evento = [
      'id'=>$id,'evento_uid'=>$uid,'user_id'=>$userId,'username'=>$username,'comercial'=>$comercial,
      'fecha'=>$fecha,'hora_inicio'=>$horaInicio,'hora_fin'=>$horaFin,'todo_el_dia'=>$todoElDia,
      'titulo'=>$titulo,'descripcion'=>$descripcion,'ubicacion'=>$ubicacion,'categoria'=>$categoria,'calendar_event_id'=>''
    ];
    $sync = agendaCompartidaWebhook($evento, 'crear');
    $status = ($sync['ok'] ?? false) ? 'sincronizado' : (($sync['skipped'] ?? false) ? 'pendiente' : 'error');
    $eventId = (string)($sync['calendar_event_id'] ?? '');
    $response = (string)($sync['response'] ?? $sync['message'] ?? '');
    $stmt = $conn->prepare("UPDATE agenda_compartida SET calendar_sync_status=?, calendar_event_id=NULLIF(?,''), calendar_response=?, updated_at=? WHERE id=?");
    if ($stmt) { $stmt->bind_param('ssssi',$status,$eventId,$response,$now,$id); $stmt->execute(); }

    $date = new DateTime($fecha);
    $message = 'Actividad añadida al calendario compartido.';
    if (!($sync['ok'] ?? false) && !($sync['skipped'] ?? false)) $message .= ' La sincronización externa queda pendiente.';
    agendaRedirect(['ok'=>$message,'tab'=>'calendario','mes'=>(int)$date->format('n'),'anio'=>(int)$date->format('Y'),'fecha_agenda'=>$fecha]);
  }


  if ($accion === 'editar') {
    $id = (int)($_POST['evento_id'] ?? 0);
    if ($id <= 0) throw new Exception('Actividad no válida.');
    $evento = agendaCompartidaEventoPorId($conn, $id);
    if (!$evento) throw new Exception('La actividad no existe.');
    $puedeEditar = (int)$evento['user_id'] === $userId || in_array($role, ['admin','master'], true);
    if (!$puedeEditar) throw new Exception('No puedes editar una actividad creada por otro usuario.');

    $fecha = trim((string)($_POST['fecha'] ?? ''));
    $titulo = mb_substr(trim((string)($_POST['titulo'] ?? '')), 0, 150, 'UTF-8');
    $descripcion = mb_substr(trim((string)($_POST['descripcion'] ?? '')), 0, 1000, 'UTF-8');
    $ubicacion = mb_substr(trim((string)($_POST['ubicacion'] ?? '')), 0, 180, 'UTF-8');
    $categoria = trim((string)($_POST['categoria'] ?? 'actividad'));
    $todoElDia = isset($_POST['todo_el_dia']) ? 1 : 0;
    $horaInicio = trim((string)($_POST['hora_inicio'] ?? ''));
    $horaFin = trim((string)($_POST['hora_fin'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) throw new Exception('La fecha no es válida.');
    if ($titulo === '') throw new Exception('Indica un título para la actividad.');
    if (!in_array($categoria, ['actividad','reunion','visita','formacion','otro'], true)) $categoria = 'actividad';
    if (!$todoElDia) {
      if (!preg_match('/^\d{2}:\d{2}$/', $horaInicio) || !preg_match('/^\d{2}:\d{2}$/', $horaFin)) throw new Exception('Indica hora de inicio y fin.');
      if ($horaFin <= $horaInicio) throw new Exception('La hora de fin debe ser posterior a la hora de inicio.');
    } else {
      $horaInicio = '';
      $horaFin = '';
    }

    $now = fichajeNow()->format('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE agenda_compartida SET fecha=?, hora_inicio=NULLIF(?,''), hora_fin=NULLIF(?,''), todo_el_dia=?, titulo=?, descripcion=?, ubicacion=?, categoria=?, calendar_sync_status='pendiente', updated_at=? WHERE id=?");
    if (!$stmt) throw new Exception('No se pudo preparar la edición.');
    $stmt->bind_param('sssisssssi', $fecha, $horaInicio, $horaFin, $todoElDia, $titulo, $descripcion, $ubicacion, $categoria, $now, $id);
    if (!$stmt->execute()) throw new Exception('No se pudo actualizar la actividad.');

    $eventoActualizado = array_merge($evento, [
      'fecha'=>$fecha,'hora_inicio'=>$horaInicio,'hora_fin'=>$horaFin,'todo_el_dia'=>$todoElDia,
      'titulo'=>$titulo,'descripcion'=>$descripcion,'ubicacion'=>$ubicacion,'categoria'=>$categoria
    ]);
    $sync = agendaCompartidaWebhook($eventoActualizado, 'editar');
    $status = ($sync['ok'] ?? false) ? 'sincronizado' : (($sync['skipped'] ?? false) ? 'pendiente' : 'error');
    $eventId = (string)($sync['calendar_event_id'] ?? $evento['calendar_event_id'] ?? '');
    $response = (string)($sync['response'] ?? $sync['message'] ?? '');
    $stmt = $conn->prepare("UPDATE agenda_compartida SET calendar_sync_status=?, calendar_event_id=NULLIF(?,''), calendar_response=?, updated_at=? WHERE id=?");
    if ($stmt) { $stmt->bind_param('ssssi',$status,$eventId,$response,$now,$id); $stmt->execute(); }

    $date = new DateTime($fecha);
    $message = 'Actividad actualizada.';
    if (!($sync['ok'] ?? false) && !($sync['skipped'] ?? false)) $message .= ' La sincronización externa queda pendiente.';
    agendaRedirect(['ok'=>$message,'tab'=>'calendario','mes'=>(int)$date->format('n'),'anio'=>(int)$date->format('Y'),'fecha_agenda'=>$fecha]);
  }

  if ($accion === 'eliminar') {
    $id = (int)($_POST['evento_id'] ?? 0);
    if ($id <= 0) throw new Exception('Actividad no válida.');
    $stmt = $conn->prepare("SELECT * FROM agenda_compartida WHERE id=? AND activo=1 LIMIT 1");
    if (!$stmt) throw new Exception('No se pudo consultar la actividad.');
    $stmt->bind_param('i',$id); $stmt->execute(); $result=$stmt->get_result(); $evento=$result?$result->fetch_assoc():null;
    if (!$evento) throw new Exception('La actividad no existe.');
    $puedeEliminar = (int)$evento['user_id'] === $userId || in_array($role,['admin','master'],true);
    if (!$puedeEliminar) throw new Exception('No puedes eliminar una actividad creada por otro usuario.');
    $now = fichajeNow()->format('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE agenda_compartida SET activo=0, updated_at=? WHERE id=?");
    if (!$stmt) throw new Exception('No se pudo preparar la eliminación.');
    $stmt->bind_param('si',$now,$id); if(!$stmt->execute()) throw new Exception('No se pudo eliminar la actividad.');
    agendaCompartidaWebhook($evento, 'eliminar');
    $date = new DateTime((string)$evento['fecha']);
    agendaRedirect(['ok'=>'Actividad eliminada.','tab'=>'calendario','mes'=>(int)$date->format('n'),'anio'=>(int)$date->format('Y'),'fecha_agenda'=>(string)$evento['fecha']]);
  }

  throw new Exception('Acción no válida.');
} catch (Throwable $e) {
  $date = preg_match('/^\d{4}-\d{2}-\d{2}$/',$fechaRetorno) ? new DateTime($fechaRetorno) : new DateTime();
  agendaRedirect(['error'=>$e->getMessage(),'tab'=>'calendario','mes'=>(int)$date->format('n'),'anio'=>(int)$date->format('Y'),'fecha_agenda'=>$date->format('Y-m-d')]);
}

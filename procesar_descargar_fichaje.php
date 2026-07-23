<?php
require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/fichaje.php";

header('Content-Type: application/json; charset=UTF-8');
date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

try {
  if (!fichajeTableExists($conn, 'fichajes') || !fichajeTableExists($conn, 'fichaje_marcas')) {
    throw new Exception('Esta descarga no está disponible en este momento.');
  }

  $input = json_decode(file_get_contents('php://input'), true);
  if (!is_array($input)) $input = $_POST;

  $mes = intval($input['mes'] ?? date('n'));
  $anio = intval($input['anio'] ?? ($input['año'] ?? date('Y')));
  $requestedUserId = intval($input['user_id'] ?? 0);
  $esAdmin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'master'], true);
  $sessionUserId = (int)($_SESSION['user_id'] ?? 0);

  if ($mes < 1 || $mes > 12) throw new Exception('Mes no válido.');
  if ($anio < 2020 || $anio > 2100) throw new Exception('Año no válido.');

  $targetUserId = $esAdmin ? $requestedUserId : $sessionUserId;

  $desde = sprintf('%04d-%02d-01', $anio, $mes);
  $hasta = date('Y-m-d', strtotime($desde . ' +1 month'));

  $where = "f.fecha >= ? AND f.fecha < ?";
  $types = "ss";
  $params = [$desde, $hasta];

  if ($targetUserId > 0) {
    $where .= " AND f.user_id = ?";
    $types .= "i";
    $params[] = $targetUserId;
  }

  $sql = "SELECT f.* FROM fichajes f WHERE $where ORDER BY f.fecha ASC, f.comercial ASC";
  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception('No se pudo preparar la consulta de fichajes.');
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $result = $stmt->get_result();

  $registros = [];
  $marcasDetalle = [];

  while ($result && ($fichaje = $result->fetch_assoc())) {
    $marcas = fichajeGetMarcas($conn, (int)$fichaje['id']);
    $principal = fichajeClasificarResumenPrincipal($marcas, $fichaje['fecha']);

    $registros[] = array_merge([
      'fichaje_id' => (int)$fichaje['id'],
      'user_id' => (int)$fichaje['user_id'],
      'username' => $fichaje['username'],
      'comercial' => $fichaje['comercial'],
      'fecha' => $fichaje['fecha'],
      'dia_mes' => str_pad((string)$fichaje['dia_mes'], 2, '0', STR_PAD_LEFT),
      'dia_semana' => $fichaje['dia_semana'],
      'horas_objetivo' => $fichaje['horas_objetivo'],
      'horas_realizadas' => $fichaje['horas_realizadas'],
      'diferencia' => $fichaje['diferencia'],
      'estado' => $fichaje['estado'],
      'auto_completado' => (int)$fichaje['auto_completado']
    ], $principal);

    foreach ($marcas as $marca) {
      $marcasDetalle[] = [
        'fecha' => $marca['fecha'],
        'dia_semana' => $marca['dia_semana'],
        'user_id' => (int)$marca['user_id'],
        'username' => $marca['username'],
        'comercial' => $marca['comercial'],
        'tipo' => $marca['tipo'],
        'hora' => $marca['hora'],
        'motivo' => $marca['motivo'],
        'nota' => $marca['nota'],
        'firma' => $marca['firma'],
        'auto_completado' => (int)$marca['auto_completado']
      ];
    }
  }

  $payload = [
    'tipo' => 'descarga_registro_jornada',
    'origen' => 'app_fichaje',
    'solicitado_por_user_id' => $sessionUserId,
    'solicitado_por_username' => $_SESSION['user'] ?? '',
    'solicitado_por_comercial' => $_SESSION['comercial'] ?? '',
    'mes' => $mes,
    'anio' => $anio,
    'user_id' => $targetUserId,
    'registros_resumen' => $registros,
    'marcas_detalle' => $marcasDetalle
  ];

  $webhook = defined('MAKE_WEBHOOK_DESCARGAR_FICHAJE') ? MAKE_WEBHOOK_DESCARGAR_FICHAJE : '';
  $makeResult = callMakeWebhook($webhook, $payload, 180);

  if (!($makeResult['ok'] ?? false)) {
    throw new Exception($makeResult['message'] ?? 'No se pudo generar el registro de jornada.');
  }

  $json = $makeResult['response_json'] ?? [];
  echo json_encode([
    'ok' => true,
    'message' => 'Registro de jornada generado correctamente.',
    'total_registros' => count($registros),
    'total_marcas' => count($marcasDetalle),
    'file_url' => is_array($json) ? ($json['file_url'] ?? $json['excel_file_url'] ?? $json['webViewLink'] ?? $json['web_view_link'] ?? null) : null
  ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
  http_response_code(400);
  echo json_encode([
    'ok' => false,
    'message' => appPublicMessage($e->getMessage())
  ], JSON_UNESCAPED_UNICODE);
}

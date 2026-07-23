<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../includes/functions.php";

header("Content-Type: application/json; charset=UTF-8");

function apiResponderPeriodo($httpCode, $payload) {
  http_response_code($httpCode);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
  apiResponderPeriodo(405, [
    'ok' => false,
    'cerrado' => null,
    'message' => 'Método no permitido'
  ]);
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$tokenHeader = '';

foreach ($headers as $key => $value) {
  if (strtolower($key) === 'x-api-token') {
    $tokenHeader = trim((string)$value);
    break;
  }
}

$tokenPost = trim((string)($_POST['token'] ?? ''));
$token = $tokenHeader !== '' ? $tokenHeader : $tokenPost;

$expectedToken = defined('API_PERIODOS_TOKEN') ? API_PERIODOS_TOKEN : '';

if ($expectedToken === '' || !hash_equals($expectedToken, $token)) {
  apiResponderPeriodo(401, [
    'ok' => false,
    'cerrado' => null,
    'message' => 'Token no autorizado'
  ]);
}

$rawBody = file_get_contents("php://input");
$json = json_decode($rawBody, true);

if (!is_array($json)) {
  $json = [];
}

$userId = intval($json['user_id'] ?? $_POST['user_id'] ?? 0);
$username = trim((string)($json['username'] ?? $_POST['username'] ?? ''));
$comercial = trim((string)($json['comercial'] ?? $_POST['comercial'] ?? ''));
$fecha = trim((string)($json['fecha'] ?? $_POST['fecha'] ?? ''));

if ($fecha === '') {
  $mes = intval($json['mes'] ?? $_POST['mes'] ?? 0);
  $anio = intval($json['anio'] ?? $_POST['anio'] ?? 0);

  if ($mes >= 1 && $mes <= 12 && $anio >= 2000 && $anio <= 2100) {
    $fecha = $anio . '-' . str_pad((string)$mes, 2, '0', STR_PAD_LEFT) . '-01';
  }
}

if ($userId <= 0 && $username !== '') {
  $stmtUser = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");

  if ($stmtUser) {
    $stmtUser->bind_param("s", $username);
    $stmtUser->execute();

    $userRow = $stmtUser->get_result()->fetch_assoc();

    if ($userRow) {
      $userId = (int)$userRow['id'];
    }
  }
}

if ($userId <= 0 && $comercial !== '') {
  $stmtUser = $conn->prepare("SELECT id FROM users WHERE comercial = ? LIMIT 1");

  if ($stmtUser) {
    $stmtUser->bind_param("s", $comercial);
    $stmtUser->execute();

    $userRow = $stmtUser->get_result()->fetch_assoc();

    if ($userRow) {
      $userId = (int)$userRow['id'];
    }
  }
}

if ($userId <= 0 || $fecha === '') {
  apiResponderPeriodo(400, [
    'ok' => false,
    'cerrado' => null,
    'message' => 'Faltan datos obligatorios: user_id o fecha'
  ]);
}

try {
  $date = new DateTime($fecha);
  $fechaNormalizada = $date->format('Y-m-d');
  $mes = (int)$date->format('n');
  $anio = (int)$date->format('Y');
} catch (Exception $e) {
  apiResponderPeriodo(400, [
    'ok' => false,
    'cerrado' => null,
    'message' => 'Fecha no válida'
  ]);
}

$info = getPeriodoCerradoInfo($conn, $userId, $fechaNormalizada);

apiResponderPeriodo(200, [
  'ok' => true,
  'cerrado' => $info['cerrado'],
  'message' => $info['cerrado'] ? $info['mensaje'] : 'Periodo abierto',
  'periodo' => [
    'mes' => $mes,
    'anio' => $anio,
    'fecha_control' => $fechaNormalizada
  ],
  'user_id' => $userId,
  'cierre' => $info['cierre']
]);
?>
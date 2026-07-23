<?php
require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/fichaje.php";

header('Content-Type: application/json; charset=UTF-8');
date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

try {
  $input = json_decode(file_get_contents('php://input'), true);
  if (!is_array($input)) {
    $input = $_POST;
  }

  $accion = trim((string)($input['accion'] ?? ''));
  $motivo = trim((string)($input['motivo'] ?? ''));
  $nota = trim((string)($input['nota'] ?? ''));

  $userId = (int)($_SESSION['user_id'] ?? 0);
  $username = (string)($_SESSION['user'] ?? '');
  $comercial = (string)($_SESSION['comercial'] ?? $username);

  if ($userId <= 0 || $username === '') {
    throw new Exception('Sesión no válida. Vuelve a iniciar sesión.');
  }

  $resultado = fichajeProcesarMarca($conn, $userId, $username, $comercial, $accion, $motivo, $nota);

  unset($resultado['sync_ok'], $resultado['sync_message']);
  echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
  http_response_code(400);
  echo json_encode([
    'ok' => false,
    'message' => appPublicMessage($e->getMessage())
  ], JSON_UNESCAPED_UNICODE);
}

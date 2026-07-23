<?php
session_start();

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/auditoria.php";

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$username = $_SESSION['user'] ?? null;
$comercial = $_SESSION['comercial'] ?? null;
$role = $_SESSION['role'] ?? null;

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'seguridad',
  'entidad' => 'login',
  'entidad_id' => $userId,
  'accion' => 'logout_manual',
  'descripcion' => 'Cierre de sesión manual.',
  'usuario_id' => $userId,
  'username' => $username,
  'comercial' => $comercial,
  'rol' => $role,
  'estado_nuevo' => 'cerrado'
]);

session_destroy();
header("Location: login.php");
exit;
?>

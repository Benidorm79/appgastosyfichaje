<?php
require_once __DIR__ . '/../admin_guard.php';
requireMasterAccess();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

function vacacionesSaldoRedirect($anio, $message, $isError = false) {
  $key = $isError ? 'error' : 'ok';
  header('Location: vacaciones_saldos.php?anio=' . (int)$anio . '&' . $key . '=' . urlencode($message));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['vacaciones_saldo_csrf']) || !hash_equals((string)$_SESSION['vacaciones_saldo_csrf'], $token)) {
  vacacionesSaldoRedirect((int)($_POST['anio'] ?? date('Y')), 'La sesión del formulario ha caducado.', true);
}

$accion = trim((string)($_POST['accion'] ?? 'individual'));
$anio = (int)($_POST['anio'] ?? 0);
$diasRaw = str_replace(',', '.', trim((string)($_POST['dias_asignados'] ?? '0')));
$dias = (float)$diasRaw;

if ($anio < 2020 || $anio > 2100 || $dias < 0 || $dias > 366 || abs(($dias * 2) - round($dias * 2)) > 0.00001) {
  vacacionesSaldoRedirect($anio, 'Datos no válidos. Usa incrementos de medio día.', true);
}

$now = date('Y-m-d H:i:s');
$asignadoPor = (int)($_SESSION['user_id'] ?? 0);

try {
  if ($accion === 'aplicar_todos') {
    $result = $conn->query("SELECT id FROM users WHERE activo = 1 ORDER BY id ASC");
    if (!$result) {
      throw new Exception('No se pudo obtener la lista de usuarios activos.');
    }

    $stmt = $conn->prepare("INSERT INTO fichaje_vacaciones_saldos
      (user_id, anio, dias_asignados, asignado_por, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE
        dias_asignados = VALUES(dias_asignados),
        asignado_por = VALUES(asignado_por),
        updated_at = VALUES(updated_at)");

    if (!$stmt) {
      throw new Exception('No se pudo preparar la asignación general.');
    }

    $conn->begin_transaction();
    $actualizados = 0;

    while ($row = $result->fetch_assoc()) {
      $userId = (int)$row['id'];
      $stmt->bind_param('iidiss', $userId, $anio, $dias, $asignadoPor, $now, $now);
      if (!$stmt->execute()) {
        throw new Exception('No se pudo actualizar uno de los usuarios.');
      }
      $actualizados++;
    }

    $conn->commit();
    vacacionesSaldoRedirect($anio, 'Se han asignado ' . number_format($dias, 1, ',', '.') . ' días a ' . $actualizados . ' usuarios activos.');
  }

  $userId = (int)($_POST['user_id'] ?? 0);
  if ($userId <= 0) {
    vacacionesSaldoRedirect($anio, 'Usuario no válido.', true);
  }

  $stmt = $conn->prepare("INSERT INTO fichaje_vacaciones_saldos
    (user_id, anio, dias_asignados, asignado_por, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      dias_asignados = VALUES(dias_asignados),
      asignado_por = VALUES(asignado_por),
      updated_at = VALUES(updated_at)");

  if (!$stmt) {
    throw new Exception('No se pudo preparar la actualización.');
  }

  $stmt->bind_param('iidiss', $userId, $anio, $dias, $asignadoPor, $now, $now);
  if (!$stmt->execute()) {
    throw new Exception('No se pudieron actualizar los días anuales.');
  }

  vacacionesSaldoRedirect($anio, 'Días anuales actualizados.');
} catch (Throwable $e) {
  try { $conn->rollback(); } catch (Throwable $ignored) {}
  vacacionesSaldoRedirect($anio, $e->getMessage(), true);
}

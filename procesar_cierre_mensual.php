<?php
require "session.php";
include "db.php";
require_once "config.php";
require_once "includes/functions.php";
require_once "includes/auditoria.php";
require_once "includes/cierre_firmas.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

function procesarCierreColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);

  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

  return $result && $result->num_rows > 0;
}

function normalizarImporteDecimal($valor) {
  $valor = trim((string)$valor);

  if ($valor === '') {
    return 0;
  }

  $valor = str_replace(' ', '', $valor);

  if (strpos($valor, ',') !== false && strpos($valor, '.') !== false) {
    $valor = str_replace('.', '', $valor);
    $valor = str_replace(',', '.', $valor);
    return (float)$valor;
  }

  if (strpos($valor, ',') !== false) {
    $valor = str_replace(',', '.', $valor);
    return (float)$valor;
  }

  return (float)$valor;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['user'] ?? '';
$comercial = $_SESSION['comercial'] ?? $username;

$mes = intval($_POST['mes'] ?? 0);
$anio = intval($_POST['anio'] ?? 0);

$importeBanco = normalizarImporteDecimal($_POST['importe_banco'] ?? '');
$comentariosComercial = trim($_POST['comentarios_comercial'] ?? '');

$localNow = date('Y-m-d H:i:s');

if ($userId <= 0 || $mes < 1 || $mes > 12 || $anio < 2000 || $anio > 2100) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'cierre',
    'accion' => 'cierre_usuario_datos_no_validos',
    'descripcion' => 'Intento de guardar cierre mensual con datos no válidos.',
    'estado_nuevo' => 'error',
    'datos' => [
      'user_id' => $userId,
      'mes' => $mes,
      'anio' => $anio
    ]
  ]);

  header("Location: cierre_mensual.php?type=error&msg=" . urlencode("Datos de cierre no válidos"));
  exit;
}

if ($importeBanco < 0) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'cierre',
    'accion' => 'cierre_usuario_importe_negativo',
    'descripcion' => 'Intento de guardar cierre mensual con importe de banco negativo.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mes' => $mes,
      'anio' => $anio,
      'importe_banco' => $importeBanco
    ]
  ]);

  header("Location: cierre_mensual.php?mes=" . urlencode((string)$mes) . "&anio=" . urlencode((string)$anio) . "&type=error&msg=" . urlencode("El importe de banco no puede ser negativo"));
  exit;
}

$sqlBloqueo = "SELECT estado
               FROM cierres_mensuales
               WHERE user_id = ?
                 AND mes = ?
                 AND anio = ?
               LIMIT 1";

$stmtBloqueo = $conn->prepare($sqlBloqueo);

if ($stmtBloqueo) {
  $stmtBloqueo->bind_param("iii", $userId, $mes, $anio);
  $stmtBloqueo->execute();
  $cierreActual = $stmtBloqueo->get_result()->fetch_assoc();

  if ($cierreActual && in_array($cierreActual['estado'], ['validado', 'con_diferencia', 'rechazado'], true)) {
    auditoriaRegistrarSeguro($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'cierre',
      'accion' => 'intento_modificar_cierre_revisado',
      'descripcion' => 'Intento bloqueado de modificar un cierre mensual ya revisado por administración.',
      'estado_anterior' => $cierreActual['estado'],
      'estado_nuevo' => 'bloqueado',
      'datos' => [
        'user_id' => $userId,
        'mes' => $mes,
        'anio' => $anio
      ]
    ]);

    header("Location: cierre_mensual.php?mes=" . urlencode((string)$mes) . "&anio=" . urlencode((string)$anio) . "&type=error&msg=" . urlencode("Este cierre ya ha sido revisado por administración y no puede modificarse"));
    exit;
  }
}

$fechaImputacionExiste = procesarCierreColumnExists($conn, 'gastos', 'fecha_imputacion');

if ($fechaImputacionExiste) {
  $fechaPeriodo = "COALESCE(fecha_imputacion, fecha_ticket)";
} else {
  $fechaPeriodo = "fecha_ticket";
}

$sqlTotal = "SELECT COALESCE(SUM(COALESCE(importe_detectado, 0)), 0) AS total_importe
             FROM gastos
             WHERE deleted_at IS NULL
               AND estado IN ('procesado', 'editado')
               AND user_id = ?
               AND $fechaPeriodo IS NOT NULL
               AND MONTH($fechaPeriodo) = ?
               AND YEAR($fechaPeriodo) = ?";

$stmtTotal = $conn->prepare($sqlTotal);

if (!$stmtTotal) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'cierre',
    'accion' => 'error_calcular_total_cierre_usuario',
    'descripcion' => 'No se pudo calcular el total registrado en la app para el cierre mensual.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mysql_error' => $conn->error,
      'mes' => $mes,
      'anio' => $anio
    ]
  ]);

  header("Location: cierre_mensual.php?mes=" . urlencode((string)$mes) . "&anio=" . urlencode((string)$anio) . "&type=error&msg=" . urlencode("No se pudo calcular el total registrado en la app"));
  exit;
}

$stmtTotal->bind_param("iii", $userId, $mes, $anio);
$stmtTotal->execute();

$rowTotal = $stmtTotal->get_result()->fetch_assoc();
$importeApp = (float)($rowTotal['total_importe'] ?? 0);
$diferencia = round($importeBanco - $importeApp, 2);

$estado = 'pendiente_admin';

$sql = "INSERT INTO cierres_mensuales
        (
          user_id,
          username,
          comercial,
          mes,
          anio,
          importe_banco,
          comentarios_comercial,
          estado,
          importe_app,
          diferencia,
          created_at,
          updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          username = VALUES(username),
          comercial = VALUES(comercial),
          importe_banco = VALUES(importe_banco),
          comentarios_comercial = VALUES(comentarios_comercial),
          estado = 'pendiente_admin',
          importe_app = VALUES(importe_app),
          diferencia = VALUES(diferencia),
          comentarios_admin = NULL,
          revisado_por = NULL,
          revisado_at = NULL,
          updated_at = VALUES(updated_at)";

$stmt = $conn->prepare($sql);

if (!$stmt) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'cierre',
    'accion' => 'error_preparar_guardar_cierre_usuario',
    'descripcion' => 'No se pudo preparar el guardado del cierre mensual enviado por el comercial.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mysql_error' => $conn->error,
      'mes' => $mes,
      'anio' => $anio
    ]
  ]);

  header("Location: cierre_mensual.php?mes=" . urlencode((string)$mes) . "&anio=" . urlencode((string)$anio) . "&type=error&msg=" . urlencode("No se pudo preparar el guardado del cierre"));
  exit;
}

$stmt->bind_param(
  "issiidssddss",
  $userId,
  $username,
  $comercial,
  $mes,
  $anio,
  $importeBanco,
  $comentariosComercial,
  $estado,
  $importeApp,
  $diferencia,
  $localNow,
  $localNow
);

if (!$stmt->execute()) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'cierre',
    'accion' => 'error_guardar_cierre_usuario',
    'descripcion' => 'No se pudo guardar el cierre mensual enviado por el comercial.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mysql_error' => $stmt->error,
      'mes' => $mes,
      'anio' => $anio
    ]
  ]);

  header("Location: cierre_mensual.php?mes=" . urlencode((string)$mes) . "&anio=" . urlencode((string)$anio) . "&type=error&msg=" . urlencode("No se pudo guardar el cierre mensual"));
  exit;
}

$cierreFirmado = null;
$stmtCierreFirmado = $conn->prepare("SELECT c.*, u.email AS user_email FROM cierres_mensuales c LEFT JOIN users u ON u.id = c.user_id WHERE c.user_id = ? AND c.mes = ? AND c.anio = ? LIMIT 1");

if ($stmtCierreFirmado) {
  $stmtCierreFirmado->bind_param("iii", $userId, $mes, $anio);
  $stmtCierreFirmado->execute();
  $cierreFirmado = $stmtCierreFirmado->get_result()->fetch_assoc();
}

$firmaWebhookResult = null;

if ($cierreFirmado) {
  $firmaWebhookResult = cierreFirmasGenerarYEnviar($conn, 'comercial', $cierreFirmado, [
    'user_id' => $userId,
    'username' => $username,
    'comercial' => $comercial,
    'rol' => $_SESSION['role'] ?? 'user'
  ], [
    'evento' => 'confirmacion_cierre_comercial',
    'forzar_envio_webhook' => true,
    'importe_app' => $importeApp,
    'importe_banco' => $importeBanco,
    'diferencia' => $diferencia
  ]);
}

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'cierre',
  'entidad' => 'cierre',
  'accion' => 'cierre_mensual_enviado_comercial',
  'descripcion' => 'Cierre mensual enviado por el comercial para revisión administrativa.',
  'estado_nuevo' => 'pendiente_admin',
  'datos' => [
    'user_id' => $userId,
    'username' => $username,
    'comercial' => $comercial,
    'mes' => $mes,
    'anio' => $anio,
    'importe_app' => $importeApp,
    'importe_banco' => $importeBanco,
    'diferencia' => $diferencia,
    'comentarios_comercial' => $comentariosComercial
  ]
]);

if (!$firmaWebhookResult || empty($firmaWebhookResult['ok'])) {
  header("Location: cierre_mensual.php?mes=" . urlencode((string)$mes) . "&anio=" . urlencode((string)$anio) . "&type=error&msg=" . urlencode("El cierre se ha guardado, pero la firma no ha podido confirmarse."));
  exit;
}

header("Location: cierre_mensual.php?mes=" . urlencode((string)$mes) . "&anio=" . urlencode((string)$anio) . "&type=success&msg=" . urlencode("Cierre mensual guardado y firmado correctamente"));
exit;
?>

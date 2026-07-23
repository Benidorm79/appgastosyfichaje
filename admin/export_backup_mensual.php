<?php
require_once __DIR__ . "/../admin_guard.php";
requireMasterAccess();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auditoria.php";

function backupMensualTableExists($conn, $table) {
  $table = $conn->real_escape_string($table);
  $result = $conn->query("SHOW TABLES LIKE '$table'");
  return $result && $result->num_rows > 0;
}

function backupMensualColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return $result && $result->num_rows > 0;
}

function backupMensualFetch($conn, $sql, $types = '', $params = []) {
  $stmt = $conn->prepare($sql);
  if (!$stmt) return [];
  if ($types !== '' && count($params) > 0) $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $result = $stmt->get_result();
  if (!$result) return [];
  $rows = [];
  while ($row = $result->fetch_assoc()) $rows[] = $row;
  return $rows;
}

function backupMensualWriteSection($output, $title, $rows) {
  fputcsv($output, [], ';');
  fputcsv($output, [$title], ';');

  if (count($rows) === 0) {
    fputcsv($output, ['Sin registros'], ';');
    return;
  }

  $headers = array_keys($rows[0]);
  fputcsv($output, $headers, ';');

  foreach ($rows as $row) {
    $line = [];
    foreach ($headers as $header) {
      $line[] = $row[$header] ?? '';
    }
    fputcsv($output, $line, ';');
  }
}

$mes = intval($_GET['mes'] ?? 0);
$anio = intval($_GET['anio'] ?? 0);
$comercial = trim($_GET['comercial'] ?? '');

if ($mes < 1 || $mes > 12 || $anio < 2020 || $anio > 2100) {
  header("Location: backup_mensual.php?type=error&msg=" . urlencode("Periodo no válido"));
  exit;
}

$fechaPeriodoGasto = backupMensualColumnExists($conn, 'gastos', 'fecha_imputacion') ? "COALESCE(fecha_imputacion, fecha_ticket, created_at)" : "COALESCE(fecha_ticket, created_at)";
$types = "ii";
$params = [$mes, $anio];
$whereComercialGastos = "";
$whereComercialCierres = "";
$whereComercialAuditoria = "";

if ($comercial !== '') {
  $whereComercialGastos = " AND comercial = ?";
  $whereComercialCierres = " AND comercial = ?";
  $whereComercialAuditoria = " AND comercial = ?";
}

$paramsGastos = $params;
$typesGastos = $types;
if ($comercial !== '') { $paramsGastos[] = $comercial; $typesGastos .= "s"; }

$gastos = backupMensualFetch(
  $conn,
  "SELECT id, gasto_uid, user_id, username, comercial, viaje, motivo, importe_detectado, fecha_ticket, " .
  (backupMensualColumnExists($conn, 'gastos', 'fecha_imputacion') ? "fecha_imputacion," : "") .
  " estado, origen, created_at, updated_at
   FROM gastos
   WHERE deleted_at IS NULL
     AND $fechaPeriodoGasto IS NOT NULL
     AND MONTH($fechaPeriodoGasto) = ?
     AND YEAR($fechaPeriodoGasto) = ?
     $whereComercialGastos
   ORDER BY comercial ASC, $fechaPeriodoGasto ASC, id ASC",
  $typesGastos,
  $paramsGastos
);

$paramsCierres = $params;
$typesCierres = $types;
if ($comercial !== '') { $paramsCierres[] = $comercial; $typesCierres .= "s"; }

$cierres = backupMensualTableExists($conn, 'cierres_mensuales') ? backupMensualFetch(
  $conn,
  "SELECT id, user_id, username, comercial, mes, anio, importe_banco, importe_app, diferencia, estado, comentarios_comercial, comentarios_admin, revisado_por, revisado_at, created_at, updated_at
   FROM cierres_mensuales
   WHERE mes = ? AND anio = ? $whereComercialCierres
   ORDER BY comercial ASC, id ASC",
  $typesCierres,
  $paramsCierres
) : [];

$paramsAudit = $params;
$typesAudit = $types;
if ($comercial !== '') { $paramsAudit[] = $comercial; $typesAudit .= "s"; }

$auditoria = backupMensualTableExists($conn, 'auditoria_eventos') ? backupMensualFetch(
  $conn,
  "SELECT id, created_at, tipo_evento, entidad, entidad_id, accion, descripcion, username, comercial, rol, estado_anterior, estado_nuevo, estado_revision, ip
   FROM auditoria_eventos
   WHERE MONTH(created_at) = ? AND YEAR(created_at) = ? $whereComercialAuditoria
   ORDER BY created_at ASC, id ASC",
  $typesAudit,
  $paramsAudit
) : [];

$envios = backupMensualTableExists($conn, 'envios_integraciones') ? backupMensualFetch(
  $conn,
  "SELECT id, tipo_destino, sistema_externo, entidad, entidad_id, referencia, id_externo, descripcion, estado, intentos, ultimo_error, created_at, updated_at, enviado_at
   FROM envios_integraciones
   WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?
   ORDER BY created_at ASC, id ASC",
  "ii",
  [$mes, $anio]
) : [];

$incidencias = backupMensualFetch(
  $conn,
  "SELECT id, gasto_uid, user_id, username, comercial, viaje, motivo, importe_detectado, fecha_ticket, estado, make_response, created_at, updated_at
   FROM gastos
   WHERE deleted_at IS NULL
     AND MONTH(COALESCE(created_at, fecha_ticket)) = ?
     AND YEAR(COALESCE(created_at, fecha_ticket)) = ?
     AND (estado IN ('pendiente', 'error') OR importe_detectado IS NULL)
   ORDER BY created_at ASC, id ASC",
  "ii",
  [$mes, $anio]
);

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'auditoria',
  'entidad' => 'sistema',
  'accion' => 'backup_mensual_exportado',
  'descripcion' => 'Exportación mensual de seguridad generada desde el panel de administración.',
  'estado_nuevo' => 'exportado',
  'datos' => [
    'mes' => $mes,
    'anio' => $anio,
    'comercial' => $comercial,
    'total_gastos' => count($gastos),
    'total_cierres' => count($cierres),
    'total_auditoria' => count($auditoria),
    'total_envios' => count($envios),
    'total_incidencias' => count($incidencias)
  ]
]);

$filename = 'backup_mensual_' . str_pad((string)$mes, 2, '0', STR_PAD_LEFT) . '_' . $anio . ($comercial !== '' ? '_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $comercial) : '') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

fputcsv($output, ['Backup mensual de gastos'], ';');
fputcsv($output, ['Mes', str_pad((string)$mes, 2, '0', STR_PAD_LEFT), 'Año', $anio, 'Comercial', $comercial !== '' ? $comercial : 'Todos'], ';');
fputcsv($output, ['Generado', date('Y-m-d H:i:s')], ';');

backupMensualWriteSection($output, 'GASTOS', $gastos);
backupMensualWriteSection($output, 'CIERRES_MENSUALES', $cierres);
backupMensualWriteSection($output, 'ENVIOS_INTEGRACIONES', $envios);
backupMensualWriteSection($output, 'INCIDENCIAS_CONTROL', $incidencias);
backupMensualWriteSection($output, 'AUDITORIA', $auditoria);

fclose($output);
exit;
?>

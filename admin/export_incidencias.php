<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auditoria.php";

function exportIncColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);

  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

  return $result && $result->num_rows > 0;
}

function exportIncGetMonthName($month) {
  $months = [
    1 => 'Enero',
    2 => 'Febrero',
    3 => 'Marzo',
    4 => 'Abril',
    5 => 'Mayo',
    6 => 'Junio',
    7 => 'Julio',
    8 => 'Agosto',
    9 => 'Septiembre',
    10 => 'Octubre',
    11 => 'Noviembre',
    12 => 'Diciembre'
  ];

  return $months[(int)$month] ?? '';
}

function exportIncFetchAll($conn, $sql, $types = "", $params = []) {
  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return [];
  }

  if ($types !== "" && count($params) > 0) {
    $stmt->bind_param($types, ...$params);
  }

  $stmt->execute();
  $result = $stmt->get_result();

  if (!$result) {
    return [];
  }

  $rows = [];

  while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
  }

  return $rows;
}

function exportIncCleanFilename($text) {
  $text = trim((string)$text);
  $text = str_replace([' ', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $text);
  $text = preg_replace('/_+/', '_', $text);

  return $text !== '' ? $text : 'todos';
}

function exportIncXlsCell($value) {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function exportIncMoney($value) {
  return number_format((float)$value, 2, ',', '.');
}

$formato = strtolower(trim($_GET['formato'] ?? 'xls'));

$mes = intval($_GET['mes'] ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));
$comercialFiltro = trim($_GET['comercial'] ?? '');
$tipoFiltro = trim($_GET['tipo'] ?? 'todas');

if ($mes < 1 || $mes > 12) {
  $mes = (int)date('n');
}

if ($anio < 2000 || $anio > 2100) {
  $anio = (int)date('Y');
}

$tiposPermitidos = [
  'todas',
  'errores',
  'sync_error',
  'sin_justificante'
];

if (!in_array($tipoFiltro, $tiposPermitidos, true)) {
  $tipoFiltro = 'todas';
}

$fechaImputacionExiste = exportIncColumnExists($conn, 'gastos', 'fecha_imputacion');

if ($fechaImputacionExiste) {
  $fechaPeriodo = "COALESCE(g.fecha_imputacion, g.fecha_ticket, g.created_at)";
} else {
  $fechaPeriodo = "COALESCE(g.fecha_ticket, g.created_at)";
}

$where = "g.deleted_at IS NULL
          AND g.estado <> 'eliminado'
          AND $fechaPeriodo IS NOT NULL
          AND MONTH($fechaPeriodo) = ?
          AND YEAR($fechaPeriodo) = ?";

$params = [$mes, $anio];
$types = "ii";

if ($comercialFiltro !== '') {
  $where .= " AND g.comercial = ?";
  $params[] = $comercialFiltro;
  $types .= "s";
}

$sql = "SELECT 
          g.id,
          g.gasto_uid,
          g.user_id,
          g.username,
          g.comercial,
          g.viaje,
          g.motivo,
          g.comentarios,
          g.importe_detectado,
          g.fecha_ticket,
          $fechaPeriodo AS fecha_periodo,
          g.estado,
          g.sync_status,
          g.origen,
          g.created_at,
          g.updated_at,
          COUNT(gt.id) AS total_tickets,
          SUM(
            CASE 
              WHEN gt.drive_file_id IS NOT NULL AND gt.drive_file_id <> '' 
              THEN 1 
              ELSE 0 
            END
          ) AS tickets_drive
        FROM gastos g
        LEFT JOIN gasto_tickets gt
          ON gt.gasto_id = g.id
          AND gt.gasto_uid = g.gasto_uid
        WHERE $where
        GROUP BY g.id
        ORDER BY fecha_periodo DESC, g.id DESC";

$gastos = exportIncFetchAll($conn, $sql, $types, $params);

$incidencias = [];

foreach ($gastos as $gasto) {
  $estado = strtolower(trim((string)($gasto['estado'] ?? '')));
  $syncStatus = strtolower(trim((string)($gasto['sync_status'] ?? '')));
  $ticketsDrive = (int)($gasto['tickets_drive'] ?? 0);

  $esPendiente = $estado === 'pendiente';
  $esError = $estado === 'error';
  $esSyncError = in_array($syncStatus, ['error', 'fallido', 'failed', 'ko', 'error_sync'], true);
  $esSinJustificante = $ticketsDrive === 0 && ($esError || $esSyncError);

  $incluir = false;

  if ($tipoFiltro === 'todas' && ($esError || $esSyncError)) {
    $incluir = true;
  }

  if ($tipoFiltro === 'errores' && $esError) {
    $incluir = true;
  }

  if ($tipoFiltro === 'sync_error' && $esSyncError) {
    $incluir = true;
  }

  if ($tipoFiltro === 'sin_justificante' && $esSinJustificante) {
    $incluir = true;
  }

  if ($incluir) {
    $tiposIncidencia = [];

    if ($esPendiente) {
      $tiposIncidencia[] = 'Pendiente';
    }

    if ($esError) {
      $tiposIncidencia[] = 'Error';
    }

    if ($esSyncError) {
      $tiposIncidencia[] = 'Error sincronización';
    }

    if ($esSinJustificante) {
      $tiposIncidencia[] = 'Sin justificante';
    }

    $gasto['tiene_justificante'] = $ticketsDrive > 0 ? 'Sí' : 'No';
    $gasto['tipos_incidencia'] = implode(', ', $tiposIncidencia);

    $incidencias[] = $gasto;
  }
}

$periodoNombre = exportIncGetMonthName($mes) . ' ' . $anio;
$comercialNombreArchivo = exportIncCleanFilename($comercialFiltro !== '' ? $comercialFiltro : 'todos');
$tipoNombreArchivo = exportIncCleanFilename($tipoFiltro);
$baseFilename = "incidencias_gastos_" . $anio . "_" . str_pad((string)$mes, 2, "0", STR_PAD_LEFT) . "_" . $comercialNombreArchivo . "_" . $tipoNombreArchivo;

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'sistema',
  'entidad' => 'incidencias',
  'accion' => 'incidencias_exportadas',
  'descripcion' => 'Exportación de incidencias de gastos.',
  'estado_nuevo' => 'exportado',
  'datos' => [
    'formato' => $formato,
    'mes' => $mes,
    'anio' => $anio,
    'comercial' => $comercialFiltro,
    'tipo' => $tipoFiltro,
    'total_incidencias' => count($incidencias)
  ]
]);

if ($formato === 'csv') {
  $filename = $baseFilename . ".csv";

  header("Content-Type: text/csv; charset=UTF-8");
  header("Content-Disposition: attachment; filename=\"$filename\"");
  header("Pragma: no-cache");
  header("Expires: 0");

  echo "\xEF\xBB\xBF";

  $out = fopen("php://output", "w");

  fputcsv($out, [
    'ID interno',
    'ID gasto',
    'Comercial',
    'Usuario',
    'Fecha periodo',
    'Fecha ticket',
    'Viaje',
    'Motivo',
    'Comentarios',
    'Importe',
    'Estado',
    'Sincronización',
    'Origen',
    'Total tickets',
    'Justificantes disponibles',
    'Tiene justificante',
    'Tipo incidencia',
    'Creado',
    'Actualizado'
  ], ';');

  foreach ($incidencias as $row) {
    fputcsv($out, [
      (int)$row['id'],
      $row['gasto_uid'],
      $row['comercial'],
      $row['username'],
      $row['fecha_periodo'],
      $row['fecha_ticket'],
      $row['viaje'],
      $row['motivo'],
      $row['comentarios'],
      number_format((float)$row['importe_detectado'], 2, ',', ''),
      $row['estado'],
      $row['sync_status'],
      $row['origen'],
      (int)$row['total_tickets'],
      (int)$row['tickets_drive'],
      $row['tiene_justificante'],
      $row['tipos_incidencia'],
      $row['created_at'],
      $row['updated_at']
    ], ';');
  }

  fclose($out);
  exit;
}

$filename = $baseFilename . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Incidencias de gastos</title>
</head>

<body>

  <h1>Incidencias de gastos — <?php echo exportIncXlsCell($periodoNombre); ?></h1>

  <table border="1">
    <tr>
      <th>Filtro</th>
      <th>Valor</th>
    </tr>
    <tr>
      <td>Periodo</td>
      <td><?php echo exportIncXlsCell($periodoNombre); ?></td>
    </tr>
    <tr>
      <td>Comercial</td>
      <td><?php echo exportIncXlsCell($comercialFiltro !== '' ? $comercialFiltro : 'Todos'); ?></td>
    </tr>
    <tr>
      <td>Tipo de incidencia</td>
      <td><?php echo exportIncXlsCell($tipoFiltro); ?></td>
    </tr>
    <tr>
      <td>Total incidencias</td>
      <td><?php echo (int)count($incidencias); ?></td>
    </tr>
    <tr>
      <td>Fecha exportación</td>
      <td><?php echo date('d-m-Y H:i'); ?></td>
    </tr>
  </table>

  <br>

  <h2>Listado de incidencias</h2>

  <table border="1">
    <tr>
      <th>ID interno</th>
      <th>ID gasto</th>
      <th>Comercial</th>
      <th>Usuario</th>
      <th>Fecha periodo</th>
      <th>Fecha ticket</th>
      <th>Viaje</th>
      <th>Motivo</th>
      <th>Comentarios</th>
      <th>Importe</th>
      <th>Estado</th>
      <th>Sincronización</th>
      <th>Origen</th>
      <th>Total tickets</th>
      <th>Justificantes disponibles</th>
      <th>Tiene justificante</th>
      <th>Tipo incidencia</th>
      <th>Creado</th>
      <th>Actualizado</th>
    </tr>

    <?php if (count($incidencias) === 0): ?>
      <tr>
        <td colspan="19">No hay incidencias para los filtros seleccionados.</td>
      </tr>
    <?php endif; ?>

    <?php foreach ($incidencias as $row): ?>
      <tr>
        <td><?php echo (int)$row['id']; ?></td>
        <td><?php echo exportIncXlsCell($row['gasto_uid']); ?></td>
        <td><?php echo exportIncXlsCell($row['comercial']); ?></td>
        <td><?php echo exportIncXlsCell($row['username']); ?></td>
        <td><?php echo exportIncXlsCell($row['fecha_periodo']); ?></td>
        <td><?php echo exportIncXlsCell($row['fecha_ticket']); ?></td>
        <td><?php echo exportIncXlsCell($row['viaje']); ?></td>
        <td><?php echo exportIncXlsCell($row['motivo']); ?></td>
        <td><?php echo exportIncXlsCell($row['comentarios']); ?></td>
        <td><?php echo exportIncMoney($row['importe_detectado']); ?> €</td>
        <td><?php echo exportIncXlsCell(formatEstadoWeb($row['estado'])); ?></td>
        <td><?php echo exportIncXlsCell(formatEstadoWeb($row['sync_status'])); ?></td>
        <td><?php echo exportIncXlsCell($row['origen']); ?></td>
        <td><?php echo (int)$row['total_tickets']; ?></td>
        <td><?php echo (int)$row['tickets_drive']; ?></td>
        <td><?php echo exportIncXlsCell($row['tiene_justificante']); ?></td>
        <td><?php echo exportIncXlsCell($row['tipos_incidencia']); ?></td>
        <td><?php echo exportIncXlsCell($row['created_at']); ?></td>
        <td><?php echo exportIncXlsCell($row['updated_at']); ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

</body>
</html>

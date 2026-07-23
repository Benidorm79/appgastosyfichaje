<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auditoria.php";

function exportColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);

  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

  return $result && $result->num_rows > 0;
}

function exportGetMonthName($month) {
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

function exportCalcPreviousPeriod($month, $year) {
  $month = (int)$month;
  $year = (int)$year;

  if ($month === 1) {
    return [
      'month' => 12,
      'year' => $year - 1
    ];
  }

  return [
    'month' => $month - 1,
    'year' => $year
  ];
}

function exportFetchSingle($conn, $sql, $types = "", $params = []) {
  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return null;
  }

  if ($types !== "" && count($params) > 0) {
    $stmt->bind_param($types, ...$params);
  }

  $stmt->execute();
  $result = $stmt->get_result();

  if (!$result) {
    return null;
  }

  return $result->fetch_assoc();
}

function exportFetchAll($conn, $sql, $types = "", $params = []) {
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

function exportBuildBaseWhere($dateExpression, $month, $year, $comercial = '') {
  $where = "g.deleted_at IS NULL
            AND g.estado IN ('procesado', 'editado')
            AND $dateExpression IS NOT NULL
            AND MONTH($dateExpression) = ?
            AND YEAR($dateExpression) = ?";

  $types = "ii";
  $params = [(int)$month, (int)$year];

  if ($comercial !== '') {
    $where .= " AND g.comercial = ?";
    $types .= "s";
    $params[] = $comercial;
  }

  return [
    'where' => $where,
    'types' => $types,
    'params' => $params
  ];
}

function exportGetPeriodSummary($conn, $dateExpression, $month, $year, $comercial = '') {
  $filter = exportBuildBaseWhere($dateExpression, $month, $year, $comercial);

  $sql = "SELECT 
            COUNT(*) AS total_gastos,
            COALESCE(SUM(COALESCE(g.importe_detectado, 0)), 0) AS total_importe
          FROM gastos g
          WHERE {$filter['where']}";

  $row = exportFetchSingle($conn, $sql, $filter['types'], $filter['params']);

  return [
    'total_gastos' => (int)($row['total_gastos'] ?? 0),
    'total_importe' => (float)($row['total_importe'] ?? 0)
  ];
}

function exportGetWithoutTicketCount($conn, $dateExpression, $month, $year, $comercial = '') {
  $filter = exportBuildBaseWhere($dateExpression, $month, $year, $comercial);

  $sql = "SELECT COUNT(*) AS total
          FROM (
            SELECT g.id
            FROM gastos g
            LEFT JOIN gasto_tickets gt
              ON gt.gasto_id = g.id
              AND gt.gasto_uid = g.gasto_uid
              AND gt.drive_file_id IS NOT NULL
              AND gt.drive_file_id <> ''
            WHERE {$filter['where']}
            GROUP BY g.id
            HAVING COUNT(gt.id) = 0
          ) x";

  $row = exportFetchSingle($conn, $sql, $filter['types'], $filter['params']);

  return (int)($row['total'] ?? 0);
}

function exportCleanFilename($text) {
  $text = trim((string)$text);
  $text = str_replace([' ', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $text);
  $text = preg_replace('/_+/', '_', $text);

  return $text !== '' ? $text : 'todos';
}

function exportXlsCell($value) {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function exportMoney($value) {
  return number_format((float)$value, 2, ',', '.');
}

$formato = strtolower(trim($_GET['formato'] ?? 'xls'));

$mes = intval($_GET['mes'] ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));
$comercialFiltro = trim($_GET['comercial'] ?? '');

if ($mes < 1 || $mes > 12) {
  $mes = (int)date('n');
}

if ($anio < 2000 || $anio > 2100) {
  $anio = (int)date('Y');
}

$fechaImputacionExiste = exportColumnExists($conn, 'gastos', 'fecha_imputacion');

if ($fechaImputacionExiste) {
  $fechaPeriodo = "COALESCE(g.fecha_imputacion, g.fecha_ticket)";
} else {
  $fechaPeriodo = "g.fecha_ticket";
}

$periodoAnterior = exportCalcPreviousPeriod($mes, $anio);

$mismoMesAnioAnterior = [
  'month' => $mes,
  'year' => $anio - 1
];

$resumenActual = exportGetPeriodSummary($conn, $fechaPeriodo, $mes, $anio, $comercialFiltro);
$resumenAnterior = exportGetPeriodSummary($conn, $fechaPeriodo, $periodoAnterior['month'], $periodoAnterior['year'], $comercialFiltro);
$resumenAnioAnterior = exportGetPeriodSummary($conn, $fechaPeriodo, $mismoMesAnioAnterior['month'], $mismoMesAnioAnterior['year'], $comercialFiltro);
$gastosSinJustificante = exportGetWithoutTicketCount($conn, $fechaPeriodo, $mes, $anio, $comercialFiltro);

$diferenciaMesAnterior = $resumenActual['total_importe'] - $resumenAnterior['total_importe'];
$diferenciaAnioAnterior = $resumenActual['total_importe'] - $resumenAnioAnterior['total_importe'];

$filterActual = exportBuildBaseWhere($fechaPeriodo, $mes, $anio, $comercialFiltro);

$totalesPorComercial = exportFetchAll(
  $conn,
  "SELECT 
     g.comercial,
     COUNT(*) AS total_gastos,
     COALESCE(SUM(COALESCE(g.importe_detectado, 0)), 0) AS total_importe
   FROM gastos g
   WHERE {$filterActual['where']}
   GROUP BY g.comercial
   ORDER BY total_importe DESC",
  $filterActual['types'],
  $filterActual['params']
);

$totalesPorCategoria = exportFetchAll(
  $conn,
  "SELECT 
     g.motivo,
     COUNT(*) AS total_gastos,
     COALESCE(SUM(COALESCE(g.importe_detectado, 0)), 0) AS total_importe
   FROM gastos g
   WHERE {$filterActual['where']}
   GROUP BY g.motivo
   ORDER BY total_importe DESC",
  $filterActual['types'],
  $filterActual['params']
);

$gastosDetalle = exportFetchAll(
  $conn,
  "SELECT 
     g.id,
     g.gasto_uid,
     g.comercial,
     g.username,
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
   WHERE {$filterActual['where']}
   GROUP BY g.id
   ORDER BY fecha_periodo DESC, g.id DESC",
  $filterActual['types'],
  $filterActual['params']
);

$sinJustificanteListado = [];

foreach ($gastosDetalle as $gasto) {
  if ((int)($gasto['tickets_drive'] ?? 0) === 0) {
    $sinJustificanteListado[] = $gasto;
  }
}

$evolucion = [];

$baseDate = DateTime::createFromFormat('Y-n-j', $anio . '-' . $mes . '-1');

if (!$baseDate) {
  $baseDate = new DateTime('first day of this month');
}

for ($i = 5; $i >= 0; $i--) {
  $date = clone $baseDate;
  $date->modify("-$i months");

  $m = (int)$date->format('n');
  $y = (int)$date->format('Y');

  $summary = exportGetPeriodSummary($conn, $fechaPeriodo, $m, $y, $comercialFiltro);

  $evolucion[] = [
    'periodo' => exportGetMonthName($m) . ' ' . $y,
    'total_gastos' => $summary['total_gastos'],
    'total_importe' => $summary['total_importe']
  ];
}

$periodoNombre = exportGetMonthName($mes) . ' ' . $anio;
$comercialNombreArchivo = exportCleanFilename($comercialFiltro !== '' ? $comercialFiltro : 'todos');
$baseFilename = "dashboard_gastos_" . $anio . "_" . str_pad((string)$mes, 2, "0", STR_PAD_LEFT) . "_" . $comercialNombreArchivo;

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'sistema',
  'entidad' => 'dashboard',
  'accion' => 'dashboard_exportado',
  'descripcion' => 'Exportación de dashboard de gastos.',
  'estado_nuevo' => 'exportado',
  'datos' => [
    'formato' => $formato,
    'mes' => $mes,
    'anio' => $anio,
    'comercial' => $comercialFiltro,
    'total_gastos' => count($gastosDetalle)
  ]
]);

if ($formato === 'csv') {
  $filename = $baseFilename . "_detalle.csv";

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
    'Periodo',
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
    'Creado',
    'Actualizado'
  ], ';');

  foreach ($gastosDetalle as $gasto) {
    $ticketsDrive = (int)($gasto['tickets_drive'] ?? 0);

    fputcsv($out, [
      (int)$gasto['id'],
      $gasto['gasto_uid'],
      $gasto['comercial'],
      $gasto['username'],
      $gasto['fecha_periodo'],
      $gasto['fecha_ticket'],
      $gasto['viaje'],
      $gasto['motivo'],
      $gasto['comentarios'],
      number_format((float)$gasto['importe_detectado'], 2, ',', ''),
      $gasto['estado'],
      $gasto['sync_status'],
      $gasto['origen'],
      (int)$gasto['total_tickets'],
      $ticketsDrive,
      $ticketsDrive > 0 ? 'Sí' : 'No',
      $gasto['created_at'],
      $gasto['updated_at']
    ], ';');
  }

  fclose($out);
  exit;
}

$filename = $baseFilename . "_resumen.xls";

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
  <title>Dashboard de gastos</title>
</head>

<body>

  <h1>Dashboard de gastos — <?php echo exportXlsCell($periodoNombre); ?></h1>

  <table border="1">
    <tr>
      <th>Filtro</th>
      <th>Valor</th>
    </tr>
    <tr>
      <td>Periodo</td>
      <td><?php echo exportXlsCell($periodoNombre); ?></td>
    </tr>
    <tr>
      <td>Comercial</td>
      <td><?php echo exportXlsCell($comercialFiltro !== '' ? $comercialFiltro : 'Todos'); ?></td>
    </tr>
    <tr>
      <td>Criterio de fecha</td>
      <td><?php echo $fechaImputacionExiste ? 'Fecha de imputación si existe; si no, fecha del ticket' : 'Fecha del ticket'; ?></td>
    </tr>
    <tr>
      <td>Fecha exportación</td>
      <td><?php echo date('d-m-Y H:i'); ?></td>
    </tr>
  </table>

  <br>

  <h2>Resumen</h2>

  <table border="1">
    <tr>
      <th>Indicador</th>
      <th>Valor</th>
    </tr>
    <tr>
      <td>Total periodo</td>
      <td><?php echo exportMoney($resumenActual['total_importe']); ?> €</td>
    </tr>
    <tr>
      <td>Número de gastos</td>
      <td><?php echo (int)$resumenActual['total_gastos']; ?></td>
    </tr>
    <tr>
      <td>Total mes anterior</td>
      <td><?php echo exportMoney($resumenAnterior['total_importe']); ?> €</td>
    </tr>
    <tr>
      <td>Diferencia contra mes anterior</td>
      <td><?php echo exportMoney($diferenciaMesAnterior); ?> €</td>
    </tr>
    <tr>
      <td>Total mismo mes año anterior</td>
      <td><?php echo exportMoney($resumenAnioAnterior['total_importe']); ?> €</td>
    </tr>
    <tr>
      <td>Diferencia contra año anterior</td>
      <td><?php echo exportMoney($diferenciaAnioAnterior); ?> €</td>
    </tr>
    <tr>
      <td>Gastos sin justificante</td>
      <td><?php echo (int)$gastosSinJustificante; ?></td>
    </tr>
  </table>

  <br>

  <h2>Totales por comercial</h2>

  <table border="1">
    <tr>
      <th>Comercial</th>
      <th>Número de gastos</th>
      <th>Total</th>
    </tr>

    <?php if (count($totalesPorComercial) === 0): ?>
      <tr>
        <td colspan="3">No hay datos.</td>
      </tr>
    <?php endif; ?>

    <?php foreach ($totalesPorComercial as $row): ?>
      <tr>
        <td><?php echo exportXlsCell($row['comercial'] ?: 'Sin comercial'); ?></td>
        <td><?php echo (int)$row['total_gastos']; ?></td>
        <td><?php echo exportMoney($row['total_importe']); ?> €</td>
      </tr>
    <?php endforeach; ?>
  </table>

  <br>

  <h2>Totales por categoría</h2>

  <table border="1">
    <tr>
      <th>Categoría</th>
      <th>Número de gastos</th>
      <th>Total</th>
    </tr>

    <?php if (count($totalesPorCategoria) === 0): ?>
      <tr>
        <td colspan="3">No hay datos.</td>
      </tr>
    <?php endif; ?>

    <?php foreach ($totalesPorCategoria as $row): ?>
      <tr>
        <td><?php echo exportXlsCell($row['motivo'] ?: 'Sin categoría'); ?></td>
        <td><?php echo (int)$row['total_gastos']; ?></td>
        <td><?php echo exportMoney($row['total_importe']); ?> €</td>
      </tr>
    <?php endforeach; ?>
  </table>

  <br>

  <h2>Evolución mensual</h2>

  <table border="1">
    <tr>
      <th>Periodo</th>
      <th>Número de gastos</th>
      <th>Total</th>
    </tr>

    <?php foreach ($evolucion as $row): ?>
      <tr>
        <td><?php echo exportXlsCell($row['periodo']); ?></td>
        <td><?php echo (int)$row['total_gastos']; ?></td>
        <td><?php echo exportMoney($row['total_importe']); ?> €</td>
      </tr>
    <?php endforeach; ?>
  </table>

  <br>

  <h2>Gastos sin justificante</h2>

  <table border="1">
    <tr>
      <th>ID gasto</th>
      <th>Comercial</th>
      <th>Fecha periodo</th>
      <th>Viaje</th>
      <th>Motivo</th>
      <th>Importe</th>
      <th>Estado</th>
    </tr>

    <?php if (count($sinJustificanteListado) === 0): ?>
      <tr>
        <td colspan="7">No hay gastos sin justificante.</td>
      </tr>
    <?php endif; ?>

    <?php foreach ($sinJustificanteListado as $row): ?>
      <tr>
        <td><?php echo exportXlsCell($row['gasto_uid']); ?></td>
        <td><?php echo exportXlsCell($row['comercial']); ?></td>
        <td><?php echo exportXlsCell($row['fecha_periodo']); ?></td>
        <td><?php echo exportXlsCell($row['viaje']); ?></td>
        <td><?php echo exportXlsCell($row['motivo']); ?></td>
        <td><?php echo exportMoney($row['importe_detectado']); ?> €</td>
        <td><?php echo exportXlsCell(formatEstadoWeb($row['estado'])); ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <br>

  <h2>Detalle completo de gastos</h2>

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
      <th>Creado</th>
      <th>Actualizado</th>
    </tr>

    <?php if (count($gastosDetalle) === 0): ?>
      <tr>
        <td colspan="18">No hay gastos en este periodo.</td>
      </tr>
    <?php endif; ?>

    <?php foreach ($gastosDetalle as $row): ?>
      <?php $ticketsDrive = (int)($row['tickets_drive'] ?? 0); ?>
      <tr>
        <td><?php echo (int)$row['id']; ?></td>
        <td><?php echo exportXlsCell($row['gasto_uid']); ?></td>
        <td><?php echo exportXlsCell($row['comercial']); ?></td>
        <td><?php echo exportXlsCell($row['username']); ?></td>
        <td><?php echo exportXlsCell($row['fecha_periodo']); ?></td>
        <td><?php echo exportXlsCell($row['fecha_ticket']); ?></td>
        <td><?php echo exportXlsCell($row['viaje']); ?></td>
        <td><?php echo exportXlsCell($row['motivo']); ?></td>
        <td><?php echo exportXlsCell($row['comentarios']); ?></td>
        <td><?php echo exportMoney($row['importe_detectado']); ?> €</td>
        <td><?php echo exportXlsCell(formatEstadoWeb($row['estado'])); ?></td>
        <td><?php echo exportXlsCell(formatEstadoWeb($row['sync_status'])); ?></td>
        <td><?php echo exportXlsCell($row['origen']); ?></td>
        <td><?php echo (int)$row['total_tickets']; ?></td>
        <td><?php echo $ticketsDrive; ?></td>
        <td><?php echo $ticketsDrive > 0 ? 'Sí' : 'No'; ?></td>
        <td><?php echo exportXlsCell($row['created_at']); ?></td>
        <td><?php echo exportXlsCell($row['updated_at']); ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

</body>
</html>

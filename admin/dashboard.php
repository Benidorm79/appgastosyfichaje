<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/gastos_unificados.php";

function adminColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);

  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

  return $result && $result->num_rows > 0;
}

function adminGetMonthName($month) {
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

function adminCalcPreviousPeriod($month, $year) {
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

function adminFetchSingle($conn, $sql, $types = "", $params = []) {
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

function adminFetchAll($conn, $sql, $types = "", $params = []) {
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

function adminBuildBaseWhere($dateExpression, $month, $year, $comercial = '') {
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

function adminGetPeriodSummary($conn, $dateExpression, $month, $year, $comercial = '') {
  $filter = adminBuildBaseWhere($dateExpression, $month, $year, $comercial);

  $sql = "SELECT 
            COUNT(*) AS total_gastos,
            COALESCE(SUM(COALESCE(g.importe_detectado, 0)), 0) AS total_importe
          FROM gastos g
          WHERE {$filter['where']}";

  $row = adminFetchSingle($conn, $sql, $filter['types'], $filter['params']);

  return [
    'total_gastos' => (int)($row['total_gastos'] ?? 0),
    'total_importe' => (float)($row['total_importe'] ?? 0)
  ];
}

function adminGetYearToDateSummary($conn, $dateExpression, $month, $year, $comercial = '') {
  $where = "g.deleted_at IS NULL
            AND g.estado IN ('procesado', 'editado')
            AND $dateExpression IS NOT NULL
            AND YEAR($dateExpression) = ?
            AND MONTH($dateExpression) <= ?";

  $types = "ii";
  $params = [(int)$year, (int)$month];

  if ($comercial !== '') {
    $where .= " AND g.comercial = ?";
    $types .= "s";
    $params[] = $comercial;
  }

  $sql = "SELECT
            COUNT(*) AS total_gastos,
            COALESCE(SUM(COALESCE(g.importe_detectado, 0)), 0) AS total_importe
          FROM gastos g
          WHERE $where";

  $row = adminFetchSingle($conn, $sql, $types, $params);

  return [
    'total_gastos' => (int)($row['total_gastos'] ?? 0),
    'total_importe' => (float)($row['total_importe'] ?? 0)
  ];
}

function adminGetExtraSummary($conn, $month, $year, $comercial = '', $yearToDate = false) {
  $summary = ['total_gastos' => 0, 'total_importe' => 0.0];
  $tables = [
    ['table' => 'efectivo_gastos', 'amount' => 'importe'],
    ['table' => 'kilometrajes', 'amount' => 'importe']
  ];

  foreach ($tables as $item) {
    if (!gastosUnificadosTableExists($conn, $item['table'])) {
      continue;
    }

    $where = "estado = 'procesado' AND YEAR(fecha) = ?";
    $types = 'i';
    $params = [(int)$year];

    if ($yearToDate) {
      $where .= " AND MONTH(fecha) <= ?";
    } else {
      $where .= " AND MONTH(fecha) = ?";
    }

    $types .= 'i';
    $params[] = (int)$month;

    if ($comercial !== '') {
      $where .= " AND comercial = ?";
      $types .= 's';
      $params[] = $comercial;
    }

    $row = adminFetchSingle(
      $conn,
      "SELECT COUNT(*) total_gastos, COALESCE(SUM({$item['amount']}),0) total_importe
       FROM `{$item['table']}`
       WHERE $where",
      $types,
      $params
    );

    $summary['total_gastos'] += (int)($row['total_gastos'] ?? 0);
    $summary['total_importe'] += (float)($row['total_importe'] ?? 0);
  }

  return $summary;
}

function adminAddSummary(&$base, $extra) {
  $base['total_gastos'] = (int)($base['total_gastos'] ?? 0) + (int)($extra['total_gastos'] ?? 0);
  $base['total_importe'] = (float)($base['total_importe'] ?? 0) + (float)($extra['total_importe'] ?? 0);
}

function adminNormalizeExpenseCategory($motivo) {
  $value = mb_strtolower(trim((string)$motivo), 'UTF-8');
  $value = strtr($value, [
    'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
    'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
    'ü' => 'u', 'ñ' => 'n'
  ]);

  if (strpos($value, 'desplaz') !== false) return 'Desplazamiento';
  if (strpos($value, 'hotel') !== false || strpos($value, 'aloj') !== false) return 'Hotel';
  if (strpos($value, 'desayun') !== false) return 'Desayuno';
  if (strpos($value, 'cena') !== false) return 'Cena';
  if (strpos($value, 'comida') !== false || strpos($value, 'almuerzo') !== false) return 'Comida';

  return 'Otros';
}

function adminGetWithoutTicketCount($conn, $dateExpression, $month, $year, $comercial = '') {
  $filter = adminBuildBaseWhere($dateExpression, $month, $year, $comercial);

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

  $row = adminFetchSingle($conn, $sql, $filter['types'], $filter['params']);

  return (int)($row['total'] ?? 0);
}

$fechaImputacionExiste = adminColumnExists($conn, 'gastos', 'fecha_imputacion');

if ($fechaImputacionExiste) {
  $fechaPeriodo = "COALESCE(g.fecha_imputacion, g.fecha_ticket)";
} else {
  $fechaPeriodo = "g.fecha_ticket";
}

$currentMonth = (int)date('n');
$currentYear = (int)date('Y');

$mes = intval($_GET['mes'] ?? $currentMonth);
$anio = intval($_GET['anio'] ?? $currentYear);
$comercialFiltro = trim($_GET['comercial'] ?? '');

if ($mes < 1 || $mes > 12) {
  $mes = $currentMonth;
}

if ($anio < 2000 || $anio > 2100) {
  $anio = $currentYear;
}

$returnUrl = "admin/dashboard.php?mes=" . urlencode((string)$mes) . "&anio=" . urlencode((string)$anio);

if ($comercialFiltro !== '') {
  $returnUrl .= "&comercial=" . urlencode($comercialFiltro);
}

$exportQuery = "mes=" . urlencode((string)$mes) . "&anio=" . urlencode((string)$anio);

if ($comercialFiltro !== '') {
  $exportQuery .= "&comercial=" . urlencode($comercialFiltro);
}

$exportExcelUrl = "export_dashboard.php?formato=xls&" . $exportQuery;
$exportCsvUrl = "export_dashboard.php?formato=csv&" . $exportQuery;

$periodoAnterior = adminCalcPreviousPeriod($mes, $anio);
$mismoMesAnioAnterior = [
  'month' => $mes,
  'year' => $anio - 1
];

$comerciales = adminFetchAll(
  $conn,
  "SELECT DISTINCT comercial
   FROM users
   WHERE comercial IS NOT NULL
     AND comercial <> ''
   ORDER BY comercial ASC"
);

$resumenActual = adminGetPeriodSummary($conn, $fechaPeriodo, $mes, $anio, $comercialFiltro);
$resumenYtd = adminGetYearToDateSummary($conn, $fechaPeriodo, $mes, $anio, $comercialFiltro);
$resumenAnterior = adminGetPeriodSummary($conn, $fechaPeriodo, $periodoAnterior['month'], $periodoAnterior['year'], $comercialFiltro);
$resumenAnioAnterior = adminGetPeriodSummary($conn, $fechaPeriodo, $mismoMesAnioAnterior['month'], $mismoMesAnioAnterior['year'], $comercialFiltro);

adminAddSummary($resumenActual, adminGetExtraSummary($conn, $mes, $anio, $comercialFiltro));
adminAddSummary($resumenYtd, adminGetExtraSummary($conn, $mes, $anio, $comercialFiltro, true));
adminAddSummary(
  $resumenAnterior,
  adminGetExtraSummary($conn, $periodoAnterior['month'], $periodoAnterior['year'], $comercialFiltro)
);
adminAddSummary(
  $resumenAnioAnterior,
  adminGetExtraSummary($conn, $mismoMesAnioAnterior['month'], $mismoMesAnioAnterior['year'], $comercialFiltro)
);

$gastosSinJustificante = adminGetWithoutTicketCount($conn, $fechaPeriodo, $mes, $anio, $comercialFiltro);

$diferenciaMesAnterior = $resumenActual['total_importe'] - $resumenAnterior['total_importe'];
$diferenciaAnioAnterior = $resumenActual['total_importe'] - $resumenAnioAnterior['total_importe'];

$filterActual = adminBuildBaseWhere($fechaPeriodo, $mes, $anio, $comercialFiltro);

$visaPorComercialRows = adminFetchAll(
  $conn,
  "SELECT
     g.comercial,
     COUNT(*) AS total_gastos,
     COALESCE(SUM(COALESCE(g.importe_detectado, 0)), 0) AS total_importe
   FROM gastos g
   WHERE {$filterActual['where']}
   GROUP BY g.comercial",
  $filterActual['types'],
  $filterActual['params']
);

$visaPorCategoriaRows = adminFetchAll(
  $conn,
  "SELECT
     g.motivo,
     COUNT(*) AS total_gastos,
     COALESCE(SUM(COALESCE(g.importe_detectado, 0)), 0) AS total_importe
   FROM gastos g
   WHERE {$filterActual['where']}
   GROUP BY g.motivo",
  $filterActual['types'],
  $filterActual['params']
);

/*
 * Separamos VISA/manual de Efectivo/Kilometraje para mantener ambos bloques
 * visibles en gráficos y tablas sin mezclar sus valores.
 */
$comercialMap = [];
foreach ($visaPorComercialRows as $row) {
  $key = trim((string)($row['comercial'] ?? ''));
  if ($key === '') $key = 'Sin comercial';

  $comercialMap[$key] = [
    'comercial' => $key,
    'visa_gastos' => (int)($row['total_gastos'] ?? 0),
    'visa_importe' => (float)($row['total_importe'] ?? 0),
    'extra_gastos' => 0,
    'extra_importe' => 0.0
  ];
}

$categoryOrder = [
  'Kilometraje',
  'Efectivo',
  'Desplazamiento',
  'Hotel',
  'Desayuno',
  'Comida',
  'Cena',
  'Otros'
];

$categoriaMap = [];
foreach ($categoryOrder as $categoryName) {
  $categoriaMap[$categoryName] = [
    'categoria' => $categoryName,
    'visa_gastos' => 0,
    'visa_importe' => 0.0,
    'extra_gastos' => 0,
    'extra_importe' => 0.0
  ];
}

foreach ($visaPorCategoriaRows as $row) {
  $categoryName = adminNormalizeExpenseCategory($row['motivo'] ?? '');
  $categoriaMap[$categoryName]['visa_gastos'] += (int)($row['total_gastos'] ?? 0);
  $categoriaMap[$categoryName]['visa_importe'] += (float)($row['total_importe'] ?? 0);
}

foreach ([
  ['table' => 'efectivo_gastos', 'category' => 'Efectivo'],
  ['table' => 'kilometrajes', 'category' => 'Kilometraje']
] as $extraTable) {
  if (!gastosUnificadosTableExists($conn, $extraTable['table'])) {
    continue;
  }

  $extraWhere = "estado = 'procesado' AND MONTH(fecha) = ? AND YEAR(fecha) = ?";
  $extraTypes = 'ii';
  $extraParams = [$mes, $anio];

  if ($comercialFiltro !== '') {
    $extraWhere .= " AND comercial = ?";
    $extraTypes .= 's';
    $extraParams[] = $comercialFiltro;
  }

  $rowsCommercial = adminFetchAll(
    $conn,
    "SELECT comercial, COUNT(*) AS total_gastos, COALESCE(SUM(importe), 0) AS total_importe
     FROM `{$extraTable['table']}`
     WHERE $extraWhere
     GROUP BY comercial",
    $extraTypes,
    $extraParams
  );

  foreach ($rowsCommercial as $row) {
    $key = trim((string)($row['comercial'] ?? ''));
    if ($key === '') $key = 'Sin comercial';

    if (!isset($comercialMap[$key])) {
      $comercialMap[$key] = [
        'comercial' => $key,
        'visa_gastos' => 0,
        'visa_importe' => 0.0,
        'extra_gastos' => 0,
        'extra_importe' => 0.0
      ];
    }

    $comercialMap[$key]['extra_gastos'] += (int)($row['total_gastos'] ?? 0);
    $comercialMap[$key]['extra_importe'] += (float)($row['total_importe'] ?? 0);
  }

  $rowCategory = adminFetchSingle(
    $conn,
    "SELECT COUNT(*) AS total_gastos, COALESCE(SUM(importe), 0) AS total_importe
     FROM `{$extraTable['table']}`
     WHERE $extraWhere",
    $extraTypes,
    $extraParams
  );

  $categoryName = $extraTable['category'];
  $categoriaMap[$categoryName]['extra_gastos'] += (int)($rowCategory['total_gastos'] ?? 0);
  $categoriaMap[$categoryName]['extra_importe'] += (float)($rowCategory['total_importe'] ?? 0);
}

$totalesPorComercial = array_values($comercialMap);
foreach ($totalesPorComercial as &$rowCommercialTotal) {
  $rowCommercialTotal['total_gastos'] = $rowCommercialTotal['visa_gastos'] + $rowCommercialTotal['extra_gastos'];
  $rowCommercialTotal['total_importe'] = $rowCommercialTotal['visa_importe'] + $rowCommercialTotal['extra_importe'];
}
unset($rowCommercialTotal);
usort($totalesPorComercial, fn($a, $b) => $b['total_importe'] <=> $a['total_importe']);

$totalesPorCategoria = [];
foreach ($categoryOrder as $categoryName) {
  $row = $categoriaMap[$categoryName];
  $row['total_gastos'] = $row['visa_gastos'] + $row['extra_gastos'];
  $row['total_importe'] = $row['visa_importe'] + $row['extra_importe'];

  if ($row['total_gastos'] > 0 || abs($row['total_importe']) > 0.0001) {
    $totalesPorCategoria[] = $row;
  }
}

$sinJustificanteListado = adminFetchAll(
  $conn,
  "SELECT
     g.id,
     g.gasto_uid,
     g.comercial,
     g.username,
     g.viaje,
     g.motivo,
     g.importe_detectado,
     g.fecha_ticket,
     $fechaPeriodo AS fecha_periodo,
     g.estado
   FROM gastos g
   LEFT JOIN gasto_tickets gt
     ON gt.gasto_id = g.id
     AND gt.gasto_uid = g.gasto_uid
     AND gt.drive_file_id IS NOT NULL
     AND gt.drive_file_id <> ''
   WHERE {$filterActual['where']}
   GROUP BY g.id
   HAVING COUNT(gt.id) = 0
   ORDER BY fecha_periodo DESC, g.id DESC
   LIMIT 50",
  $filterActual['types'],
  $filterActual['params']
);

$evolucionLabels = [];
$evolucionVisaImportes = [];
$evolucionExtraImportes = [];
$evolucionVisaGastos = [];
$evolucionExtraGastos = [];

$baseDate = DateTime::createFromFormat('Y-n-j', $anio . '-' . $mes . '-1');

if (!$baseDate) {
  $baseDate = new DateTime('first day of this month');
}

for ($i = 5; $i >= 0; $i--) {
  $date = clone $baseDate;
  $date->modify("-$i months");

  $m = (int)$date->format('n');
  $y = (int)$date->format('Y');

  $visaSummary = adminGetPeriodSummary($conn, $fechaPeriodo, $m, $y, $comercialFiltro);
  $extraSummary = adminGetExtraSummary($conn, $m, $y, $comercialFiltro);

  $evolucionLabels[] = adminGetMonthName($m) . ' ' . $y;
  $evolucionVisaImportes[] = round((float)$visaSummary['total_importe'], 2);
  $evolucionExtraImportes[] = round((float)$extraSummary['total_importe'], 2);
  $evolucionVisaGastos[] = (int)$visaSummary['total_gastos'];
  $evolucionExtraGastos[] = (int)$extraSummary['total_gastos'];
}

$chartComercialLabels = [];
$chartComercialVisa = [];
$chartComercialExtra = [];

foreach ($totalesPorComercial as $row) {
  $chartComercialLabels[] = $row['comercial'] ?: 'Sin comercial';
  $chartComercialVisa[] = round((float)$row['visa_importe'], 2);
  $chartComercialExtra[] = round((float)$row['extra_importe'], 2);
}

$chartCategoriaLabels = [];
$chartCategoriaVisa = [];
$chartCategoriaExtra = [];

foreach ($totalesPorCategoria as $row) {
  $chartCategoriaLabels[] = $row['categoria'];
  $chartCategoriaVisa[] = round((float)$row['visa_importe'], 2);
  $chartCategoriaExtra[] = round((float)$row['extra_importe'], 2);
}

$periodoNombre = adminGetMonthName($mes) . ' ' . $anio;
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard de gastos - Panel Admin</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="../css/admin.css?v=<?php echo APP_VERSION; ?>">

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    .dashboard-mobile-fit {
      width: 100% !important;
      min-width: 0 !important;
      table-layout: fixed !important;
    }

    .dashboard-mobile-fit th,
    .dashboard-mobile-fit td {
      white-space: normal !important;
      word-break: normal !important;
      overflow-wrap: anywhere !important;
    }

    .dashboard-mobile-fit th:nth-child(1),
    .dashboard-mobile-fit td:nth-child(1) { width: 31% !important; }

    .dashboard-mobile-fit th:nth-child(2),
    .dashboard-mobile-fit td:nth-child(2),
    .dashboard-mobile-fit th:nth-child(3),
    .dashboard-mobile-fit td:nth-child(3),
    .dashboard-mobile-fit th:nth-child(4),
    .dashboard-mobile-fit td:nth-child(4) {
      width: 23% !important;
      text-align: right !important;
    }

    .dashboard-value-stack {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 2px;
    }

    .dashboard-value-stack strong {
      color: inherit;
      font-size: 13px;
      line-height: 1.2;
    }

    .dashboard-value-stack small {
      color: #64748b;
      font-size: 10px;
      line-height: 1.2;
    }

    @media (max-width: 700px) {
      .dashboard-mobile-table-wrap {
        overflow-x: hidden !important;
      }

      .dashboard-mobile-fit {
        min-width: 0 !important;
        font-size: 12px !important;
      }

      .dashboard-mobile-fit th,
      .dashboard-mobile-fit td {
        padding: 10px 6px !important;
        font-size: 12px !important;
        line-height: 1.25 !important;
      }

      .dashboard-mobile-fit th {
        font-size: 10px !important;
        letter-spacing: 0.03em !important;
      }
    }
  </style>
</head>

<body class="admin-body">
  <div class="admin-wrapper">

    <header class="admin-header">
      <div>
        <h1>Dashboard de gastos</h1>
        <p>
          Resumen visual del periodo <?php echo h($periodoNombre); ?><?php echo $comercialFiltro !== '' ? ' · ' . h($comercialFiltro) : ''; ?>.
        </p>
      </div>

      <div class="top-actions">
  		<a class="btn" href="<?php echo h($exportExcelUrl); ?>"style="background: linear-gradient(135deg, #bbf7d0, #86efac) !important; color: #064e3b !important; border-color: rgba(187, 247, 208, 0.95) !important; box-shadow: 0 12px 28px rgba(34, 197, 94, 0.26) !important;">Exportar Excel</a>
		<a class="btn" href="<?php echo h($exportCsvUrl); ?>"style="background: linear-gradient(135deg, #bbf7d0, #86efac) !important; color: #064e3b !important; border-color: rgba(187, 247, 208, 0.95) !important; box-shadow: 0 12px 28px rgba(34, 197, 94, 0.26) !important;">Exportar CSV</a>
		<a class="btn" href="index.php">Panel Admin</a>
  		<a class="btn" href="../home.php">Inicio</a>
  		<a class="btn" href="../logout.php">Cerrar sesión</a>
	  </div>
    </header>

    <section class="panel">
      <form method="get" action="dashboard.php" class="filters">
        <div>
          <label for="mes">Mes</label>
          <select id="mes" name="mes">
            <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?php echo $m; ?>" <?php echo $mes === $m ? 'selected' : ''; ?>>
                <?php echo h(adminGetMonthName($m)); ?>
              </option>
            <?php endfor; ?>
          </select>
        </div>

        <div>
          <label for="anio">Año</label>
          <input type="number" id="anio" name="anio" value="<?php echo (int)$anio; ?>" min="2000" max="2100">
        </div>

        <div>
          <label for="comercial">Comercial</label>
          <select id="comercial" name="comercial">
            <option value="">Todos</option>
            <?php foreach ($comerciales as $comercial): ?>
              <option value="<?php echo h($comercial['comercial']); ?>" <?php echo $comercialFiltro === $comercial['comercial'] ? 'selected' : ''; ?>>
                <?php echo h($comercial['comercial']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <button class="btn primary" type="submit">Aplicar filtros</button>
        </div>
      </form>

      <div class="note">
        Criterio de fecha usado: <?php echo $fechaImputacionExiste ? 'fecha de imputación si existe; si no, fecha del ticket.' : 'fecha del ticket.'; ?>
      </div>
    </section>

    <section class="kpi-grid">
      <article class="kpi-card">
        <span>Total periodo</span>
        <strong><?php echo h(number_format($resumenActual['total_importe'], 2, ',', '.')); ?> €</strong>
        <small><?php echo (int)$resumenActual['total_gastos']; ?> gastos registrados</small>
      </article>

      <article class="kpi-card">
        <span>Total acumulado Año</span>
        <strong><?php echo h(number_format($resumenYtd['total_importe'], 2, ',', '.')); ?> €</strong>
        <small><?php echo (int)$resumenYtd['total_gastos']; ?> gastos de enero a <?php echo h(adminGetMonthName($mes)); ?></small>
      </article>

      <article class="kpi-card">
        <span>Comparativa año anterior</span>
        <strong class="<?php echo $diferenciaAnioAnterior > 0 ? 'negative' : ($diferenciaAnioAnterior < 0 ? 'positive' : 'neutral'); ?>">
          <?php echo h(number_format($diferenciaAnioAnterior, 2, ',', '.')); ?> €
        </strong>
        <small>
          Mismo mes año anterior: <?php echo h(number_format($resumenAnioAnterior['total_importe'], 2, ',', '.')); ?> €
        </small>
      </article>

      <article class="kpi-card">
        <span>Sin justificante</span>
        <strong class="<?php echo $gastosSinJustificante > 0 ? 'negative' : 'positive'; ?>">
          <?php echo (int)$gastosSinJustificante; ?>
        </strong>
        <small>Gastos del periodo sin justificante disponible</small>
      </article>
    </section>

    <section class="charts-grid">
      <article class="chart-box">
        <h2>Evolución mensual</h2>
        <div class="chart-container">
          <canvas id="chartEvolucion"></canvas>
        </div>
      </article>

      <article class="chart-box">
        <h2>Gasto por comercial</h2>
        <div class="chart-container">
          <canvas id="chartComercial"></canvas>
        </div>
      </article>

      <article class="chart-box">
        <h2>Gasto por categoría</h2>
        <div class="chart-container">
          <canvas id="chartCategoria"></canvas>
        </div>
      </article>

      <article class="chart-box">
        <h2>Número de gastos últimos meses</h2>
        <div class="chart-container">
          <canvas id="chartNumeroGastos"></canvas>
        </div>
      </article>
    </section>

    <section class="tables-grid">
      <article class="panel">
        <h2 class="section-title">Totales por comercial</h2>

        <div class="table-wrap dashboard-mobile-table-wrap">
          <table class="dashboard-mobile-fit">
            <thead>
              <tr>
                <th>Comercial</th>
                <th>VISA / manual</th>
                <th>Efectivo / Kms</th>
                <th>Total</th>
              </tr>
            </thead>

            <tbody>
              <?php if (count($totalesPorComercial) === 0): ?>
                <tr>
                  <td colspan="4" class="muted">No hay datos para este periodo.</td>
                </tr>
              <?php endif; ?>

              <?php foreach ($totalesPorComercial as $row): ?>
                <tr>
                  <td><?php echo h($row['comercial'] ?: 'Sin comercial'); ?></td>
                  <td>
                    <span class="dashboard-value-stack">
                      <strong><?php echo h(number_format((float)$row['visa_importe'], 2, ',', '.')); ?> €</strong>
                      <small><?php echo (int)$row['visa_gastos']; ?> registros</small>
                    </span>
                  </td>
                  <td>
                    <span class="dashboard-value-stack">
                      <strong><?php echo h(number_format((float)$row['extra_importe'], 2, ',', '.')); ?> €</strong>
                      <small><?php echo (int)$row['extra_gastos']; ?> registros</small>
                    </span>
                  </td>
                  <td>
                    <span class="dashboard-value-stack">
                      <strong><?php echo h(number_format((float)$row['total_importe'], 2, ',', '.')); ?> €</strong>
                      <small><?php echo (int)$row['total_gastos']; ?> registros</small>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </article>

      <article class="panel">
        <h2 class="section-title">Totales por categoría</h2>

        <div class="table-wrap dashboard-mobile-table-wrap">
          <table class="dashboard-mobile-fit">
            <thead>
              <tr>
                <th>Categoría</th>
                <th>VISA / manual</th>
                <th>Efectivo / Kms</th>
                <th>Total</th>
              </tr>
            </thead>

            <tbody>
              <?php if (count($totalesPorCategoria) === 0): ?>
                <tr>
                  <td colspan="4" class="muted">No hay datos para este periodo.</td>
                </tr>
              <?php endif; ?>

              <?php foreach ($totalesPorCategoria as $row): ?>
                <tr>
                  <td><?php echo h($row['categoria']); ?></td>
                  <td>
                    <span class="dashboard-value-stack">
                      <strong><?php echo h(number_format((float)$row['visa_importe'], 2, ',', '.')); ?> €</strong>
                      <small><?php echo (int)$row['visa_gastos']; ?> registros</small>
                    </span>
                  </td>
                  <td>
                    <span class="dashboard-value-stack">
                      <strong><?php echo h(number_format((float)$row['extra_importe'], 2, ',', '.')); ?> €</strong>
                      <small><?php echo (int)$row['extra_gastos']; ?> registros</small>
                    </span>
                  </td>
                  <td>
                    <span class="dashboard-value-stack">
                      <strong><?php echo h(number_format((float)$row['total_importe'], 2, ',', '.')); ?> €</strong>
                      <small><?php echo (int)$row['total_gastos']; ?> registros</small>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </article>
    </section>

    <section class="panel">
      <h2 class="section-title">Gastos sin justificante</h2>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Comercial</th>
              <th>Fecha</th>
              <th>Viaje</th>
              <th>Motivo</th>
              <th>Importe</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>

          <tbody>
            <?php if (count($sinJustificanteListado) === 0): ?>
              <tr>
                <td colspan="8" class="muted">No hay gastos sin justificante para este periodo.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($sinJustificanteListado as $row): ?>
              <tr>
                <td><?php echo h($row['gasto_uid']); ?></td>
                <td><?php echo h($row['comercial']); ?></td>
                <td><?php echo h(formatFechaWeb($row['fecha_periodo'])); ?></td>
                <td><?php echo h($row['viaje']); ?></td>
                <td><?php echo h($row['motivo']); ?></td>
                <td><?php echo h(number_format((float)$row['importe_detectado'], 2, ',', '.')); ?> €</td>
                <td><?php echo h(formatEstadoWeb($row['estado'])); ?></td>
                <td>
                  <a class="link-table" href="../ver_gasto.php?id=<?php echo (int)$row['id']; ?>&return=<?php echo urlencode($returnUrl); ?>">
                    Ver
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

  </div>

  <script>
    const evolucionLabels = <?php echo json_encode($evolucionLabels, JSON_UNESCAPED_UNICODE); ?>;
    const evolucionVisaImportes = <?php echo json_encode($evolucionVisaImportes, JSON_UNESCAPED_UNICODE); ?>;
    const evolucionExtraImportes = <?php echo json_encode($evolucionExtraImportes, JSON_UNESCAPED_UNICODE); ?>;
    const evolucionVisaGastos = <?php echo json_encode($evolucionVisaGastos, JSON_UNESCAPED_UNICODE); ?>;
    const evolucionExtraGastos = <?php echo json_encode($evolucionExtraGastos, JSON_UNESCAPED_UNICODE); ?>;

    const comercialLabels = <?php echo json_encode($chartComercialLabels, JSON_UNESCAPED_UNICODE); ?>;
    const comercialVisa = <?php echo json_encode($chartComercialVisa, JSON_UNESCAPED_UNICODE); ?>;
    const comercialExtra = <?php echo json_encode($chartComercialExtra, JSON_UNESCAPED_UNICODE); ?>;

    const categoriaLabels = <?php echo json_encode($chartCategoriaLabels, JSON_UNESCAPED_UNICODE); ?>;
    const categoriaVisa = <?php echo json_encode($chartCategoriaVisa, JSON_UNESCAPED_UNICODE); ?>;
    const categoriaExtra = <?php echo json_encode($chartCategoriaExtra, JSON_UNESCAPED_UNICODE); ?>;

    const chartBlue = '#2563eb';
    const chartBlueSoft = 'rgba(37, 99, 235, 0.72)';
    const chartRed = '#dc2626';
    const chartRedSoft = 'rgba(220, 38, 38, 0.72)';

    function createChart(canvasId, config) {
      const canvas = document.getElementById(canvasId);

      if (!canvas || typeof Chart === 'undefined') {
        return;
      }

      return new Chart(canvas, config);
    }

    const commonOptions = {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          labels: {
            color: '#ffffff'
          }
        }
      },
      scales: {
        x: {
          ticks: {
            color: 'rgba(255, 255, 255, 0.75)'
          },
          grid: {
            color: 'rgba(255, 255, 255, 0.12)'
          }
        },
        y: {
          beginAtZero: true,
          ticks: {
            color: 'rgba(255, 255, 255, 0.75)'
          },
          grid: {
            color: 'rgba(255, 255, 255, 0.12)'
          }
        }
      }
    };

    const stackedOptions = {
      ...commonOptions,
      scales: {
        x: {
          stacked: true,
          ticks: {
            color: 'rgba(255, 255, 255, 0.75)'
          },
          grid: {
            color: 'rgba(255, 255, 255, 0.12)'
          }
        },
        y: {
          stacked: true,
          beginAtZero: true,
          ticks: {
            color: 'rgba(255, 255, 255, 0.75)'
          },
          grid: {
            color: 'rgba(255, 255, 255, 0.12)'
          }
        }
      }
    };

    createChart('chartEvolucion', {
      type: 'line',
      data: {
        labels: evolucionLabels,
        datasets: [
          {
            label: 'VISA y gastos manuales (€)',
            data: evolucionVisaImportes,
            borderColor: chartBlue,
            backgroundColor: chartBlueSoft,
            tension: 0.35,
            borderWidth: 3,
            pointRadius: 4,
            pointBackgroundColor: chartBlue
          },
          {
            label: 'Efectivo y Kilometraje (€)',
            data: evolucionExtraImportes,
            borderColor: chartRed,
            backgroundColor: chartRedSoft,
            tension: 0.35,
            borderWidth: 3,
            pointRadius: 4,
            pointBackgroundColor: chartRed
          }
        ]
      },
      options: commonOptions
    });

    createChart('chartComercial', {
      type: 'bar',
      data: {
        labels: comercialLabels,
        datasets: [
          {
            label: 'VISA y gastos manuales (€)',
            data: comercialVisa,
            backgroundColor: chartBlueSoft,
            borderColor: chartBlue,
            borderWidth: 1
          },
          {
            label: 'Efectivo y Kilometraje (€)',
            data: comercialExtra,
            backgroundColor: chartRedSoft,
            borderColor: chartRed,
            borderWidth: 1
          }
        ]
      },
      options: stackedOptions
    });

    createChart('chartCategoria', {
      type: 'bar',
      data: {
        labels: categoriaLabels,
        datasets: [
          {
            label: 'VISA y gastos manuales (€)',
            data: categoriaVisa,
            backgroundColor: chartBlueSoft,
            borderColor: chartBlue,
            borderWidth: 1
          },
          {
            label: 'Efectivo y Kilometraje (€)',
            data: categoriaExtra,
            backgroundColor: chartRedSoft,
            borderColor: chartRed,
            borderWidth: 1
          }
        ]
      },
      options: stackedOptions
    });

    createChart('chartNumeroGastos', {
      type: 'bar',
      data: {
        labels: evolucionLabels,
        datasets: [
          {
            label: 'VISA y gastos manuales',
            data: evolucionVisaGastos,
            backgroundColor: chartBlueSoft,
            borderColor: chartBlue,
            borderWidth: 1
          },
          {
            label: 'Efectivo y Kilometraje',
            data: evolucionExtraGastos,
            backgroundColor: chartRedSoft,
            borderColor: chartRed,
            borderWidth: 1
          }
        ]
      },
      options: stackedOptions
    });
  </script>

<?php include __DIR__ . '/../includes/admin_chat_launcher.php'; ?>
</body>
</html>

<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";

function incidenciasColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);

  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

  return $result && $result->num_rows > 0;
}

function incidenciasGetMonthName($month) {
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

function incidenciasFetchAll($conn, $sql, $types = "", $params = []) {
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

$fechaImputacionExiste = incidenciasColumnExists($conn, 'gastos', 'fecha_imputacion');

if ($fechaImputacionExiste) {
  $fechaPeriodo = "COALESCE(g.fecha_imputacion, g.fecha_ticket, g.created_at)";
} else {
  $fechaPeriodo = "COALESCE(g.fecha_ticket, g.created_at)";
}

$currentMonth = (int)date('n');
$currentYear = (int)date('Y');

$mes = intval($_GET['mes'] ?? $currentMonth);
$anio = intval($_GET['anio'] ?? $currentYear);
$comercialFiltro = trim($_GET['comercial'] ?? '');
$tipoFiltro = trim($_GET['tipo'] ?? 'todas');

if ($mes < 1 || $mes > 12) {
  $mes = $currentMonth;
}

if ($anio < 2000 || $anio > 2100) {
  $anio = $currentYear;
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

$comerciales = incidenciasFetchAll(
  $conn,
  "SELECT DISTINCT comercial
   FROM users
   WHERE comercial IS NOT NULL
     AND comercial <> ''
   ORDER BY comercial ASC"
);

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

$gastos = incidenciasFetchAll($conn, $sql, $types, $params);

$incidencias = [];

$contadorPendientes = 0;
$contadorErrores = 0;
$contadorSyncError = 0;
$contadorSinJustificante = 0;

foreach ($gastos as $gasto) {
  $estado = strtolower(trim((string)($gasto['estado'] ?? '')));
  $syncStatus = strtolower(trim((string)($gasto['sync_status'] ?? '')));
  $ticketsDrive = (int)($gasto['tickets_drive'] ?? 0);

  $esPendiente = $estado === 'pendiente';
  $esError = $estado === 'error';
  $esSyncError = in_array($syncStatus, ['error', 'fallido', 'failed', 'ko', 'error_sync'], true);
  $esSinJustificante = $ticketsDrive === 0 && ($esError || $esSyncError);

  if ($esPendiente) {
    $contadorPendientes++;
  }

  if ($esError) {
    $contadorErrores++;
  }

  if ($esSyncError) {
    $contadorSyncError++;
  }

  if ($esSinJustificante) {
    $contadorSinJustificante++;
  }

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
    $gasto['incidencia_pendiente'] = $esPendiente;
    $gasto['incidencia_error'] = $esError;
    $gasto['incidencia_sync_error'] = $esSyncError;
    $gasto['incidencia_sin_justificante'] = $esSinJustificante;

    $incidencias[] = $gasto;
  }
}

$totalIncidencias = count($incidencias);

$returnUrl = "admin/incidencias.php?mes=" . urlencode((string)$mes) . "&anio=" . urlencode((string)$anio) . "&tipo=" . urlencode($tipoFiltro);

if ($comercialFiltro !== '') {
  $returnUrl .= "&comercial=" . urlencode($comercialFiltro);
}

$exportQuery = "mes=" . urlencode((string)$mes) . "&anio=" . urlencode((string)$anio) . "&tipo=" . urlencode($tipoFiltro);

if ($comercialFiltro !== '') {
  $exportQuery .= "&comercial=" . urlencode($comercialFiltro);
}

$exportExcelUrl = "export_incidencias.php?formato=xls&" . $exportQuery;
$exportCsvUrl = "export_incidencias.php?formato=csv&" . $exportQuery;

$dashboardUrl = "dashboard.php?mes=" . urlencode((string)$mes) . "&anio=" . urlencode((string)$anio);

if ($comercialFiltro !== '') {
  $dashboardUrl .= "&comercial=" . urlencode($comercialFiltro);
}

$periodoNombre = incidenciasGetMonthName($mes) . ' ' . $anio;
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Incidencias - Panel Admin</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="../css/admin.css?v=<?php echo APP_VERSION; ?>">
</head>

<body class="admin-body">
  <div class="admin-wrapper">

    <header class="admin-header">
      <div>
        <h1>Incidencias de gastos</h1>
        <p>
          Revisión de incidencias del periodo <?php echo h($periodoNombre); ?><?php echo $comercialFiltro !== '' ? ' · ' . h($comercialFiltro) : ''; ?>.
        </p>
      </div>

      <div class="top-actions">
        <a 
          class="btn" 
          href="<?php echo h($exportExcelUrl); ?>"
          style="background: linear-gradient(135deg, #bbf7d0, #86efac) !important; color: #064e3b !important; border-color: rgba(187, 247, 208, 0.95) !important; box-shadow: 0 12px 28px rgba(34, 197, 94, 0.26) !important;"
        >
          Exportar Excel
        </a>

        <a 
          class="btn" 
          href="<?php echo h($exportCsvUrl); ?>"
          style="background: linear-gradient(135deg, #bbf7d0, #86efac) !important; color: #064e3b !important; border-color: rgba(187, 247, 208, 0.95) !important; box-shadow: 0 12px 28px rgba(34, 197, 94, 0.26) !important;"
        >
          Exportar CSV
        </a>

        <a class="btn" href="index.php">Panel Admin</a>
        <a class="btn" href="../home.php">Inicio</a>
        <a class="btn" href="../logout.php">Cerrar sesión</a>
      </div>
    </header>

    <section class="panel">
      <form method="get" action="incidencias.php" class="filters">
        <div>
          <label for="mes">Mes</label>
          <select id="mes" name="mes">
            <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?php echo $m; ?>" <?php echo $mes === $m ? 'selected' : ''; ?>>
                <?php echo h(incidenciasGetMonthName($m)); ?>
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
          <label for="tipo">Tipo de incidencia</label>
          <select id="tipo" name="tipo">
            <option value="todas" <?php echo $tipoFiltro === 'todas' ? 'selected' : ''; ?>>Todas</option>
            <option value="errores" <?php echo $tipoFiltro === 'errores' ? 'selected' : ''; ?>>Con error</option>
            <option value="sync_error" <?php echo $tipoFiltro === 'sync_error' ? 'selected' : ''; ?>>Error sincronización</option>
            <option value="sin_justificante" <?php echo $tipoFiltro === 'sin_justificante' ? 'selected' : ''; ?>>Sin justificante</option>
          </select>
        </div>

        <div>
          <button class="btn primary" type="submit">Aplicar filtros</button>
        </div>
      </form>

      <div class="note">
        Criterio de fecha usado: <?php echo $fechaImputacionExiste ? 'fecha de imputación si existe; si no, fecha del ticket o fecha de creación.' : 'fecha del ticket o fecha de creación.'; ?>
      </div>
    </section>

    <section class="kpi-grid">
      <article class="kpi-card">
        <span>Total incidencias filtradas</span>
        <strong><?php echo (int)$totalIncidencias; ?></strong>
        <small>Incidencias mostradas según los filtros aplicados</small>
      </article>

      <article class="kpi-card">
        <span>Error sincronización</span>
        <strong class="<?php echo $contadorSyncError > 0 ? 'negative' : 'positive'; ?>">
          <?php echo (int)$contadorSyncError; ?>
        </strong>
        <small>Gastos con error de sincronización</small>
      </article>

      <article class="kpi-card">
        <span>Gastos con error</span>
        <strong class="<?php echo $contadorErrores > 0 ? 'negative' : 'positive'; ?>">
          <?php echo (int)$contadorErrores; ?>
        </strong>
        <small>Gastos marcados con estado error</small>
      </article>

      <article class="kpi-card">
        <span>Sin justificante</span>
        <strong class="<?php echo $contadorSinJustificante > 0 ? 'negative' : 'positive'; ?>">
          <?php echo (int)$contadorSinJustificante; ?>
        </strong>
        <small>Gastos sin justificante disponible</small>
      </article>
    </section>

    <section class="panel">
      <h2 class="section-title">Listado de incidencias</h2>

      <div class="table-wrap">
        <table class="admin-table-wide">
          <thead>
            <tr>
              <th>ID</th>
              <th>Comercial</th>
              <th>Fecha</th>
              <th>Viaje</th>
              <th>Motivo</th>
              <th>Importe</th>
              <th>Estado</th>
              <th>Sync</th>
              <th>Justificante</th>
              <th>Incidencia</th>
              <th>Acción</th>
            </tr>
          </thead>

          <tbody>
            <?php if (count($incidencias) === 0): ?>
              <tr>
                <td colspan="11" class="muted">No hay incidencias para los filtros seleccionados.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($incidencias as $row): ?>
              <?php
                $ticketsDrive = (int)($row['tickets_drive'] ?? 0);
                $badges = [];

                if ($row['incidencia_error']) {
                  $badges[] = '<span class="pill inactive">Error</span>';
                }

                if ($row['incidencia_sync_error']) {
                  $badges[] = '<span class="pill inactive">Sync error</span>';
                }

                if ($row['incidencia_sin_justificante']) {
                  $badges[] = '<span class="pill inactive">Sin justificante</span>';
                }
              ?>

              <tr>
                <td><?php echo h($row['gasto_uid']); ?></td>
                <td><?php echo h($row['comercial']); ?></td>
                <td><?php echo h(formatFechaWeb($row['fecha_periodo'])); ?></td>
                <td><?php echo h($row['viaje']); ?></td>
                <td><?php echo h($row['motivo']); ?></td>
                <td><?php echo h(number_format((float)$row['importe_detectado'], 2, ',', '.')); ?> €</td>
                <td><?php echo h(formatEstadoWeb($row['estado'])); ?></td>
                <td><?php echo h(formatEstadoWeb($row['sync_status'] ?? '')); ?></td>
                <td>
                  <?php if ($ticketsDrive > 0): ?>
                    <span class="pill active">Sí</span>
                  <?php else: ?>
                    <span class="pill inactive">No</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="actions">
                    <?php echo implode(' ', $badges); ?>
                  </div>
                </td>
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

<?php include __DIR__ . '/../includes/admin_chat_launcher.php'; ?>
</body>
</html>

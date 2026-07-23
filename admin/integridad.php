<?php
require_once __DIR__ . "/../admin_guard.php";
requireMasterAccess();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auditoria.php";

function integridadTableExists($conn, $table) {
  $table = $conn->real_escape_string($table);
  $result = $conn->query("SHOW TABLES LIKE '$table'");
  return $result && $result->num_rows > 0;
}

function integridadColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return $result && $result->num_rows > 0;
}

function integridadFetchAll($conn, $sql, $types = '', $params = []) {
  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return [];
  }

  if ($types !== '' && count($params) > 0) {
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

function integridadFetchCount($conn, $sql, $types = '', $params = []) {
  $rows = integridadFetchAll($conn, $sql, $types, $params);
  return (int)($rows[0]['total'] ?? 0);
}

function integridadFechaPeriodoSql($conn) {
  if (integridadColumnExists($conn, 'gastos', 'fecha_imputacion')) {
    return "COALESCE(fecha_imputacion, fecha_ticket, created_at)";
  }

  return "COALESCE(fecha_ticket, created_at)";
}

$mesActual = (int)date('n');
$anioActual = (int)date('Y');
$mes = intval($_GET['mes'] ?? $mesActual);
$anio = intval($_GET['anio'] ?? $anioActual);
$comercial = trim($_GET['comercial'] ?? '');

if ($mes < 1 || $mes > 12) $mes = $mesActual;
if ($anio < 2020 || $anio > 2100) $anio = $anioActual;

$fechaPeriodo = integridadFechaPeriodoSql($conn);
$paramsPeriodo = [$mes, $anio];
$typesPeriodo = 'ii';
$whereComercial = '';

if ($comercial !== '') {
  $whereComercial = ' AND comercial = ?';
  $paramsPeriodo[] = $comercial;
  $typesPeriodo .= 's';
}

$comerciales = integridadFetchAll($conn, "SELECT DISTINCT comercial FROM users WHERE comercial IS NOT NULL AND comercial <> '' ORDER BY comercial ASC");

$totalGastosPeriodo = integridadFetchCount(
  $conn,
  "SELECT COUNT(*) AS total
   FROM gastos
   WHERE deleted_at IS NULL
     AND $fechaPeriodo IS NOT NULL
     AND MONTH($fechaPeriodo) = ?
     AND YEAR($fechaPeriodo) = ?
     $whereComercial",
  $typesPeriodo,
  $paramsPeriodo
);

$gastosPendientes = integridadFetchCount(
  $conn,
  "SELECT COUNT(*) AS total
   FROM gastos
   WHERE deleted_at IS NULL
     AND estado = 'pendiente'
     AND $fechaPeriodo IS NOT NULL
     AND MONTH($fechaPeriodo) = ?
     AND YEAR($fechaPeriodo) = ?
     $whereComercial",
  $typesPeriodo,
  $paramsPeriodo
);

$gastosError = integridadFetchCount(
  $conn,
  "SELECT COUNT(*) AS total
   FROM gastos
   WHERE deleted_at IS NULL
     AND estado = 'error'
     AND $fechaPeriodo IS NOT NULL
     AND MONTH($fechaPeriodo) = ?
     AND YEAR($fechaPeriodo) = ?
     $whereComercial",
  $typesPeriodo,
  $paramsPeriodo
);

$gastosSinJustificante = 0;

if (integridadTableExists($conn, 'gasto_tickets')) {
  $gastosSinJustificante = integridadFetchCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM gastos g
     WHERE g.deleted_at IS NULL
       AND g.estado IN ('procesado', 'editado')
       AND $fechaPeriodo IS NOT NULL
       AND MONTH($fechaPeriodo) = ?
       AND YEAR($fechaPeriodo) = ?
       " . ($comercial !== '' ? " AND g.comercial = ?" : "") . "
       AND NOT EXISTS (
         SELECT 1
         FROM gasto_tickets gt
         WHERE gt.gasto_id = g.id
           AND gt.gasto_uid = g.gasto_uid
           AND gt.drive_file_id IS NOT NULL
           AND gt.drive_file_id <> ''
       )",
    $typesPeriodo,
    $paramsPeriodo
  );
}

$cierresPendientes = integridadTableExists($conn, 'cierres_mensuales') ? integridadFetchCount(
  $conn,
  "SELECT COUNT(*) AS total
   FROM cierres_mensuales
   WHERE mes = ?
     AND anio = ?
     " . ($comercial !== '' ? " AND comercial = ?" : "") . "
     AND estado IN ('pendiente_admin', 'con_diferencia', 'rechazado')",
  $typesPeriodo,
  $paramsPeriodo
) : 0;

$enviosPendientes = integridadTableExists($conn, 'envios_integraciones') ? integridadFetchCount(
  $conn,
  "SELECT COUNT(*) AS total
   FROM envios_integraciones
   WHERE estado IN ('pendiente', 'error')",
  '',
  []
) : 0;

$erroresSistema = integridadTableExists($conn, 'auditoria_eventos') ? integridadFetchCount(
  $conn,
  "SELECT COUNT(*) AS total
   FROM auditoria_eventos
   WHERE tipo_evento IN ('sistema', 'seguridad')
     AND estado_nuevo = 'error'
     AND MONTH(created_at) = ?
     AND YEAR(created_at) = ?
     " . ($comercial !== '' ? " AND comercial = ?" : ""),
  $typesPeriodo,
  $paramsPeriodo
) : 0;

$listadoProblemas = integridadFetchAll(
  $conn,
  "SELECT id, gasto_uid, comercial, viaje, motivo, importe_detectado, fecha_ticket, estado, created_at
   FROM gastos
   WHERE deleted_at IS NULL
     AND $fechaPeriodo IS NOT NULL
     AND MONTH($fechaPeriodo) = ?
     AND YEAR($fechaPeriodo) = ?
     $whereComercial
     AND (estado IN ('pendiente', 'error') OR importe_detectado IS NULL)
   ORDER BY created_at DESC, id DESC
   LIMIT 100",
  $typesPeriodo,
  $paramsPeriodo
);

$enviosProblema = integridadTableExists($conn, 'envios_integraciones') ? integridadFetchAll(
  $conn,
  "SELECT id, tipo_destino, entidad, entidad_id, referencia, descripcion, estado, ultimo_error, created_at, updated_at
   FROM envios_integraciones
   WHERE estado IN ('pendiente', 'error')
   ORDER BY created_at DESC, id DESC
   LIMIT 100"
) : [];

$erroresAuditoria = integridadTableExists($conn, 'auditoria_eventos') ? integridadFetchAll(
  $conn,
  "SELECT id, created_at, tipo_evento, entidad, accion, descripcion, username, comercial, estado_nuevo
   FROM auditoria_eventos
   WHERE tipo_evento IN ('sistema', 'seguridad')
     AND (estado_nuevo = 'error' OR accion LIKE 'error_%' OR accion LIKE 'intento_%')
   ORDER BY created_at DESC, id DESC
   LIMIT 100"
) : [];

$nivel = 'ok';
if ($gastosError > 0 || $erroresSistema > 0) $nivel = 'error';
elseif ($gastosPendientes > 0 || $gastosSinJustificante > 0 || $cierresPendientes > 0 || $enviosPendientes > 0) $nivel = 'warning';

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'auditoria',
  'entidad' => 'sistema',
  'accion' => 'panel_integridad_consultado',
  'descripcion' => 'Consulta del panel de integridad del sistema.',
  'estado_nuevo' => $nivel,
  'datos' => [
    'mes' => $mes,
    'anio' => $anio,
    'comercial' => $comercial,
    'total_gastos_periodo' => $totalGastosPeriodo,
    'gastos_pendientes' => $gastosPendientes,
    'gastos_error' => $gastosError,
    'gastos_sin_justificante' => $gastosSinJustificante,
    'cierres_pendientes' => $cierresPendientes,
    'envios_pendientes' => $enviosPendientes,
    'errores_sistema' => $erroresSistema
  ]
]);
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Integridad del sistema - Panel Admin</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="../css/admin.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/admin_cierres.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/admin_auditoria.css?v=<?php echo APP_VERSION; ?>">

  <style>
    .integridad-compact-table {
      width: 100% !important;
      min-width: 0 !important;
      table-layout: fixed !important;
      font-size: 12px !important;
    }

    .integridad-compact-table th,
    .integridad-compact-table td {
      padding: 10px 8px !important;
      vertical-align: middle !important;
      overflow: hidden !important;
      text-overflow: ellipsis !important;
    }

    .integridad-compact-table td {
      white-space: normal !important;
      line-height: 1.3 !important;
    }

    .integridad-compact-table .btn.small {
      padding: 7px 9px !important;
      white-space: nowrap !important;
    }

    .integridad-actions-cell {
      white-space: nowrap !important;
      width: 92px !important;
    }
  </style>
</head>

<body class="admin-body">
  <div class="admin-wrapper">
    <header class="admin-header">
      <div>
        <h1>Integridad del sistema</h1>
        <p>Resumen de posibles incidencias operativas, cierres pendientes, errores e integraciones.</p>
      </div>
      <div class="top-actions">
        <a class="btn" href="centro_control.php">Centro de control</a>
        <a class="btn" href="index.php">Panel Admin</a>
        <a class="btn" href="../home.php">Inicio</a>
        <a class="btn" href="../logout.php">Cerrar sesión</a>
      </div>
    </header>

    <section class="panel">
      <form method="get" action="integridad.php" class="filters">
        <div>
          <label for="mes">Mes</label>
          <select id="mes" name="mes">
            <?php for ($i = 1; $i <= 12; $i++): ?>
              <option value="<?php echo $i; ?>" <?php echo $mes === $i ? 'selected' : ''; ?>><?php echo str_pad((string)$i, 2, '0', STR_PAD_LEFT); ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div>
          <label for="anio">Año</label>
          <input type="number" id="anio" name="anio" value="<?php echo (int)$anio; ?>" min="2020" max="2100">
        </div>
        <div>
          <label for="comercial">Comercial</label>
          <select id="comercial" name="comercial">
            <option value="" <?php echo $comercial === '' ? 'selected' : ''; ?>>Todos</option>
            <?php foreach ($comerciales as $item): ?>
              <?php $comercialOption = $item['comercial'] ?? ''; ?>
              <?php if ($comercialOption !== ''): ?>
                <option value="<?php echo h($comercialOption); ?>" <?php echo $comercial === $comercialOption ? 'selected' : ''; ?>><?php echo h($comercialOption); ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <button class="btn primary" type="submit">Actualizar</button>
        </div>
      </form>
    </section>

    <section class="kpi-grid auditoria-kpi-grid">
      <article class="kpi-card"><span>Gastos periodo</span><strong><?php echo (int)$totalGastosPeriodo; ?></strong><small>Registros activos</small></article>
      <article class="kpi-card"><span>Pendientes</span><strong><?php echo (int)$gastosPendientes; ?></strong><small>Gastos sin procesar</small></article>
      <article class="kpi-card"><span>Errores gasto</span><strong><?php echo (int)$gastosError; ?></strong><small>Requieren revisión</small></article>
      <article class="kpi-card"><span>Sin justificante</span><strong><?php echo (int)$gastosSinJustificante; ?></strong><small>Procesados sin ticket</small></article>
      <article class="kpi-card"><span>Errores sistema</span><strong><?php echo (int)$erroresSistema; ?></strong><small>Auditoría del periodo</small></article>
    </section>

    <section class="kpi-grid auditoria-kpi-grid">
      <article class="kpi-card"><span>Cierres pendientes</span><strong><?php echo (int)$cierresPendientes; ?></strong><small>Pendientes, con diferencia o rechazados</small></article>
      <article class="kpi-card"><span>Envíos pendientes/error</span><strong><?php echo (int)$enviosPendientes; ?></strong><small>Integraciones a revisar</small></article>
      <article class="kpi-card"><span>Nivel integridad</span><strong><?php echo h(strtoupper($nivel)); ?></strong><small>OK, WARNING o ERROR</small></article>
      <article class="kpi-card"><span>Mes</span><strong><?php echo str_pad((string)$mes, 2, '0', STR_PAD_LEFT); ?></strong><small><?php echo (int)$anio; ?></small></article>
      <article class="kpi-card"><span>Comercial</span><strong><?php echo h($comercial !== '' ? $comercial : 'Todos'); ?></strong><small>Filtro aplicado</small></article>
    </section>

    <section class="panel">
      <h2 class="section-title">Gastos a revisar</h2>
      <div class="table-wrap">
        <table class="admin-table-wide integridad-compact-table">
          <thead><tr><th>ID</th><th>Fecha</th><th>Comercial</th><th>Viaje</th><th>Motivo</th><th>Importe</th><th>Estado</th><th>Acción</th></tr></thead>
          <tbody>
            <?php if (count($listadoProblemas) === 0): ?><tr><td colspan="8" class="muted">No hay gastos problemáticos para el filtro seleccionado.</td></tr><?php endif; ?>
            <?php foreach ($listadoProblemas as $gasto): ?>
              <tr>
                <td><?php echo (int)$gasto['id']; ?></td>
                <td><?php echo h(formatFechaWeb($gasto['fecha_ticket'] ?? $gasto['created_at'] ?? '')); ?></td>
                <td><?php echo h($gasto['comercial'] ?? ''); ?></td>
                <td><?php echo h($gasto['viaje'] ?? ''); ?></td>
                <td><?php echo h($gasto['motivo'] ?? ''); ?></td>
                <td><?php echo h($gasto['importe_detectado'] ?? ''); ?></td>
                <td><?php echo h($gasto['estado'] ?? ''); ?></td>
                <td class="integridad-actions-cell"><a class="btn small" href="../ver_gasto.php?id=<?php echo (int)$gasto['id']; ?>">Ver</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <h2 class="section-title">Envíos e integraciones pendientes</h2>
      <div class="table-wrap">
        <table class="admin-table-wide integridad-compact-table">
          <thead><tr><th>ID</th><th>Tipo</th><th>Entidad</th><th>Referencia</th><th>Estado</th><th>Error</th><th>Acción</th></tr></thead>
          <tbody>
            <?php if (count($enviosProblema) === 0): ?><tr><td colspan="7" class="muted">No hay envíos pendientes o con error.</td></tr><?php endif; ?>
            <?php foreach ($enviosProblema as $envio): ?>
              <tr>
                <td><?php echo (int)$envio['id']; ?></td>
                <td><?php echo h($envio['tipo_destino'] ?? ''); ?></td>
                <td><?php echo h($envio['entidad'] ?? ''); ?> #<?php echo h($envio['entidad_id'] ?? ''); ?></td>
                <td><?php echo h($envio['referencia'] ?? ''); ?></td>
                <td><?php echo h($envio['estado'] ?? ''); ?></td>
                <td><?php echo h($envio['ultimo_error'] ?? ''); ?></td>
                <td class="integridad-actions-cell"><a class="btn small" href="editar_envio_integracion.php?id=<?php echo (int)$envio['id']; ?>&return=envios.php">Gestionar</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <h2 class="section-title">Últimos eventos sensibles</h2>
      <div class="table-wrap">
        <table class="admin-table-wide integridad-compact-table">
          <thead><tr><th>ID</th><th>Fecha</th><th>Tipo</th><th>Entidad</th><th>Acción</th><th>Descripción</th><th>Usuario</th></tr></thead>
          <tbody>
            <?php if (count($erroresAuditoria) === 0): ?><tr><td colspan="7" class="muted">No hay eventos sensibles recientes.</td></tr><?php endif; ?>
            <?php foreach ($erroresAuditoria as $evento): ?>
              <tr>
                <td><?php echo (int)$evento['id']; ?></td>
                <td><?php echo h(formatFechaWeb($evento['created_at'] ?? '', true)); ?></td>
                <td><?php echo h($evento['tipo_evento'] ?? ''); ?></td>
                <td><?php echo h($evento['entidad'] ?? ''); ?></td>
                <td><?php echo h($evento['accion'] ?? ''); ?></td>
                <td><?php echo h($evento['descripcion'] ?? ''); ?></td>
                <td><?php echo h($evento['comercial'] ?? $evento['username'] ?? 'Sistema'); ?></td>
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

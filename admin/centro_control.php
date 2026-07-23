<?php
require_once __DIR__ . "/../admin_guard.php";
requireMasterAccess();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auditoria.php";

function centroControlTableExists($conn, $table) {
  $table = $conn->real_escape_string($table);
  $result = $conn->query("SHOW TABLES LIKE '$table'");
  return $result && $result->num_rows > 0;
}

function centroControlColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return $result && $result->num_rows > 0;
}

function centroControlFetchAll($conn, $sql, $types = '', $params = []) {
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

function centroControlFetchOne($conn, $sql, $types = '', $params = []) {
  $rows = centroControlFetchAll($conn, $sql, $types, $params);
  return $rows[0] ?? null;
}

function centroControlFetchCount($conn, $sql, $types = '', $params = []) {
  $row = centroControlFetchOne($conn, $sql, $types, $params);
  return (int)($row['total'] ?? 0);
}

function centroControlFechaPeriodoSql($conn) {
  if (centroControlColumnExists($conn, 'gastos', 'fecha_imputacion')) {
    return "COALESCE(fecha_imputacion, fecha_ticket, created_at)";
  }

  return "COALESCE(fecha_ticket, created_at)";
}

function centroControlTipoTexto($tipo) {
  $map = [
    'cierre' => 'Cierre',
    'envio' => 'Envío',
    'usuario' => 'Usuario',
    'gasto' => 'Gasto',
    'seguridad' => 'Seguridad',
    'sistema' => 'Sistema',
    'auditoria' => 'Auditoría'
  ];

  return $map[$tipo] ?? ucfirst((string)$tipo);
}

function centroControlEstadoRevisionTexto($estado) {
  if ($estado === 'normal') return 'Normal';
  if ($estado === 'revisado') return 'Revisado';
  if ($estado === 'corregido') return 'Corregido';
  if ($estado === 'anulado') return 'Anulado';

  return 'Normal';
}

function centroControlNombreMes($mes) {
  $meses = [
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

  return $meses[(int)$mes] ?? '';
}

$mesActual = (int)date('n');
$anioActual = (int)date('Y');

$mes = intval($_GET['mes'] ?? $mesActual);
$anio = intval($_GET['anio'] ?? $anioActual);
$comercial = trim($_GET['comercial'] ?? '');

if ($mes < 1 || $mes > 12) {
  $mes = $mesActual;
}

if ($anio < 2020 || $anio > 2100) {
  $anio = $anioActual;
}

$fechaPeriodo = centroControlFechaPeriodoSql($conn);
$paramsPeriodo = [$mes, $anio];
$typesPeriodo = 'ii';
$whereComercial = '';

if ($comercial !== '') {
  $whereComercial = ' AND comercial = ?';
  $paramsPeriodo[] = $comercial;
  $typesPeriodo .= 's';
}

$comerciales = centroControlFetchAll(
  $conn,
  "SELECT id, username, comercial, activo
   FROM users
   WHERE comercial IS NOT NULL
     AND comercial <> ''
   ORDER BY comercial ASC, username ASC"
);

$totalGastosPeriodo = centroControlFetchCount(
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

$gastosPendientes = centroControlFetchCount(
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

$gastosError = centroControlFetchCount(
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

if (centroControlTableExists($conn, 'gasto_tickets')) {
  $gastosSinJustificante = centroControlFetchCount(
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

$cierresPendientes = centroControlTableExists($conn, 'cierres_mensuales') ? centroControlFetchCount(
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

$enviosPendientes = centroControlTableExists($conn, 'envios_integraciones') ? centroControlFetchCount(
  $conn,
  "SELECT COUNT(*) AS total
   FROM envios_integraciones
   WHERE estado IN ('pendiente', 'error')"
) : 0;

$eventosSensibles = centroControlTableExists($conn, 'auditoria_eventos') ? centroControlFetchCount(
  $conn,
  "SELECT COUNT(*) AS total
   FROM auditoria_eventos
   WHERE tipo_evento IN ('sistema', 'seguridad')
     AND (estado_nuevo = 'error' OR accion LIKE 'error_%' OR accion LIKE 'intento_%')
     AND MONTH(created_at) = ?
     AND YEAR(created_at) = ?
     " . ($comercial !== '' ? " AND comercial = ?" : ""),
  $typesPeriodo,
  $paramsPeriodo
) : 0;

$eventosPendientesRevision = centroControlTableExists($conn, 'auditoria_eventos') ? centroControlFetchCount(
  $conn,
  "SELECT COUNT(*) AS total
   FROM auditoria_eventos
   WHERE estado_revision = 'normal'"
) : 0;

$listadoGastosRevision = centroControlFetchAll(
  $conn,
  "SELECT id, gasto_uid, comercial, viaje, motivo, importe_detectado, fecha_ticket, estado, created_at
   FROM gastos
   WHERE deleted_at IS NULL
     AND $fechaPeriodo IS NOT NULL
     AND MONTH($fechaPeriodo) = ?
     AND YEAR($fechaPeriodo) = ?
     $whereComercial
     AND estado = 'error'
   ORDER BY created_at DESC, id DESC
   LIMIT 12",
  $typesPeriodo,
  $paramsPeriodo
);

$enviosRevision = centroControlTableExists($conn, 'envios_integraciones') ? centroControlFetchAll(
  $conn,
  "SELECT id, tipo_destino, entidad, entidad_id, referencia, estado, ultimo_error, created_at, updated_at
   FROM envios_integraciones
   WHERE estado IN ('pendiente', 'error')
   ORDER BY created_at DESC, id DESC
   LIMIT 12"
) : [];

$ultimosEventos = centroControlTableExists($conn, 'auditoria_eventos') ? centroControlFetchAll(
  $conn,
  "SELECT id, created_at, tipo_evento, entidad, entidad_id, accion, descripcion, username, comercial, estado_anterior, estado_nuevo, estado_revision
   FROM auditoria_eventos
   ORDER BY created_at DESC, id DESC
   LIMIT 12"
) : [];

$eventosSensiblesListado = centroControlTableExists($conn, 'auditoria_eventos') ? centroControlFetchAll(
  $conn,
  "SELECT id, created_at, tipo_evento, entidad, accion, descripcion, username, comercial, estado_nuevo
   FROM auditoria_eventos
   WHERE tipo_evento IN ('sistema', 'seguridad')
     AND (estado_nuevo = 'error' OR accion LIKE 'error_%' OR accion LIKE 'intento_%')
   ORDER BY created_at DESC, id DESC
   LIMIT 12"
) : [];

$nivel = 'ok';
if ($gastosError > 0 || $eventosSensibles > 0) {
  $nivel = 'error';
} elseif ($gastosPendientes > 0 || $gastosSinJustificante > 0 || $cierresPendientes > 0 || $enviosPendientes > 0 || $eventosPendientesRevision > 0) {
  $nivel = 'warning';
}

if (function_exists('auditoriaRegistrarSeguro')) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'auditoria',
    'entidad' => 'sistema',
    'accion' => 'centro_control_consultado',
    'descripcion' => 'Consulta del centro de control administrativo.',
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
      'eventos_sensibles' => $eventosSensibles,
      'eventos_pendientes_revision' => $eventosPendientesRevision
    ]
  ]);
}

$centroControlReturnUrl = 'admin/centro_control.php?' . http_build_query([
  'mes' => $mes,
  'anio' => $anio,
  'comercial' => $comercial
]);

$gestionarErroresUrl = '../gestionar_gastos.php?' . http_build_query([
  'estado' => 'error',
  'mes' => $mes,
  'anio' => $anio,
  'comercial' => $comercial,
  'return' => $centroControlReturnUrl
]);

$backupUrl = 'export_backup_mensual.php?' . http_build_query([
  'mes' => $mes,
  'anio' => $anio,
  'comercial' => $comercial
]);
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Centro de control - Panel Admin</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="../css/admin.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/admin_cierres.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/admin_auditoria.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/admin_centro_control.css?v=<?php echo APP_VERSION; ?>">

  <style>
    .control-export-button {
      background: linear-gradient(135deg, #b7f7cf, #8ef0b6) !important;
      color: #004c46 !important;
      border: 1px solid rgba(183, 247, 207, 0.85) !important;
      box-shadow: 0 8px 18px rgba(142, 240, 182, 0.22) !important;
      font-weight: 800 !important;
    }

    .control-export-button:hover {
      background: linear-gradient(135deg, #a8f3c5, #7ce9a9) !important;
      color: #003f3a !important;
      transform: translateY(-1px);
    }

    .control-export-button:visited,
    .control-export-button:active,
    .control-export-button:focus {
      color: #004c46 !important;
    }


    /* Refuerzo móvil real: en Centro de control no se fuerzan tablas en móvil.
       Se ocultan las tablas y se muestran tarjetas completas, igual que en Últimos registros de Envíos. */
    .control-mobile-cards {
      display: none;
    }

    @media (max-width: 700px) {
      .control-card-wrap.control-desktop-table {
        display: none !important;
      }

      .control-mobile-cards {
        display: grid !important;
        grid-template-columns: 1fr !important;
        gap: 14px !important;
        width: 100% !important;
      }

      .control-mobile-card {
        display: block !important;
        width: 100% !important;
        padding: 12px 12px !important;
        border: 1px solid rgba(255, 255, 255, 0.18) !important;
        border-radius: 16px !important;
        background: rgba(255, 255, 255, 0.07) !important;
        box-shadow: none !important;
      }

      .control-mobile-row {
        display: block !important;
        width: 100% !important;
        padding: 8px 0 !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.10) !important;
        line-height: 1.35 !important;
        white-space: normal !important;
        word-break: normal !important;
        overflow-wrap: anywhere !important;
      }

      .control-mobile-row:last-child {
        border-bottom: none !important;
        padding-bottom: 0 !important;
      }

      .control-mobile-label {
        display: block !important;
        width: 100% !important;
        margin: 0 0 4px 0 !important;
        color: rgba(255, 255, 255, 0.68) !important;
        font-size: 11px !important;
        font-weight: 900 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.04em !important;
      }

      .control-mobile-value {
        display: block !important;
        width: 100% !important;
        color: #ffffff !important;
        font-size: 14px !important;
        font-weight: 700 !important;
        line-height: 1.35 !important;
        white-space: normal !important;
        overflow: visible !important;
        text-overflow: clip !important;
        word-break: normal !important;
        overflow-wrap: anywhere !important;
      }

      .control-mobile-actions {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 8px !important;
        width: 100% !important;
        margin-top: 2px !important;
      }

      .control-mobile-actions.single-action {
        grid-template-columns: 1fr !important;
      }

      .control-mobile-actions .btn.small {
        width: 100% !important;
        min-height: 38px !important;
        white-space: nowrap !important;
      }
    }
  </style>
</head>

<body class="admin-body">
  <div class="admin-wrapper">
    <header class="admin-header">
      <div>
        <h1>Centro de control</h1>
        <p>Estado del sistema, actividad registrada y copias mensuales desde una única pantalla.</p>
      </div>

      <div class="top-actions">
        <a class="btn" href="index.php">Panel Admin</a>
        <a class="btn" href="../home.php">Inicio</a>
        <a class="btn" href="../logout.php">Cerrar sesión</a>
      </div>
    </header>

    <section class="control-nav panel">
      <a href="#estado">Estado del sistema</a>
      <a href="#actividad">Registro de actividad</a>
      <a href="#copias">Copias y exportaciones</a>
    </section>

    <section class="panel" id="filtros">
      <form method="get" action="centro_control.php" class="filters">
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
            <?php foreach ($comerciales as $usuario): ?>
              <?php
                $comercialUsuario = trim((string)($usuario['comercial'] ?? ''));
                $usernameUsuario = trim((string)($usuario['username'] ?? ''));
                $activoUsuario = (int)($usuario['activo'] ?? 0);
              ?>
              <?php if ($comercialUsuario !== ''): ?>
                <option value="<?php echo h($comercialUsuario); ?>" <?php echo $comercial === $comercialUsuario ? 'selected' : ''; ?>>
                  <?php echo h($comercialUsuario); ?><?php echo $usernameUsuario !== '' ? h(' (' . $usernameUsuario . ')') : ''; ?><?php echo $activoUsuario !== 1 ? h(' - Inactivo') : ''; ?>
                </option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <button class="btn primary" type="submit">Actualizar</button>
          <a class="btn" href="centro_control.php">Limpiar</a>
        </div>
      </form>
    </section>

    <section id="estado">
      <div class="control-section-title">
        <div>
          <h2>Estado del sistema</h2>
          <p><?php echo h(centroControlNombreMes($mes)); ?> <?php echo (int)$anio; ?> · <?php echo h($comercial !== '' ? $comercial : 'Todos los comerciales'); ?></p>
        </div>
        <span class="control-status control-status-<?php echo h($nivel); ?>"><?php echo h(strtoupper($nivel)); ?></span>
      </div>

      <section class="kpi-grid auditoria-kpi-grid">
        <article class="kpi-card"><span>Gastos periodo</span><strong><?php echo (int)$totalGastosPeriodo; ?></strong><small>Registros activos</small></article>
        <article class="kpi-card"><span>Pendientes</span><strong><?php echo (int)$gastosPendientes; ?></strong><small>Gastos sin procesar</small></article>
        <article class="kpi-card"><span>Errores gasto</span><strong><?php echo (int)$gastosError; ?></strong><small>Requieren revisión</small></article>
        <article class="kpi-card"><span>Sin justificante</span><strong><?php echo (int)$gastosSinJustificante; ?></strong><small>Procesados sin ticket</small></article>
        <article class="kpi-card"><span>Cierres pendientes</span><strong><?php echo (int)$cierresPendientes; ?></strong><small>Pendientes o con diferencia</small></article>
      </section>

      <section class="kpi-grid auditoria-kpi-grid">
        <article class="kpi-card"><span>Envíos pendientes/error</span><strong><?php echo (int)$enviosPendientes; ?></strong><small>Integraciones a revisar</small></article>
        <article class="kpi-card"><span>Eventos sensibles</span><strong><?php echo (int)$eventosSensibles; ?></strong><small>Errores o intentos bloqueados</small></article>
        <article class="kpi-card"><span>Pendientes revisión</span><strong><?php echo (int)$eventosPendientesRevision; ?></strong><small>Auditoría sin revisar</small></article>
        <article class="kpi-card"><span>Mes</span><strong><?php echo str_pad((string)$mes, 2, '0', STR_PAD_LEFT); ?></strong><small><?php echo (int)$anio; ?></small></article>
        <article class="kpi-card"><span>Comercial</span><strong><?php echo h($comercial !== '' ? $comercial : 'Todos'); ?></strong><small>Filtro aplicado</small></article>
      </section>
    </section>

    <section class="control-grid-two">
      <article class="panel">
        <div class="control-panel-heading">
          <h2 class="section-title">Gastos a revisar</h2>
          <a class="btn small" href="<?php echo h($gestionarErroresUrl); ?>">Ver gestión</a>
        </div>

        <div class="table-wrap control-card-wrap control-desktop-table">
          <table class="admin-table-wide control-compact-table">
            <thead><tr><th>ID</th><th>Fecha</th><th>Comercial</th><th>Viaje / motivo</th><th>Estado</th><th>Acción</th></tr></thead>
            <tbody>
              <?php if (count($listadoGastosRevision) === 0): ?><tr><td colspan="6" class="muted">No hay gastos problemáticos para el filtro seleccionado.</td></tr><?php endif; ?>
              <?php foreach ($listadoGastosRevision as $gasto): ?>
                <tr>
                  <td data-label="ID"><?php echo (int)$gasto['id']; ?></td>
                  <td data-label="Fecha"><?php echo h(formatFechaWeb($gasto['fecha_ticket'] ?? $gasto['created_at'] ?? '')); ?></td>
                  <td data-label="Comercial"><?php echo h($gasto['comercial'] ?? ''); ?></td>
                  <td data-label="Viaje / motivo"><?php echo h(trim(($gasto['viaje'] ?? '') . ' · ' . ($gasto['motivo'] ?? ''), ' ·')); ?></td>
                  <td data-label="Estado"><?php echo h($gasto['estado'] ?? ''); ?></td>
                  <td data-label="Acción"><a class="btn small" href="../ver_gasto.php?id=<?php echo (int)$gasto['id']; ?>&return=<?php echo urlencode($centroControlReturnUrl); ?>">Ver</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="control-mobile-cards">
          <?php if (count($listadoGastosRevision) === 0): ?>
            <div class="control-mobile-card"><div class="control-mobile-value muted">No hay gastos problemáticos para el filtro seleccionado.</div></div>
          <?php endif; ?>
          <?php foreach ($listadoGastosRevision as $gasto): ?>
            <article class="control-mobile-card">
              <div class="control-mobile-row"><span class="control-mobile-label">ID</span><span class="control-mobile-value"><?php echo (int)$gasto['id']; ?></span></div>
              <div class="control-mobile-row"><span class="control-mobile-label">Fecha</span><span class="control-mobile-value"><?php echo h(formatFechaWeb($gasto['fecha_ticket'] ?? $gasto['created_at'] ?? '')); ?></span></div>
              <div class="control-mobile-row"><span class="control-mobile-label">Comercial</span><span class="control-mobile-value"><?php echo h($gasto['comercial'] ?? ''); ?></span></div>
              <div class="control-mobile-row"><span class="control-mobile-label">Viaje / motivo</span><span class="control-mobile-value"><?php echo h(trim(($gasto['viaje'] ?? '') . ' · ' . ($gasto['motivo'] ?? ''), ' ·')); ?></span></div>
              <div class="control-mobile-row"><span class="control-mobile-label">Estado</span><span class="control-mobile-value"><?php echo h($gasto['estado'] ?? ''); ?></span></div>
              <div class="control-mobile-row">
                <span class="control-mobile-label">Acción</span>
                <div class="control-mobile-actions single-action"><a class="btn small" href="../ver_gasto.php?id=<?php echo (int)$gasto['id']; ?>&return=<?php echo urlencode($centroControlReturnUrl); ?>">Ver</a></div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </article>

      <article class="panel">
        <div class="control-panel-heading">
          <h2 class="section-title">Envíos pendientes</h2>
          <a class="btn small" href="envios.php">Ver envíos</a>
        </div>

        <div class="table-wrap control-card-wrap control-desktop-table">
          <table class="admin-table-wide control-compact-table">
            <thead><tr><th>ID</th><th>Destino</th><th>Entidad</th><th>Estado</th><th>Error / nota</th><th>Acción</th></tr></thead>
            <tbody>
              <?php if (count($enviosRevision) === 0): ?><tr><td colspan="6" class="muted">No hay envíos pendientes o con error.</td></tr><?php endif; ?>
              <?php foreach ($enviosRevision as $envio): ?>
                <tr>
                  <td data-label="ID"><?php echo (int)$envio['id']; ?></td>
                  <td data-label="Destino"><?php echo h($envio['tipo_destino'] ?? ''); ?></td>
                  <td data-label="Entidad"><?php echo h($envio['entidad'] ?? ''); ?> #<?php echo h($envio['entidad_id'] ?? ''); ?></td>
                  <td data-label="Estado"><?php echo h($envio['estado'] ?? ''); ?></td>
                  <td data-label="Error / nota"><?php echo h($envio['ultimo_error'] ?? $envio['referencia'] ?? ''); ?></td>
                  <td data-label="Acción"><a class="btn small" href="editar_envio_integracion.php?id=<?php echo (int)$envio['id']; ?>&return=envios.php">Gestionar</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="control-mobile-cards">
          <?php if (count($enviosRevision) === 0): ?>
            <div class="control-mobile-card"><div class="control-mobile-value muted">No hay envíos pendientes o con error.</div></div>
          <?php endif; ?>
          <?php foreach ($enviosRevision as $envio): ?>
            <article class="control-mobile-card">
              <div class="control-mobile-row"><span class="control-mobile-label">ID</span><span class="control-mobile-value"><?php echo (int)$envio['id']; ?></span></div>
              <div class="control-mobile-row"><span class="control-mobile-label">Destino</span><span class="control-mobile-value"><?php echo h($envio['tipo_destino'] ?? ''); ?></span></div>
              <div class="control-mobile-row"><span class="control-mobile-label">Entidad</span><span class="control-mobile-value"><?php echo h($envio['entidad'] ?? ''); ?> #<?php echo h($envio['entidad_id'] ?? ''); ?></span></div>
              <div class="control-mobile-row"><span class="control-mobile-label">Estado</span><span class="control-mobile-value"><?php echo h($envio['estado'] ?? ''); ?></span></div>
              <div class="control-mobile-row"><span class="control-mobile-label">Error / nota</span><span class="control-mobile-value"><?php echo h($envio['ultimo_error'] ?? $envio['referencia'] ?? ''); ?></span></div>
              <div class="control-mobile-row">
                <span class="control-mobile-label">Acción</span>
                <div class="control-mobile-actions single-action"><a class="btn small" href="editar_envio_integracion.php?id=<?php echo (int)$envio['id']; ?>&return=envios.php">Gestionar</a></div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </article>
    </section>

    <section id="actividad" class="panel">
      <div class="control-panel-heading">
        <div>
          <h2 class="section-title">Registro de actividad</h2>
          <p class="muted">Últimos eventos registrados. La auditoría completa se mantiene en su vista específica.</p>
        </div>
        <a class="btn" href="auditoria.php">Ver auditoría completa</a>
      </div>

      <div class="table-wrap control-card-wrap control-desktop-table">
        <table class="admin-table-wide control-compact-table">
          <thead><tr><th>ID</th><th>Fecha</th><th>Tipo</th><th>Entidad</th><th>Acción</th><th>Usuario</th><th>Cambio</th><th>Acciones</th></tr></thead>
          <tbody>
            <?php if (count($ultimosEventos) === 0): ?><tr><td colspan="8" class="muted">No hay eventos registrados.</td></tr><?php endif; ?>
            <?php foreach ($ultimosEventos as $evento): ?>
              <tr>
                <td data-label="ID"><?php echo (int)$evento['id']; ?></td>
                <td data-label="Fecha"><?php echo h(formatFechaWeb($evento['created_at'] ?? '', true)); ?></td>
                <td data-label="Tipo"><?php echo h(centroControlTipoTexto($evento['tipo_evento'] ?? '')); ?></td>
                <td data-label="Entidad"><?php echo h($evento['entidad'] ?? ''); ?><?php echo !empty($evento['entidad_id']) ? ' #' . (int)$evento['entidad_id'] : ''; ?></td>
                <td data-label="Acción"><?php echo h($evento['accion'] ?? ''); ?></td>
                <td data-label="Usuario"><?php echo h($evento['comercial'] ?? $evento['username'] ?? 'Sistema'); ?></td>
                <td data-label="Cambio"><?php echo h(($evento['estado_anterior'] ?? '—') . ' → ' . ($evento['estado_nuevo'] ?? '—')); ?></td>
                <td data-label="Acciones"><div class="control-actions"><a class="btn small" href="detalle_auditoria.php?id=<?php echo (int)$evento['id']; ?>">Ver</a><a class="btn small" href="editar_auditoria.php?id=<?php echo (int)$evento['id']; ?>">Revisar</a></div></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="control-mobile-cards">
        <?php if (count($ultimosEventos) === 0): ?>
          <div class="control-mobile-card"><div class="control-mobile-value muted">No hay eventos registrados.</div></div>
        <?php endif; ?>
        <?php foreach ($ultimosEventos as $evento): ?>
          <article class="control-mobile-card">
            <div class="control-mobile-row"><span class="control-mobile-label">ID</span><span class="control-mobile-value"><?php echo (int)$evento['id']; ?></span></div>
            <div class="control-mobile-row"><span class="control-mobile-label">Fecha</span><span class="control-mobile-value"><?php echo h(formatFechaWeb($evento['created_at'] ?? '', true)); ?></span></div>
            <div class="control-mobile-row"><span class="control-mobile-label">Tipo</span><span class="control-mobile-value"><?php echo h(centroControlTipoTexto($evento['tipo_evento'] ?? '')); ?></span></div>
            <div class="control-mobile-row"><span class="control-mobile-label">Entidad</span><span class="control-mobile-value"><?php echo h($evento['entidad'] ?? ''); ?><?php echo !empty($evento['entidad_id']) ? ' #' . (int)$evento['entidad_id'] : ''; ?></span></div>
            <div class="control-mobile-row"><span class="control-mobile-label">Acción</span><span class="control-mobile-value"><?php echo h($evento['accion'] ?? ''); ?></span></div>
            <div class="control-mobile-row"><span class="control-mobile-label">Usuario</span><span class="control-mobile-value"><?php echo h($evento['comercial'] ?? $evento['username'] ?? 'Sistema'); ?></span></div>
            <div class="control-mobile-row"><span class="control-mobile-label">Cambio</span><span class="control-mobile-value"><?php echo h(($evento['estado_anterior'] ?? '—') . ' → ' . ($evento['estado_nuevo'] ?? '—')); ?></span></div>
            <div class="control-mobile-row">
              <span class="control-mobile-label">Acciones</span>
              <div class="control-mobile-actions"><a class="btn small" href="detalle_auditoria.php?id=<?php echo (int)$evento['id']; ?>">Ver</a><a class="btn small" href="editar_auditoria.php?id=<?php echo (int)$evento['id']; ?>">Revisar</a></div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="panel">
      <div class="control-panel-heading">
        <h2 class="section-title">Eventos sensibles recientes</h2>
        <a class="btn small" href="auditoria.php?tipo=seguridad">Ver seguridad</a>
      </div>

      <div class="table-wrap control-card-wrap control-desktop-table">
        <table class="admin-table-wide control-compact-table">
          <thead><tr><th>ID</th><th>Fecha</th><th>Tipo</th><th>Entidad</th><th>Acción</th><th>Descripción</th><th>Usuario</th></tr></thead>
          <tbody>
            <?php if (count($eventosSensiblesListado) === 0): ?><tr><td colspan="7" class="muted">No hay eventos sensibles recientes.</td></tr><?php endif; ?>
            <?php foreach ($eventosSensiblesListado as $evento): ?>
              <tr>
                <td data-label="ID"><?php echo (int)$evento['id']; ?></td>
                <td data-label="Fecha"><?php echo h(formatFechaWeb($evento['created_at'] ?? '', true)); ?></td>
                <td data-label="Tipo"><?php echo h(centroControlTipoTexto($evento['tipo_evento'] ?? '')); ?></td>
                <td data-label="Entidad"><?php echo h($evento['entidad'] ?? ''); ?></td>
                <td data-label="Acción"><?php echo h($evento['accion'] ?? ''); ?></td>
                <td data-label="Descripción"><?php echo h($evento['descripcion'] ?? ''); ?></td>
                <td data-label="Usuario"><?php echo h($evento['comercial'] ?? $evento['username'] ?? 'Sistema'); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="control-mobile-cards">
        <?php if (count($eventosSensiblesListado) === 0): ?>
          <div class="control-mobile-card"><div class="control-mobile-value muted">No hay eventos sensibles recientes.</div></div>
        <?php endif; ?>
        <?php foreach ($eventosSensiblesListado as $evento): ?>
          <article class="control-mobile-card">
            <div class="control-mobile-row"><span class="control-mobile-label">ID</span><span class="control-mobile-value"><?php echo (int)$evento['id']; ?></span></div>
            <div class="control-mobile-row"><span class="control-mobile-label">Fecha</span><span class="control-mobile-value"><?php echo h(formatFechaWeb($evento['created_at'] ?? '', true)); ?></span></div>
            <div class="control-mobile-row"><span class="control-mobile-label">Tipo</span><span class="control-mobile-value"><?php echo h(centroControlTipoTexto($evento['tipo_evento'] ?? '')); ?></span></div>
            <div class="control-mobile-row"><span class="control-mobile-label">Entidad</span><span class="control-mobile-value"><?php echo h($evento['entidad'] ?? ''); ?></span></div>
            <div class="control-mobile-row"><span class="control-mobile-label">Acción</span><span class="control-mobile-value"><?php echo h($evento['accion'] ?? ''); ?></span></div>
            <div class="control-mobile-row"><span class="control-mobile-label">Descripción</span><span class="control-mobile-value"><?php echo h($evento['descripcion'] ?? ''); ?></span></div>
            <div class="control-mobile-row"><span class="control-mobile-label">Usuario</span><span class="control-mobile-value"><?php echo h($evento['comercial'] ?? $evento['username'] ?? 'Sistema'); ?></span></div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section id="copias" class="panel">
      <div class="control-panel-heading">
        <div>
          <h2 class="section-title">Copias y exportaciones</h2>
          <p class="muted">Descarga una copia mensual con gastos, cierres, envíos, incidencias y auditoría del filtro seleccionado.</p>
        </div>
        <a class="btn small" href="backup_mensual.php">Vista de backup</a>
      </div>

      <div class="control-backup-box">
        <div>
          <strong>Backup seleccionado</strong><br>
          <?php echo h(centroControlNombreMes($mes)); ?> <?php echo (int)$anio; ?> · <?php echo h($comercial !== '' ? $comercial : 'Todos los comerciales'); ?>
        </div>
        <a class="btn control-export-button" href="<?php echo h($backupUrl); ?>">Descargar CSV mensual</a>
      </div>
    </section>
  </div>

<?php include __DIR__ . '/../includes/admin_chat_launcher.php'; ?>
</body>
</html>

<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/gastos_unificados.php";

function cierresAdminColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);

  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

  return $result && $result->num_rows > 0;
}

function cierresAdminTableExists($conn, $table) {
  $table = $conn->real_escape_string($table);

  $result = $conn->query("SHOW TABLES LIKE '$table'");

  return $result && $result->num_rows > 0;
}

function cierresAdminGetMonthName($month) {
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

function cierresAdminFetchAll($conn, $sql, $types = "", $params = []) {
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

function cierresAdminEstadoTexto($estadoTrabajo) {
  if ($estadoTrabajo === 'sin_presentar') {
    return 'Sin presentar';
  }

  if ($estadoTrabajo === 'pendiente_admin') {
    return 'Pendiente admin';
  }

  if ($estadoTrabajo === 'validado') {
    return 'Validado';
  }

  if ($estadoTrabajo === 'con_diferencia') {
    return 'Con diferencia';
  }

  if ($estadoTrabajo === 'rechazado') {
    return 'Rechazado';
  }

  return formatEstadoWeb($estadoTrabajo);
}

function cierresAdminEstadoClass($estadoTrabajo) {
  if ($estadoTrabajo === 'sin_presentar') {
    return 'missing';
  }

  if ($estadoTrabajo === 'pendiente_admin') {
    return 'pending';
  }

  if ($estadoTrabajo === 'validado') {
    return 'validated';
  }

  if ($estadoTrabajo === 'con_diferencia') {
    return 'difference';
  }

  if ($estadoTrabajo === 'rechazado') {
    return 'rejected';
  }

  return 'pending';
}

function cierresAdminDiferenciaEsCero($diferencia) {
  return abs((float)$diferencia) < 0.005;
}

function cierresAdminFetchCierresContabilizados($conn) {
  if (!cierresAdminTableExists($conn, 'envios_integraciones')) {
    return [];
  }

  $sql = "SELECT DISTINCT entidad_id
          FROM envios_integraciones
          WHERE entidad = 'cierre'
            AND estado = 'enviado'
            AND entidad_id IS NOT NULL";

  $rows = cierresAdminFetchAll($conn, $sql);

  $contabilizados = [];

  foreach ($rows as $row) {
    $cierreId = (int)($row['entidad_id'] ?? 0);

    if ($cierreId > 0) {
      $contabilizados[$cierreId] = true;
    }
  }

  return $contabilizados;
}

$currentMonth = (int)date('n');
$currentYear = (int)date('Y');

$mes = intval($_GET['mes'] ?? $currentMonth);
$anio = intval($_GET['anio'] ?? $currentYear);
$comercialFiltro = trim($_GET['comercial'] ?? '');
$estadoFiltro = trim($_GET['estado'] ?? 'todos');

if ($mes < 1 || $mes > 12) {
  $mes = $currentMonth;
}

if ($anio < 2000 || $anio > 2100) {
  $anio = $currentYear;
}

$estadosPermitidos = [
  'todos',
  'sin_presentar',
  'pendiente_admin',
  'validado',
  'con_diferencia',
  'rechazado'
];

if (!in_array($estadoFiltro, $estadosPermitidos, true)) {
  $estadoFiltro = 'todos';
}

$fechaImputacionExiste = cierresAdminColumnExists($conn, 'gastos', 'fecha_imputacion');

if ($fechaImputacionExiste) {
  $fechaPeriodo = "COALESCE(fecha_imputacion, fecha_ticket)";
} else {
  $fechaPeriodo = "fecha_ticket";
}

$cierresContabilizados = cierresAdminFetchCierresContabilizados($conn);

$comerciales = cierresAdminFetchAll(
  $conn,
  "SELECT DISTINCT comercial
   FROM users
   WHERE comercial IS NOT NULL
     AND comercial <> ''
   ORDER BY comercial ASC"
);

$whereUsuarios = "u.comercial IS NOT NULL AND u.comercial <> ''";
$paramsUsuarios = [];
$typesUsuarios = "";

if ($comercialFiltro !== '') {
  $whereUsuarios .= " AND u.comercial = ?";
  $paramsUsuarios[] = $comercialFiltro;
  $typesUsuarios .= "s";
}

$sqlUsuarios = "SELECT 
                  u.id,
                  u.username,
                  u.comercial,
                  u.email,
                  u.activo
                FROM users u
                WHERE $whereUsuarios
                ORDER BY u.comercial ASC";

$usuarios = cierresAdminFetchAll($conn, $sqlUsuarios, $typesUsuarios, $paramsUsuarios);

$sqlTotalesApp = "SELECT 
                    user_id,
                    COUNT(*) AS total_gastos,
                    COALESCE(SUM(COALESCE(importe_detectado, 0)), 0) AS total_importe
                  FROM gastos
                  WHERE deleted_at IS NULL
                    AND estado IN ('procesado', 'editado')
                    AND $fechaPeriodo IS NOT NULL
                    AND MONTH($fechaPeriodo) = ?
                    AND YEAR($fechaPeriodo) = ?
                  GROUP BY user_id
                  HAVING COUNT(*) > 0";

$totalesAppRows = cierresAdminFetchAll($conn, $sqlTotalesApp, "ii", [$mes, $anio]);

$totalesApp = [];

foreach ($totalesAppRows as $row) {
  $totalesApp[(int)$row['user_id']] = [
    'total_gastos' => (int)($row['total_gastos'] ?? 0),
    'total_importe' => (float)($row['total_importe'] ?? 0)
  ];
}

$sqlCierres = "SELECT *
               FROM cierres_mensuales
               WHERE mes = ?
                 AND anio = ?";

$paramsCierres = [$mes, $anio];
$typesCierres = "ii";

if ($comercialFiltro !== '') {
  $sqlCierres .= " AND comercial = ?";
  $paramsCierres[] = $comercialFiltro;
  $typesCierres .= "s";
}

$cierresRows = cierresAdminFetchAll($conn, $sqlCierres, $typesCierres, $paramsCierres);

$cierres = [];

foreach ($cierresRows as $row) {
  $cierres[(int)$row['user_id']] = $row;
}

$filas = [];

$totalConGastos = 0;
$totalSinPresentar = 0;
$totalPendientesAdmin = 0;
$totalValidados = 0;
$totalConDiferencia = 0;
$totalRechazados = 0;
$totalContabilizados = 0;
$usuariosConGastos = [];
$totalSinPresentarEfectivo = 0;
$totalPendientesAdminEfectivo = 0;
$totalValidadosEfectivo = 0;
$totalConDiferenciaEfectivo = 0;
$totalRechazadosEfectivo = 0;
$totalContabilizadosEfectivo = 0;

foreach ($usuarios as $usuario) {
  $userId = (int)$usuario['id'];

  if (!isset($totalesApp[$userId])) {
    continue;
  }

  $usuariosConGastos[$userId] = true;

  $totalApp = (float)$totalesApp[$userId]['total_importe'];
  $totalGastos = (int)$totalesApp[$userId]['total_gastos'];
  $cierre = $cierres[$userId] ?? null;
  $cierreId = $cierre ? (int)$cierre['id'] : 0;
  $contabilizado = $cierreId > 0 && !empty($cierresContabilizados[$cierreId]);

  $totalConGastos++;

  if (!$cierre) {
    $estadoTrabajo = 'sin_presentar';
    $totalSinPresentar++;
  } else {
    $estadoTrabajo = $cierre['estado'] ?? 'pendiente_admin';

    if ($estadoTrabajo === 'pendiente_admin') {
      $totalPendientesAdmin++;
    } elseif ($estadoTrabajo === 'validado') {
      $totalValidados++;
    } elseif ($estadoTrabajo === 'con_diferencia') {
      $totalConDiferencia++;
    } elseif ($estadoTrabajo === 'rechazado') {
      $totalRechazados++;
    }

    if ($contabilizado) {
      $totalContabilizados++;
    }
  }

  if ($estadoFiltro !== 'todos' && $estadoTrabajo !== $estadoFiltro) {
    continue;
  }

  $importeBanco = $cierre ? (float)$cierre['importe_banco'] : null;
  $diferenciaActual = $cierre ? ($totalApp - $importeBanco) : null;
  $diferenciaCero = $cierre ? cierresAdminDiferenciaEsCero($diferenciaActual) : false;

  $filas[] = [
    'usuario' => $usuario,
    'cierre' => $cierre,
    'estado_trabajo' => $estadoTrabajo,
    'total_app' => $totalApp,
    'total_gastos' => $totalGastos,
    'importe_banco' => $importeBanco,
    'diferencia' => $diferenciaActual,
    'diferencia_cero' => $diferenciaCero,
    'diferencia_guardada' => $cierre ? (float)$cierre['diferencia'] : null,
    'importe_app_guardado' => $cierre ? (float)$cierre['importe_app'] : null,
    'contabilizado' => $contabilizado
  ];
}


$filasEfectivo = [];
$totalEfectivoPeriodo = 0.0;
$totalKmPeriodo = 0.0;
$totalRegistrosEfectivo = 0;
$tablaCierresEfectivoOk = gastosUnificadosTableExists($conn, 'cierres_mensuales_efectivo');

foreach ($usuarios as $usuarioEfectivo) {
  $userIdEfectivo = (int)$usuarioEfectivo['id'];
  $resumenEfectivoUsuario = gastosUnificadosTotalEfectivo($conn, $userIdEfectivo, $mes, $anio);

  if ((int)$resumenEfectivoUsuario['total_registros'] <= 0) {
    continue;
  }

  $usuariosConGastos[$userIdEfectivo] = true;

  $cierreEfectivoUsuario = $tablaCierresEfectivoOk
    ? gastosUnificadosCierreEfectivo($conn, $userIdEfectivo, $mes, $anio)
    : null;
  $estadoTrabajoEfectivo = $cierreEfectivoUsuario
    ? (string)$cierreEfectivoUsuario['estado']
    : 'sin_presentar';
  $contabilizadoEfectivo = $cierreEfectivoUsuario
    ? gastosUnificadosCierreEfectivoContabilizado($conn, (int)$cierreEfectivoUsuario['id'])
    : false;

  if ($estadoTrabajoEfectivo === 'sin_presentar') {
    $totalSinPresentarEfectivo++;
  } elseif ($estadoTrabajoEfectivo === 'pendiente_admin') {
    $totalPendientesAdminEfectivo++;
  } elseif ($estadoTrabajoEfectivo === 'validado') {
    $totalValidadosEfectivo++;
  } elseif ($estadoTrabajoEfectivo === 'con_diferencia') {
    $totalConDiferenciaEfectivo++;
  } elseif ($estadoTrabajoEfectivo === 'rechazado') {
    $totalRechazadosEfectivo++;
  }

  if ($contabilizadoEfectivo) {
    $totalContabilizadosEfectivo++;
  }

  if ($estadoFiltro !== 'todos' && $estadoTrabajoEfectivo !== $estadoFiltro) {
    continue;
  }

  $importeDeclaradoEfectivo = $cierreEfectivoUsuario
    ? (float)$cierreEfectivoUsuario['importe_banco']
    : null;
  $diferenciaEfectivo = $cierreEfectivoUsuario
    ? round($importeDeclaradoEfectivo - (float)$resumenEfectivoUsuario['total_importe'], 2)
    : null;

  $filasEfectivo[] = [
    'usuario' => $usuarioEfectivo,
    'resumen' => $resumenEfectivoUsuario,
    'cierre' => $cierreEfectivoUsuario,
    'estado_trabajo' => $estadoTrabajoEfectivo,
    'contabilizado' => $contabilizadoEfectivo,
    'importe_declarado' => $importeDeclaradoEfectivo,
    'diferencia' => $diferenciaEfectivo
  ];

  $totalEfectivoPeriodo += (float)$resumenEfectivoUsuario['total_efectivo'];
  $totalKmPeriodo += (float)$resumenEfectivoUsuario['total_kilometraje'];
  $totalRegistrosEfectivo += (int)$resumenEfectivoUsuario['total_registros'];
}

$totalConGastos = count($usuariosConGastos);
$totalSinPresentar += $totalSinPresentarEfectivo;
$totalPendientesAdmin += $totalPendientesAdminEfectivo;
$totalValidados += $totalValidadosEfectivo;
$totalConDiferencia += $totalConDiferenciaEfectivo;
$totalRechazados += $totalRechazadosEfectivo;
$totalContabilizados += $totalContabilizadosEfectivo;

$periodoNombre = cierresAdminGetMonthName($mes) . ' ' . $anio;

$mensaje = appPublicMessage($_GET['msg'] ?? '');
$tipoMensaje = $_GET['type'] ?? 'success';

$returnUrl = "admin/cierres_mensuales.php?mes=" . urlencode((string)$mes) . "&anio=" . urlencode((string)$anio) . "&estado=" . urlencode($estadoFiltro);

if ($comercialFiltro !== '') {
  $returnUrl .= "&comercial=" . urlencode($comercialFiltro);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cierre mensual - Panel Admin</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="../css/admin.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/processing_overlay.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/admin_cierres.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/admin_cierres_kpis.css?v=<?php echo APP_VERSION; ?>">
  <style>
    .cierre-efectivo-summary {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 18px;
    }
    .cierre-efectivo-summary div {
      padding: 14px;
      border: 1px solid #dbe5ef;
      border-radius: 12px;
      background: #f8fafc;
    }
    .cierre-efectivo-summary span,
    .cierre-efectivo-summary strong { display: block; }
    .cierre-efectivo-summary span { color: #64748b; font-size: 12px; margin-bottom: 5px; }
    .cierre-efectivo-summary strong { color: #003366; font-size: 20px; }
    .cierre-efectivo-review {
      min-width: 220px;
      display: grid;
      gap: 7px;
    }
    .cierre-efectivo-review select,
    .cierre-efectivo-review textarea {
      width: 100%;
      min-width: 0;
    }
    .cierre-efectivo-review textarea { min-height: 64px; resize: vertical; }
    @media (max-width: 720px) {
      .cierre-efectivo-summary { grid-template-columns: 1fr; }
      .cierre-efectivo-review { min-width: 0; }
    }
  </style>
</head>

<body class="admin-body">
  <div class="admin-wrapper">

    <header class="admin-header">
      <div>
        <h1>Cierre mensual</h1>
        <p>Revisión administrativa de cierres mensuales del periodo <?php echo h($periodoNombre); ?>.</p>
      </div>

      <div class="top-actions">
        <a class="btn" href="index.php">Panel Admin</a>
        <a class="btn" href="../home.php">Inicio</a>
        <a class="btn" href="../logout.php">Cerrar sesión</a>
      </div>
    </header>

    <?php if ($mensaje !== ''): ?>
      <div class="message <?php echo $tipoMensaje === 'error' ? 'error' : 'success'; ?>">
        <?php echo h($mensaje); ?>
      </div>
    <?php endif; ?>

    <section class="panel">
      <form method="get" action="cierres_mensuales.php" class="filters">
        <div>
          <label for="mes">Mes</label>
          <select id="mes" name="mes">
            <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?php echo $m; ?>" <?php echo $mes === $m ? 'selected' : ''; ?>>
                <?php echo h(cierresAdminGetMonthName($m)); ?>
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
          <label for="estado">Estado</label>
          <select id="estado" name="estado">
            <option value="todos" <?php echo $estadoFiltro === 'todos' ? 'selected' : ''; ?>>Todos</option>
            <option value="sin_presentar" <?php echo $estadoFiltro === 'sin_presentar' ? 'selected' : ''; ?>>Sin presentar</option>
            <option value="pendiente_admin" <?php echo $estadoFiltro === 'pendiente_admin' ? 'selected' : ''; ?>>Pendiente admin</option>
            <option value="validado" <?php echo $estadoFiltro === 'validado' ? 'selected' : ''; ?>>Validado</option>
            <option value="con_diferencia" <?php echo $estadoFiltro === 'con_diferencia' ? 'selected' : ''; ?>>Con diferencia</option>
            <option value="rechazado" <?php echo $estadoFiltro === 'rechazado' ? 'selected' : ''; ?>>Rechazado</option>
          </select>
        </div>

        <div>
          <button class="btn primary" type="submit">Aplicar filtros</button>
        </div>
      </form>

      <p><small>
        Esta pantalla muestra comerciales con gastos en el periodo. Los cierres contabilizados ya no se pueden modificar ni reabrir.
      </small></p>
    </section>

    <section class="kpi-grid cierres-kpi-grid-compact">
      <article class="kpi-card">
        <span>Comerciales con gastos</span>
        <strong><?php echo (int)$totalConGastos; ?></strong>
        <small>Usuarios con VISA, Efectivo o Kilometraje</small>
      </article>

      <article class="kpi-card">
        <span>Sin presentar</span>
        <strong class="<?php echo $totalSinPresentar > 0 ? 'negative' : 'positive'; ?>">
          <?php echo (int)$totalSinPresentar; ?>
        </strong>
        <small>Cierres VISA y Efectivo/Kms pendientes de presentar</small>
      </article>

      <article class="kpi-card">
        <span>Pendientes validación</span>
        <strong class="<?php echo $totalPendientesAdmin > 0 ? 'negative' : 'positive'; ?>">
          <?php echo (int)$totalPendientesAdmin; ?>
        </strong>
        <small>Cierres VISA y Efectivo/Kms pendientes de revisión</small>
      </article>

      <article class="kpi-card">
        <span>Validados</span>
        <strong class="<?php echo $totalValidados > 0 ? 'positive' : ''; ?>">
          <?php echo (int)$totalValidados; ?>
        </strong>
        <small>Cierres VISA y Efectivo/Kms ya validados</small>
      </article>

      <article class="kpi-card">
        <span>Contabilizados</span>
        <strong class="<?php echo $totalContabilizados > 0 ? 'positive' : ''; ?>">
          <?php echo (int)$totalContabilizados; ?>
        </strong>
        <small>Cierres VISA y Efectivo/Kms contabilizados</small>
      </article>
    </section>

    <section class="panel">
      <h2 class="section-title">Cierres VISA</h2>

      <div class="table-wrap">
        <table class="admin-table-wide">
          <thead>
            <tr>
              <th>Comercial</th>
              <th>Usuario</th>
              <th>Gastos</th>
              <th>Total app</th>
              <th>Total banco</th>
              <th>Diferencia</th>
              <th>Estado</th>
              <th>Comentarios comercial</th>
              <th>Acción</th>
            </tr>
          </thead>

          <tbody>
            <?php if (count($filas) === 0): ?>
              <tr>
                <td colspan="9" class="muted">No hay cierres para los filtros seleccionados.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($filas as $fila): ?>
              <?php
                $usuario = $fila['usuario'];
                $cierre = $fila['cierre'];
                $estadoTrabajo = $fila['estado_trabajo'];
                $estadoTexto = cierresAdminEstadoTexto($estadoTrabajo);
                $estadoClass = cierresAdminEstadoClass($estadoTrabajo);
                $diferenciaRaw = $cierre ? number_format((float)$fila['diferencia'], 2, '.', '') : '';
                $diferenciaCero = !empty($fila['diferencia_cero']);
                $contabilizado = !empty($fila['contabilizado']);
              ?>

              <tr>
                <td><?php echo h($usuario['comercial']); ?></td>
                <td><?php echo h($usuario['username']); ?></td>
                <td><?php echo (int)$fila['total_gastos']; ?></td>
                <td><?php echo h(number_format((float)$fila['total_app'], 2, ',', '.')); ?> €</td>
                <td>
                  <?php if ($cierre): ?>
                    <?php echo h(number_format((float)$fila['importe_banco'], 2, ',', '.')); ?> €
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($cierre): ?>
                    <?php echo h(number_format((float)$fila['diferencia'], 2, ',', '.')); ?> €
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="cierre-status-mini <?php echo h($estadoClass); ?>">
                    <?php echo h($estadoTexto); ?>
                  </span>

                  <?php if ($contabilizado): ?>
                    <br>
                    <span class="cierre-status-mini validated">Contabilizado</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($cierre && trim((string)$cierre['comentarios_comercial']) !== ''): ?>
                    <?php echo nl2br(h($cierre['comentarios_comercial'])); ?>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($cierre && $contabilizado): ?>
                    <span class="muted">Bloqueado</span>
                  <?php elseif ($cierre): ?>
                    <button
                      type="button"
                      class="btn primary small cierre-review-button"
                      data-cierre-id="<?php echo (int)$cierre['id']; ?>"
                      data-comercial="<?php echo h($usuario['comercial']); ?>"
                      data-usuario="<?php echo h($usuario['username']); ?>"
                      data-total-app="<?php echo h(number_format((float)$fila['total_app'], 2, ',', '.')); ?> €"
                      data-total-banco="<?php echo h(number_format((float)$fila['importe_banco'], 2, ',', '.')); ?> €"
                      data-diferencia="<?php echo h(number_format((float)$fila['diferencia'], 2, ',', '.')); ?> €"
                      data-diferencia-raw="<?php echo h($diferenciaRaw); ?>"
                      data-diferencia-cero="<?php echo $diferenciaCero ? '1' : '0'; ?>"
                      data-estado="<?php echo h($cierre['estado']); ?>"
                      data-comentarios-admin="<?php echo h($cierre['comentarios_admin'] ?? ''); ?>"
                    >
                      Revisar
                    </button>
                  <?php else: ?>
                    <span class="muted">Esperando cierre</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="note">
        Al validar un cierre se enviará la información al sistema para notificar al comercial y a administración. Si un cierre validado pasa a enviado en el módulo de envíos, queda contabilizado y no podrá modificarse ni reabrirse. Para poder validar un cierre, la diferencia actual debe ser 0,00 €.
      </div>
    </section>

    <section class="panel" id="cierres-efectivo">
      <h2 class="section-title">Cierres de Efectivo y Kilometraje</h2>
      <p class="muted">
        Flujo independiente del cierre VISA. El comercial presenta el cierre, dirección lo revisa y contabilidad lo marca como enviado.
      </p>

      <?php if (!$tablaCierresEfectivoOk): ?>
        <div class="message error">
          Este apartado todavía no está disponible.
        </div>
      <?php else: ?>
        <div class="cierre-efectivo-summary">
          <div>
            <span>Efectivo del periodo</span>
            <strong><?php echo h(number_format($totalEfectivoPeriodo, 2, ',', '.')); ?> €</strong>
          </div>
          <div>
            <span>Kilometraje del periodo</span>
            <strong><?php echo h(number_format($totalKmPeriodo, 2, ',', '.')); ?> €</strong>
          </div>
          <div>
            <span>Registros incluidos</span>
            <strong><?php echo (int)$totalRegistrosEfectivo; ?></strong>
          </div>
        </div>

        <div class="table-wrap">
          <table class="admin-table-wide">
            <thead>
              <tr>
                <th>Comercial</th>
                <th>Registros</th>
                <th>Efectivo</th>
                <th>Kilometraje</th>
                <th>Total app</th>
                <th>Total declarado</th>
                <th>Diferencia</th>
                <th>Estado</th>
                <th>Revisión</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$filasEfectivo): ?>
                <tr><td colspan="9" class="muted">No hay registros de Efectivo o Kilometraje para los filtros seleccionados.</td></tr>
              <?php endif; ?>

              <?php foreach ($filasEfectivo as $filaEfectivo): ?>
                <?php
                  $uE = $filaEfectivo['usuario'];
                  $rE = $filaEfectivo['resumen'];
                  $cE = $filaEfectivo['cierre'];
                  $estadoE = $filaEfectivo['estado_trabajo'];
                  $contabE = !empty($filaEfectivo['contabilizado']);
                  $puedeModificarContabilizado = $contabE && (($_SESSION['role'] ?? '') === 'master');
                ?>
                <tr>
                  <td>
                    <strong><?php echo h($uE['comercial']); ?></strong><br>
                    <small><?php echo h($uE['username']); ?></small>
                  </td>
                  <td><?php echo (int)$rE['total_registros']; ?></td>
                  <td><?php echo h(number_format((float)$rE['total_efectivo'], 2, ',', '.')); ?> €</td>
                  <td><?php echo h(number_format((float)$rE['total_kilometraje'], 2, ',', '.')); ?> €</td>
                  <td><?php echo h(number_format((float)$rE['total_importe'], 2, ',', '.')); ?> €</td>
                  <td>
                    <?php echo $cE ? h(number_format((float)$filaEfectivo['importe_declarado'], 2, ',', '.')) . ' €' : '—'; ?>
                  </td>
                  <td>
                    <?php echo $cE ? h(number_format((float)$filaEfectivo['diferencia'], 2, ',', '.')) . ' €' : '—'; ?>
                  </td>
                  <td>
                    <span class="cierre-status-mini <?php echo h(cierresAdminEstadoClass($estadoE)); ?>">
                      <?php echo h(cierresAdminEstadoTexto($estadoE)); ?>
                    </span>
                    <?php if ($contabE): ?>
                      <br><span class="cierre-status-mini validated">Contabilizado</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!$cE): ?>
                      <span class="muted">Esperando cierre</span>
                    <?php elseif ($contabE && !$puedeModificarContabilizado): ?>
                      <span class="muted">Bloqueado</span>
                    <?php else: ?>
                      <form
                        method="post"
                        action="procesar_cierre_efectivo.php"
                        class="cierre-efectivo-review"
                        data-processing-overlay
                        data-processing-message="Estamos revisando el cierre de Efectivo y Kilometraje."
                      >
                        <input type="hidden" name="id" value="<?php echo (int)$cE['id']; ?>">
                        <select name="estado" required>
                          <option value="pendiente_admin" <?php echo $estadoE === 'pendiente_admin' ? 'selected' : ''; ?>>Pendiente admin</option>
                          <option value="validado" <?php echo $estadoE === 'validado' ? 'selected' : ''; ?>>Validado</option>
                          <option value="con_diferencia" <?php echo $estadoE === 'con_diferencia' ? 'selected' : ''; ?>>Con diferencia</option>
                          <option value="rechazado" <?php echo $estadoE === 'rechazado' ? 'selected' : ''; ?>>Rechazado</option>
                        </select>
                        <textarea name="comentarios_admin" placeholder="Comentarios de dirección"><?php echo h($cE['comentarios_admin'] ?? ''); ?></textarea>
                        <button class="btn primary small" type="submit">
                          <?php echo $puedeModificarContabilizado ? 'Modificar como Máster' : 'Guardar revisión'; ?>
                        </button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

  </div>

  <div class="cierre-admin-modal" id="cierreAdminModal" aria-hidden="true">
    <div class="cierre-admin-modal-backdrop" data-close-modal="1"></div>

    <div class="cierre-admin-modal-box" role="dialog" aria-modal="true" aria-labelledby="modalCierreTitle">
      <div class="cierre-admin-modal-header">
        <div>
          <h2 id="modalCierreTitle">Revisar cierre</h2>
          <p id="modalCierreSubtitle">Selecciona el resultado de la revisión administrativa.</p>
        </div>

        <button type="button" class="cierre-admin-modal-close" data-close-modal="1">×</button>
      </div>

      <div class="cierre-admin-modal-summary">
        <div>
          <span>Comercial</span>
          <strong id="modalComercial">—</strong>
        </div>

        <div>
          <span>Usuario</span>
          <strong id="modalUsuario">—</strong>
        </div>

        <div>
          <span>Total app</span>
          <strong id="modalTotalApp">—</strong>
        </div>

        <div>
          <span>Total banco</span>
          <strong id="modalTotalBanco">—</strong>
        </div>

        <div>
          <span>Diferencia</span>
          <strong id="modalDiferencia">—</strong>
        </div>
      </div>

      <div class="message error" id="modalValidadoWarning" hidden>
        No se puede pasar este cierre a Validado porque la diferencia actual no es 0,00 €. Revisa los gastos o marca el cierre como Con diferencia.
      </div>

      <form method="post" action="procesar_cierre_mensual.php" id="cierreAdminForm" data-processing-overlay data-processing-message="Estamos revisando el cierre. Espera unos segundos, por favor.">
        <input type="hidden" name="id" id="modalCierreId" value="">
        <input type="hidden" name="return" value="<?php echo h($returnUrl); ?>">

        <div class="form-group full">
          <label for="modalEstado">Estado</label>
          <select id="modalEstado" name="estado" required>
            <option value="pendiente_admin">Pendiente admin</option>
            <option value="validado">Validado</option>
            <option value="con_diferencia">Con diferencia</option>
            <option value="rechazado">Rechazado</option>
          </select>
        </div>

        <div class="form-group full">
          <label for="modalComentariosAdmin">Comentarios admin</label>
          <textarea id="modalComentariosAdmin" name="comentarios_admin" rows="4"></textarea>
        </div>

        <div class="cierre-admin-modal-actions">
          <button type="button" class="btn" data-close-modal="1">Cancelar</button>
          <button type="submit" class="btn primary" id="modalGuardarRevision">Guardar revisión</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const modal = document.getElementById('cierreAdminModal');
      const reviewButtons = document.querySelectorAll('.cierre-review-button');

      const modalCierreId = document.getElementById('modalCierreId');
      const modalComercial = document.getElementById('modalComercial');
      const modalUsuario = document.getElementById('modalUsuario');
      const modalTotalApp = document.getElementById('modalTotalApp');
      const modalTotalBanco = document.getElementById('modalTotalBanco');
      const modalDiferencia = document.getElementById('modalDiferencia');
      const modalEstado = document.getElementById('modalEstado');
      const modalComentariosAdmin = document.getElementById('modalComentariosAdmin');
      const modalValidadoWarning = document.getElementById('modalValidadoWarning');
      const cierreAdminForm = document.getElementById('cierreAdminForm');

      let diferenciaActualModal = 0;
      let diferenciaCeroModal = true;

      function getValidadoOption() {
        return modalEstado.querySelector('option[value="validado"]');
      }

      function bloquearValidadoSiHayDiferencia() {
        const validadoOption = getValidadoOption();

        if (!validadoOption) {
          return;
        }

        validadoOption.disabled = !diferenciaCeroModal;

        if (!diferenciaCeroModal && modalEstado.value === 'validado') {
          modalEstado.value = 'con_diferencia';
        }

        if (modalValidadoWarning) {
          modalValidadoWarning.hidden = diferenciaCeroModal;
        }
      }

      function validarEstadoAntesDeGuardar(event) {
        if (!diferenciaCeroModal && modalEstado.value === 'validado') {
          event.preventDefault();

          if (modalValidadoWarning) {
            modalValidadoWarning.hidden = false;
          }

          alert('No se puede validar el cierre mientras la diferencia no sea 0,00 €.');
        }
      }

      function openModal(button) {
        const diferenciaRaw = button.dataset.diferenciaRaw || '0';
        diferenciaActualModal = parseFloat(diferenciaRaw.replace(',', '.'));

        if (Number.isNaN(diferenciaActualModal)) {
          diferenciaActualModal = 0;
        }

        diferenciaCeroModal = button.dataset.diferenciaCero === '1' || Math.abs(diferenciaActualModal) < 0.005;

        modalCierreId.value = button.dataset.cierreId || '';
        modalComercial.textContent = button.dataset.comercial || '—';
        modalUsuario.textContent = button.dataset.usuario || '—';
        modalTotalApp.textContent = button.dataset.totalApp || '—';
        modalTotalBanco.textContent = button.dataset.totalBanco || '—';
        modalDiferencia.textContent = button.dataset.diferencia || '—';
        modalEstado.value = button.dataset.estado || 'pendiente_admin';
        modalComentariosAdmin.value = button.dataset.comentariosAdmin || '';

        bloquearValidadoSiHayDiferencia();

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
      }

      function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
      }

      reviewButtons.forEach(function (button) {
        button.addEventListener('click', function () {
          openModal(button);
        });
      });

      modalEstado.addEventListener('change', function () {
        bloquearValidadoSiHayDiferencia();
      });

      if (cierreAdminForm) {
        cierreAdminForm.addEventListener('submit', validarEstadoAntesDeGuardar);
      }

      document.querySelectorAll('[data-close-modal="1"]').forEach(function (item) {
        item.addEventListener('click', closeModal);
      });

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          closeModal();
        }
      });
    });
  </script>
  <script src="../js/processing_overlay.js?v=<?php echo APP_VERSION; ?>"></script>

<?php include __DIR__ . '/../includes/admin_chat_launcher.php'; ?>
</body>
</html>

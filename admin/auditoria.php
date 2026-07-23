<?php
require_once __DIR__ . "/../admin_guard.php";
requireMasterAccess();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auditoria.php";

function auditoriaAdminFetchAll($conn, $sql, $types = "", $params = []) {
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

function auditoriaAdminFetchOne($conn, $sql, $types = "", $params = []) {
  $rows = auditoriaAdminFetchAll($conn, $sql, $types, $params);
  return $rows[0] ?? null;
}


function auditoriaAdminSincronizarRevisionesPropias($conn) {
  if (!auditoriaTableExists($conn)) {
    return;
  }

  if (
    !auditoriaColumnExists($conn, 'estado_revision') ||
    !auditoriaColumnExists($conn, 'updated_at')
  ) {
    return;
  }

  date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');
  $now = date('Y-m-d H:i:s');

  $sql = "UPDATE auditoria_eventos
          SET estado_revision = 'revisado',
              updated_at = ?
          WHERE accion IN ('revision_evento_auditoria', 'revision_lote_eventos_auditoria')
            AND estado_revision = 'normal'
            AND estado_nuevo IN ('revisado', 'corregido', 'anulado')";

  $stmt = $conn->prepare($sql);

  if ($stmt) {
    $stmt->bind_param('s', $now);
    $stmt->execute();
  }
}

function auditoriaAdminTipoTexto($tipo) {
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

function auditoriaAdminTipoClass($tipo) {
  if ($tipo === 'cierre') return 'audit-cierre';
  if ($tipo === 'envio') return 'audit-envio';
  if ($tipo === 'usuario') return 'audit-usuario';
  if ($tipo === 'gasto') return 'audit-gasto';
  if ($tipo === 'seguridad') return 'audit-seguridad';
  if ($tipo === 'auditoria') return 'audit-auditoria';

  return 'audit-sistema';
}

function auditoriaAdminRevisionTexto($estado) {
  if ($estado === 'normal') return 'Normal';
  if ($estado === 'revisado') return 'Revisado';
  if ($estado === 'corregido') return 'Corregido';
  if ($estado === 'anulado') return 'Anulado';

  return 'Normal';
}

function auditoriaAdminBuildUrl($archivo, $params = []) {
  $limpios = [];

  foreach ($params as $key => $value) {
    if ($value !== '' && $value !== null) {
      $limpios[$key] = $value;
    }
  }

  return $archivo . (count($limpios) > 0 ? '?' . http_build_query($limpios) : '');
}

function auditoriaAdminBuildWhere(&$types, &$params, $filtros) {
  $where = "1 = 1";

  if ($filtros['tipo'] !== 'todos') {
    $where .= " AND tipo_evento = ?";
    $params[] = $filtros['tipo'];
    $types .= "s";
  }

  if ($filtros['entidad'] !== 'todos') {
    $where .= " AND entidad = ?";
    $params[] = $filtros['entidad'];
    $types .= "s";
  }

  if ($filtros['revision'] !== 'todos') {
    $where .= " AND estado_revision = ?";
    $params[] = $filtros['revision'];
    $types .= "s";
  }

  if ($filtros['comercial'] !== '') {
    $where .= " AND comercial = ?";
    $params[] = $filtros['comercial'];
    $types .= "s";
  }

  if ($filtros['desde'] !== '') {
    $where .= " AND created_at >= ?";
    $params[] = $filtros['desde'] . " 00:00:00";
    $types .= "s";
  }

  if ($filtros['hasta'] !== '') {
    $where .= " AND created_at <= ?";
    $params[] = $filtros['hasta'] . " 23:59:59";
    $types .= "s";
  }

  if ($filtros['buscar'] !== '') {
    $where .= " AND (
                  accion LIKE ?
                  OR descripcion LIKE ?
                  OR username LIKE ?
                  OR comercial LIKE ?
                  OR estado_anterior LIKE ?
                  OR estado_nuevo LIKE ?
                  OR notas_revision LIKE ?
                  OR entidad LIKE ?
                )";

    $like = "%" . $filtros['buscar'] . "%";
    for ($i = 0; $i < 8; $i++) {
      $params[] = $like;
      $types .= "s";
    }
  }

  return $where;
}

$tablaExiste = auditoriaTableExists($conn);

if ($tablaExiste) {
  auditoriaAdminSincronizarRevisionesPropias($conn);
}

$tipoFiltro = trim($_GET['tipo'] ?? 'todos');
$entidadFiltro = trim($_GET['entidad'] ?? 'todos');
$revisionFiltro = trim($_GET['revision'] ?? 'todos');
$buscar = trim($_GET['buscar'] ?? '');
$comercialFiltro = trim($_GET['comercial'] ?? '');
$fechaDesde = trim($_GET['desde'] ?? '');
$fechaHasta = trim($_GET['hasta'] ?? '');
$pagina = max(1, intval($_GET['pagina'] ?? 1));
$porPagina = intval($_GET['por_pagina'] ?? 50);

$tiposPermitidos = ['todos', 'cierre', 'envio', 'usuario', 'gasto', 'seguridad', 'sistema', 'auditoria'];
$entidadesPermitidas = ['todos', 'cierre', 'envio_integracion', 'usuario', 'gasto', 'tickets_pdf', 'sistema', 'auditoria'];
$revisionesPermitidas = ['todos', 'normal', 'revisado', 'corregido', 'anulado'];
$porPaginaPermitidos = [25, 50, 100, 200];

if (!in_array($tipoFiltro, $tiposPermitidos, true)) {
  $tipoFiltro = 'todos';
}

if (!in_array($entidadFiltro, $entidadesPermitidas, true)) {
  $entidadFiltro = 'todos';
}

if (!in_array($revisionFiltro, $revisionesPermitidas, true)) {
  $revisionFiltro = 'todos';
}

if (!in_array($porPagina, $porPaginaPermitidos, true)) {
  $porPagina = 50;
}

$filtros = [
  'tipo' => $tipoFiltro,
  'entidad' => $entidadFiltro,
  'revision' => $revisionFiltro,
  'desde' => $fechaDesde,
  'hasta' => $fechaHasta,
  'buscar' => $buscar,
  'comercial' => $comercialFiltro,
  'por_pagina' => $porPagina
];

$eventos = [];
$totalEventos = 0;
$totalCierres = 0;
$totalEnvios = 0;
$totalUsuarios = 0;
$totalSeguridad = 0;
$totalPendientesRevision = 0;
$totalFiltrado = 0;
$totalPaginas = 1;

$comercialesFiltro = [];

if ($tablaExiste) {
  $sqlKpis = "SELECT tipo_evento, COUNT(*) AS total
              FROM auditoria_eventos
              GROUP BY tipo_evento";

  $kpis = auditoriaAdminFetchAll($conn, $sqlKpis);

  foreach ($kpis as $kpi) {
    $total = (int)($kpi['total'] ?? 0);
    $tipo = $kpi['tipo_evento'] ?? '';

    $totalEventos += $total;

    if ($tipo === 'cierre') {
      $totalCierres = $total;
    } elseif ($tipo === 'envio') {
      $totalEnvios = $total;
    } elseif ($tipo === 'usuario') {
      $totalUsuarios = $total;
    } elseif ($tipo === 'seguridad') {
      $totalSeguridad = $total;
    }
  }

  $sqlPendientesRevision = "SELECT COUNT(*) AS total
                            FROM auditoria_eventos
                            WHERE estado_revision = 'normal'";

  $pendientesRows = auditoriaAdminFetchAll($conn, $sqlPendientesRevision);

  if (!empty($pendientesRows[0])) {
    $totalPendientesRevision = (int)($pendientesRows[0]['total'] ?? 0);
  }

  $comercialesFiltro = auditoriaAdminFetchAll(
    $conn,
    "SELECT DISTINCT comercial FROM users WHERE comercial IS NOT NULL AND comercial <> '' ORDER BY comercial ASC LIMIT 500"
  );

  $types = "";
  $params = [];
  $where = auditoriaAdminBuildWhere($types, $params, $filtros);

  $sqlCount = "SELECT COUNT(*) AS total FROM auditoria_eventos WHERE $where";
  $rowCount = auditoriaAdminFetchOne($conn, $sqlCount, $types, $params);
  $totalFiltrado = (int)($rowCount['total'] ?? 0);
  $totalPaginas = max(1, (int)ceil($totalFiltrado / $porPagina));

  if ($pagina > $totalPaginas) {
    $pagina = $totalPaginas;
  }

  $offset = ($pagina - 1) * $porPagina;

  $sqlEventos = "SELECT *
                 FROM auditoria_eventos
                 WHERE $where
                 ORDER BY created_at DESC, id DESC
                 LIMIT ? OFFSET ?";

  $paramsListado = $params;
  $typesListado = $types . "ii";
  $paramsListado[] = $porPagina;
  $paramsListado[] = $offset;

  $eventos = auditoriaAdminFetchAll($conn, $sqlEventos, $typesListado, $paramsListado);
}

$mensaje = appPublicMessage($_GET['msg'] ?? '');
$tipoMensaje = $_GET['type'] ?? 'success';

$urlParams = $filtros;
$urlParams['pagina'] = $pagina;

$urlCsv = auditoriaAdminBuildUrl('exportar_auditoria_csv.php', $filtros);
$urlExcel = auditoriaAdminBuildUrl('exportar_auditoria_excel.php', $filtros);

$paramsAnterior = $urlParams;
$paramsAnterior['pagina'] = max(1, $pagina - 1);

$paramsSiguiente = $urlParams;
$paramsSiguiente['pagina'] = min($totalPaginas, $pagina + 1);

$urlAnterior = auditoriaAdminBuildUrl('auditoria.php', $paramsAnterior);
$urlSiguiente = auditoriaAdminBuildUrl('auditoria.php', $paramsSiguiente);
$returnUrlActual = 'auditoria.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Auditoría - Panel Admin</title>

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
    .audit-export-button {
      background: linear-gradient(135deg, #b7f7cf, #8ef0b6) !important;
      color: #004c46 !important;
      border: 1px solid rgba(183, 247, 207, 0.85) !important;
      box-shadow: 0 8px 18px rgba(142, 240, 182, 0.22) !important;
      font-weight: 800 !important;
    }

    .audit-export-button:hover {
      background: linear-gradient(135deg, #a8f3c5, #7ce9a9) !important;
      color: #003f3a !important;
      transform: translateY(-1px);
    }

    .audit-export-button:visited,
    .audit-export-button:active,
    .audit-export-button:focus {
      color: #004c46 !important;
    }

    .audit-review-button {
      background: rgba(255, 255, 255, 0.92) !important;
      color: #003366 !important;
      border: 1px solid rgba(255, 255, 255, 0.75) !important;
      font-weight: 800 !important;
      text-decoration: none !important;
      white-space: nowrap !important;
    }

    .audit-review-button:hover {
      background: #ffffff !important;
      color: #002244 !important;
    }

    .audit-actions-cell {
      display: flex !important;
      align-items: center !important;
      gap: 10px !important;
      white-space: nowrap !important;
    }


    .audit-events-table {
      width: 100% !important;
      min-width: 0 !important;
      table-layout: fixed !important;
      font-size: 12px !important;
    }

    .audit-events-table th,
    .audit-events-table td {
      padding: 10px 8px !important;
      vertical-align: middle !important;
      overflow: hidden !important;
      text-overflow: ellipsis !important;
    }

    .audit-events-table th:nth-child(1),
    .audit-events-table td:nth-child(1) { width: 48px !important; }

    .audit-events-table th:nth-child(2),
    .audit-events-table td:nth-child(2) { width: 96px !important; }

    .audit-events-table th:nth-child(3),
    .audit-events-table td:nth-child(3) { width: 92px !important; }

    .audit-events-table th:nth-child(4),
    .audit-events-table td:nth-child(4) { width: 100px !important; }

    .audit-events-table th:nth-child(5),
    .audit-events-table td:nth-child(5) { width: 130px !important; }

    .audit-events-table th:nth-child(6),
    .audit-events-table td:nth-child(6) { width: auto !important; }

    .audit-events-table th:nth-child(7),
    .audit-events-table td:nth-child(7) { width: 130px !important; }

    .audit-events-table th:nth-child(8),
    .audit-events-table td:nth-child(8) { width: 120px !important; }

    .audit-events-table th:nth-child(9),
    .audit-events-table td:nth-child(9) { width: 104px !important; }

    .audit-events-table th:nth-child(10),
    .audit-events-table td:nth-child(10) { width: 154px !important; }

    .audit-events-table .audit-description-cell {
      white-space: normal !important;
      line-height: 1.3 !important;
      max-height: 42px !important;
    }

    .audit-events-table .audit-change-cell {
      white-space: normal !important;
      line-height: 1.25 !important;
    }

    .audit-events-table .audit-actions-cell .btn {
      padding: 7px 9px !important;
      min-width: 58px !important;
      text-align: center !important;
    }


    .audit-events-table th:nth-child(10),
    .audit-events-table td:nth-child(10) {
      width: 118px !important;
      min-width: 118px !important;
      max-width: 118px !important;
      overflow: visible !important;
      text-overflow: clip !important;
    }

    .audit-events-table td:nth-child(10) {
      padding-left: 6px !important;
      padding-right: 6px !important;
    }

    .audit-events-table .audit-actions-cell {
      display: flex !important;
      flex-direction: column !important;
      align-items: stretch !important;
      justify-content: center !important;
      gap: 6px !important;
      white-space: normal !important;
      width: 100% !important;
    }

    .audit-events-table .audit-actions-cell .btn {
      width: 100% !important;
      min-width: 0 !important;
      padding: 6px 5px !important;
      font-size: 11px !important;
      line-height: 1.1 !important;
      box-sizing: border-box !important;
    }


    .audit-bulk-actions {
      display: grid !important;
      grid-template-columns: minmax(170px, 220px) 1fr auto !important;
      gap: 12px !important;
      align-items: end !important;
      margin: 14px 0 16px 0 !important;
      padding: 14px !important;
      border-radius: 18px !important;
      background: rgba(255, 255, 255, 0.08) !important;
      border: 1px solid rgba(255, 255, 255, 0.14) !important;
    }

    .audit-bulk-actions label {
      display: block !important;
      margin-bottom: 6px !important;
      font-weight: 800 !important;
      color: rgba(255, 255, 255, 0.88) !important;
    }

    .audit-bulk-actions select,
    .audit-bulk-actions input {
      width: 100% !important;
    }

    .audit-check-cell {
      text-align: center !important;
      overflow: visible !important;
      text-overflow: clip !important;
    }

    .audit-check-cell input[type="checkbox"] {
      width: 18px !important;
      height: 18px !important;
      cursor: pointer !important;
    }

    .audit-events-table th:nth-child(1),
    .audit-events-table td:nth-child(1) { width: 34px !important; min-width: 34px !important; max-width: 34px !important; }

    .audit-events-table th:nth-child(2),
    .audit-events-table td:nth-child(2) { width: 42px !important; }

    .audit-events-table th:nth-child(3),
    .audit-events-table td:nth-child(3) { width: 92px !important; }

    .audit-events-table th:nth-child(4),
    .audit-events-table td:nth-child(4) { width: 88px !important; }

    .audit-events-table th:nth-child(5),
    .audit-events-table td:nth-child(5) { width: 96px !important; }

    .audit-events-table th:nth-child(6),
    .audit-events-table td:nth-child(6) { width: 120px !important; }

    .audit-events-table th:nth-child(7),
    .audit-events-table td:nth-child(7) { width: auto !important; }

    .audit-events-table th:nth-child(8),
    .audit-events-table td:nth-child(8) { width: 118px !important; }

    .audit-events-table th:nth-child(9),
    .audit-events-table td:nth-child(9) { width: 108px !important; }

    .audit-events-table th:nth-child(10),
    .audit-events-table td:nth-child(10) { width: 88px !important; }

    .audit-events-table th:nth-child(11),
    .audit-events-table td:nth-child(11) {
      width: 112px !important;
      min-width: 112px !important;
      max-width: 112px !important;
      overflow: visible !important;
      text-overflow: clip !important;
    }

    .audit-events-table td:nth-child(11) {
      padding-left: 6px !important;
      padding-right: 6px !important;
    }

    .audit-events-table td:nth-child(11) .audit-actions-cell {
      display: flex !important;
      flex-direction: column !important;
      align-items: stretch !important;
      justify-content: center !important;
      gap: 6px !important;
      white-space: normal !important;
      width: 100% !important;
    }

    .audit-events-table td:nth-child(11) .audit-actions-cell .btn {
      width: 100% !important;
      min-width: 0 !important;
      padding: 6px 5px !important;
      font-size: 11px !important;
      line-height: 1.1 !important;
      box-sizing: border-box !important;
    }

    @media (max-width: 900px) {
      .audit-bulk-actions {
        grid-template-columns: 1fr !important;
      }
    }
  </style>
</head>

<body class="admin-body">
  <div class="admin-wrapper">

    <header class="admin-header">
      <div>
        <h1>Auditoría</h1>
        <p>Registro de acciones importantes realizadas dentro del sistema.</p>
      </div>

      <div class="top-actions">
        <a class="btn primary audit-export-button" href="<?php echo h($urlExcel); ?>">Exportar Excel</a>
        <a class="btn primary audit-export-button" href="<?php echo h($urlCsv); ?>">Exportar CSV</a>
        <a class="btn" href="centro_control.php">Centro de control</a>
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

    <?php if (!$tablaExiste): ?>
      <section class="panel">
        <div class="message error">
          Este apartado todavía no está disponible.
        </div>
      </section>
    <?php endif; ?>

    <section class="kpi-grid auditoria-kpi-grid">
      <article class="kpi-card">
        <span>Total eventos</span>
        <strong><?php echo (int)$totalEventos; ?></strong>
        <small>Registros de auditoría</small>
      </article>

      <article class="kpi-card">
        <span>Resultado filtro</span>
        <strong><?php echo (int)$totalFiltrado; ?></strong>
        <small>Coincidencias actuales</small>
      </article>

      <article class="kpi-card">
        <span>Envíos</span>
        <strong><?php echo (int)$totalEnvios; ?></strong>
        <small>Cambios de integración</small>
      </article>

      <article class="kpi-card">
        <span>Seguridad</span>
        <strong><?php echo (int)$totalSeguridad; ?></strong>
        <small>Bloqueos o acciones sensibles</small>
      </article>

      <article class="kpi-card">
        <span>Pendientes revisión</span>
        <strong><?php echo (int)$totalPendientesRevision; ?></strong>
        <small>Eventos sin revisar</small>
      </article>
    </section>

    <section class="panel">
      <form method="get" action="auditoria.php" class="filters">
        <div>
          <label for="tipo">Tipo</label>
          <select id="tipo" name="tipo">
            <option value="todos" <?php echo $tipoFiltro === 'todos' ? 'selected' : ''; ?>>Todos</option>
            <option value="cierre" <?php echo $tipoFiltro === 'cierre' ? 'selected' : ''; ?>>Cierre</option>
            <option value="envio" <?php echo $tipoFiltro === 'envio' ? 'selected' : ''; ?>>Envío</option>
            <option value="usuario" <?php echo $tipoFiltro === 'usuario' ? 'selected' : ''; ?>>Usuario</option>
            <option value="gasto" <?php echo $tipoFiltro === 'gasto' ? 'selected' : ''; ?>>Gasto</option>
            <option value="seguridad" <?php echo $tipoFiltro === 'seguridad' ? 'selected' : ''; ?>>Seguridad</option>
            <option value="sistema" <?php echo $tipoFiltro === 'sistema' ? 'selected' : ''; ?>>Sistema</option>
            <option value="auditoria" <?php echo $tipoFiltro === 'auditoria' ? 'selected' : ''; ?>>Auditoría</option>
          </select>
        </div>

        <div>
          <label for="entidad">Entidad</label>
          <select id="entidad" name="entidad">
            <option value="todos" <?php echo $entidadFiltro === 'todos' ? 'selected' : ''; ?>>Todas</option>
            <option value="cierre" <?php echo $entidadFiltro === 'cierre' ? 'selected' : ''; ?>>Cierre</option>
            <option value="envio_integracion" <?php echo $entidadFiltro === 'envio_integracion' ? 'selected' : ''; ?>>Envío integración</option>
            <option value="usuario" <?php echo $entidadFiltro === 'usuario' ? 'selected' : ''; ?>>Usuario</option>
            <option value="gasto" <?php echo $entidadFiltro === 'gasto' ? 'selected' : ''; ?>>Gasto</option>
            <option value="tickets_pdf" <?php echo $entidadFiltro === 'tickets_pdf' ? 'selected' : ''; ?>>Tickets PDF</option>
            <option value="sistema" <?php echo $entidadFiltro === 'sistema' ? 'selected' : ''; ?>>Sistema</option>
            <option value="auditoria" <?php echo $entidadFiltro === 'auditoria' ? 'selected' : ''; ?>>Auditoría</option>
          </select>
        </div>

        <div>
          <label for="revision">Revisión</label>
          <select id="revision" name="revision">
            <option value="todos" <?php echo $revisionFiltro === 'todos' ? 'selected' : ''; ?>>Todas</option>
            <option value="normal" <?php echo $revisionFiltro === 'normal' ? 'selected' : ''; ?>>Normal</option>
            <option value="revisado" <?php echo $revisionFiltro === 'revisado' ? 'selected' : ''; ?>>Revisado</option>
            <option value="corregido" <?php echo $revisionFiltro === 'corregido' ? 'selected' : ''; ?>>Corregido</option>
            <option value="anulado" <?php echo $revisionFiltro === 'anulado' ? 'selected' : ''; ?>>Anulado</option>
          </select>
        </div>

        <div>
          <label for="comercial">Comercial</label>
          <select id="comercial" name="comercial">
            <option value="" <?php echo $comercialFiltro === '' ? 'selected' : ''; ?>>Todos</option>
            <?php foreach ($comercialesFiltro as $item): ?>
              <?php $comercialOption = $item['comercial'] ?? ''; ?>
              <?php if ($comercialOption !== ''): ?>
                <option value="<?php echo h($comercialOption); ?>" <?php echo $comercialFiltro === $comercialOption ? 'selected' : ''; ?>><?php echo h($comercialOption); ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label for="desde">Desde</label>
          <input type="date" id="desde" name="desde" value="<?php echo h($fechaDesde); ?>">
        </div>

        <div>
          <label for="hasta">Hasta</label>
          <input type="date" id="hasta" name="hasta" value="<?php echo h($fechaHasta); ?>">
        </div>

        <div>
          <label for="buscar">Buscar</label>
          <input type="text" id="buscar" name="buscar" value="<?php echo h($buscar); ?>" placeholder="Acción, usuario, comercial...">
        </div>

        <div>
          <label for="por_pagina">Registros</label>
          <select id="por_pagina" name="por_pagina">
            <option value="25" <?php echo $porPagina === 25 ? 'selected' : ''; ?>>25</option>
            <option value="50" <?php echo $porPagina === 50 ? 'selected' : ''; ?>>50</option>
            <option value="100" <?php echo $porPagina === 100 ? 'selected' : ''; ?>>100</option>
            <option value="200" <?php echo $porPagina === 200 ? 'selected' : ''; ?>>200</option>
          </select>
        </div>

        <div>
          <button class="btn primary" type="submit">Aplicar filtros</button>
          <a class="btn" href="auditoria.php">Limpiar</a>
        </div>
      </form>
    </section>

    <section class="panel">
      <h2 class="section-title">Eventos</h2>
      <p class="muted">Página <?php echo (int)$pagina; ?> de <?php echo (int)$totalPaginas; ?> · <?php echo (int)$totalFiltrado; ?> registros encontrados.</p>

      <form method="POST" action="procesar_auditoria_lote.php" id="auditBulkForm">
        <input type="hidden" name="return_url" value="<?php echo h($returnUrlActual); ?>">

        <div class="audit-bulk-actions">
          <div>
            <label for="estado_revision_lote">Aplicar revisión a seleccionados</label>
            <select id="estado_revision_lote" name="estado_revision_lote" required>
              <option value="revisado">Revisado</option>
              <option value="corregido">Corregido</option>
              <option value="anulado">Anulado</option>
              <option value="normal">Normal</option>
            </select>
          </div>

          <div>
            <label for="notas_revision_lote">Nota común</label>
            <input type="text" id="notas_revision_lote" name="notas_revision_lote" placeholder="Nota interna opcional para todos los registros seleccionados">
          </div>

          <div>
            <button class="btn primary" type="submit" onclick="return confirm('¿Aplicar este cambio a todos los eventos seleccionados?');">Aplicar cambio</button>
          </div>
        </div>

        <div class="table-wrap">
          <table class="admin-table-wide audit-events-table">
            <thead>
              <tr>
                <th class="audit-check-cell"><input type="checkbox" id="auditSelectAll" title="Seleccionar todos"></th>
                <th>ID</th>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Entidad</th>
                <th>Acción</th>
                <th>Descripción</th>
                <th>Comercial</th>
                <th>Cambio</th>
                <th>Revisión</th>
                <th>Acciones</th>
              </tr>
            </thead>

          <tbody>
            <?php if (!$tablaExiste): ?>
              <tr>
                <td colspan="11" class="muted">Pendiente de crear la tabla.</td>
              </tr>
            <?php elseif (count($eventos) === 0): ?>
              <tr>
                <td colspan="11" class="muted">No hay eventos para los filtros seleccionados.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($eventos as $evento): ?>
              <?php
                $tipo = $evento['tipo_evento'] ?? 'sistema';
                $tipoClass = auditoriaAdminTipoClass($tipo);
                $estadoRevision = $evento['estado_revision'] ?? 'normal';
                $detalleUrl = 'detalle_auditoria.php?id=' . urlencode((string)((int)$evento['id']));
                $editUrl = 'editar_auditoria.php?id=' . urlencode((string)((int)$evento['id']));
              ?>

              <tr>
                <td class="audit-check-cell"><input type="checkbox" class="audit-row-check" name="ids[]" value="<?php echo (int)$evento['id']; ?>"></td>
                <td><?php echo (int)$evento['id']; ?></td>
                <td><?php echo h(formatFechaWeb($evento['created_at'] ?? '', true)); ?></td>
                <td>
                  <span class="audit-pill <?php echo h($tipoClass); ?>">
                    <?php echo h(auditoriaAdminTipoTexto($tipo)); ?>
                  </span>
                </td>
                <td>
                  <?php echo h($evento['entidad'] ?? '—'); ?>
                  <?php if (!empty($evento['entidad_id'])): ?>
                    #<?php echo (int)$evento['entidad_id']; ?>
                  <?php endif; ?>
                </td>
                <td><?php echo h(appPlainTechnicalText($evento['accion'] ?? '')); ?></td>
                <td class="audit-description-cell" title="<?php echo h(appPlainTechnicalText($evento['descripcion'] ?? '')); ?>">
                  <?php if (!empty($evento['descripcion'])): ?>
                    <?php echo h(appPlainTechnicalText($evento['descripcion'])); ?>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($evento['comercial'])): ?>
                    <?php echo h($evento['comercial']); ?>
                  <?php elseif (!empty($evento['username'])): ?>
                    <?php echo h($evento['username']); ?>
                  <?php else: ?>
                    <span class="muted">Sistema</span>
                  <?php endif; ?>
                </td>
                <td class="audit-change-cell">
                  <strong><?php echo h(!empty($evento['estado_anterior']) ? appPlainTechnicalText($evento['estado_anterior']) : '—'); ?></strong><br>
                  <span class="muted">→</span> <?php echo h(!empty($evento['estado_nuevo']) ? appPlainTechnicalText($evento['estado_nuevo']) : '—'); ?>
                </td>
                <td>
                  <span class="audit-revision <?php echo h('revision-' . $estadoRevision); ?>">
                    <?php echo h(auditoriaAdminRevisionTexto($estadoRevision)); ?>
                  </span>
                </td>
                <td>
                  <div class="audit-actions-cell">
                    <a class="btn small audit-review-button" href="<?php echo h($detalleUrl); ?>" target="_self" rel="nofollow">Ver</a>
                    <a class="btn small audit-review-button" href="<?php echo h($editUrl); ?>" target="_self" rel="nofollow">Revisar</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </form>

      <div class="form-footer">
        <?php if ($pagina > 1): ?>
          <a class="btn" href="<?php echo h($urlAnterior); ?>">Anterior</a>
        <?php else: ?>
          <span class="btn disabled">Anterior</span>
        <?php endif; ?>

        <span class="btn">Página <?php echo (int)$pagina; ?> / <?php echo (int)$totalPaginas; ?></span>

        <?php if ($pagina < $totalPaginas): ?>
          <a class="btn" href="<?php echo h($urlSiguiente); ?>">Siguiente</a>
        <?php else: ?>
          <span class="btn disabled">Siguiente</span>
        <?php endif; ?>
      </div>

      <div class="note">
        Los eventos de auditoría no se eliminan. Si un registro necesita revisión futura, se marca como revisado, corregido o anulado, manteniendo siempre la trazabilidad.
      </div>
    </section>

  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var selectAll = document.getElementById('auditSelectAll');
      var checks = Array.prototype.slice.call(document.querySelectorAll('.audit-row-check'));
      var form = document.getElementById('auditBulkForm');

      if (selectAll) {
        selectAll.addEventListener('change', function () {
          checks.forEach(function (check) {
            check.checked = selectAll.checked;
          });
        });
      }

      checks.forEach(function (check) {
        check.addEventListener('change', function () {
          if (!selectAll) return;
          selectAll.checked = checks.length > 0 && checks.every(function (item) { return item.checked; });
        });
      });

      if (form) {
        form.addEventListener('submit', function (event) {
          var selected = checks.filter(function (check) { return check.checked; }).length;

          if (selected === 0) {
            event.preventDefault();
            alert('Selecciona al menos un registro de auditoría.');
          }
        });
      }
    });
  </script>

<?php include __DIR__ . '/../includes/admin_chat_launcher.php'; ?>
</body>
</html>

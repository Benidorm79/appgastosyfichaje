<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/integraciones.php";
require_once __DIR__ . "/../includes/gastos_unificados.php";

function enviosAdminFetchAll($conn, $sql, $types = "", $params = []) {
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

function enviosAdminEstadoTexto($estado) {
  if ($estado === 'pendiente') {
    return 'Pendiente';
  }

  if ($estado === 'enviado') {
    return 'Enviado';
  }

  if ($estado === 'error') {
    return 'Error';
  }

  if ($estado === 'omitido') {
    return 'Omitido';
  }

  return ucfirst((string)$estado);
}

function enviosAdminEstadoClass($estado) {
  if ($estado === 'pendiente') {
    return 'pending';
  }

  if ($estado === 'enviado') {
    return 'validated';
  }

  if ($estado === 'error') {
    return 'rejected';
  }

  if ($estado === 'omitido') {
    return 'missing';
  }

  return 'pending';
}

function enviosAdminDestinoTexto($destino) {
  $map = [
    'email' => 'Email',
    'make' => 'Automatización',
    'a3' => 'A3',
    'erp' => 'ERP',
    'verifactu' => 'VERI*FACTU',
    'contabilidad' => 'Contabilidad',
    'otro' => 'Otro'
  ];

  return $map[$destino] ?? ucfirst((string)$destino);
}

function enviosAdminColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);

  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

  return $result && $result->num_rows > 0;
}

$tablaExiste = integracionesTableExists($conn);
$tieneSistemaExterno = $tablaExiste ? enviosAdminColumnExists($conn, 'envios_integraciones', 'sistema_externo') : false;
$tieneIdExterno = $tablaExiste ? enviosAdminColumnExists($conn, 'envios_integraciones', 'id_externo') : false;
$tieneTipoCierre = $tablaExiste ? integracionesEnsureTipoCierreColumn($conn) : false;

/* Recupera cierres de Efectivo/Kms ya validados que todavía no tengan envío contable. */
if ($tablaExiste && $tieneTipoCierre && gastosUnificadosTableExists($conn, 'cierres_mensuales_efectivo')) {
  $sqlPendientesEfectivo = "SELECT c.* FROM cierres_mensuales_efectivo c
    LEFT JOIN envios_integraciones e ON e.entidad='cierre' AND e.entidad_id=c.id AND e.tipo_cierre='efectivo' AND e.estado IN ('pendiente','enviado')
    WHERE c.estado='validado' AND e.id IS NULL";
  $resPendientesEfectivo = $conn->query($sqlPendientesEfectivo);
  if ($resPendientesEfectivo) {
    while ($cierreEf = $resPendientesEfectivo->fetch_assoc()) {
      $periodKeyEf = (int)$cierreEf['user_id'] . '_' . str_pad((string)$cierreEf['mes'], 2, '0', STR_PAD_LEFT) . '_' . (int)$cierreEf['anio'] . '_efectivo';
      integracionesRegistrar($conn, [
        'tipo_destino' => 'contabilidad',
        'entidad' => 'cierre',
        'entidad_id' => (int)$cierreEf['id'],
        'tipo_cierre' => 'efectivo',
        'referencia' => 'CIERRE-EFECTIVO-' . $periodKeyEf,
        'descripcion' => 'Cierre de Efectivo y Kilometraje validado de ' . $cierreEf['comercial'] . ' - ' . str_pad((string)$cierreEf['mes'], 2, '0', STR_PAD_LEFT) . '/' . (int)$cierreEf['anio'],
        'estado' => 'pendiente',
        'payload' => ['tipo'=>'cierre_mensual_validado','tipo_cierre'=>'efectivo','cierre_id'=>(int)$cierreEf['id']],
        'creado_por' => (int)($cierreEf['revisado_por'] ?? 0)
      ]);
    }
  }
}

$estadoFiltro = trim($_GET['estado'] ?? 'todos');
$destinoFiltro = trim($_GET['destino'] ?? 'todos');
$entidadFiltro = trim($_GET['entidad'] ?? 'todos');
$buscar = trim($_GET['buscar'] ?? '');

$estadosPermitidos = ['todos', 'pendiente', 'enviado', 'error', 'omitido'];
$destinosPermitidos = ['todos', 'email', 'make', 'a3', 'erp', 'verifactu', 'contabilidad', 'otro'];
$entidadesPermitidas = ['todos', 'gasto', 'cierre', 'justificante', 'usuario', 'sistema', 'otro'];

if (!in_array($estadoFiltro, $estadosPermitidos, true)) {
  $estadoFiltro = 'todos';
}

if (!in_array($destinoFiltro, $destinosPermitidos, true)) {
  $destinoFiltro = 'todos';
}

if (!in_array($entidadFiltro, $entidadesPermitidas, true)) {
  $entidadFiltro = 'todos';
}

$filas = [];
$totalPendientes = 0;
$totalEnviados = 0;
$totalErrores = 0;
$totalOmitidos = 0;

if ($tablaExiste) {
  $sqlKpis = "SELECT estado, COUNT(*) AS total
              FROM envios_integraciones
              GROUP BY estado";

  $kpis = enviosAdminFetchAll($conn, $sqlKpis);

  foreach ($kpis as $kpi) {
    if ($kpi['estado'] === 'pendiente') {
      $totalPendientes = (int)$kpi['total'];
    } elseif ($kpi['estado'] === 'enviado') {
      $totalEnviados = (int)$kpi['total'];
    } elseif ($kpi['estado'] === 'error') {
      $totalErrores = (int)$kpi['total'];
    } elseif ($kpi['estado'] === 'omitido') {
      $totalOmitidos = (int)$kpi['total'];
    }
  }

  $where = "1 = 1";
  $params = [];
  $types = "";

  if ($estadoFiltro !== 'todos') {
    $where .= " AND estado = ?";
    $params[] = $estadoFiltro;
    $types .= "s";
  }

  if ($destinoFiltro !== 'todos') {
    $where .= " AND tipo_destino = ?";
    $params[] = $destinoFiltro;
    $types .= "s";
  }

  if ($entidadFiltro !== 'todos') {
    $where .= " AND entidad = ?";
    $params[] = $entidadFiltro;
    $types .= "s";
  }

  if ($buscar !== '') {
    $where .= " AND (
                  referencia LIKE ?
                  OR descripcion LIKE ?
                  OR ultimo_error LIKE ?
                )";

    $like = "%" . $buscar . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
  }

  $campoSistema = $tieneSistemaExterno ? "sistema_externo" : "NULL AS sistema_externo";
  $campoIdExterno = $tieneIdExterno ? "id_externo" : "NULL AS id_externo";
  $campoTipoCierre = $tieneTipoCierre ? "tipo_cierre" : "NULL AS tipo_cierre";

  $sql = "SELECT id,
                 tipo_destino,
                 $campoSistema,
                 entidad,
                 entidad_id,
                 $campoTipoCierre,
                 referencia,
                 $campoIdExterno,
                 descripcion,
                 estado,
                 ultimo_error,
                 intentos,
                 created_at,
                 enviado_at
          FROM envios_integraciones
          WHERE $where
          ORDER BY created_at DESC, id DESC
          LIMIT 200";

  $filas = enviosAdminFetchAll($conn, $sql, $types, $params);
}

$mensaje = appPublicMessage($_GET['msg'] ?? '');
$tipoMensaje = $_GET['type'] ?? 'success';
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Envíos e integraciones - Panel Admin</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="../css/admin.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/admin_cierres.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/admin_envios.css?v=<?php echo APP_VERSION; ?>">

  <style>
    .envios-compact-table {
      width: 100% !important;
      min-width: 0 !important;
      table-layout: fixed !important;
      font-size: 12px !important;
    }

    .envios-compact-table th,
    .envios-compact-table td {
      padding: 10px 8px !important;
      vertical-align: middle !important;
      overflow: hidden !important;
      text-overflow: ellipsis !important;
    }

    .envios-compact-table th:nth-child(1),
    .envios-compact-table td:nth-child(1) { width: 46px !important; }

    .envios-compact-table th:nth-child(2),
    .envios-compact-table td:nth-child(2) { width: 82px !important; }

    .envios-compact-table th:nth-child(3),
    .envios-compact-table td:nth-child(3) { width: 100px !important; }

    .envios-compact-table th:nth-child(4),
    .envios-compact-table td:nth-child(4) { width: 130px !important; }

    .envios-compact-table th:nth-child(5),
    .envios-compact-table td:nth-child(5) { width: auto !important; }

    .envios-compact-table th:nth-child(6),
    .envios-compact-table td:nth-child(6) { width: 96px !important; }

    .envios-compact-table th:nth-child(7),
    .envios-compact-table td:nth-child(7) { width: 70px !important; text-align: center !important; }

    .envios-compact-table th:nth-child(8),
    .envios-compact-table td:nth-child(8) { width: 112px !important; }

    .envios-compact-table th:nth-child(9),
    .envios-compact-table td:nth-child(9) { width: 126px !important; }

    .envios-compact-table .envios-text-wrap {
      white-space: normal !important;
      line-height: 1.3 !important;
      max-height: 42px !important;
    }

    .envios-compact-table .btn.small {
      padding: 7px 9px !important;
      white-space: nowrap !important;
    }


    @media (max-width: 700px) {
      .envios-compact-table,
      .envios-compact-table thead,
      .envios-compact-table tbody,
      .envios-compact-table tr,
      .envios-compact-table td {
        display: block !important;
        width: 100% !important;
        min-width: 0 !important;
      }

      .envios-compact-table thead {
        display: none !important;
      }

      .envios-compact-table tr {
        margin: 0 0 14px 0 !important;
        padding: 12px 12px !important;
        border: 1px solid rgba(255, 255, 255, 0.18) !important;
        border-radius: 16px !important;
        background: rgba(255, 255, 255, 0.07) !important;
      }

      .envios-compact-table td {
        display: block !important;
        padding: 8px 0 !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.10) !important;
        white-space: normal !important;
        line-height: 1.35 !important;
        max-height: none !important;
        overflow: visible !important;
        text-overflow: clip !important;
        word-break: normal !important;
        overflow-wrap: anywhere !important;
      }

      .envios-compact-table td:last-child {
        border-bottom: none !important;
        padding-bottom: 0 !important;
      }

      .envios-compact-table td::before {
        content: attr(data-label);
        display: block !important;
        width: 100% !important;
        margin: 0 0 4px 0 !important;
        color: rgba(255, 255, 255, 0.68);
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }

      .envios-compact-table td:last-child::before {
        display: block !important;
      }

      .envios-compact-table .envios-text-wrap {
        display: block !important;
        max-height: none !important;
        overflow: visible !important;
        white-space: normal !important;
        line-height: 1.35 !important;
      }

      .envios-compact-table .btn.small {
        width: 100% !important;
        min-height: 38px !important;
        margin-top: 2px !important;
      }
    }
  </style>
</head>

<body class="admin-body">
  <div class="admin-wrapper">

    <header class="admin-header">
      <div>
        <h1>Envíos e integraciones</h1>
        <p>Registro preparado para futuras conexiones contables y exportaciones.</p>
      </div>

      <div class="top-actions">
        <?php if ((string)($_SESSION['role'] ?? '') === 'master'): ?><a class="btn" href="centro_control.php">Centro de control</a><?php endif; ?>
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

    <?php if ($tablaExiste && (!$tieneSistemaExterno || !$tieneIdExterno)): ?>
      <section class="panel">
        <div class="message error">
          Este apartado todavía no está disponible.
        </div>
      </section>
    <?php endif; ?>

    <section class="kpi-grid">
      <article class="kpi-card">
        <span>Pendientes</span>
        <strong class="<?php echo $totalPendientes > 0 ? 'negative' : 'positive'; ?>">
          <?php echo (int)$totalPendientes; ?>
        </strong>
        <small>Registros preparados para envío futuro</small>
      </article>

      <article class="kpi-card">
        <span>Enviados</span>
        <strong class="<?php echo $totalEnviados > 0 ? 'positive' : ''; ?>">
          <?php echo (int)$totalEnviados; ?>
        </strong>
        <small>Integraciones confirmadas</small>
      </article>

      <article class="kpi-card">
        <span>Errores</span>
        <strong class="<?php echo $totalErrores > 0 ? 'negative' : 'positive'; ?>">
          <?php echo (int)$totalErrores; ?>
        </strong>
        <small>Requieren revisión</small>
      </article>

      <article class="kpi-card">
        <span>Omitidos</span>
        <strong><?php echo (int)$totalOmitidos; ?></strong>
        <small>Registros descartados u omitidos</small>
      </article>
    </section>

    <section class="panel">
      <form method="get" action="envios.php" class="filters">
        <div>
          <label for="estado">Estado</label>
          <select id="estado" name="estado">
            <option value="todos" <?php echo $estadoFiltro === 'todos' ? 'selected' : ''; ?>>Todos</option>
            <option value="pendiente" <?php echo $estadoFiltro === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
            <option value="enviado" <?php echo $estadoFiltro === 'enviado' ? 'selected' : ''; ?>>Enviado</option>
            <option value="error" <?php echo $estadoFiltro === 'error' ? 'selected' : ''; ?>>Error</option>
            <option value="omitido" <?php echo $estadoFiltro === 'omitido' ? 'selected' : ''; ?>>Omitido</option>
          </select>
        </div>

        <div>
          <label for="destino">Destino</label>
          <select id="destino" name="destino">
            <option value="todos" <?php echo $destinoFiltro === 'todos' ? 'selected' : ''; ?>>Todos</option>
            <option value="email" <?php echo $destinoFiltro === 'email' ? 'selected' : ''; ?>>Email</option>
            <option value="make" <?php echo $destinoFiltro === 'make' ? 'selected' : ''; ?>>Automatización</option>
            <option value="a3" <?php echo $destinoFiltro === 'a3' ? 'selected' : ''; ?>>A3</option>
            <option value="erp" <?php echo $destinoFiltro === 'erp' ? 'selected' : ''; ?>>ERP</option>
            <option value="verifactu" <?php echo $destinoFiltro === 'verifactu' ? 'selected' : ''; ?>>VERI*FACTU</option>
            <option value="contabilidad" <?php echo $destinoFiltro === 'contabilidad' ? 'selected' : ''; ?>>Contabilidad</option>
            <option value="otro" <?php echo $destinoFiltro === 'otro' ? 'selected' : ''; ?>>Otro</option>
          </select>
        </div>

        <div>
          <label for="entidad">Entidad</label>
          <select id="entidad" name="entidad">
            <option value="todos" <?php echo $entidadFiltro === 'todos' ? 'selected' : ''; ?>>Todas</option>
            <option value="gasto" <?php echo $entidadFiltro === 'gasto' ? 'selected' : ''; ?>>Gasto</option>
            <option value="cierre" <?php echo $entidadFiltro === 'cierre' ? 'selected' : ''; ?>>Cierre</option>
            <option value="justificante" <?php echo $entidadFiltro === 'justificante' ? 'selected' : ''; ?>>Justificante</option>
            <option value="usuario" <?php echo $entidadFiltro === 'usuario' ? 'selected' : ''; ?>>Usuario</option>
            <option value="sistema" <?php echo $entidadFiltro === 'sistema' ? 'selected' : ''; ?>>Sistema</option>
            <option value="otro" <?php echo $entidadFiltro === 'otro' ? 'selected' : ''; ?>>Otro</option>
          </select>
        </div>

        <div>
          <label for="buscar">Buscar</label>
          <input type="text" id="buscar" name="buscar" value="<?php echo h($buscar); ?>" placeholder="Referencia, descripción o nota">
        </div>

        <div>
          <button class="btn primary" type="submit">Aplicar filtros</button>
        </div>
      </form>
    </section>

    <section class="panel">
      <h2 class="section-title">Últimos registros</h2>

      <div class="table-wrap">
        <table class="admin-table-wide envios-compact-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Destino</th>
              <th>Entidad</th>
              <th>Referencia</th>
              <th>Descripción / nota</th>
              <th>Estado</th>
              <th>Intentos</th>
              <th>Fechas</th>
              <th>Acciones</th>
            </tr>
          </thead>

          <tbody>
            <?php if (!$tablaExiste): ?>
              <tr>
                <td colspan="9" class="muted">Pendiente de crear la tabla.</td>
              </tr>
            <?php elseif (count($filas) === 0): ?>
              <tr>
                <td colspan="9" class="muted">No hay registros para los filtros seleccionados.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($filas as $fila): ?>
              <?php
                $estado = $fila['estado'] ?? 'pendiente';
                $estadoClass = enviosAdminEstadoClass($estado);
                $descripcionCompacta = appPlainTechnicalText($fila['descripcion'] ?? '');
                $errorCompacto = !empty($fila['ultimo_error']) ? appPlainTechnicalText($fila['ultimo_error']) : '';
                $sistemaCompacto = trim((string)($fila['sistema_externo'] ?? ''));
                $idExternoCompacto = trim((string)($fila['id_externo'] ?? ''));
              ?>

              <tr>
                <td data-label="ID"><?php echo (int)$fila['id']; ?></td>
                <td data-label="Destino"><?php echo h(enviosAdminDestinoTexto($fila['tipo_destino'] ?? 'otro')); ?></td>
                <td data-label="Entidad">
                  <?php
                    $tipoCierreFila = trim((string)($fila['tipo_cierre'] ?? ''));
                    $entidadTexto = (string)($fila['entidad'] ?? 'otro');
                    if ($entidadTexto === 'cierre' && ($tipoCierreFila === 'efectivo' || (int)($fila['entidad_id'] ?? 0) < 0)) {
                      $entidadTexto = 'Cierre Efectivo/Kms';
                    } elseif ($entidadTexto === 'cierre') {
                      $entidadTexto = 'Cierre VISA';
                    }
                    echo h($entidadTexto);
                  ?>
                  <?php if (!empty($fila['entidad_id'])): ?>
                    #<?php echo abs((int)$fila['entidad_id']); ?>
                  <?php endif; ?>
                </td>
                <td data-label="Referencia" title="<?php echo h($fila['referencia'] ?? ''); ?>">
                  <?php if (!empty($fila['referencia'])): ?>
                    <?php echo h($fila['referencia']); ?>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                  <?php if ($sistemaCompacto !== '' || $idExternoCompacto !== ''): ?>
                    <br><small class="muted"><?php echo h($sistemaCompacto !== '' ? $sistemaCompacto : 'Sistema'); ?><?php echo $idExternoCompacto !== '' ? ' · ' . h($idExternoCompacto) : ''; ?></small>
                  <?php endif; ?>
                </td>
                <td data-label="Nota" class="envios-text-wrap" title="<?php echo h($descripcionCompacta . ($errorCompacto !== '' ? ' ' . $errorCompacto : '')); ?>">
                  <?php if ($descripcionCompacta !== ''): ?>
                    <?php echo h($descripcionCompacta); ?>
                  <?php endif; ?>
                  <?php if ($errorCompacto !== ''): ?>
                    <?php if ($descripcionCompacta !== ''): ?><br><?php endif; ?>
                    <span class="envios-error-text"><?php echo h($errorCompacto); ?></span>
                  <?php endif; ?>
                  <?php if ($descripcionCompacta === '' && $errorCompacto === ''): ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td data-label="Estado">
                  <span class="cierre-status-mini <?php echo h($estadoClass); ?>">
                    <?php echo h(enviosAdminEstadoTexto($estado)); ?>
                  </span>
                </td>
                <td data-label="Intentos"><?php echo (int)($fila['intentos'] ?? 0); ?></td>
                <td data-label="Fechas">
                  <?php echo h(formatFechaWeb($fila['created_at'] ?? '')); ?>
                  <?php if (!empty($fila['enviado_at'])): ?>
                    <br><small class="muted">Enviado: <?php echo h(formatFechaWeb($fila['enviado_at'])); ?></small>
                  <?php endif; ?>
                </td>
                <td data-label="Acciones">
                  <a class="btn small" href="editar_envio_integracion.php?id=<?php echo (int)$fila['id']; ?>&return=admin/envios.php">Cambiar estado</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="note">
        Esta pantalla todavía no envía datos a A3, ERP ni VERI*FACTU. Permite controlar manualmente el estado de cada registro mientras se prepara la integración automática.
      </div>
    </section>

  </div>

<?php include __DIR__ . '/../includes/admin_chat_launcher.php'; ?>
</body>
</html>

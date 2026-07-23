<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/efectivo_kms.php';
require_once __DIR__ . '/includes/gastos_unificados.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$esAdmin = isAdmin();
$mes = (int)($_GET['mes'] ?? date('n'));
$anio = (int)($_GET['anio'] ?? date('Y'));
$comercialFiltro = $esAdmin ? trim((string)($_GET['comercial'] ?? '')) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? (defined('GASTOS_PER_PAGE') ? GASTOS_PER_PAGE : 25));
$returnUrl = sanitizeRedirect($_GET['return'] ?? 'home.php');

if ($mes < 1 || $mes > 12) {
    $mes = (int)date('n');
}

if ($anio < 2020 || $anio > 2100) {
    $anio = (int)date('Y');
}

if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 25;
}

$queries = [];
$params = [];
$types = '';

$fechaVisa = "COALESCE(g.fecha_imputacion, g.fecha_ticket, DATE(g.created_at))";
$whereVisa = "g.deleted_at IS NULL
              AND $fechaVisa IS NOT NULL
              AND MONTH($fechaVisa) = ?
              AND YEAR($fechaVisa) = ?";

$params[] = $mes;
$params[] = $anio;
$types .= 'ii';

if (!$esAdmin) {
    $whereVisa .= " AND g.user_id = ?";
    $params[] = $userId;
    $types .= 'i';
} elseif ($comercialFiltro !== '') {
    $whereVisa .= " AND g.comercial = ?";
    $params[] = $comercialFiltro;
    $types .= 's';
}

$queries[] = "SELECT
                'visa' AS tipo_registro,
                g.id AS registro_id,
                g.gasto_uid AS referencia,
                g.user_id,
                g.comercial,
                g.username,
                $fechaVisa AS fecha_registro,
                g.fecha_ticket,
                g.fecha_imputacion,
                g.viaje AS detalle,
                g.motivo,
                g.importe_detectado AS importe,
                g.estado,
                g.sync_status,
                g.created_at
              FROM gastos g
              WHERE $whereVisa";

if (efectivoKmsTableExists($conn, 'efectivo_gastos')) {
    $where = "e.estado <> 'eliminado'
              AND MONTH(e.fecha) = ?
              AND YEAR(e.fecha) = ?";

    $params[] = $mes;
    $params[] = $anio;
    $types .= 'ii';

    if (!$esAdmin) {
        $where .= " AND e.user_id = ?";
        $params[] = $userId;
        $types .= 'i';
    } elseif ($comercialFiltro !== '') {
        $where .= " AND e.comercial = ?";
        $params[] = $comercialFiltro;
        $types .= 's';
    }

    $queries[] = "SELECT
                    'efectivo' AS tipo_registro,
                    e.id AS registro_id,
                    CONCAT('EFE-', e.id) AS referencia,
                    e.user_id,
                    e.comercial,
                    e.username,
                    e.fecha AS fecha_registro,
                    e.fecha AS fecha_ticket,
                    e.fecha AS fecha_imputacion,
                    'Gasto en efectivo' AS detalle,
                    e.motivo,
                    e.importe AS importe,
                    e.estado,
                    e.estado AS sync_status,
                    e.created_at
                  FROM efectivo_gastos e
                  WHERE $where";
}

if (efectivoKmsTableExists($conn, 'kilometrajes')) {
    $where = "k.estado <> 'eliminado'
              AND MONTH(k.fecha) = ?
              AND YEAR(k.fecha) = ?";

    $params[] = $mes;
    $params[] = $anio;
    $types .= 'ii';

    if (!$esAdmin) {
        $where .= " AND k.user_id = ?";
        $params[] = $userId;
        $types .= 'i';
    } elseif ($comercialFiltro !== '') {
        $where .= " AND k.comercial = ?";
        $params[] = $comercialFiltro;
        $types .= 's';
    }

    $queries[] = "SELECT
                    'kilometraje' AS tipo_registro,
                    k.id AS registro_id,
                    CONCAT('KM-', k.id) AS referencia,
                    k.user_id,
                    k.comercial,
                    k.username,
                    k.fecha AS fecha_registro,
                    k.fecha AS fecha_ticket,
                    k.fecha AS fecha_imputacion,
                    TRIM(CONCAT(COALESCE(k.origen, ''), ' → ', COALESCE(k.destino, ''))) AS detalle,
                    k.motivo,
                    k.importe AS importe,
                    k.estado,
                    k.estado AS sync_status,
                    k.created_at
                  FROM kilometrajes k
                  WHERE $where";
}

$unionSql = implode("\nUNION ALL\n", $queries);
$sqlCount = "SELECT COUNT(*) AS total, COALESCE(SUM(importe), 0) AS total_importe
             FROM ($unionSql) registros";

$stmtCount = $conn->prepare($sqlCount);

if (!$stmtCount) {
    die('No se pudo preparar el listado de gastos: ' . $conn->error);
}

if ($types !== '') {
    $stmtCount->bind_param($types, ...$params);
}

$stmtCount->execute();
$resumen = $stmtCount->get_result()->fetch_assoc();
$totalRows = (int)($resumen['total'] ?? 0);
$totalImporte = (float)($resumen['total_importe'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;
$sql = "SELECT *
        FROM ($unionSql) registros
        ORDER BY fecha_registro DESC, created_at DESC, registro_id DESC
        LIMIT ? OFFSET ?";

$paramsListado = $params;
$paramsListado[] = $perPage;
$paramsListado[] = $offset;
$typesListado = $types . 'ii';

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('No se pudo preparar la consulta de gastos: ' . $conn->error);
}

$stmt->bind_param($typesListado, ...$paramsListado);
$stmt->execute();
$result = $stmt->get_result();

$comercialesResult = null;

if ($esAdmin) {
    $comercialesResult = $conn->query(
        "SELECT DISTINCT comercial
         FROM users
         WHERE comercial IS NOT NULL AND comercial <> ''
         ORDER BY comercial ASC"
    );
}

$queryParams = $_GET;
unset($queryParams['page']);
$baseQueryString = http_build_query($queryParams);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gestionar gastos</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="css/estilos.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/processing_overlay.css?v=<?php echo APP_VERSION; ?>">

  <style>
    .gastos-table .id-cell {
      min-width: 135px !important;
      max-width: 175px !important;
      overflow-wrap: anywhere !important;
      word-break: break-word !important;
      line-height: 1.2 !important;
    }

    .gastos-table .id-cell strong,
    .gastos-table .id-cell small {
      display: block !important;
    }

    .gastos-table .id-cell small {
      margin-top: 5px !important;
      color: #64748b !important;
      font-weight: 800 !important;
    }

    .gastos-table .actions-cell {
      min-width: 220px !important;
    }

    .gasto-actions {
      display: flex !important;
      align-items: center !important;
      gap: 8px !important;
      flex-wrap: wrap !important;
    }

    .gasto-actions a,
    .gasto-actions button {
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      min-height: 30px !important;
      padding: 5px 8px !important;
      border-radius: 10px !important;
      background: rgba(226, 232, 240, 0.7) !important;
      text-decoration: none !important;
      border: 0 !important;
      margin: 0 !important;
      cursor: pointer !important;
      font-size: 13px !important;
      font-weight: 800 !important;
      white-space: nowrap !important;
    }

    .gasto-actions a:hover,
    .gasto-actions button:hover {
      background: rgba(203, 213, 225, 0.95) !important;
    }

    .gasto-actions a.danger-link,
    .gasto-actions button.danger-link {
      color: #b91c1c !important;
      background: #fee2e2 !important;
    }

    .payment-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 5px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 900;
      white-space: nowrap;
    }

    .payment-badge.visa {
      background: #dbeafe;
      color: #1d4ed8;
    }

    .payment-badge.efectivo {
      background: #dcfce7;
      color: #166534;
    }

    .payment-badge.kilometraje {
      background: #fef3c7;
      color: #92400e;
    }
  </style>
</head>
<body>
  <div class="container wide-container">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <h1>Gestionar Gastos</h1>

    <?php if (!empty($_GET['msg'])): ?>
      <div class="<?php echo ($_GET['type'] ?? '') === 'error' ? 'error' : 'success'; ?>">
        <?php echo h(appPublicMessage($_GET['msg'])); ?>
      </div>
    <?php endif; ?>

    <form method="get" class="filters-form compact-filters">
      <input type="hidden" name="return" value="<?php echo h($returnUrl); ?>">

      <div class="filter-field">
        <label for="mes">Mes</label>
        <select id="mes" name="mes">
          <?php for ($month = 1; $month <= 12; $month++): ?>
            <option value="<?php echo $month; ?>" <?php echo $mes === $month ? 'selected' : ''; ?>>
              <?php echo str_pad((string)$month, 2, '0', STR_PAD_LEFT); ?>
            </option>
          <?php endfor; ?>
        </select>
      </div>

      <div class="filter-field">
        <label for="anio">Año</label>
        <input type="number" id="anio" name="anio" value="<?php echo (int)$anio; ?>" min="2020" max="2100">
      </div>

      <?php if ($esAdmin): ?>
        <div class="filter-field">
          <label for="comercial">Comercial</label>
          <select id="comercial" name="comercial">
            <option value="">Todos</option>
            <?php if ($comercialesResult): ?>
              <?php while ($comercialRow = $comercialesResult->fetch_assoc()): ?>
                <option value="<?php echo h($comercialRow['comercial']); ?>" <?php echo $comercialFiltro === $comercialRow['comercial'] ? 'selected' : ''; ?>>
                  <?php echo h($comercialRow['comercial']); ?>
                </option>
              <?php endwhile; ?>
            <?php endif; ?>
          </select>
        </div>
      <?php endif; ?>

      <div class="filter-field">
        <label for="per_page">Registros</label>
        <select id="per_page" name="per_page">
          <?php foreach ([10, 25, 50, 100] as $option): ?>
            <option value="<?php echo $option; ?>" <?php echo $perPage === $option ? 'selected' : ''; ?>>
              <?php echo $option; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="filters-actions">
        <button type="submit" class="btn-primary-small">Filtrar</button>
      </div>
    </form>

    <p class="results-count">
      Resultados encontrados: <strong><?php echo $totalRows; ?></strong> ·
      Total filtrado: <strong><?php echo h(number_format($totalImporte, 2, ',', '.')); ?> €</strong>
    </p>

    <?php if (!$result || $result->num_rows === 0): ?>
      <p class="empty-message">No hay gastos para el periodo seleccionado.</p>
    <?php else: ?>
      <div class="table-wrapper">
        <table class="gastos-table clean-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Tipo</th>
              <th>Fecha</th>
              <th>Detalle</th>
              <th>Motivo</th>
              <th>Importe</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
              <?php
                $tipo = (string)$row['tipo_registro'];
                $returnList = 'gestionar_gastos.php?' . $baseQueryString . ($baseQueryString ? '&' : '') . 'page=' . $page;
                $esVisa = $tipo === 'visa';
                $esError = $esVisa && (
                    (string)$row['estado'] === 'error'
                    || (string)$row['sync_status'] === 'error_sync'
                );
              ?>
              <tr>
                <td class="id-cell">
                  <strong><?php echo h($row['referencia']); ?></strong>
                  <small>#<?php echo (int)$row['registro_id']; ?></small>
                </td>

                <td>
                  <span class="payment-badge <?php echo h($tipo); ?>">
                    <?php echo h(gastosUnificadosTipoTexto($tipo)); ?>
                  </span>
                </td>

                <td><?php echo h(formatFechaWeb($row['fecha_registro'])); ?></td>
                <td><?php echo h($row['detalle'] ?: '—'); ?></td>
                <td><?php echo h($row['motivo']); ?></td>
                <td class="amount-cell"><?php echo number_format((float)$row['importe'], 2, ',', '.'); ?> €</td>
                <td><span class="<?php echo h(gastoEstadoClass((string)$row['estado'])); ?>"><?php echo h(formatEstadoWeb($row['estado'])); ?></span></td>

                <td class="actions-cell">
                  <div class="gasto-actions">
                    <?php if ($esVisa): ?>
                      <a href="ver_gasto.php?id=<?php echo (int)$row['registro_id']; ?>&return=<?php echo urlencode($returnList); ?>">Ver</a>
                      <a href="editar_gasto.php?id=<?php echo (int)$row['registro_id']; ?>&return=<?php echo urlencode($returnList); ?>">Editar</a>
                      <a href="reprocesar_gasto.php?id=<?php echo (int)$row['registro_id']; ?>"
                         onclick="return confirm('¿Quieres reintentar el procesamiento/sincronización de este gasto?') && window.AppProcessing.show('Estamos reintentando el procesamiento del gasto. Espera unos segundos, por favor.');">
                        Reintentar
                      </a>

                      <?php if ($esError): ?>
                        <a href="revisar_gasto_error.php?id=<?php echo (int)$row['registro_id']; ?>">Marcar revisado</a>
                      <?php endif; ?>

                      <a href="eliminar_gasto.php?id=<?php echo (int)$row['registro_id']; ?>"
                         class="danger-link"
                         onclick="return confirm('¿Seguro que quieres eliminar este gasto?') && window.AppProcessing.show('Estamos eliminando el gasto. Espera unos segundos, por favor.');">
                        Eliminar
                      </a>
                    <?php else: ?>
                      <a href="ver_efectivo_km.php?tipo=<?php echo urlencode($tipo); ?>&id=<?php echo (int)$row['registro_id']; ?>&return=<?php echo urlencode($returnList); ?>">Ver</a>
                      <form method="post" action="eliminar_efectivo_km.php"
                            onsubmit="return confirm('¿Seguro que quieres eliminar este registro de <?php echo h(gastosUnificadosTipoTexto($tipo)); ?>?') && window.AppProcessing.show('Estamos eliminando el registro. Espera unos segundos, por favor.');">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                        <input type="hidden" name="tipo" value="<?php echo h($tipo); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$row['registro_id']; ?>">
                        <input type="hidden" name="return" value="<?php echo h($returnList); ?>">
                        <button type="submit" class="danger-link">Eliminar</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="?<?php echo h($baseQueryString . ($baseQueryString ? '&' : '') . 'page=' . ($page - 1)); ?>">Anterior</a>
          <?php endif; ?>

          <span>Página <?php echo $page; ?> de <?php echo $totalPages; ?></span>

          <?php if ($page < $totalPages): ?>
            <a href="?<?php echo h($baseQueryString . ($baseQueryString ? '&' : '') . 'page=' . ($page + 1)); ?>">Siguiente</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="volver-container">
    <a href="<?php echo h($returnUrl); ?>" class="volver-btn">Volver</a>
  </div>

  <script src="js/processing_overlay.js?v=<?php echo APP_VERSION; ?>"></script>
</body>
</html>

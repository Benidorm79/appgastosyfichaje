<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/efectivo_kms.php';

securitySendHeaders();

$userId = (int)($_SESSION['user_id'] ?? 0);
$esAdmin = isAdmin();
$selectedUser = $esAdmin ? (int)($_GET['user_id'] ?? 0) : $userId;
$selectedUser = $selectedUser > 0 ? $selectedUser : $userId;
$vista = (string)($_GET['vista'] ?? 'efectivo');
$vista = in_array($vista, ['efectivo', 'kilometraje'], true) ? $vista : 'efectivo';
$mesHistorial = (int)($_GET['mes_historial'] ?? date('n'));
$anioHistorial = (int)($_GET['anio_historial'] ?? date('Y'));

if ($mesHistorial < 1 || $mesHistorial > 12) $mesHistorial = (int)date('n');
if ($anioHistorial < 2020 || $anioHistorial > 2100) $anioHistorial = (int)date('Y');

$users = [];
if ($esAdmin) {
    $resultUsers = $conn->query(
        "SELECT id, comercial, username FROM users WHERE activo = 1 ORDER BY comercial ASC, username ASC"
    );
    if ($resultUsers) {
        while ($row = $resultUsers->fetch_assoc()) $users[] = $row;
    }
}

$efectivo = [];
$kilometrajes = [];

if ($vista === 'efectivo' && efectivoKmsTableExists($conn, 'efectivo_gastos')) {
    $stmt = $conn->prepare(
        "SELECT * FROM efectivo_gastos
         WHERE user_id = ? AND estado = 'procesado' AND MONTH(fecha) = ? AND YEAR(fecha) = ?
         ORDER BY fecha DESC, id DESC"
    );
    if ($stmt) {
        $stmt->bind_param('iii', $selectedUser, $mesHistorial, $anioHistorial);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) $efectivo[] = $row;
    }
}

if ($vista === 'kilometraje' && efectivoKmsTableExists($conn, 'kilometrajes')) {
    $stmt = $conn->prepare(
        "SELECT * FROM kilometrajes
         WHERE user_id = ? AND estado = 'procesado' AND MONTH(fecha) = ? AND YEAR(fecha) = ?
         ORDER BY fecha DESC, id DESC"
    );
    if ($stmt) {
        $stmt->bind_param('iii', $selectedUser, $mesHistorial, $anioHistorial);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) $kilometrajes[] = $row;
    }
}

$commonParams = [
    'mes_historial' => $mesHistorial,
    'anio_historial' => $anioHistorial
];
if ($esAdmin) $commonParams['user_id'] = $selectedUser;

$tabEfectivo = http_build_query(array_merge($commonParams, ['vista' => 'efectivo']));
$tabKilometraje = http_build_query(array_merge($commonParams, ['vista' => 'kilometraje']));
$exportQuery = http_build_query([
    'mes' => $mesHistorial,
    'anio' => $anioHistorial,
    'tipo' => $vista,
] + ($esAdmin ? ['user_id' => $selectedUser] : []));
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Efectivo y Kilometraje</title>
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">
  <link rel="stylesheet" href="css/estilos.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/processing_overlay.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/gasto_confirmacion.css?v=<?php echo APP_VERSION; ?>">
  <?php if ($vista === 'kilometraje'): ?>
    <link rel="stylesheet" href="vendor/leaflet/leaflet.css?v=1.9.4">
  <?php endif; ?>
  <link rel="stylesheet" href="css/efectivo_kms.css?v=<?php echo APP_VERSION; ?>">
</head>
<body>
  <div class="container wide-container">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <img id="logo" src="https://disvent.com/media/logo-disvent-doofinder.png" alt="Disvent Ingenieros">
    <h1>Efectivo y Kilometraje</h1>

    <nav class="ek-tabs" aria-label="Tipo de registro">
      <a class="<?php echo $vista === 'efectivo' ? 'active' : ''; ?>" href="efectivo_kms.php?<?php echo h($tabEfectivo); ?>">💶 Gastos en efectivo</a>
      <a class="<?php echo $vista === 'kilometraje' ? 'active' : ''; ?>" href="efectivo_kms.php?<?php echo h($tabKilometraje); ?>">🚗 Kilometraje</a>
    </nav>

    <?php if ($esAdmin): ?>
      <form method="get" class="ek-admin-filter">
        <input type="hidden" name="vista" value="<?php echo h($vista); ?>">
        <input type="hidden" name="mes_historial" value="<?php echo $mesHistorial; ?>">
        <input type="hidden" name="anio_historial" value="<?php echo $anioHistorial; ?>">
        <div>
          <label for="user_id">Usuario</label>
          <select name="user_id" id="user_id" onchange="this.form.submit()">
            <?php foreach ($users as $user): ?>
              <option value="<?php echo (int)$user['id']; ?>" <?php echo $selectedUser === (int)$user['id'] ? 'selected' : ''; ?>>
                <?php echo h(($user['comercial'] ?: $user['username']) . ' (' . $user['username'] . ')'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
    <?php endif; ?>

    <div id="form-message"></div>

    <?php if ($vista === 'efectivo'): ?>
      <section class="ek-card ek-single-card">
        <h2>💶 Gasto en efectivo</h2>
        <p class="help">El justificante es obligatorio. Si no dispones de ticket, habla con el Responsable correspondiente para su gestión.</p>
        <form id="cash-form">
          <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
          <div><label for="cash-fecha">Fecha</label><input id="cash-fecha" type="date" name="fecha" required></div>
          <div><label for="cash-motivo">Motivo</label><input id="cash-motivo" type="text" name="motivo" maxlength="180" required></div>
          <div><label for="cash-importe">Importe</label><input id="cash-importe" type="number" name="importe" step="0.01" min="0.01" required></div>
          <div>
            <label for="cash-imagen">Imagen del ticket</label>
            <input id="cash-imagen" type="file" name="imagen" accept="image/jpeg,image/png,image/webp" required>
            <div class="help">JPG, PNG o WEBP. Máximo 8 MB.</div>
          </div>
          <button type="submit">Guardar gasto en efectivo</button>
        </form>
      </section>
    <?php else: ?>
      <section class="ek-card ek-single-card">
        <h2>🚗 Kilometraje</h2>
        <p class="help">Introduce origen y destino para calcular la ruta o escribe los kilómetros manualmente.</p>
        <form id="km-form">
          <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
          <input type="hidden" name="paradas_json" value="[]">
          <input type="hidden" name="duracion_minutos" value="">
          <input type="hidden" name="ruta_polyline" value="">
          <div><label for="km-fecha">Fecha</label><input id="km-fecha" type="date" name="fecha" required></div>
          <div><label for="km-motivo">Motivo</label><input id="km-motivo" type="text" name="motivo" maxlength="180" required></div>
          <div class="ek-route-planner">
            <div class="ek-route">
              <div><label for="route-origin">Origen</label><input type="text" name="origen" id="route-origin" maxlength="255" placeholder="Dirección de salida"></div>
              <div><label for="route-destination">Destino</label><input type="text" name="destino" id="route-destination" maxlength="255" placeholder="Dirección de llegada"></div>
            </div>
            <div id="route-stops" class="ek-route-stops"></div>
            <div class="ek-route-actions">
              <button type="button" id="add-stop" class="btn-secondary-small">＋ Añadir parada</button>
              <button type="button" id="calc-route" class="btn-secondary-small">Calcular ruta</button>
            </div>
            <div id="route-map" class="ek-route-map" aria-label="Mapa de la ruta"></div>
            <div id="route-alternatives" class="ek-route-alternatives"></div>
          </div>
          <div><label for="km-kilometros">Kilómetros</label><input id="km-kilometros" type="number" name="kilometros" step="0.01" min="0.01" required></div>
          <div><label for="km-ruta-url">Enlace de la ruta (opcional)</label><input id="km-ruta-url" type="url" name="ruta_url" placeholder="https://www.google.com/maps/..."></div>
          <div class="ek-total">Precio: <?php echo number_format(KILOMETRAJE_EUR_KM, 2, ',', '.'); ?> €/km · Total estimado: <strong id="km-total">0,00 €</strong></div>
          <button type="submit">Guardar kilometraje</button>
        </form>
      </section>
    <?php endif; ?>

    <section class="ek-card ek-history">
      <div class="ek-history-heading">
        <div>
          <h2><?php echo $vista === 'efectivo' ? 'Gastos en efectivo' : 'Kilometraje'; ?> del periodo</h2>
          <p class="help">Consulta y exporta la información procesada del periodo seleccionado.</p>
        </div>
        <div class="ek-history-export-actions">
          <a class="ek-export-button" href="exportar_efectivo_kms.php?formato=xls&amp;<?php echo h($exportQuery); ?>">Exportar Excel</a>
          <a class="ek-export-button" href="exportar_efectivo_kms.php?formato=csv&amp;<?php echo h($exportQuery); ?>">Exportar CSV</a>
        </div>
      </div>

      <form method="get" class="ek-history-filter">
        <input type="hidden" name="vista" value="<?php echo h($vista); ?>">
        <?php if ($esAdmin): ?><input type="hidden" name="user_id" value="<?php echo $selectedUser; ?>"><?php endif; ?>
        <div>
          <label for="mes_historial">Mes</label>
          <select id="mes_historial" name="mes_historial">
            <?php for ($month = 1; $month <= 12; $month++): ?>
              <option value="<?php echo $month; ?>" <?php echo $mesHistorial === $month ? 'selected' : ''; ?>><?php echo str_pad((string)$month, 2, '0', STR_PAD_LEFT); ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div><label for="anio_historial">Año</label><input id="anio_historial" type="number" name="anio_historial" min="2020" max="2100" value="<?php echo $anioHistorial; ?>"></div>
        <button type="submit" class="btn-secondary-small">Consultar</button>
      </form>

      <?php if ($vista === 'efectivo'): ?>
        <div class="table-wrap"><table>
          <thead><tr><th>Fecha</th><th>Motivo</th><th>Importe</th><th>Ticket</th></tr></thead>
          <tbody>
            <?php foreach ($efectivo as $row): ?><tr>
              <td data-label="Fecha"><?php echo h(formatFechaWeb($row['fecha'])); ?></td>
              <td data-label="Motivo"><?php echo h($row['motivo']); ?></td>
              <td data-label="Importe"><?php echo number_format((float)$row['importe'], 2, ',', '.'); ?> €</td>
              <td data-label="Ticket"><?php if (!empty($row['drive_file_url'])): ?><a target="_blank" rel="noopener" href="<?php echo h($row['drive_file_url']); ?>">Ver</a><?php else: ?>Pendiente<?php endif; ?></td>
            </tr><?php endforeach; ?>
            <?php if (!$efectivo): ?><tr><td colspan="4">Sin registros para este periodo.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <div class="table-wrap"><table>
          <thead><tr><th>Fecha</th><th>Motivo</th><th>Ruta</th><th>Kms</th><th>Importe</th></tr></thead>
          <tbody>
            <?php foreach ($kilometrajes as $row): ?><tr>
              <td data-label="Fecha"><?php echo h(formatFechaWeb($row['fecha'])); ?></td>
              <td data-label="Motivo"><?php echo h($row['motivo']); ?></td>
              <td data-label="Ruta"><?php echo h(trim(($row['origen'] ?? '') . ' → ' . ($row['destino'] ?? ''), ' →')); ?></td>
              <td data-label="Kms"><?php echo number_format((float)$row['kilometros'], 2, ',', '.'); ?></td>
              <td data-label="Importe"><?php echo number_format((float)$row['importe'], 2, ',', '.'); ?> €</td>
            </tr><?php endforeach; ?>
            <?php if (!$kilometrajes): ?><tr><td colspan="5">Sin registros para este periodo.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>
  </div>

  <div class="volver-container"><a class="volver-btn" href="home.php">Volver</a></div>
  <script>
    window.EK_CONFIG = {
      csrfToken: <?php echo json_encode(csrfToken()); ?>,
      priceKm: <?php echo json_encode((float)KILOMETRAJE_EUR_KM); ?>
    };
  </script>
  <script src="js/processing_overlay.js?v=<?php echo APP_VERSION; ?>"></script>
  <script src="js/gasto_confirmacion.js?v=<?php echo APP_VERSION; ?>"></script>
  <?php if ($vista === 'kilometraje'): ?><script src="vendor/leaflet/leaflet.js?v=1.9.4"></script><?php endif; ?>
  <script src="js/efectivo_kms.js?v=<?php echo APP_VERSION; ?>"></script>
</body>
</html>

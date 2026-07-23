<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gastos_unificados.php';

$tipo = trim((string)($_GET['tipo'] ?? ''));
$id = (int)($_GET['id'] ?? 0);
$returnUrl = sanitizeRedirect($_GET['return'] ?? 'gestionar_gastos.php');
$registro = gastosUnificadosGetEfectivoKmRecord(
    $conn,
    $tipo,
    $id,
    (int)($_SESSION['user_id'] ?? 0),
    isAdmin()
);

if (!$registro) {
    die('Registro no encontrado o sin permisos.');
}

$esEfectivo = $tipo === 'efectivo';
$paradas = $esEfectivo
    ? []
    : json_decode((string)($registro['paradas_json'] ?? '[]'), true);

if (!is_array($paradas)) {
    $paradas = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Detalle de <?php echo h(gastosUnificadosTipoTexto($tipo)); ?></title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="css/estilos.css?v=<?php echo APP_VERSION; ?>">
</head>
<body>
  <div class="container wide-container">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <h1><?php echo h(gastosUnificadosTipoTexto($tipo)); ?></h1>

    <div class="detail-card">
      <h2><?php echo h(($esEfectivo ? 'EFE-' : 'KM-') . (int)$registro['id']); ?></h2>

      <div class="detail-grid">
        <div>
          <strong>Comercial</strong>
          <p><?php echo h($registro['comercial']); ?></p>
        </div>

        <div>
          <strong>Usuario</strong>
          <p><?php echo h($registro['username']); ?></p>
        </div>

        <div>
          <strong>Fecha</strong>
          <p><?php echo h(formatFechaWeb($registro['fecha'])); ?></p>
        </div>

        <div>
          <strong>Motivo</strong>
          <p><?php echo h($registro['motivo']); ?></p>
        </div>

        <div>
          <strong>Importe</strong>
          <p><?php echo number_format((float)$registro['importe'], 2, ',', '.'); ?> €</p>
        </div>

        <div>
          <strong>Estado</strong>
          <p><?php echo h(formatEstadoWeb($registro['estado'])); ?></p>
        </div>

        <div>
          <strong>Fecha de creación</strong>
          <p><?php echo h(formatFechaWeb($registro['created_at'], true)); ?></p>
        </div>
      </div>

      <?php if ($esEfectivo): ?>
        <div class="detail-section">
          <strong>Justificante</strong>

          <?php if (!empty($registro['drive_file_url'])): ?>
            <p>
              <a href="<?php echo h($registro['drive_file_url']); ?>" target="_blank" rel="noopener">
                Ver justificante
              </a>
            </p>
          <?php else: ?>
            <p>No hay ningún archivo disponible.</p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="detail-grid">
          <div>
            <strong>Origen</strong>
            <p><?php echo h($registro['origen'] ?: '—'); ?></p>
          </div>

          <div>
            <strong>Destino</strong>
            <p><?php echo h($registro['destino'] ?: '—'); ?></p>
          </div>

          <div>
            <strong>Kilómetros</strong>
            <p><?php echo number_format((float)$registro['kilometros'], 2, ',', '.'); ?> km</p>
          </div>

          <div>
            <strong>Precio por km</strong>
            <p><?php echo number_format((float)$registro['precio_km'], 4, ',', '.'); ?> €</p>
          </div>

          <div>
            <strong>Duración</strong>
            <p><?php echo (int)($registro['duracion_minutos'] ?? 0); ?> min</p>
          </div>

          <div>
            <strong>Tipo de cálculo</strong>
            <p><?php echo h(formatEstadoWeb($registro['calculo_origen'])); ?></p>
          </div>
        </div>

        <?php if ($paradas): ?>
          <div class="detail-section">
            <strong>Paradas intermedias</strong>
            <ul>
              <?php foreach ($paradas as $parada): ?>
                <li><?php echo h($parada); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if (!empty($registro['ruta_url'])): ?>
          <div class="detail-section">
            <strong>Ruta</strong>
            <p>
              <a href="<?php echo h($registro['ruta_url']); ?>" target="_blank" rel="noopener">
                Abrir en Google Maps
              </a>
            </p>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="detail-actions">
        <a href="<?php echo h($returnUrl); ?>" class="volver-btn">Volver</a>
      </div>
    </div>
  </div>
</body>
</html>

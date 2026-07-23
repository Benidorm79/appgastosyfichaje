<?php
require "session.php";
include "db.php";
require_once "includes/functions.php";

$id = intval($_GET['id'] ?? 0);
$returnUrl = sanitizeRedirect($_GET['return'] ?? 'gestionar_gastos.php');

$gasto = getGastoByIdForCurrentUser($conn, $id);

if (!$gasto || $gasto['deleted_at'] !== null || $gasto['estado'] === 'eliminado') {
  die("Gasto no encontrado o sin permisos.");
}

$sqlTickets = "SELECT * FROM gasto_tickets 
               WHERE gasto_id = ? AND gasto_uid = ?
               ORDER BY orden ASC, id ASC";

$stmtTickets = $conn->prepare($sqlTickets);
$stmtTickets->bind_param("is", $gasto['id'], $gasto['gasto_uid']);
$stmtTickets->execute();
$tickets = $stmtTickets->get_result();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ficha de gasto</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="css/estilos.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/processing_overlay.css?v=<?php echo APP_VERSION; ?>">
</head>

<body>
  <div class="container wide-container">

    <?php include "includes/topbar.php"; ?>

    <h1>Ficha de gasto</h1>

    <?php if (isset($_GET['updated'])): ?>
      <div class="success">
        Gasto actualizado correctamente.
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['edit_error'])): ?>
      <div class="error">
        No se ha aplicado el cambio.
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['sync_error'])): ?>
      <div class="error">
        No se han podido sincronizar los cambios.
      </div>
    <?php endif; ?>

    <?php if (!empty($_GET['msg'])): ?>
      <div class="<?php echo ($_GET['type'] ?? '') === 'error' ? 'error' : 'success'; ?>">
        <?php echo h(appPublicMessage($_GET['msg'])); ?>
      </div>
    <?php endif; ?>

    <div class="detail-card">
      <h2><?php echo h($gasto['gasto_uid']); ?></h2>

      <div class="detail-grid">
        <div>
          <strong>Comercial</strong>
          <p><?php echo h($gasto['comercial']); ?></p>
        </div>

        <div>
          <strong>Usuario</strong>
          <p><?php echo h($gasto['username']); ?></p>
        </div>

        <div>
          <strong>Viaje</strong>
          <p><?php echo h($gasto['viaje']); ?></p>
        </div>

        <div>
          <strong>Motivo</strong>
          <p><?php echo h($gasto['motivo']); ?></p>
        </div>

        <div>
          <strong>Importe detectado</strong>
          <p>
            <?php echo $gasto['importe_detectado'] !== null ? h(number_format((float)$gasto['importe_detectado'], 2, ',', '.')) . ' €' : 'Pendiente'; ?>
          </p>
        </div>

        <div>
          <strong>Fecha ticket</strong>
          <p><?php echo h(formatFechaWeb($gasto['fecha_ticket'])); ?></p>
        </div>

        <div>
          <strong>Fecha imputación</strong>
          <p><?php echo h(formatFechaWeb($gasto['fecha_imputacion'] ?? '')); ?></p>
        </div>

        <div>
          <strong>Estado</strong>
          <p><?php echo h(formatEstadoWeb($gasto['estado'])); ?></p>
        </div>

        <div>
          <strong>Sincronización</strong>
          <p><?php echo h(formatEstadoWeb($gasto['sync_status'] ?? '')); ?></p>
        </div>

        <div>
          <strong>Fecha creación</strong>
          <p><?php echo h(formatFechaWeb($gasto['created_at'], true)); ?></p>
        </div>

        <div>
          <strong>Última actualización</strong>
          <p><?php echo h(formatFechaWeb($gasto['updated_at'], true)); ?></p>
        </div>
      </div>

      <div class="detail-section">
        <strong>Comentarios</strong>
        <p><?php echo $gasto['comentarios'] ? nl2br(h($gasto['comentarios'])) : 'Sin comentarios'; ?></p>
      </div>

      <div class="detail-section">
        <strong>Ticket</strong>

        <?php $totalTicketsGasto = (int)$tickets->num_rows; ?>

        <?php if ($totalTicketsGasto === 0): ?>
          <p>No hay ticket asociado.</p>
        <?php else: ?>
          <ul>
            <?php while ($ticket = $tickets->fetch_assoc()): ?>
              <li>
                <?php echo h($ticket['filename']); ?>

                <?php if (!empty($ticket['drive_file_url'])): ?>
                  —
                  <a href="<?php echo h($ticket['drive_file_url']); ?>" target="_blank" rel="noopener">
                    Ver justificante
                  </a>
                <?php endif; ?>
              </li>
            <?php endwhile; ?>
          </ul>
        <?php endif; ?>
      </div>

      <?php
        $estadoGastoActual = (string)($gasto['estado'] ?? '');
        $syncGastoActual = (string)($gasto['sync_status'] ?? '');
        $origenGastoActual = (string)($gasto['origen'] ?? '');
        $esGastoManualSinJustificante = $totalTicketsGasto === 0 && $origenGastoActual !== 'ticket';
        $gastoYaAutorizado = $estadoGastoActual === 'procesado' && in_array($syncGastoActual, ['autorizado_sin_justificante', 'sincronizado', 'revisado'], true);
        $puedeAutorizarGastoManual = isAdmin() && $esGastoManualSinJustificante && !$gastoYaAutorizado && $estadoGastoActual !== 'eliminado';
      ?>

      <?php if ($puedeAutorizarGastoManual): ?>
        <div class="detail-section" style="border: 2px solid #f59e0b; background: #fff7ed; border-radius: 14px; padding: 16px;">
          <strong style="display:block; color:#9a3412; font-size:18px; margin-bottom:6px;">Autorización administrativa</strong>
          <p>Este gasto no tiene justificante asociado. Un admin o máster puede autorizarlo para que deje de aparecer como error, conservando la trazabilidad en auditoría.</p>
          <a href="autorizar_gasto_manual.php?id=<?php echo (int)$gasto['id']; ?>&return=<?php echo urlencode('ver_gasto.php?id=' . (int)$gasto['id'] . '&return=' . urlencode($returnUrl)); ?>"
             class="volver-btn"
             style="background:#f59e0b; color:#111827; font-weight:900;"
             onclick="return confirm('¿Autorizar este gasto sin justificante? Quedará registrado en auditoría.')">
            Autorizar gasto sin justificante
          </a>
        </div>
      <?php elseif (isAdmin() && $esGastoManualSinJustificante && $gastoYaAutorizado): ?>
        <div class="detail-section" style="border: 2px solid #22c55e; background: #f0fdf4; border-radius: 14px; padding: 16px;">
          <strong style="display:block; color:#166534; font-size:18px; margin-bottom:6px;">Gasto autorizado</strong>
          <p>Este gasto sin justificante ya está autorizado administrativamente.</p>
        </div>
      <?php endif; ?>

      <div class="detail-actions">
        <a href="editar_gasto.php?id=<?php echo (int)$gasto['id']; ?>&return=<?php echo urlencode($returnUrl); ?>" class="volver-btn">Editar</a>
        <a href="<?php echo h($returnUrl); ?>" class="volver-btn">Volver</a>
      </div>
    </div>

  </div>
  <script src="js/processing_overlay.js?v=<?php echo APP_VERSION; ?>"></script>
</body>
</html>

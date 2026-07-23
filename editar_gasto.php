<?php
require "session.php";
include "db.php";
require_once "config.php";
require_once "includes/functions.php";

$id = intval($_GET['id'] ?? 0);

$gasto = getGastoByIdForCurrentUser($conn, $id);

if (!$gasto || $gasto['deleted_at'] !== null || $gasto['estado'] === 'eliminado') {
  die("Gasto no encontrado o sin permisos.");
}

if ($gasto['motivo'] === 'Otro') {
  $gasto['motivo'] = 'Otros';
}

$userId = (int)($gasto['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$returnUrl = sanitizeRedirect($_GET['return'] ?? ('ver_gasto.php?id=' . $id));
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Editar gasto</title>

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
  <div class="container">

    <?php include "includes/topbar.php"; ?>

    <h1>Editar gasto</h1>

    <div class="info-box">
      <strong>Valores actuales:</strong><br>
      Viaje: <?php echo h($gasto['viaje']); ?> ·
      Motivo: <?php echo h($gasto['motivo']); ?> ·
      Importe: <?php echo $gasto['importe_detectado'] !== null ? h(number_format((float)$gasto['importe_detectado'], 2, ',', '.')) . ' €' : 'Pendiente'; ?> ·
      Fecha ticket: <?php echo h(formatFechaWeb($gasto['fecha_ticket'])); ?> ·
      Fecha imputación: <?php echo h(formatFechaWeb($gasto['fecha_imputacion'] ?? '')); ?>
    </div>

    <form action="procesar_editar_gasto.php" method="POST" data-processing-overlay data-processing-message="Estamos guardando los cambios y sincronizando el gasto. Espera unos segundos, por favor.">
      <input type="hidden" name="id" value="<?php echo (int)$gasto['id']; ?>">
      <input type="hidden" id="user_id" name="user_id" value="<?php echo (int)$userId; ?>">
      <input type="hidden" name="return" value="<?php echo h($returnUrl); ?>">

      <div>
        <label>Identificador</label>
        <input type="text" value="<?php echo h($gasto['gasto_uid']); ?>" readonly>
      </div>

      <div>
        <label for="viaje">Viaje</label>
        <input type="text" id="viaje" name="viaje" value="<?php echo h($gasto['viaje']); ?>" required>
      </div>

      <div>
        <label for="motivo">Motivo</label>
        <select id="motivo" name="motivo" required>
          <?php
          $motivos = ['Desplazamiento', 'Hotel', 'Desayuno', 'Comida', 'Cena', 'Otros'];
          foreach ($motivos as $m):
          ?>
            <option value="<?php echo h($m); ?>" <?php echo $gasto['motivo'] === $m ? 'selected' : ''; ?>>
              <?php echo h($m); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="importe_detectado">Importe detectado</label>
        <input type="number" step="0.01" id="importe_detectado" name="importe_detectado" value="<?php echo h($gasto['importe_detectado']); ?>">
      </div>

      <div>
        <label for="fecha_ticket">Fecha ticket</label>
        <input type="date" id="fecha_ticket" name="fecha_ticket" value="<?php echo h($gasto['fecha_ticket']); ?>">
      </div>

      <div>
        <label for="fecha_imputacion">Fecha de imputación</label>
        <input type="date" id="fecha_imputacion" name="fecha_imputacion" value="<?php echo h($gasto['fecha_imputacion'] ?? ''); ?>">
        <div class="help">Si cambia el periodo, se comprobará el bloqueo tanto del periodo anterior como del nuevo.</div>
      </div>

      <div>
        <label for="comentarios">Comentarios</label>
        <textarea id="comentarios" name="comentarios"><?php echo h($gasto['comentarios']); ?></textarea>
      </div>

      <div>
        <label for="motivo_edicion">Motivo de edición</label>
        <textarea id="motivo_edicion" name="motivo_edicion" required placeholder="Indica por qué se modifica este gasto."></textarea>
      </div>

      <button type="submit">Guardar cambios</button>
    </form>

    <div class="volver-container">
      <a href="<?php echo h($returnUrl); ?>" class="volver-btn">Cancelar</a>
    </div>

  </div>
  <script src="js/processing_overlay.js?v=<?php echo APP_VERSION; ?>"></script>
</body>
</html>

<?php
require "session.php";
require_once "config.php";
require_once "includes/functions.php";
require_once "includes/security.php";

securitySendHeaders();

$success = $_SESSION['manual_success'] ?? '';
$error = $_SESSION['manual_error'] ?? '';

unset($_SESSION['manual_success']);
unset($_SESSION['manual_error']);
$manualConfirmacion = $_SESSION['manual_confirmacion'] ?? null;
unset($_SESSION['manual_confirmacion']);
$oldManual = $_SESSION['manual_payload'] ?? [];
unset($_SESSION['manual_payload']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?php echo h(csrfToken()); ?>">
  <title>Gasto manual</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="css/estilos.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/processing_overlay.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/gasto_confirmacion.css?v=<?php echo APP_VERSION; ?>">
</head>

<body>
  <div class="container">

    <?php include "includes/topbar.php"; ?>

    <img id="logo" src="https://disvent.com/media/logo-disvent-doofinder.png" alt="Disvent Ingenieros">

    <h1>Registro de Gasto sin Ticket</h1>

    <?php if ($success !== ''): ?>
      <div class="success">
        <?php echo h($success); ?>
      </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="error">
        <?php echo h($error); ?>
      </div>
    <?php endif; ?>

    <form action="procesar_gasto_manual.php" method="POST" data-processing-overlay data-processing-message="Estamos guardando el gasto manual. Espera unos segundos, por favor.">
      <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
      <input type="hidden" id="user_id" name="user_id" value="<?php echo (int)($_SESSION['user_id'] ?? 0); ?>">

      <div>
        <label for="comercial">Comercial</label>
        <input type="text"
               id="comercial"
               name="comercial"
               value="<?php echo h($_SESSION['comercial']); ?>"
               readonly
               required>
      </div>

      <div>
        <label for="viaje">Viaje a</label>
        <input type="text" id="viaje" name="viaje" value="<?php echo h($oldManual['viaje'] ?? ''); ?>" required>
      </div>

      <div>
        <label for="motivo">Motivo</label>
        <select id="motivo" name="motivo" required>
          <option value="">-- Selecciona --</option>
          <option value="Desplazamiento" <?php echo (($oldManual['motivo'] ?? '') === 'Desplazamiento') ? 'selected' : ''; ?>>Desplazamiento</option>
          <option value="Hotel" <?php echo (($oldManual['motivo'] ?? '') === 'Hotel') ? 'selected' : ''; ?>>Hotel</option>
          <option value="Desayuno" <?php echo (($oldManual['motivo'] ?? '') === 'Desayuno') ? 'selected' : ''; ?>>Desayuno</option>
          <option value="Comida" <?php echo (($oldManual['motivo'] ?? '') === 'Comida') ? 'selected' : ''; ?>>Comida</option>
          <option value="Cena" <?php echo (($oldManual['motivo'] ?? '') === 'Cena') ? 'selected' : ''; ?>>Cena</option>
          <option value="Otros" <?php echo (($oldManual['motivo'] ?? '') === 'Otros') ? 'selected' : ''; ?>>Otros</option>
        </select>
      </div>

      <div>
        <label for="importe_detectado">Importe</label>
        <input type="number" step="0.01" min="0.01" id="importe_detectado" name="importe_detectado" value="<?php echo h($oldManual['importe_detectado'] ?? ''); ?>" required>
      </div>

      <div>
        <label for="fecha_ticket">Fecha real del gasto</label>
        <input type="date" id="fecha_ticket" name="fecha_ticket" value="<?php echo h($oldManual['fecha_ticket'] ?? ''); ?>" required>
      </div>

      <div>
        <label for="fecha_imputacion">Fecha de imputación / liquidación</label>
        <input type="date" id="fecha_imputacion" name="fecha_imputacion" value="<?php echo h($oldManual['fecha_imputacion'] ?? ''); ?>" required>
      </div>

      <div>
        <label for="comentarios">Comentarios</label>
        <textarea id="comentarios" name="comentarios" placeholder="Ejemplo: ticket extraviado, gasto cargado en otra liquidación, ajuste manual..."><?php echo h($oldManual['comentarios'] ?? ''); ?></textarea>
      </div>

      <div>
        <label>
          <input type="checkbox" name="confirmar_duplicado" value="1">
          Confirmar registro aunque exista un gasto similar
        </label>
        <div class="help">Márcalo solo si el sistema ha avisado de posible duplicado y confirmas que el gasto es correcto.</div>
      </div>

      <button type="submit">Guardar gasto manual</button>
    </form>

  </div>

  <div class="volver-container">
    <a href="home.php" class="volver-btn">Volver</a>
  </div>
  <script src="js/processing_overlay.js?v=<?php echo APP_VERSION; ?>"></script>
  <script src="js/gasto_confirmacion.js?v=<?php echo APP_VERSION; ?>"></script>
<?php if (is_array($manualConfirmacion)): ?><script>document.addEventListener('DOMContentLoaded',function(){ if(window.GastoConfirmacion){window.GastoConfirmacion.show(<?php echo json_encode($manualConfirmacion,JSON_UNESCAPED_UNICODE); ?>);}});</script><?php endif; ?>
</body>
</html>

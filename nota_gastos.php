<?php
require "session.php";
require_once "config.php";
require_once "includes/security.php";

securitySendHeaders();

$userId = (int)($_SESSION['user_id'] ?? 0);
$comercial = $_SESSION['comercial'] ?? '';
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registro Gastos con Ticket</title>

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

    <h1>Registro de Gasto con Ticket</h1>

    <div id="form-message"></div>

    <form id="expense-form">
      <input type="hidden" id="user_id" name="user_id" value="<?php echo (int)$userId; ?>">

      <div>
        <label for="comercial">Comercial</label>
        <input type="text"
               id="comercial"
               name="comercial"
               value="<?php echo htmlspecialchars($comercial, ENT_QUOTES, 'UTF-8'); ?>"
               readonly
               required>
      </div>

      <div>
        <label for="viaje">Viaje a</label>
        <input type="text" id="viaje" name="viaje" required>
      </div>

      <div>
        <label for="motivo">Motivo</label>
        <select id="motivo" name="motivo" required>
          <option value="">-- Selecciona --</option>
          <option value="Desplazamiento">Desplazamiento</option>
          <option value="Hotel">Hotel</option>
          <option value="Desayuno">Desayuno</option>
          <option value="Comida">Comida</option>
          <option value="Cena">Cena</option>
          <option value="Otros">Otros</option>
        </select>
      </div>

      <div>
        <label for="comentarios">Comentarios</label>
        <textarea id="comentarios" name="comentarios"></textarea>
      </div>

      <div>
        <label for="foto">Foto del ticket</label>
        <input type="file" id="foto" name="foto" accept="image/*" required>
      </div>

      <button type="submit">Enviar gasto</button>
    </form>

  </div>

  <div class="volver-container">
    <a href="home.php" class="volver-btn">Volver</a>
  </div>

  <script>
    window.APP_CONFIG = {
      USER_ID: <?php echo (int)$userId; ?>,
      CSRF_TOKEN: <?php echo json_encode(csrfToken()); ?>
    };
  </script>

  <script src="js/processing_overlay.js?v=<?php echo APP_VERSION; ?>"></script>
  <script src="js/gasto_confirmacion.js?v=<?php echo APP_VERSION; ?>"></script>
  <script src="js/app.js?v=<?php echo APP_VERSION; ?>"></script>
</body>
</html>

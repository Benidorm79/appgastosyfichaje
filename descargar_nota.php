<?php
require "session.php";
require_once "config.php";
require_once "includes/security.php";
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Descargar nota de gastos</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" content="#003366">
  <link rel="stylesheet" href="css/estilos.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/processing_overlay.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/descargas.css?v=<?php echo APP_VERSION; ?>">
</head>

<body>
  <div class="container">

    <?php include "includes/topbar.php"; ?>

    <img id="logo" src="https://disvent.com/media/logo-disvent-doofinder.png" alt="Disvent Ingenieros">

    <h1>Descargar Documentación</h1>

    <div id="form-message"></div>

    <form id="automation-form">
      <input type="hidden" id="csrf_token" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
      <input type="hidden" id="user_id" name="user_id" value="<?php echo (int)($_SESSION['user_id'] ?? 0); ?>">
      <div>
        <label for="comercial">Comercial</label>
        <input type="text"
               id="comercial"
               name="comercial"
               value="<?php echo htmlspecialchars($_SESSION['comercial'], ENT_QUOTES, 'UTF-8'); ?>"
               readonly
               required>
      </div>

      <div>
        <label for="mes">Mes</label>
        <select id="mes" name="mes" required>
          <option value="">-- Selecciona --</option>
          <option value="1">Enero</option>
          <option value="2">Febrero</option>
          <option value="3">Marzo</option>
          <option value="4">Abril</option>
          <option value="5">Mayo</option>
          <option value="6">Junio</option>
          <option value="7">Julio</option>
          <option value="8">Agosto</option>
          <option value="9">Septiembre</option>
          <option value="10">Octubre</option>
          <option value="11">Noviembre</option>
          <option value="12">Diciembre</option>
        </select>
      </div>

      <div>
        <label for="año">Año</label>
        <input type="number" id="año" name="año" value="<?php echo date('Y'); ?>" required>
      </div>

      <div class="download-actions">
        <!--<button type="submit" name="accion" value="excel">
          Descargar Excel Nota de Gastos
        </button>-->

        <button type="submit" name="accion" value="tickets">
          Descargar Nota Gastos Visa
        </button>

        <button type="submit" name="accion" value="efectivo_kms" class="efectivo-kms-download-button">
          Descargar Nota Gastos Efectivo y Kilometraje
        </button>

        <button type="submit" name="accion" value="jornada" id="descargarRegistroJornada" class="jornada-download-button">
          Descargar Registro de Jornada
        </button>
      </div>
    </form>

  </div>

  <div class="volver-container">
    <a href="index.html" class="volver-btn">Volver</a>
  </div>

  <script>
    window.APP_CONFIG = {
      USER_ID: <?php echo (int)($_SESSION['user_id'] ?? 0); ?>,
      CSRF_TOKEN: <?php echo json_encode(csrfToken()); ?>
    };
  </script>

  <script src="js/processing_overlay.js?v=<?php echo APP_VERSION; ?>"></script>
  <script src="js/descargar_fichaje.js?v=<?php echo APP_VERSION; ?>"></script>
  <script src="js/app.js?v=<?php echo APP_VERSION; ?>"></script>
</body>
</html>

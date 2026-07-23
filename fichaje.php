<?php
require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/fichaje.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['user'] ?? '';
$comercial = $_SESSION['comercial'] ?? $username;
$now = fichajeNow();
$fechaHoy = $now->format('Y-m-d');
$horaActual = $now->format('H:i');
$diaSemana = fichajeDiaSemana($fechaHoy);
$accionSiguiente = fichajeAccionSiguiente($conn, $userId);
$resumenHoy = fichajeTableExists($conn, 'fichajes') ? fichajeGetResumen($conn, $userId, $fechaHoy) : null;
$marcasHoy = $resumenHoy ? fichajeGetMarcas($conn, (int)$resumenHoy['id']) : [];
$horasObjetivo = fichajeMinutosAHHMM(fichajeObjetivoMinutosConCalendario($conn, $fechaHoy, $userId));
$horasRealizadas = $resumenHoy['horas_realizadas'] ?? '00:00';
$diferencia = $resumenHoy['diferencia'] ?? fichajeDiferenciaHHMM(0 - fichajeObjetivoMinutosConCalendario($conn, $fechaHoy, $userId));
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Fichaje horario</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="css/estilos.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/processing_overlay.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/fichaje.css?v=<?php echo APP_VERSION; ?>">
</head>
<body class="fichaje-page">
  <main class="fichaje-shell">
    <div class="fichaje-layout">
      <section class="fichaje-card">
        <?php include "includes/topbar.php"; ?>

        <img class="fichaje-logo" src="https://disvent.com/media/logo-disvent-doofinder.png" alt="Disvent Ingenieros">

      <div class="fichaje-title">
        <h1>Fichaje horario</h1>
        <p><?php echo h($comercial); ?> · <?php echo h(ucfirst($diaSemana)); ?> <?php echo h($now->format('d-m-Y')); ?> · <?php echo h($horaActual); ?></p>
      </div>

      <div id="form-message"></div>

      <div class="fichaje-status">
        <div class="fichaje-kpi">
          <span>Objetivo</span>
          <strong id="horasObjetivo"><?php echo h($horasObjetivo); ?></strong>
        </div>
        <div class="fichaje-kpi">
          <span>Realizadas</span>
          <strong id="horasRealizadas"><?php echo h($horasRealizadas); ?></strong>
        </div>
        <div class="fichaje-kpi">
          <span>Diferencia</span>
          <strong id="diferenciaDia"><?php echo h($diferencia); ?></strong>
        </div>
      </div>

      <div class="fichaje-next">
        <strong>Siguiente acción:</strong>
        <span id="accionTexto"><?php echo $accionSiguiente === 'entrada' ? 'Registrar entrada' : 'Registrar salida'; ?></span>
      </div>

      <form id="fichaje-form" class="fichaje-form-grid">
        <input type="hidden" id="accion" name="accion" value="<?php echo h($accionSiguiente); ?>">

        <div id="motivoSalidaBox" class="<?php echo $accionSiguiente === 'salida' ? '' : 'fichaje-hidden'; ?>">
          <label class="fichaje-label" for="motivo">Motivo de salida</label>
          <select class="fichaje-select" id="motivo" name="motivo">
            <option value="fin_jornada">Fin de jornada</option>
            <option value="comida">Comida / pausa</option>
            <option value="medico">Médico</option>
            <option value="personal">Personal</option>
            <option value="otro">Otro</option>
          </select>
        </div>

        <div id="notaSalidaBox" class="fichaje-hidden">
          <label class="fichaje-label" for="nota">Nota breve</label>
          <input class="fichaje-input" type="text" id="nota" name="nota" maxlength="255" placeholder="Indica el motivo brevemente">
        </div>

        <button class="fichaje-main-button" id="fichajeSubmit" type="submit">
          <?php echo $accionSiguiente === 'entrada' ? 'Registrar entrada' : 'Registrar salida'; ?>
        </button>
      </form>

      <div class="fichaje-marcas" id="marcasHoy">
        <?php if (count($marcasHoy) === 0): ?>
          <div class="fichaje-marca"><span>No hay marcas registradas hoy.</span><strong>—</strong></div>
        <?php else: ?>
          <?php foreach ($marcasHoy as $marca): ?>
            <div class="fichaje-marca">
              <span><?php echo h(ucfirst($marca['tipo'])); ?> · <?php echo h(str_replace('_', ' ', $marca['motivo'])); ?></span>
              <strong><?php echo h($marca['hora']); ?></strong>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      </section>

      <div class="volver-container fichaje-volver-container">
        <a class="volver-btn" href="home.php">Volver</a>
      </div>
    </div>
  </main>

  <script src="js/processing_overlay.js?v=<?php echo APP_VERSION; ?>"></script>
  <script src="js/fichaje.js?v=<?php echo APP_VERSION; ?>"></script>
</body>
</html>

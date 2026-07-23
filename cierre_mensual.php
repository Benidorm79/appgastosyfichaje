<?php
require "session.php";
include "db.php";
require_once "config.php";
require_once "includes/functions.php";
require_once "includes/gastos_unificados.php";

function cierreColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);

  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

  return $result && $result->num_rows > 0;
}

function cierreGetMonthName($month) {
  $months = [
    1 => 'Enero',
    2 => 'Febrero',
    3 => 'Marzo',
    4 => 'Abril',
    5 => 'Mayo',
    6 => 'Junio',
    7 => 'Julio',
    8 => 'Agosto',
    9 => 'Septiembre',
    10 => 'Octubre',
    11 => 'Noviembre',
    12 => 'Diciembre'
  ];

  return $months[(int)$month] ?? '';
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['user'] ?? '';
$comercial = $_SESSION['comercial'] ?? $username;

$currentMonth = (int)date('n');
$currentYear = (int)date('Y');

$mes = intval($_GET['mes'] ?? $currentMonth);
$anio = intval($_GET['anio'] ?? $currentYear);

if ($mes < 1 || $mes > 12) {
  $mes = $currentMonth;
}

if ($anio < 2000 || $anio > 2100) {
  $anio = $currentYear;
}

$tab = trim((string)($_GET['tab'] ?? 'visa'));
if (!in_array($tab, ['visa', 'efectivo'], true)) {
  $tab = 'visa';
}

$fechaImputacionExiste = cierreColumnExists($conn, 'gastos', 'fecha_imputacion');

if ($fechaImputacionExiste) {
  $fechaPeriodo = "COALESCE(fecha_imputacion, fecha_ticket)";
} else {
  $fechaPeriodo = "fecha_ticket";
}

$sqlTotal = "SELECT 
               COUNT(*) AS total_gastos,
               COALESCE(SUM(COALESCE(importe_detectado, 0)), 0) AS total_importe
             FROM gastos
             WHERE deleted_at IS NULL
               AND estado IN ('procesado', 'editado')
               AND user_id = ?
               AND $fechaPeriodo IS NOT NULL
               AND MONTH($fechaPeriodo) = ?
               AND YEAR($fechaPeriodo) = ?";

$stmtTotal = $conn->prepare($sqlTotal);

$totalGastos = 0;
$totalApp = 0.00;

if ($stmtTotal) {
  $stmtTotal->bind_param("iii", $userId, $mes, $anio);
  $stmtTotal->execute();
  $resumen = $stmtTotal->get_result()->fetch_assoc();

  $totalGastos = (int)($resumen['total_gastos'] ?? 0);
  $totalApp = (float)($resumen['total_importe'] ?? 0);
}

$sqlCierre = "SELECT *
              FROM cierres_mensuales
              WHERE user_id = ?
                AND mes = ?
                AND anio = ?
              LIMIT 1";

$stmtCierre = $conn->prepare($sqlCierre);
$stmtCierre->bind_param("iii", $userId, $mes, $anio);
$stmtCierre->execute();
$cierre = $stmtCierre->get_result()->fetch_assoc();

$importeBanco = $cierre['importe_banco'] ?? '';
$comentariosComercial = $cierre['comentarios_comercial'] ?? '';
$estado = $cierre['estado'] ?? '';
$diferencia = $cierre['diferencia'] ?? null;
$comentariosAdmin = $cierre['comentarios_admin'] ?? '';

$mensaje = appPublicMessage($_GET['msg'] ?? '');
$tipoMensaje = $_GET['type'] ?? 'success';

$periodoNombre = cierreGetMonthName($mes) . ' ' . $anio;

$estadoClass = 'warning';

if ($estado === 'validado') {
  $estadoClass = 'ok';
} elseif ($estado === 'rechazado' || $estado === 'con_diferencia') {
  $estadoClass = 'error';
}

$cierreBloqueado = in_array($estado, ['validado', 'con_diferencia', 'rechazado'], true);
$cierreEfectivoDisponible = gastosUnificadosTableExists($conn, 'cierres_mensuales_efectivo');
$resumenEfectivo = gastosUnificadosTotalEfectivo($conn, $userId, $mes, $anio);
$cierreEfectivo = $cierreEfectivoDisponible
  ? gastosUnificadosCierreEfectivo($conn, $userId, $mes, $anio)
  : null;
$estadoEfectivo = (string)($cierreEfectivo['estado'] ?? '');
$importeDeclaradoEfectivo = $cierreEfectivo['importe_banco'] ?? '';
$comentariosEfectivo = (string)($cierreEfectivo['comentarios_comercial'] ?? '');
$diferenciaEfectivo = $cierreEfectivo['diferencia'] ?? null;
$comentariosAdminEfectivo = (string)($cierreEfectivo['comentarios_admin'] ?? '');
$contabilizadoEfectivo = $cierreEfectivo
  ? gastosUnificadosCierreEfectivoContabilizado($conn, (int)$cierreEfectivo['id'])
  : false;
$cierreEfectivoBloqueado = $contabilizadoEfectivo ||
  in_array($estadoEfectivo, ['validado', 'con_diferencia', 'rechazado'], true);
$estadoEfectivoClass = 'warning';
if ($contabilizadoEfectivo || $estadoEfectivo === 'validado') {
  $estadoEfectivoClass = 'ok';
} elseif (in_array($estadoEfectivo, ['con_diferencia', 'rechazado'], true)) {
  $estadoEfectivoClass = 'error';
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cierre mensual</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="css/estilos.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/processing_overlay.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/cierre.css?v=<?php echo APP_VERSION; ?>">

  <style>
    .cierre-page-title {
      margin-bottom: 22px !important;
    }

    .cierre-status {
      margin: 0 0 24px 0 !important;
      padding: 16px 18px !important;
      border-radius: 14px !important;
      line-height: 1.5 !important;
      font-size: 14px !important;
      font-weight: 600 !important;
      color: #111827 !important;
      box-shadow: none !important;
    }

    .cierre-status strong {
      display: block !important;
      margin-bottom: 6px !important;
      color: #111827 !important;
      font-size: 15px !important;
      font-weight: 900 !important;
    }

    .cierre-status.warning {
      background: #fffbeb !important;
      border: 1px solid #d97706 !important;
      color: #111827 !important;
    }

    .cierre-status.ok {
      background: #dcfce7 !important;
      border: 1px solid #16a34a !important;
      color: #111827 !important;
    }

    .cierre-status.error {
      background: #fee2e2 !important;
      border: 1px solid #dc2626 !important;
      color: #111827 !important;
    }

    .cierre-intro {
      margin: 0 0 18px 0 !important;
      color: #475569 !important;
      line-height: 1.45 !important;
      font-size: 14px !important;
      text-align: center !important;
    }

    .cierre-filter-form {
      display: grid !important;
      grid-template-columns: minmax(0, 1fr) 105px 165px !important;
      gap: 10px !important;
      align-items: end !important;
      margin: 0 0 18px 0 !important;
      width: 100% !important;
    }

    .cierre-filter-form .cierre-filter-field,
    .cierre-filter-form .cierre-filter-action {
      min-width: 0 !important;
      width: auto !important;
      margin: 0 !important;
    }

    .cierre-filter-form label {
      display: block !important;
      margin: 0 0 6px 0 !important;
      color: #0f172a !important;
      font-size: 14px !important;
      font-weight: 800 !important;
    }

    .cierre-filter-form select,
    .cierre-filter-form input {
      width: 100% !important;
      height: 42px !important;
      min-height: 42px !important;
      margin: 0 !important;
      padding: 0 12px !important;
      border-radius: 10px !important;
      border: 1px solid #cbd5e1 !important;
      background: #ffffff !important;
      color: #0f172a !important;
      font-size: 14px !important;
      box-sizing: border-box !important;
    }

    .cierre-filter-form button {
      width: 100% !important;
      height: 42px !important;
      min-height: 42px !important;
      margin: 0 !important;
      padding: 0 12px !important;
      border-radius: 10px !important;
      font-size: 13px !important;
      font-weight: 900 !important;
      white-space: nowrap !important;
      box-sizing: border-box !important;
    }

    .cierre-total-app {
      display: block !important;
      margin: 0 0 22px 0 !important;
      padding: 18px !important;
      border-radius: 14px !important;
      background: #f8fafc !important;
      border: 1px solid #cbd5e1 !important;
      color: #0f172a !important;
    }

    .cierre-total-app span {
      display: block !important;
      font-size: 13px !important;
      font-weight: 900 !important;
      color: #475569 !important;
      margin-bottom: 8px !important;
    }

    .cierre-total-app strong {
      display: block !important;
      font-size: 28px !important;
      line-height: 1.1 !important;
      color: #003366 !important;
      margin-bottom: 8px !important;
    }

    .cierre-total-app small {
      display: block !important;
      color: #64748b !important;
      font-size: 12px !important;
      line-height: 1.35 !important;
    }

    .cierre-note {
      margin: 0 0 18px 0 !important;
      font-size: 13px !important;
      line-height: 1.45 !important;
      color: #475569 !important;
    }

    .cierre-note-alert {
      display: block !important;
      margin: 0 0 20px 0 !important;
      padding: 12px 14px !important;
      border-radius: 12px !important;
      background: #fef2f2 !important;
      border: 1px solid #ef4444 !important;
      color: #b91c1c !important;
      font-size: 15px !important;
      line-height: 1.45 !important;
      font-weight: 900 !important;
      text-align: center !important;
    }

    .cierre-section {
      margin-top: 28px !important;
      padding-top: 26px !important;
      border-top: 2px solid #dbe5ef !important;
    }

    .cierre-section-title {
      margin: 0 0 8px 0 !important;
      color: #003366 !important;
      font-size: 22px !important;
      font-weight: 900 !important;
      text-align: center !important;
    }

    .cierre-section-subtitle {
      margin: 0 0 18px 0 !important;
      color: #64748b !important;
      font-size: 13px !important;
      text-align: center !important;
    }

    .cierre-breakdown {
      display: grid !important;
      grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      gap: 10px !important;
      margin-top: 12px !important;
    }

    .cierre-breakdown div {
      padding: 10px !important;
      border-radius: 10px !important;
      background: #ffffff !important;
      border: 1px solid #e2e8f0 !important;
    }

    .cierre-breakdown span,
    .cierre-breakdown strong {
      display: block !important;
    }

    .cierre-breakdown span {
      font-size: 11px !important;
      color: #64748b !important;
      margin-bottom: 4px !important;
    }

    .cierre-breakdown strong {
      font-size: 15px !important;
      color: #0f172a !important;
    }

    .cierre-readonly {
      opacity: 0.78 !important;
    }

    .cierre-readonly input,
    .cierre-readonly textarea,
    .cierre-readonly button {
      pointer-events: none !important;
      opacity: 0.70 !important;
    }

    .cierre-tabs {
      display: flex !important;
      gap: 8px !important;
      flex-wrap: wrap !important;
      margin: 0 0 22px 0 !important;
    }

    .cierre-tabs a {
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      min-height: 42px !important;
      padding: 9px 15px !important;
      border-radius: 999px !important;
      text-decoration: none !important;
      font-weight: 900 !important;
      color: #003366 !important;
      background: #ffffff !important;
      border: 1px solid #cbd5e1 !important;
    }

    .cierre-tabs a.active {
      background: #003366 !important;
      color: #ffffff !important;
      border-color: #003366 !important;
      box-shadow: 0 8px 18px rgba(0, 51, 102, 0.18) !important;
    }

    .cierre-tab-panel {
      margin-top: 0 !important;
    }

    @media (max-width: 520px) {
      .cierre-filter-form {
        grid-template-columns: minmax(0, 1fr) 92px 145px !important;
        gap: 8px !important;
      }

      .cierre-filter-form button {
        font-size: 12px !important;
        padding: 0 8px !important;
      }
    }

    @media (max-width: 430px) {
      .cierre-filter-form {
        grid-template-columns: 1fr !important;
      }

      .cierre-tabs {
        display: grid !important;
        grid-template-columns: 1fr !important;
      }

      .cierre-total-app strong {
        font-size: 24px !important;
      }
    }
  </style>
</head>

<body>
  <div class="container">

    <?php include "includes/topbar.php"; ?>

    <h1 class="cierre-page-title">Cierre Mensual</h1>

    <?php if ($mensaje !== ''): ?>
      <div class="<?php echo $tipoMensaje === 'error' ? 'error' : 'success'; ?>">
        <?php echo h($mensaje); ?>
      </div>
    <?php endif; ?>

    <p class="cierre-intro">
      Selecciona el periodo, revisa el total registrado por la app e introduce el importe total de tu liquidación bancaria.
    </p>

    <form method="get" action="cierre_mensual.php" class="cierre-filter-form">
      <input type="hidden" name="tab" value="<?php echo h($tab); ?>">
      <div class="cierre-filter-field">
        <label for="mes">Mes</label>
        <select id="mes" name="mes" required>
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?php echo $m; ?>" <?php echo $mes === $m ? 'selected' : ''; ?>>
              <?php echo h(cierreGetMonthName($m)); ?>
            </option>
          <?php endfor; ?>
        </select>
      </div>

      <div class="cierre-filter-field cierre-filter-year">
        <label for="anio">Año</label>
        <input type="number" id="anio" name="anio" value="<?php echo (int)$anio; ?>" min="2000" max="2100" required>
      </div>

      <div class="cierre-filter-action">
        <button type="submit">Consultar periodo</button>
      </div>
    </form>

    <nav class="cierre-tabs" aria-label="Tipos de cierre mensual">
      <a class="<?php echo $tab === 'visa' ? 'active' : ''; ?>" href="?mes=<?php echo (int)$mes; ?>&anio=<?php echo (int)$anio; ?>&tab=visa">Cierre mensual VISA</a>
      <a class="<?php echo $tab === 'efectivo' ? 'active' : ''; ?>" href="?mes=<?php echo (int)$mes; ?>&anio=<?php echo (int)$anio; ?>&tab=efectivo">Cierre Efectivo y Kms</a>
    </nav>

    <?php if ($tab === 'visa'): ?>
    <section id="cierre-visa" class="cierre-tab-panel">
      <h2 class="cierre-section-title">VISA</h2>
      <p class="cierre-section-subtitle">Liquidación de gastos abonados con tarjeta.</p>

      <?php if ($cierre): ?>
        <div class="cierre-status <?php echo h($estadoClass); ?>">
          <strong>Cierre registrado</strong>
          Estado actual: <?php echo h(formatEstadoWeb($estado)); ?><br>
          Importe banco: <?php echo h(number_format((float)$importeBanco, 2, ',', '.')); ?> €<br>
          Diferencia actual: <?php echo h(number_format((float)$diferencia, 2, ',', '.')); ?> €

          <?php if (trim((string)$comentariosAdmin) !== ''): ?>
            <br><br>
            Comentarios de administración:<br>
            <?php echo nl2br(h($comentariosAdmin)); ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    <div class="cierre-total-app">
      <span>Total VISA registrado en la app</span>
      <strong><?php echo h(number_format($totalApp, 2, ',', '.')); ?> €</strong>
      <small>
        Periodo: <?php echo h($periodoNombre); ?> · Gastos incluidos: <?php echo (int)$totalGastos; ?>
      </small>
    </div>

    <?php if ($cierreBloqueado): ?>
      <p class="cierre-note cierre-note-alert">
        Este cierre ya ha sido revisado por administración y no puede modificarse desde esta pantalla.
      </p>
    <?php else: ?>
      <p class="cierre-note">
        Introduce el importe total que aparece en tu liquidación bancaria. Verifica que el importe que tenemos registrado coincide con tu liquidación.
      </p>
    <?php endif; ?>

    <form method="post" action="procesar_cierre_mensual.php" class="<?php echo $cierreBloqueado ? 'cierre-readonly' : ''; ?>" data-processing-overlay data-processing-message="Estamos enviando el cierre mensual. Espera unos segundos, por favor.">
      <input type="hidden" name="mes" value="<?php echo (int)$mes; ?>">
      <input type="hidden" name="anio" value="<?php echo (int)$anio; ?>">

      <div>
        <label for="importe_banco">Importe total liquidación bancaria</label>
        <input 
          type="text"
          inputmode="decimal"
          id="importe_banco" 
          name="importe_banco" 
          value="<?php echo h($importeBanco !== '' ? number_format((float)$importeBanco, 2, ',', '') : ''); ?>" 
          placeholder="0,00"
          required
        >
      </div>

      <div>
        <label for="comentarios_comercial">Comentarios</label>
        <textarea id="comentarios_comercial" name="comentarios_comercial" placeholder="Añade cualquier aclaración sobre el cierre mensual"><?php echo h($comentariosComercial); ?></textarea>
      </div>

      <button type="submit">Guardar cierre mensual</button>
    </form>
    </section>
    <?php endif; ?>

    <?php if ($tab === 'efectivo'): ?>
    <section id="cierre-efectivo" class="cierre-tab-panel">
      <h2 class="cierre-section-title">Efectivo y Kilometraje</h2>
      <p class="cierre-section-subtitle">
        Cierre independiente de los gastos abonados en efectivo y de los desplazamientos por kilometraje.
      </p>

      <?php if (!$cierreEfectivoDisponible): ?>
        <div class="error">
          Este apartado todavía no está disponible.
        </div>
      <?php else: ?>
        <?php if ($cierreEfectivo): ?>
          <div class="cierre-status <?php echo h($estadoEfectivoClass); ?>">
            <strong>Cierre de Efectivo y Kilometraje registrado</strong>
            Estado actual:
            <?php echo $contabilizadoEfectivo ? 'Contabilizado' : h(formatEstadoWeb($estadoEfectivo)); ?><br>
            Importe declarado:
            <?php echo h(number_format((float)$importeDeclaradoEfectivo, 2, ',', '.')); ?> €<br>
            Diferencia actual:
            <?php echo h(number_format((float)$diferenciaEfectivo, 2, ',', '.')); ?> €

            <?php if ($comentariosAdminEfectivo !== ''): ?>
              <br><br>
              Comentarios de administración:<br>
              <?php echo nl2br(h($comentariosAdminEfectivo)); ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="cierre-total-app">
          <span>Total Efectivo y Kilometraje registrado en la app</span>
          <strong><?php echo h(number_format((float)$resumenEfectivo['total_importe'], 2, ',', '.')); ?> €</strong>
          <small>
            Periodo: <?php echo h($periodoNombre); ?> ·
            Registros incluidos: <?php echo (int)$resumenEfectivo['total_registros']; ?>
          </small>

          <div class="cierre-breakdown">
            <div>
              <span>Gastos en efectivo</span>
              <strong><?php echo h(number_format((float)$resumenEfectivo['total_efectivo'], 2, ',', '.')); ?> €</strong>
            </div>
            <div>
              <span>Kilometraje</span>
              <strong><?php echo h(number_format((float)$resumenEfectivo['total_kilometraje'], 2, ',', '.')); ?> €</strong>
            </div>
          </div>
        </div>

        <?php if ($cierreEfectivoBloqueado): ?>
          <p class="cierre-note cierre-note-alert">
            Este cierre ya ha sido revisado por dirección o contabilizado y no puede modificarse desde esta pantalla.
          </p>
        <?php else: ?>
          <p class="cierre-note">
            Comprueba el total de efectivo y kilometraje e introduce el importe que deseas presentar para revisión.
          </p>
        <?php endif; ?>

        <form
          method="post"
          action="procesar_cierre_efectivo.php"
          class="<?php echo $cierreEfectivoBloqueado ? 'cierre-readonly' : ''; ?>"
          data-processing-overlay
          data-processing-message="Estamos enviando el cierre de Efectivo y Kilometraje. Espera unos segundos, por favor."
        >
          <input type="hidden" name="mes" value="<?php echo (int)$mes; ?>">
          <input type="hidden" name="anio" value="<?php echo (int)$anio; ?>">

          <div>
            <label for="importe_declarado">Importe total Efectivo y Kilometraje</label>
            <input
              type="text"
              inputmode="decimal"
              id="importe_declarado"
              name="importe_declarado"
              value="<?php echo h($importeDeclaradoEfectivo !== '' ? number_format((float)$importeDeclaradoEfectivo, 2, ',', '') : number_format((float)$resumenEfectivo['total_importe'], 2, ',', '')); ?>"
              placeholder="0,00"
              required
            >
          </div>

          <div>
            <label for="comentarios_comercial_efectivo">Comentarios</label>
            <textarea
              id="comentarios_comercial_efectivo"
              name="comentarios_comercial_efectivo"
              placeholder="Añade cualquier aclaración sobre el cierre de Efectivo y Kilometraje"
            ><?php echo h($comentariosEfectivo); ?></textarea>
          </div>

          <button type="submit">Guardar cierre de Efectivo y Kilometraje</button>
        </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <div class="volver-container">
      <a href="home.php" class="volver-btn">Volver al inicio</a>
    </div>

  </div>
  <script src="js/processing_overlay.js?v=<?php echo APP_VERSION; ?>"></script>
</body>
</html>

<?php
require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/fichaje.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

$esAdmin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'master'], true);
$userIdSesion = (int)($_SESSION['user_id'] ?? 0);
$mes = intval($_GET['mes'] ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));
$usuarioFiltro = $esAdmin ? intval($_GET['user_id'] ?? 0) : $userIdSesion;

if ($mes < 1 || $mes > 12) $mes = (int)date('n');
if ($anio < 2020 || $anio > 2100) $anio = (int)date('Y');

$usuarios = $esAdmin ? fichajeUsuariosConsulta($conn) : [];
$tablasOk = fichajeTableExists($conn, 'fichajes') && fichajeTableExists($conn, 'fichaje_marcas');
$resumenes = [];
$marcasPorFichaje = [];
$totRealizado = 0;
$diasAuto = 0;
$diasRegistrados = 0;

$usuariosObjetivoIds = [];

if ($esAdmin && $usuarioFiltro === 0) {
  foreach ($usuarios as $usuario) {
    $idUsuarioObjetivo = (int)($usuario['id'] ?? 0);
    if ($idUsuarioObjetivo > 0) {
      $usuariosObjetivoIds[] = $idUsuarioObjetivo;
    }
  }
} elseif ($usuarioFiltro > 0) {
  $usuariosObjetivoIds[] = $usuarioFiltro;
} else {
  $usuariosObjetivoIds[] = $userIdSesion;
}

$usuariosObjetivoIds = array_values(array_unique(array_filter($usuariosObjetivoIds)));
$calendarioLaboral = fichajeCalendarioLaboralMes($conn, $anio, $mes, $usuariosObjetivoIds);
$diasLaborables = (int)$calendarioLaboral['dias_laborables'];
$totObjetivo = (int)$calendarioLaboral['minutos_objetivo'];
$usuariosObjetivo = max(1, count($usuariosObjetivoIds));
$ausenciasMes = (int)($calendarioLaboral['ausencias_count'] ?? 0);

if ($tablasOk) {
  $desde = sprintf('%04d-%02d-01', $anio, $mes);
  $hasta = date('Y-m-d', strtotime($desde . ' +1 month'));

  $where = "fecha >= ? AND fecha < ?";
  $types = "ss";
  $params = [$desde, $hasta];

  if ($esAdmin && $usuarioFiltro > 0) {
    $where .= " AND user_id = ?";
    $types .= "i";
    $params[] = $usuarioFiltro;
  } elseif (!$esAdmin) {
    $where .= " AND user_id = ?";
    $types .= "i";
    $params[] = $userIdSesion;
  }

  $sql = "SELECT * FROM fichajes WHERE $where ORDER BY fecha DESC, comercial ASC";
  $stmt = $conn->prepare($sql);

  if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($result && ($row = $result->fetch_assoc())) {
      $resumenes[] = $row;
      $marcasPorFichaje[(int)$row['id']] = fichajeGetMarcas($conn, (int)$row['id']);
      $diasRegistrados++;
      $totRealizado += fichajeHoraAMinutos($row['horas_realizadas']);
      if ((int)$row['auto_completado'] === 1 || $row['estado'] === 'auto_cerrado') $diasAuto++;
    }
  }
}

$diferenciaMensual = fichajeDiferenciaHHMM($totRealizado - $totObjetivo);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gestión de fichajes</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="css/estilos.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/processing_overlay.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/fichaje.css?v=<?php echo APP_VERSION; ?>">

  <style>
    .fichaje-export-button {
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      min-height: 44px !important;
      padding: 0 18px !important;
      border-radius: 999px !important;
      text-decoration: none !important;
      background: linear-gradient(135deg, #b7f7cf, #8ef0b6) !important;
      color: #004c46 !important;
      border: 1px solid rgba(183, 247, 207, 0.85) !important;
      box-shadow: 0 8px 18px rgba(142, 240, 182, 0.22) !important;
      font-weight: 800 !important;
    }

    .fichaje-export-button:hover {
      background: linear-gradient(135deg, #a8f3c5, #7ce9a9) !important;
      color: #003f3a !important;
      transform: translateY(-1px);
    }

    .fichaje-export-button:visited,
    .fichaje-export-button:active,
    .fichaje-export-button:focus {
      color: #004c46 !important;
    }
  </style>
</head>

<body>
  <div class="container wide-container fichaje-gestion-container">
    <?php include "includes/topbar.php"; ?>

    <header class="fichaje-user-header">
      <div>
        <h1>Gestión de fichajes</h1>
        <p>Consulta mensual de registros de jornada.</p>
      </div>

      <div class="fichaje-user-actions">
        <a class="fichaje-export-button" href="exportar_fichajes_excel.php?mes=<?php echo (int)$mes; ?>&anio=<?php echo (int)$anio; ?><?php echo $esAdmin ? '&user_id=' . (int)$usuarioFiltro : ''; ?>">Exportar registro mensual</a>
      </div>
    </header>

    <?php if (!$tablasOk): ?>
      <div class="error">Este apartado todavía no está disponible.</div>
    <?php else: ?>
      <section class="fichaje-filter-card">
        <form class="fichaje-filter-form" method="get" action="gestion_fichajes.php">
          <div>
            <label for="mes">Mes</label>
            <select id="mes" name="mes">
              <?php for ($i = 1; $i <= 12; $i++): ?>
                <option value="<?php echo $i; ?>" <?php echo $mes === $i ? 'selected' : ''; ?>><?php echo str_pad((string)$i, 2, '0', STR_PAD_LEFT); ?></option>
              <?php endfor; ?>
            </select>
          </div>

          <div>
            <label for="anio">Año</label>
            <input id="anio" name="anio" type="number" min="2020" max="2100" value="<?php echo (int)$anio; ?>">
          </div>

          <?php if ($esAdmin): ?>
            <div>
              <label for="user_id">Usuario</label>
              <select id="user_id" name="user_id">
                <option value="0">Todos</option>
                <?php foreach ($usuarios as $usuario): ?>
                  <option value="<?php echo (int)$usuario['id']; ?>" <?php echo $usuarioFiltro === (int)$usuario['id'] ? 'selected' : ''; ?>>
                    <?php echo h($usuario['comercial'] ?: $usuario['username']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <div class="fichaje-filter-actions">
            <button type="submit">Consultar</button>
          </div>
        </form>
      </section>

      <section class="fichaje-kpi-grid fichaje-gestion-kpis">
        <article class="fichaje-gestion-kpi">
          <span>Días registrados</span>
          <strong><?php echo (int)$diasRegistrados; ?></strong>
          <small>Mes seleccionado</small>
        </article>

        <article class="fichaje-gestion-kpi">
          <span>Días laborables</span>
          <strong><?php echo (int)$diasLaborables; ?></strong>
          <small>Calendario laboral</small>
        </article>

        <article class="fichaje-gestion-kpi">
          <span>Vacaciones / libres</span>
          <strong><?php echo (int)$ausenciasMes; ?></strong>
          <small>Días personales no computados</small>
        </article>

        <article class="fichaje-gestion-kpi">
          <span>Objetivo mensual</span>
          <strong><?php echo h(fichajeMinutosAHHMM($totObjetivo)); ?></strong>
          <small><?php echo $usuariosObjetivo > 1 ? h($usuariosObjetivo . ' usuarios incluidos') : 'Horas previstas'; ?></small>
        </article>

        <article class="fichaje-gestion-kpi">
          <span>Realizado mensual</span>
          <strong><?php echo h(fichajeMinutosAHHMM($totRealizado)); ?></strong>
          <small>Horas registradas</small>
        </article>

        <article class="fichaje-gestion-kpi">
          <span>Diferencia</span>
          <strong><?php echo h($diferenciaMensual); ?></strong>
          <small>Saldo mensual</small>
        </article>

        <article class="fichaje-gestion-kpi">
          <span>Auto completados</span>
          <strong><?php echo (int)$diasAuto; ?></strong>
          <small>Días con cierre automático</small>
        </article>
      </section>

      <?php if (count($calendarioLaboral['festivos']) > 0): ?>
        <section class="fichaje-holidays-note">
          <strong>Festivos del mes no computados:</strong>
          <?php $festivosTexto = []; ?>
          <?php foreach ($calendarioLaboral['festivos'] as $fechaFestivo => $nombreFestivo): ?>
            <?php $festivosTexto[] = formatFechaWeb($fechaFestivo) . ' · ' . $nombreFestivo; ?>
          <?php endforeach; ?>
          <?php echo h(implode(' | ', $festivosTexto)); ?>
        </section>
      <?php endif; ?>

      <?php if ($ausenciasMes > 0): ?>
        <section class="fichaje-holidays-note">
          <strong>Vacaciones y días libres no computados:</strong>
          <?php $ausenciasTexto = []; ?>
          <?php foreach (($calendarioLaboral['ausencias'] ?? []) as $ausenciasUsuario): ?>
            <?php foreach ($ausenciasUsuario as $ausencia): ?>
              <?php $ausenciasTexto[] = formatFechaWeb($ausencia['fecha']) . ' · ' . (($ausencia['tipo'] ?? '') === 'vacaciones' ? 'Vacaciones' : 'Día libre') . (trim((string)($ausencia['descripcion'] ?? '')) !== '' ? ' · ' . trim((string)$ausencia['descripcion']) : ''); ?>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php echo h(implode(' | ', $ausenciasTexto)); ?>
        </section>
      <?php endif; ?>

      <section class="fichaje-records-panel">
        <h2>Registros del mes</h2>
        <div id="form-message"></div>

        <?php if (count($resumenes) === 0): ?>
          <div class="empty-message">No hay fichajes registrados para el periodo seleccionado.</div>
        <?php endif; ?>

        <?php foreach ($resumenes as $resumen): ?>
          <?php $marcas = $marcasPorFichaje[(int)$resumen['id']] ?? []; $principal = fichajeClasificarResumenPrincipal($marcas, $resumen['fecha']); ?>
          <article class="fichaje-day-card">
            <div class="fichaje-day-head">
              <div>
                <div class="fichaje-day-title"><?php echo h(formatFechaWeb($resumen['fecha'])); ?> · <?php echo h(ucfirst($resumen['dia_semana'])); ?></div>
                <div class="fichaje-day-subtitle"><?php echo h($resumen['comercial']); ?> · <?php echo h(formatEstadoWeb($resumen['estado'])); ?></div>
              </div>
            </div>

            <div class="fichaje-day-summary">
              <div><span>Entrada mañana</span><strong><?php echo h($principal['entrada_manana'] ?: '—'); ?></strong></div>
              <div><span>Salida mañana</span><strong><?php echo h($principal['salida_manana'] ?: '—'); ?></strong></div>
              <div><span>Entrada tarde</span><strong><?php echo h($principal['entrada_tarde'] ?: '—'); ?></strong></div>
              <div><span>Salida tarde</span><strong><?php echo h($principal['salida_tarde'] ?: '—'); ?></strong></div>
              <div><span>Diferencia</span><strong><?php echo h($resumen['diferencia']); ?></strong></div>
            </div>

            <div class="fichaje-day-summary fichaje-day-summary-secondary">
              <div><span>Objetivo</span><strong><?php echo h($resumen['horas_objetivo']); ?></strong></div>
              <div><span>Realizadas</span><strong><?php echo h($resumen['horas_realizadas']); ?></strong></div>
              <div><span>Estado</span><strong><?php echo h(formatEstadoWeb($resumen['estado'])); ?></strong></div>
              <div><span>Auto completado</span><strong><?php echo (int)$resumen['auto_completado'] === 1 ? 'Sí' : 'No'; ?></strong></div>
              <div><span>Marcas</span><strong><?php echo count($marcas); ?></strong></div>
            </div>

            <div class="fichaje-mark-list">
              <?php foreach ($marcas as $marca): ?>
                <div class="fichaje-mark-row">
                  <strong class="fichaje-mark-hora"><?php echo h($marca['hora']); ?></strong>
                  <span class="fichaje-mark-tipo"><?php echo h(ucfirst($marca['tipo'])); ?></span>
                  <span class="fichaje-mark-motivo"><?php echo h(str_replace('_', ' ', $marca['motivo'])); ?><?php echo trim((string)$marca['nota']) !== '' ? ' · ' . h($marca['nota']) : ''; ?><?php echo (int)$marca['auto_completado'] === 1 ? ' · Automático' : ''; ?></span>
                  <span class="fichaje-mark-firma"><?php echo h($marca['firma']); ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </div>

  <div class="volver-container wide-container">
    <a href="home.php" class="volver-btn">Volver</a>
  </div>

  <script>
    window.FICHAJE_EXPORT = {
      mes: <?php echo (int)$mes; ?>,
      anio: <?php echo (int)$anio; ?>,
      user_id: <?php echo (int)$usuarioFiltro; ?>
    };
  </script>
  <script src="js/processing_overlay.js?v=<?php echo APP_VERSION; ?>"></script>
  <script src="js/descargar_fichaje.js?v=<?php echo APP_VERSION; ?>"></script>
</body>
</html>

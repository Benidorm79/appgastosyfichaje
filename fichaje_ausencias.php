<?php
require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/fichaje.php";
require_once __DIR__ . "/includes/agenda_compartida.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = trim((string)($_SESSION['user'] ?? ''));
$comercial = trim((string)($_SESSION['comercial'] ?? $username));
$role = trim((string)($_SESSION['role'] ?? 'user'));
$esPrivilegiado = in_array($role, ['admin', 'master'], true);
$mes = intval($_GET['mes'] ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));
$mensajeOk = trim((string)($_GET['ok'] ?? ''));
$mensajeError = trim((string)($_GET['error'] ?? ''));
$tab = trim((string)($_GET['tab'] ?? 'calendario'));
if ($tab === 'registrar') $tab = 'calendario';
if (!in_array($tab, ['calendario','saldo'], true)) $tab = 'calendario';

if ($mes < 1 || $mes > 12) $mes = (int)date('n');
if ($anio < 2020 || $anio > 2100) $anio = (int)date('Y');

if (empty($_SESSION['fichaje_ausencias_csrf'])) {
  $_SESSION['fichaje_ausencias_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['fichaje_ausencias_csrf'];

$tablaDiasOk = fichajeAusenciasTableExists($conn);
$tablaPeriodosOk = fichajeAusenciasPeriodosTableExists($conn);
$tablasOk = $tablaDiasOk && $tablaPeriodosOk;
$ausenciasPropias = $tablaDiasOk ? fichajeAusenciasUsuarioMes($conn, $userId, $anio, $mes) : [];
$ausenciasCompartidas = $tablaDiasOk ? fichajeAusenciasCompartidasMes($conn, $anio, $mes) : [];
$periodosPropios = $tablaPeriodosOk ? fichajeAusenciasPeriodosUsuario($conn, $userId, $anio, $mes) : [];
$calendarioLaboral = fichajeCalendarioLaboralMes($conn, $anio, $mes, [$userId]);
$diasLaborables = (int)($calendarioLaboral['dias_laborables'] ?? 0);
$ausenciasMes = (int)($calendarioLaboral['ausencias_count'] ?? 0);
$objetivoMes = (int)($calendarioLaboral['minutos_objetivo'] ?? 0);
$saldoVacaciones = fichajeVacacionesSaldo($conn, $userId, $anio);
$creditosVacaciones = fichajeVacacionesCreditosUsuario($conn, $userId, $anio);
$agendaTablaOk = agendaCompartidaTableExists($conn);
$agendaMes = $agendaTablaOk ? agendaCompartidaEventosMes($conn, $anio, $mes) : [];
$fechaAgenda = trim((string)($_GET['fecha_agenda'] ?? ''));
$hoyLocal = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaAgenda)) {
  $fechaAgenda = ((int)date('Y') === $anio && (int)date('n') === $mes) ? $hoyLocal : sprintf('%04d-%02d-01', $anio, $mes);
}
$fechaAgendaObj = new DateTime($fechaAgenda, new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid'));
if ((int)$fechaAgendaObj->format('Y') !== $anio || (int)$fechaAgendaObj->format('n') !== $mes) {
  $fechaAgenda = sprintf('%04d-%02d-01', $anio, $mes);
  $fechaAgendaObj = new DateTime($fechaAgenda, new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid'));
}
$agendaDia = $agendaTablaOk ? agendaCompartidaEventosDia($conn, $fechaAgenda) : [];
$ausenciasDiaAgenda = array_values(array_filter($ausenciasCompartidas, function ($row) use ($fechaAgenda) { return (string)($row['fecha'] ?? '') === $fechaAgenda && (string)($row['jornada'] ?? 'completa') === 'completa'; }));

$editarActividadId = (int)($_GET['editar_actividad'] ?? 0);
$actividadEditar = $editarActividadId > 0 ? agendaCompartidaEventoPorId($conn, $editarActividadId) : null;
if ($actividadEditar && (int)$actividadEditar['user_id'] !== $userId && !in_array($role, ['admin','master'], true)) {
  $actividadEditar = null;
}
$editarPeriodoId = (int)($_GET['editar_periodo'] ?? 0);
$periodoEditar = $editarPeriodoId > 0 ? fichajeAusenciaPeriodoPorId($conn, $editarPeriodoId) : null;
if (
  $periodoEditar &&
  (int)$periodoEditar['user_id'] !== $userId &&
  !$esPrivilegiado
) {
  $periodoEditar = null;
}
if (
  $periodoEditar &&
  (string)$periodoEditar['fecha_fin'] < date('Y-m-d') &&
  !$esPrivilegiado
) {
  $periodoEditar = null;
  if ($mensajeError === '') {
    $mensajeError = 'Los periodos vencidos solo pueden ser modificados por Admin o Máster.';
  }
}

$fechaPrimera = new DateTime(sprintf('%04d-%02d-01', $anio, $mes), new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid'));
$mesAnterior = (clone $fechaPrimera)->modify('-1 month');
$mesSiguiente = (clone $fechaPrimera)->modify('+1 month');
$diasMes = (int)$fechaPrimera->format('t');
$primerDiaSemana = (int)$fechaPrimera->format('N');
$nombresMeses = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
$nombresDiasSemana = [1=>'lunes',2=>'martes',3=>'miércoles',4=>'jueves',5=>'viernes',6=>'sábado',7=>'domingo'];
$eventosPorFecha = [];

foreach ($ausenciasCompartidas as $ausencia) {
  $fecha = (string)$ausencia['fecha'];
  if (!isset($eventosPorFecha[$fecha])) $eventosPorFecha[$fecha] = [];
  $eventosPorFecha[$fecha][] = $ausencia;
}
$agendaPorFecha = [];
foreach ($agendaMes as $actividad) {
  $fecha = (string)$actividad['fecha'];
  if (!isset($agendaPorFecha[$fecha])) $agendaPorFecha[$fecha] = [];
  $agendaPorFecha[$fecha][] = $actividad;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Vacaciones y días libres</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="css/estilos.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/fichaje.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/processing_overlay.css?v=<?php echo APP_VERSION; ?>">
  <style>
    .vacaciones-export-button {
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      min-height: 42px !important;
      padding: 10px 16px !important;
      border-radius: 12px !important;
      background: linear-gradient(135deg, #b7f7cf, #8ef0b6) !important;
      color: #004c46 !important;
      border: 1px solid #65d997 !important;
      box-shadow: 0 10px 22px rgba(16, 185, 129, 0.20) !important;
      text-decoration: none !important;
      font-weight: 900 !important;
      white-space: nowrap !important;
    }
    .vacaciones-export-button:hover {
      transform: translateY(-1px);
      color: #003f3a !important;
    }
    .vacaciones-expired-label {
      display: inline-block;
      padding: 5px 9px;
      border-radius: 999px;
      background: #f1f5f9;
      border: 1px solid #cbd5e1;
      color: #64748b;
      font-size: 11px;
      font-weight: 800;
    }
  </style>
</head>
<body>
  <div class="container wide-container fichaje-gestion-container">
    <?php include "includes/topbar.php"; ?>

    <header class="fichaje-user-header">
      <div>
        <h1>Vacaciones y días libres</h1>
        <p>Gestiona tus periodos personales y consulta la disponibilidad del resto del equipo.</p>
      </div>
      <div class="fichaje-user-actions">
        <a class="vacaciones-export-button" href="exportar_vacaciones_anual.php?anio=<?php echo (int)$anio; ?>">
          📅 Descargar informe anual
        </a>
      </div>
    </header>

    <?php if (!$tablasOk): ?>
      <div class="error">Este apartado todavía no está disponible.</div>
    <?php else: ?>
      <?php if ($mensajeOk !== ''): ?><div class="success"><?php echo h($mensajeOk); ?></div><?php endif; ?>
      <?php if ($mensajeError !== ''): ?><div class="error"><?php echo h($mensajeError); ?></div><?php endif; ?>

      <nav class="vacaciones-tabs" aria-label="Secciones de vacaciones">
        <a class="<?php echo $tab === 'calendario' ? 'active' : ''; ?>" href="?mes=<?php echo $mes; ?>&anio=<?php echo $anio; ?>&tab=calendario">Calendario y gestión</a>
        <a class="<?php echo $tab === 'saldo' ? 'active' : ''; ?>" href="?mes=<?php echo $mes; ?>&anio=<?php echo $anio; ?>&tab=saldo">Vacaciones pendientes</a>
      </nav>

      <?php if ($tab === 'saldo'): ?>
        <section class="fichaje-kpi-grid fichaje-gestion-kpis vacaciones-balance-kpis">
          <article class="fichaje-gestion-kpi"><span>Asignadas <?php echo $anio; ?></span><strong><?php echo number_format((float)$saldoVacaciones['asignados'], 2, ',', '.'); ?></strong><small>Días fijados por administración</small></article>
          <article class="fichaje-gestion-kpi"><span>Disfrutadas</span><strong><?php echo number_format((float)$saldoVacaciones['disfrutados'], 2, ',', '.'); ?></strong><small>Incluye medias jornadas</small></article>
          <article class="fichaje-gestion-kpi"><span>Días añadidos</span><strong>+<?php echo number_format((float)$saldoVacaciones['creditos'], 2, ',', '.'); ?></strong><small>Festivos y fines de semana trabajados</small></article>
          <article class="fichaje-gestion-kpi vacaciones-disponibles"><span>Pendientes</span><strong><?php echo number_format((float)$saldoVacaciones['disponibles'], 2, ',', '.'); ?></strong><small>Saldo disponible del año</small></article>
        </section>

        <section class="fichaje-records-panel">
          <h2>Añadir día trabajado compensable</h2>
          <p class="vacaciones-form-intro">Se admite un festivo o fin de semana trabajado. Si existe fichaje se verificará automáticamente; si no existe, el día se registrará igualmente. Cada fecha suma 1 día completo.</p>
          <form class="fichaje-ausencia-form" method="post" action="procesar_fichaje_ausencia.php" data-processing-form>
            <input type="hidden" name="accion" value="crear_credito"><input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>"><input type="hidden" name="mes" value="<?php echo $mes; ?>"><input type="hidden" name="anio" value="<?php echo $anio; ?>">
            <div><label for="credito_fecha">Fecha trabajada</label><input id="credito_fecha" name="fecha" type="date" required></div>
            <div class="vacaciones-credit-description"><label for="credito_descripcion">Descripción</label><input id="credito_descripcion" name="descripcion" type="text" maxlength="180" placeholder="Trabajo especial, evento, guardia..."></div>
            <div class="fichaje-filter-actions"><button type="submit">Añadir 1 día</button></div>
          </form>
        </section>

        <section class="fichaje-records-panel"><h2>Días compensables del año</h2>
          <?php if (!$creditosVacaciones): ?><div class="empty-message">No hay días compensables registrados en <?php echo $anio; ?>.</div><?php else: ?><div class="fichaje-ausencias-list">
          <?php foreach ($creditosVacaciones as $credito): ?><article class="fichaje-ausencia-item"><div><strong><?php echo h(formatFechaWeb($credito['fecha'])); ?> · +1 día</strong><span><?php echo $credito['tipo']==='festivo'?'Festivo trabajado':'Fin de semana trabajado'; ?><?php echo trim((string)$credito['descripcion'])!==''?' · '.h($credito['descripcion']):''; ?><?php if (array_key_exists('fichaje_verificado', $credito)): ?> · <?php echo (int)$credito['fichaje_verificado'] === 1 ? 'Fichaje verificado' : 'Sin fichaje asociado'; ?><?php endif; ?></span></div><form method="post" action="procesar_fichaje_ausencia.php" data-processing-form><input type="hidden" name="accion" value="eliminar_credito"><input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>"><input type="hidden" name="credito_id" value="<?php echo (int)$credito['id']; ?>"><input type="hidden" name="mes" value="<?php echo $mes; ?>"><input type="hidden" name="anio" value="<?php echo $anio; ?>"><button class="fichaje-ausencia-delete" type="submit">Eliminar</button></form></article><?php endforeach; ?>
          </div><?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($tab !== 'saldo'): ?>
      <section class="fichaje-filter-card">
        <form class="fichaje-filter-form" method="get" action="fichaje_ausencias.php"><input type="hidden" name="tab" value="<?php echo h($tab); ?>">
          <div>
            <label for="mes">Mes</label>
            <select id="mes" name="mes">
              <?php for ($i = 1; $i <= 12; $i++): ?>
                <option value="<?php echo $i; ?>" <?php echo $mes === $i ? 'selected' : ''; ?>><?php echo ucfirst($nombresMeses[$i]); ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div>
            <label for="anio">Año</label>
            <input id="anio" name="anio" type="number" min="2020" max="2100" value="<?php echo (int)$anio; ?>">
          </div>
          <div class="fichaje-filter-actions"><button type="submit">Consultar</button></div>
        </form>
      </section>

      <section class="fichaje-kpi-grid fichaje-gestion-kpis fichaje-ausencias-kpis">
        <article class="fichaje-gestion-kpi"><span>Días laborables</span><strong><?php echo $diasLaborables; ?></strong><small>Calendario laboral</small></article>
        <article class="fichaje-gestion-kpi"><span>Tus vacaciones / libres</span><strong><?php echo $ausenciasMes; ?></strong><small>No computan como jornada prevista</small></article>
        <article class="fichaje-gestion-kpi"><span>Objetivo del mes</span><strong><?php echo h(fichajeMinutosAHHMM($objetivoMes)); ?></strong><small>Tras descontar festivos y ausencias</small></article>
      </section>

      <?php if ($tab === 'calendario'): ?>
      <section class="fichaje-records-panel vacaciones-calendar-panel">
        <div class="vacaciones-calendar-toolbar">
          <div>
            <h2>Calendario compartido del equipo</h2>
            <p>Vacaciones, días libres y actividades compartidas en una única agenda.</p>
          </div>
          <div class="vacaciones-calendar-nav">
            <a href="?mes=<?php echo (int)$mesAnterior->format('n'); ?>&anio=<?php echo (int)$mesAnterior->format('Y'); ?>&tab=calendario" aria-label="Mes anterior">‹</a>
            <strong><?php echo h(ucfirst($nombresMeses[$mes]) . ' ' . $anio); ?></strong>
            <a href="?mes=<?php echo (int)$mesSiguiente->format('n'); ?>&anio=<?php echo (int)$mesSiguiente->format('Y'); ?>&tab=calendario" aria-label="Mes siguiente">›</a>
          </div>
        </div>

        <?php if (!$agendaTablaOk): ?>
          <div class="error">Este apartado todavía no está disponible.</div>
        <?php endif; ?>

        <div class="vacaciones-selection-tools">
          <button type="button" id="vacacionesSelectionToggle" class="vacaciones-selection-toggle" aria-pressed="false">Seleccionar vacaciones o días libres</button>
          <div class="vacaciones-selection-help">
            <strong>Selección desde el calendario</strong>
            <span>Activa el modo de selección. Haz un primer clic y un segundo clic para marcar un rango consecutivo. Mantén <kbd>Ctrl</kbd> (o <kbd>⌘</kbd> en Mac) para añadir o quitar días sueltos.</span>
          </div>
          <div class="vacaciones-selection-status">
            <span id="vacacionesSelectionSummary">Ningún día seleccionado</span>
            <button type="button" id="vacacionesSelectionClear" class="vacaciones-selection-clear">Limpiar selección</button>
          </div>
        </div>

        <div class="vacaciones-agenda-layout">
          <div class="vacaciones-calendar-scroll">
            <div class="vacaciones-calendar vacaciones-calendar-compact" id="vacacionesCalendar">
              <?php foreach (['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $diaCabecera): ?>
                <div class="vacaciones-calendar-weekday"><?php echo h($diaCabecera); ?></div>
              <?php endforeach; ?>

              <?php for ($vacio = 1; $vacio < $primerDiaSemana; $vacio++): ?>
                <div class="vacaciones-calendar-day is-empty"></div>
              <?php endfor; ?>

              <?php for ($dia = 1; $dia <= $diasMes; $dia++): ?>
                <?php
                  $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
                  $eventos = $eventosPorFecha[$fecha] ?? [];
                  $actividades = $agendaPorFecha[$fecha] ?? [];
                  $esPropio = isset($ausenciasPropias[$fecha]);
                  $dateObj = new DateTime($fecha);
                  $finSemana = (int)$dateObj->format('N') >= 6;
                  $esActivo = $fecha === $fechaAgenda;
                  $agendaUrl = '?mes=' . $mes . '&anio=' . $anio . '&tab=calendario&fecha_agenda=' . rawurlencode($fecha);
                ?>
                <button type="button" class="vacaciones-calendar-day<?php echo $finSemana ? ' is-weekend' : ''; ?><?php echo $esPropio ? ' is-own' : ''; ?><?php echo $esActivo ? ' is-agenda-active' : ''; ?>" data-date="<?php echo h($fecha); ?>" data-agenda-url="<?php echo h($agendaUrl); ?>">
                  <span class="vacaciones-calendar-number"><?php echo $dia; ?></span>
                  <span class="vacaciones-calendar-events">
                    <?php foreach (array_slice($eventos, 0, 2) as $evento): ?>
                      <span class="vacaciones-calendar-event type-<?php echo h($evento['tipo']); ?><?php echo (int)$evento['user_id'] === $userId ? ' is-mine' : ''; ?>" title="<?php echo h(($evento['comercial'] ?: $evento['username']) . ' · ' . ($evento['tipo'] === 'vacaciones' ? 'Vacaciones' : 'Día libre')); ?>">
                        <?php echo h($evento['comercial'] ?: $evento['username']); ?>
                      </span>
                    <?php endforeach; ?>
                    <?php foreach (array_slice($actividades, 0, 2) as $actividad): ?>
                      <span class="vacaciones-calendar-event type-actividad" title="<?php echo h(($actividad['hora_inicio'] ? substr($actividad['hora_inicio'],0,5) . ' · ' : '') . $actividad['titulo']); ?>">
                        <?php echo h(($actividad['todo_el_dia'] ? '' : substr((string)$actividad['hora_inicio'],0,5) . ' ') . $actividad['titulo']); ?>
                      </span>
                    <?php endforeach; ?>
                    <?php $totalEventosDia = count($eventos) + count($actividades); ?>
                    <?php if ($totalEventosDia > 4): ?><span class="vacaciones-calendar-more">+<?php echo $totalEventosDia - 4; ?> más</span><?php endif; ?>
                  </span>
                </button>
              <?php endfor; ?>
            </div>
          </div>

          <aside class="agenda-day-panel">
            <div class="agenda-day-heading">
              <div>
                <span class="agenda-day-kicker">Agenda del día</span>
                <h3><?php echo h(ucfirst($nombresDiasSemana[(int)$fechaAgendaObj->format('N')]) . ' ' . $fechaAgendaObj->format('d')); ?></h3>
              </div>
              <span class="agenda-day-date"><?php echo h($fechaAgendaObj->format('d/m/Y')); ?></span>
            </div>

            <div class="agenda-absences-block">
              <h4>Ausentes todo el día</h4>
              <?php if (!$ausenciasDiaAgenda): ?>
                <p class="agenda-empty">No hay ausencias registradas.</p>
              <?php else: ?>
                <div class="agenda-absence-list">
                  <?php foreach ($ausenciasDiaAgenda as $ausencia): ?>
                    <?php $periodoDia = ((int)$ausencia['user_id'] === $userId || $esPrivilegiado) ? fichajeAusenciaPeriodoPropioEnFecha($conn, (int)$ausencia['user_id'], $fechaAgenda) : null; $periodoDiaVencido = $periodoDia && (string)$periodoDia['fecha_fin'] < date('Y-m-d'); ?>
                    <div class="agenda-absence-item">
                      <span><?php echo h($ausencia['comercial'] ?: $ausencia['username']); ?> · <?php echo $ausencia['tipo'] === 'vacaciones' ? 'Vacaciones' : 'Día libre'; ?></span>
                      <?php if ($periodoDia && (!$periodoDiaVencido || $esPrivilegiado)): ?>
                        <a class="agenda-edit-link" href="?mes=<?php echo $mes; ?>&anio=<?php echo $anio; ?>&tab=calendario&editar_periodo=<?php echo (int)$periodoDia['id']; ?>">Editar</a>
                        <?php if ($esPrivilegiado): ?>
                          <form method="post" action="procesar_fichaje_ausencia.php" data-processing-form onsubmit="return confirm('¿Eliminar este periodo?');">
                            <input type="hidden" name="accion" value="eliminar_periodo">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                            <input type="hidden" name="periodo_id" value="<?php echo (int)$periodoDia['id']; ?>">
                            <input type="hidden" name="mes" value="<?php echo $mes; ?>">
                            <input type="hidden" name="anio" value="<?php echo $anio; ?>">
                            <button type="submit" class="agenda-delete-button" aria-label="Eliminar periodo" title="Eliminar">×</button>
                          </form>
                        <?php endif; ?>
                      <?php elseif ($periodoDiaVencido): ?>
                        <span class="vacaciones-expired-label">Vencido</span>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="agenda-activities-block">
              <h4>Actividades</h4>
              <?php if (!$agendaDia): ?>
                <p class="agenda-empty">No hay actividades para este día.</p>
              <?php else: ?>
                <div class="agenda-activity-list">
                  <?php foreach ($agendaDia as $actividad): ?>
                    <article class="agenda-activity-card category-<?php echo h($actividad['categoria']); ?>">
                      <div class="agenda-activity-time"><?php echo (int)$actividad['todo_el_dia'] === 1 ? 'Todo el día' : h(substr((string)$actividad['hora_inicio'],0,5) . '–' . substr((string)$actividad['hora_fin'],0,5)); ?></div>
                      <div class="agenda-activity-content">
                        <strong><?php echo h($actividad['titulo']); ?></strong>
                        <span><?php echo h($actividad['comercial'] ?: $actividad['username']); ?><?php echo trim((string)$actividad['ubicacion']) !== '' ? ' · ' . h($actividad['ubicacion']) : ''; ?></span>
                        <?php if (trim((string)$actividad['descripcion']) !== ''): ?><p><?php echo nl2br(h($actividad['descripcion'])); ?></p><?php endif; ?>
                      </div>
                      <?php if ((int)$actividad['user_id'] === $userId || in_array($role, ['admin','master'], true)): ?>
                        <div class="agenda-activity-actions">
                          <a class="agenda-edit-button" href="?mes=<?php echo $mes; ?>&anio=<?php echo $anio; ?>&tab=calendario&fecha_agenda=<?php echo h($fechaAgenda); ?>&editar_actividad=<?php echo (int)$actividad['id']; ?>" aria-label="Editar actividad" title="Editar">✎</a>
                          <form method="post" action="procesar_agenda_compartida.php" data-processing-form onsubmit="return confirm('¿Eliminar esta actividad?');">
                            <input type="hidden" name="accion" value="eliminar">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                            <input type="hidden" name="evento_id" value="<?php echo (int)$actividad['id']; ?>">
                            <input type="hidden" name="fecha_retorno" value="<?php echo h($fechaAgenda); ?>">
                            <button type="submit" class="agenda-delete-button" aria-label="Eliminar actividad" title="Eliminar">×</button>
                          </form>
                        </div>
                      <?php endif; ?>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <?php if ($agendaTablaOk): ?>
              <?php $agendaEditando = is_array($actividadEditar); ?>
              <details class="agenda-create-box" open>
                <summary><?php echo $agendaEditando ? 'Editar actividad' : 'Añadir actividad'; ?></summary>
                <form method="post" action="procesar_agenda_compartida.php" data-processing-form class="agenda-create-form">
                  <input type="hidden" name="accion" value="<?php echo $agendaEditando ? 'editar' : 'crear'; ?>">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                  <input type="hidden" name="fecha_retorno" value="<?php echo h($fechaAgenda); ?>">
                  <?php if ($agendaEditando): ?><input type="hidden" name="evento_id" value="<?php echo (int)$actividadEditar['id']; ?>"><?php endif; ?>
                  <div><label for="agenda_fecha">Fecha</label><input id="agenda_fecha" type="date" name="fecha" value="<?php echo h($agendaEditando ? $actividadEditar['fecha'] : $fechaAgenda); ?>" required></div>
                  <div><label for="agenda_titulo">Actividad</label><input id="agenda_titulo" type="text" name="titulo" maxlength="150" required placeholder="Reunión, visita, formación..." value="<?php echo h($agendaEditando ? $actividadEditar['titulo'] : ''); ?>"></div>
                  <div class="agenda-time-grid">
                    <div><label for="agenda_inicio">Inicio</label><input id="agenda_inicio" type="time" name="hora_inicio" value="<?php echo h($agendaEditando && $actividadEditar['hora_inicio'] ? substr((string)$actividadEditar['hora_inicio'],0,5) : '09:00'); ?>"></div>
                    <div><label for="agenda_fin">Fin</label><input id="agenda_fin" type="time" name="hora_fin" value="<?php echo h($agendaEditando && $actividadEditar['hora_fin'] ? substr((string)$actividadEditar['hora_fin'],0,5) : '10:00'); ?>"></div>
                  </div>
                  <label class="agenda-all-day"><input type="checkbox" name="todo_el_dia" value="1" <?php echo $agendaEditando && (int)$actividadEditar['todo_el_dia'] === 1 ? 'checked' : ''; ?>> Todo el día</label>
                  <div><label for="agenda_categoria">Categoría</label><select id="agenda_categoria" name="categoria"><?php foreach (['actividad'=>'Actividad','reunion'=>'Reunión','visita'=>'Visita','formacion'=>'Formación','otro'=>'Otro'] as $catValor=>$catTexto): ?><option value="<?php echo h($catValor); ?>" <?php echo ($agendaEditando ? $actividadEditar['categoria'] : 'actividad') === $catValor ? 'selected' : ''; ?>><?php echo h($catTexto); ?></option><?php endforeach; ?></select></div>
                  <div><label for="agenda_ubicacion">Ubicación</label><input id="agenda_ubicacion" type="text" name="ubicacion" maxlength="180" placeholder="Oficina, Teams, cliente..." value="<?php echo h($agendaEditando ? $actividadEditar['ubicacion'] : ''); ?>"></div>
                  <div><label for="agenda_descripcion">Notas</label><textarea id="agenda_descripcion" name="descripcion" maxlength="1000" rows="3"><?php echo h($agendaEditando ? $actividadEditar['descripcion'] : ''); ?></textarea></div>
                  <div class="agenda-form-buttons"><button type="submit"><?php echo $agendaEditando ? 'Guardar cambios' : 'Guardar actividad'; ?></button><?php if ($agendaEditando): ?><a href="?mes=<?php echo $mes; ?>&anio=<?php echo $anio; ?>&tab=calendario&fecha_agenda=<?php echo h($fechaAgenda); ?>">Cancelar</a><?php endif; ?></div>
                </form>
              </details>
            <?php endif; ?>
          </aside>
        </div>

        <div class="vacaciones-calendar-legend">
          <span><i class="legend-vacaciones"></i> Vacaciones</span>
          <span><i class="legend-libre"></i> Día libre</span>
          <span><i class="legend-actividad"></i> Actividad</span>
          <span><i class="legend-propio"></i> Tu registro</span>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($tab === 'calendario'): ?>
      <section class="fichaje-records-panel">
        <h2><?php echo $periodoEditar ? 'Editar vacaciones o día libre' : 'Añadir vacaciones o día libre'; ?></h2>
        <p class="vacaciones-form-intro">Puedes indicar las fechas manualmente o seleccionarlas en el calendario superior. Para fechas no consecutivas, mantén pulsada la tecla Control mientras marcas cada día.</p>

        <form class="fichaje-ausencia-form vacaciones-shared-form" method="post" action="procesar_fichaje_ausencia.php" data-processing-form>
          <input type="hidden" name="accion" value="<?php echo $periodoEditar ? 'editar_periodo' : 'crear'; ?>">
          <?php if ($periodoEditar): ?><input type="hidden" name="periodo_id" value="<?php echo (int)$periodoEditar['id']; ?>"><?php endif; ?>
          <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
          <input type="hidden" name="mes" value="<?php echo $mes; ?>">
          <input type="hidden" name="anio" value="<?php echo $anio; ?>">
          <input type="hidden" id="fechas_seleccionadas" name="fechas_seleccionadas" value="">

          <?php
            $fechaInicioIso = $periodoEditar ? (string)$periodoEditar['fecha_inicio'] : '';
            $fechaFinIso = $periodoEditar ? (string)$periodoEditar['fecha_fin'] : '';
            $fechaInicioVisual = $fechaInicioIso !== '' ? date('d/m/Y', strtotime($fechaInicioIso)) : '';
            $fechaFinVisual = $fechaFinIso !== '' ? date('d/m/Y', strtotime($fechaFinIso)) : '';
          ?>
          <div>
            <label for="fecha_inicio_visual">Fecha inicio</label>
            <input id="fecha_inicio_visual" type="text" inputmode="numeric" autocomplete="off" maxlength="10" placeholder="dd/mm/aaaa" value="<?php echo h($fechaInicioVisual); ?>" required>
            <input id="fecha_inicio" name="fecha_inicio" type="hidden" value="<?php echo h($fechaInicioIso); ?>">
          </div>
          <div>
            <label for="fecha_fin_visual">Fecha fin</label>
            <input id="fecha_fin_visual" type="text" inputmode="numeric" autocomplete="off" maxlength="10" placeholder="dd/mm/aaaa" value="<?php echo h($fechaFinVisual); ?>" required>
            <input id="fecha_fin" name="fecha_fin" type="hidden" value="<?php echo h($fechaFinIso); ?>">
          </div>
          <div><label for="tipo">Tipo</label><select id="tipo" name="tipo"><option value="vacaciones" <?php echo $periodoEditar && $periodoEditar['tipo'] === 'vacaciones' ? 'selected' : ''; ?>>Vacaciones</option><option value="dia_libre" <?php echo $periodoEditar && $periodoEditar['tipo'] === 'dia_libre' ? 'selected' : ''; ?>>Día libre</option></select></div><div><label for="jornada">Jornada</label><select id="jornada" name="jornada"><option value="completa" <?php echo !$periodoEditar || $periodoEditar['jornada'] === 'completa' ? 'selected' : ''; ?>>Día completo</option><option value="manana" <?php echo $periodoEditar && $periodoEditar['jornada'] === 'manana' ? 'selected' : ''; ?>>Media jornada · mañana</option><option value="tarde" <?php echo $periodoEditar && $periodoEditar['jornada'] === 'tarde' ? 'selected' : ''; ?>>Media jornada · tarde</option></select></div>
          <div><label for="descripcion">Descripción opcional</label><input id="descripcion" name="descripcion" type="text" maxlength="180" placeholder="Ej.: vacaciones de verano, día libre..." value="<?php echo h($periodoEditar ? $periodoEditar['descripcion'] : ''); ?>"></div>
          <div class="fichaje-filter-actions"><button type="submit"><?php echo $periodoEditar ? 'Guardar cambios' : 'Guardar periodo'; ?></button><?php if ($periodoEditar): ?><a class="btn-secondary-small" href="?mes=<?php echo $mes; ?>&anio=<?php echo $anio; ?>&tab=calendario">Cancelar</a><?php endif; ?></div>
        </form>
      </section>

      <section class="fichaje-records-panel">
        <h2>Tus vacaciones y días libres del mes</h2>
        <?php if (count($periodosPropios) === 0): ?>
          <div class="empty-message">No tienes periodos registrados para el mes seleccionado.</div>
        <?php else: ?>
          <div class="fichaje-ausencias-list">
            <?php foreach ($periodosPropios as $periodo): ?>
              <article class="fichaje-ausencia-item vacaciones-period-item">
                <div>
                  <strong><?php echo h(formatFechaWeb($periodo['fecha_inicio'])); ?><?php echo $periodo['fecha_fin'] !== $periodo['fecha_inicio'] ? ' → ' . h(formatFechaWeb($periodo['fecha_fin'])) : ''; ?></strong>
                  <span><?php echo $periodo['tipo'] === 'vacaciones' ? 'Vacaciones' : 'Día libre'; ?> · <?php echo (($periodo['jornada'] ?? 'completa') === 'completa') ? 'Día completo' : ((($periodo['jornada'] ?? '') === 'manana') ? 'Media jornada de mañana' : 'Media jornada de tarde'); ?><?php echo trim((string)$periodo['descripcion']) !== '' ? ' · ' . h($periodo['descripcion']) : ''; ?></span>
                </div>
                <?php $periodoVencido = (string)$periodo['fecha_fin'] < date('Y-m-d'); ?>
                <div class="vacaciones-period-actions">
                  <?php if (!$periodoVencido || $esPrivilegiado): ?>
                    <a class="agenda-edit-link" href="?mes=<?php echo $mes; ?>&anio=<?php echo $anio; ?>&tab=calendario&editar_periodo=<?php echo (int)$periodo['id']; ?>">Editar</a>
                    <form method="post" action="procesar_fichaje_ausencia.php" data-processing-form onsubmit="return confirm('¿Seguro que quieres eliminar este periodo?');">
                      <input type="hidden" name="accion" value="eliminar_periodo">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                      <input type="hidden" name="periodo_id" value="<?php echo (int)$periodo['id']; ?>">
                      <input type="hidden" name="mes" value="<?php echo $mes; ?>">
                      <input type="hidden" name="anio" value="<?php echo $anio; ?>">
                      <button type="submit" class="fichaje-ausencia-delete">Eliminar periodo</button>
                    </form>
                  <?php else: ?>
                    <span class="vacaciones-expired-label">Periodo vencido · solo Admin/Máster</span>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
      <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="volver-container wide-container"><a href="home.php" class="volver-btn">Volver</a></div>
  <script src="js/processing_overlay.js?v=<?php echo APP_VERSION; ?>"></script>
  <script src="js/fichaje_ausencias.js?v=<?php echo APP_VERSION; ?>"></script>
</body>
</html>

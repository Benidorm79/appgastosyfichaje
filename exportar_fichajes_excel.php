<?php
require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/fichaje.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

function exportFichajesExcelText($value) {
  return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function exportFichajesExcelEstado($estado) {
  if (function_exists('formatEstadoWeb')) {
    return formatEstadoWeb($estado);
  }
  return ucfirst(str_replace('_', ' ', (string)$estado));
}

function exportFichajesExcelMotivo($motivo) {
  $map = [
    'entrada' => 'Entrada',
    'comida' => 'Comida / pausa',
    'medico' => 'Médico',
    'personal' => 'Personal',
    'otro' => 'Otro',
    'fin_jornada' => 'Fin de jornada',
    'auto_cierre' => 'Cierre automático'
  ];
  return $map[$motivo] ?? ucfirst(str_replace('_', ' ', (string)$motivo));
}

function exportFichajesExcelFecha($fecha) {
  if (function_exists('formatFechaWeb')) {
    return formatFechaWeb($fecha);
  }
  return $fecha;
}

try {
  if (!fichajeTableExists($conn, 'fichajes') || !fichajeTableExists($conn, 'fichaje_marcas')) {
    throw new Exception('Esta descarga no está disponible en este momento.');
  }

  $mes = intval($_GET['mes'] ?? date('n'));
  $anio = intval($_GET['anio'] ?? date('Y'));
  $requestedUserId = intval($_GET['user_id'] ?? 0);
  $esAdmin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'master'], true);
  $sessionUserId = (int)($_SESSION['user_id'] ?? 0);

  if ($mes < 1 || $mes > 12) throw new Exception('Mes no válido.');
  if ($anio < 2020 || $anio > 2100) throw new Exception('Año no válido.');

  $targetUserId = $esAdmin ? $requestedUserId : $sessionUserId;
  if (!$esAdmin && $sessionUserId <= 0) throw new Exception('Sesión no válida.');

  $desde = sprintf('%04d-%02d-01', $anio, $mes);
  $hasta = date('Y-m-d', strtotime($desde . ' +1 month'));

  $where = "fecha >= ? AND fecha < ?";
  $types = "ss";
  $params = [$desde, $hasta];

  if ($targetUserId > 0) {
    $where .= " AND user_id = ?";
    $types .= "i";
    $params[] = $targetUserId;
  }

  $sql = "SELECT * FROM fichajes WHERE $where ORDER BY fecha ASC, comercial ASC";
  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception('No se pudo preparar la consulta de fichajes.');
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $result = $stmt->get_result();

  $resumenes = [];
  $marcasPorFichaje = [];

  while ($result && ($fichaje = $result->fetch_assoc())) {
    $resumenes[] = $fichaje;
    $marcasPorFichaje[(int)$fichaje['id']] = fichajeGetMarcas($conn, (int)$fichaje['id']);
  }

  $nombreUsuario = 'todos';
  if ($targetUserId > 0 && count($resumenes) > 0) {
    $nombreUsuario = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim((string)$resumenes[0]['comercial']));
  } elseif (!$esAdmin) {
    $nombreUsuario = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim((string)($_SESSION['comercial'] ?? $_SESSION['user'] ?? 'usuario')));
  }
  if ($nombreUsuario === '') $nombreUsuario = 'usuario';

  $filename = sprintf('registro_jornada_%04d_%02d_%s.xls', $anio, $mes, $nombreUsuario);

  header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: max-age=0');
  header('Pragma: public');

  echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: Arial, sans-serif; font-size: 11pt; color: #111827; }
    h1 { font-size: 18pt; color: #003366; margin: 0 0 8px 0; }
    h2 { font-size: 14pt; color: #003366; margin: 22px 0 8px 0; }
    .meta { margin-bottom: 18px; color: #374151; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 18px; }
    th { background: #003366; color: #ffffff; font-weight: bold; border: 1px solid #1f2937; padding: 7px; text-align: left; }
    td { border: 1px solid #9ca3af; padding: 6px; vertical-align: top; }
    .section-title { background: #e5eef8; color: #003366; font-weight: bold; }
    .number { text-align: center; }
  </style>
</head>
<body>
  <h1>Registro mensual de jornada</h1>
  <div class="meta">
    Periodo: <?php echo exportFichajesExcelText(str_pad((string)$mes, 2, '0', STR_PAD_LEFT) . '/' . $anio); ?><br>
    Generado: <?php echo exportFichajesExcelText(date('d/m/Y H:i')); ?><br>
    Registros diarios: <?php echo (int)count($resumenes); ?>
  </div>

  <h2>Resumen diario</h2>
  <table>
    <thead>
      <tr>
        <th>Fecha</th>
        <th>Día</th>
        <th>Usuario</th>
        <th>Comercial</th>
        <th>Entrada mañana</th>
        <th>Salida mañana</th>
        <th>Entrada tarde</th>
        <th>Salida tarde</th>
        <th>Horas objetivo</th>
        <th>Horas realizadas</th>
        <th>Diferencia</th>
        <th>Estado</th>
        <th>Auto completado</th>
        <th>Total marcas</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($resumenes) === 0): ?>
        <tr><td colspan="14">No hay fichajes registrados para el periodo seleccionado.</td></tr>
      <?php endif; ?>

      <?php foreach ($resumenes as $resumen): ?>
        <?php $marcas = $marcasPorFichaje[(int)$resumen['id']] ?? []; $principal = fichajeClasificarResumenPrincipal($marcas, $resumen['fecha']); ?>
        <tr>
          <td><?php echo exportFichajesExcelText(exportFichajesExcelFecha($resumen['fecha'])); ?></td>
          <td><?php echo exportFichajesExcelText(ucfirst((string)$resumen['dia_semana'])); ?></td>
          <td><?php echo exportFichajesExcelText($resumen['username']); ?></td>
          <td><?php echo exportFichajesExcelText($resumen['comercial']); ?></td>
          <td class="number"><?php echo exportFichajesExcelText($principal['entrada_manana'] ?: ''); ?></td>
          <td class="number"><?php echo exportFichajesExcelText($principal['salida_manana'] ?: ''); ?></td>
          <td class="number"><?php echo exportFichajesExcelText($principal['entrada_tarde'] ?: ''); ?></td>
          <td class="number"><?php echo exportFichajesExcelText($principal['salida_tarde'] ?: ''); ?></td>
          <td class="number"><?php echo exportFichajesExcelText($resumen['horas_objetivo']); ?></td>
          <td class="number"><?php echo exportFichajesExcelText($resumen['horas_realizadas']); ?></td>
          <td class="number"><?php echo exportFichajesExcelText($resumen['diferencia']); ?></td>
          <td><?php echo exportFichajesExcelText(exportFichajesExcelEstado($resumen['estado'])); ?></td>
          <td class="number"><?php echo (int)$resumen['auto_completado'] === 1 ? 'Sí' : 'No'; ?></td>
          <td class="number"><?php echo (int)count($marcas); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Detalle completo de marcas</h2>
  <table>
    <thead>
      <tr>
        <th>Fecha</th>
        <th>Día</th>
        <th>Usuario</th>
        <th>Comercial</th>
        <th>Tipo</th>
        <th>Hora</th>
        <th>Motivo</th>
        <th>Nota</th>
        <th>Firma digital</th>
        <th>Auto completado</th>
      </tr>
    </thead>
    <tbody>
      <?php $hayMarcas = false; ?>
      <?php foreach ($resumenes as $resumen): ?>
        <?php foreach (($marcasPorFichaje[(int)$resumen['id']] ?? []) as $marca): ?>
          <?php $hayMarcas = true; ?>
          <tr>
            <td><?php echo exportFichajesExcelText(exportFichajesExcelFecha($marca['fecha'])); ?></td>
            <td><?php echo exportFichajesExcelText(ucfirst((string)$marca['dia_semana'])); ?></td>
            <td><?php echo exportFichajesExcelText($marca['username']); ?></td>
            <td><?php echo exportFichajesExcelText($marca['comercial']); ?></td>
            <td><?php echo exportFichajesExcelText(ucfirst((string)$marca['tipo'])); ?></td>
            <td class="number"><?php echo exportFichajesExcelText($marca['hora']); ?></td>
            <td><?php echo exportFichajesExcelText(exportFichajesExcelMotivo($marca['motivo'])); ?></td>
            <td><?php echo exportFichajesExcelText($marca['nota']); ?></td>
            <td><?php echo exportFichajesExcelText($marca['firma']); ?></td>
            <td class="number"><?php echo (int)$marca['auto_completado'] === 1 ? 'Sí' : 'No'; ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (!$hayMarcas): ?>
        <tr><td colspan="10">No hay marcas registradas para el periodo seleccionado.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</body>
</html>
<?php
} catch (Exception $e) {
  header('Content-Type: text/html; charset=UTF-8');
  http_response_code(400);
  echo '<p>' . exportFichajesExcelText($e->getMessage()) . '</p>';
}

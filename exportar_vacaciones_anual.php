<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/fichaje.php';

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = (string)($_SESSION['user'] ?? '');
$comercial = (string)($_SESSION['comercial'] ?? $username);
$anio = max(2020, min(2100, (int)($_GET['anio'] ?? date('Y'))));

if ($userId <= 0) {
    http_response_code(403);
    exit('Usuario no válido.');
}

$saldo = fichajeVacacionesSaldo($conn, $userId, $anio);
$festivos = fichajeFestivosBarcelona($conn, $anio);
$periodos = [];

if (fichajeAusenciasPeriodosTableExists($conn)) {
    $desde = sprintf('%04d-01-01', $anio);
    $hasta = sprintf('%04d-12-31', $anio);
    $stmt = $conn->prepare(
        "SELECT *
         FROM fichaje_ausencias_periodos
         WHERE user_id = ?
           AND activo = 1
           AND fecha_inicio <= ?
           AND fecha_fin >= ?
         ORDER BY fecha_inicio ASC, id ASC"
    );
    if ($stmt) {
        $stmt->bind_param('iss', $userId, $hasta, $desde);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $periodos[] = $row;
        }
    }
}

$marcas = [];
foreach ($periodos as $periodo) {
    $inicio = max(sprintf('%04d-01-01', $anio), (string)$periodo['fecha_inicio']);
    $fin = min(sprintf('%04d-12-31', $anio), (string)$periodo['fecha_fin']);
    $cursor = new DateTime($inicio);
    $limite = new DateTime($fin);
    while ($cursor <= $limite) {
        $fecha = $cursor->format('Y-m-d');
        $marcas[$fecha] = [
            'tipo' => (string)$periodo['tipo'],
            'jornada' => (string)($periodo['jornada'] ?? 'completa')
        ];
        $cursor->modify('+1 day');
    }
}

$meses = [
    1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
    7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'
];
$dias = ['L','M','X','J','V','S','D'];

$filename = 'vacaciones_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $username) . '_' . $anio . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
body{font-family:Arial,sans-serif;color:#0f172a}
h1{color:#003366}
.summary{border-collapse:collapse;margin-bottom:22px}
.summary td,.summary th,.list td,.list th,.calendar td,.calendar th{border:1px solid #94a3b8;padding:7px}
.summary th,.list th{background:#003366;color:#fff}
.calendar{border-collapse:collapse;margin:12px 0 24px 0}
.calendar th{background:#e2e8f0;text-align:center}
.calendar td{width:44px;height:36px;text-align:center;vertical-align:middle}
.vac{background:#dcfce7;color:#14532d;font-weight:bold}
.free{background:#dbeafe;color:#1e3a8a;font-weight:bold}
.half{background:#fef3c7;color:#92400e;font-weight:bold}
.weekend{background:#f1f5f9;color:#64748b}
.holiday{background:#fee2e2;color:#991b1b}
.month-title{font-size:16px;font-weight:bold;color:#003366}
.note{font-size:11px;color:#475569}
</style>
</head>
<body>
<h1>Informe anual de vacaciones y días libres</h1>
<p><strong>Usuario:</strong> <?php echo h($comercial); ?> (<?php echo h($username); ?>)</p>
<p><strong>Año:</strong> <?php echo (int)$anio; ?></p>

<table class="summary">
<tr><th>Días asignados</th><th>Días disfrutados</th><th>Días añadidos</th><th>Días pendientes</th></tr>
<tr>
<td><?php echo number_format((float)$saldo['asignados'],2,',','.'); ?></td>
<td><?php echo number_format((float)$saldo['disfrutados'],2,',','.'); ?></td>
<td><?php echo number_format((float)$saldo['creditos'],2,',','.'); ?></td>
<td><strong><?php echo number_format((float)$saldo['disponibles'],2,',','.'); ?></strong></td>
</tr>
</table>

<h2>Periodos confirmados</h2>
<table class="list">
<tr><th>Desde</th><th>Hasta</th><th>Tipo</th><th>Jornada</th><th>Descripción</th></tr>
<?php if (!$periodos): ?>
<tr><td colspan="5">No hay periodos confirmados.</td></tr>
<?php endif; ?>
<?php foreach ($periodos as $periodo): ?>
<tr>
<td><?php echo h(formatFechaWeb($periodo['fecha_inicio'])); ?></td>
<td><?php echo h(formatFechaWeb($periodo['fecha_fin'])); ?></td>
<td><?php echo h($periodo['tipo'] === 'dia_libre' ? 'Día libre' : 'Vacaciones'); ?></td>
<td><?php echo h(ucfirst(str_replace('_',' ',(string)($periodo['jornada'] ?? 'completa')))); ?></td>
<td><?php echo h((string)($periodo['descripcion'] ?? '')); ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>Calendario anual</h2>
<p class="note">Verde: vacaciones · Azul: día libre · Amarillo: media jornada · Rojo: festivo · Gris: fin de semana.</p>
<?php for ($mes=1; $mes<=12; $mes++): ?>
<div class="month-title"><?php echo h($meses[$mes]); ?> <?php echo (int)$anio; ?></div>
<table class="calendar">
<tr><?php foreach ($dias as $d): ?><th><?php echo $d; ?></th><?php endforeach; ?></tr>
<?php
$primero = new DateTime(sprintf('%04d-%02d-01',$anio,$mes));
$ultimoDia = (int)$primero->format('t');
$offset = (int)$primero->format('N') - 1;
$cell = 0;
?>
<tr>
<?php for ($i=0;$i<$offset;$i++): $cell++; ?><td></td><?php endfor; ?>
<?php for ($dia=1;$dia<=$ultimoDia;$dia++): ?>
<?php
$fecha=sprintf('%04d-%02d-%02d',$anio,$mes,$dia);
$n=(int)date('N',strtotime($fecha));
$class='';
$title='';
if (isset($marcas[$fecha])) {
    $j=(string)$marcas[$fecha]['jornada'];
    $class=$j!=='completa'?'half':($marcas[$fecha]['tipo']==='dia_libre'?'free':'vac');
    $title=$marcas[$fecha]['tipo']==='dia_libre'?'Día libre':'Vacaciones';
} elseif (isset($festivos[$fecha])) {
    $class='holiday'; $title=(string)$festivos[$fecha];
} elseif ($n>=6) {
    $class='weekend';
}
$cell++;
?>
<td class="<?php echo h($class); ?>" title="<?php echo h($title); ?>"><?php echo $dia; ?></td>
<?php if ($cell%7===0 && $dia<$ultimoDia): ?></tr><tr><?php endif; ?>
<?php endfor; ?>
<?php while ($cell%7!==0): $cell++; ?><td></td><?php endwhile; ?>
</tr>
</table>
<?php endfor; ?>
</body></html>

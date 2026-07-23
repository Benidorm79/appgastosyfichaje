<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/efectivo_kms.php';

$esAdmin = isAdmin();
$userIdSesion = (int)($_SESSION['user_id'] ?? 0);
$userId = $esAdmin ? (int)($_GET['user_id'] ?? $userIdSesion) : $userIdSesion;
$mes = (int)($_GET['mes'] ?? date('n'));
$anio = (int)($_GET['anio'] ?? date('Y'));
$formato = strtolower(trim((string)($_GET['formato'] ?? 'xls')));
$tipo = (string)($_GET['tipo'] ?? 'efectivo');
$tipo = in_array($tipo, ['efectivo', 'kilometraje'], true) ? $tipo : 'efectivo';

if ($userId <= 0) {
    $userId = $userIdSesion;
}

if ($mes < 1 || $mes > 12) {
    $mes = (int)date('n');
}

if ($anio < 2020 || $anio > 2100) {
    $anio = (int)date('Y');
}

if (!in_array($formato, ['xls', 'csv'], true)) {
    $formato = 'xls';
}

$usuario = null;
$stmtUsuario = $conn->prepare(
    "SELECT id, username, comercial
     FROM users
     WHERE id = ?
     LIMIT 1"
);

if ($stmtUsuario) {
    $stmtUsuario->bind_param('i', $userId);
    $stmtUsuario->execute();
    $usuario = $stmtUsuario->get_result()->fetch_assoc();
}

if (!$usuario) {
    http_response_code(404);
    exit('Usuario no encontrado.');
}

$efectivo = [];
$kilometrajes = [];

if ($tipo === 'efectivo' && efectivoKmsTableExists($conn, 'efectivo_gastos')) {
    $stmt = $conn->prepare(
        "SELECT fecha, motivo, importe, drive_file_url, nombre_archivo
         FROM efectivo_gastos
         WHERE user_id = ?
           AND estado = 'procesado'
           AND MONTH(fecha) = ?
           AND YEAR(fecha) = ?
         ORDER BY fecha ASC, id ASC"
    );

    if ($stmt) {
        $stmt->bind_param('iii', $userId, $mes, $anio);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($result && ($row = $result->fetch_assoc())) {
            $efectivo[] = $row;
        }
    }
}

if ($tipo === 'kilometraje' && efectivoKmsTableExists($conn, 'kilometrajes')) {
    $stmt = $conn->prepare(
        "SELECT fecha, motivo, origen, destino, paradas_json, ruta_url,
                kilometros, duracion_minutos, precio_km, importe, calculo_origen
         FROM kilometrajes
         WHERE user_id = ?
           AND estado = 'procesado'
           AND MONTH(fecha) = ?
           AND YEAR(fecha) = ?
         ORDER BY fecha ASC, id ASC"
    );

    if ($stmt) {
        $stmt->bind_param('iii', $userId, $mes, $anio);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($result && ($row = $result->fetch_assoc())) {
            $kilometrajes[] = $row;
        }
    }
}

$nombreBase = $tipo . '_'
    . preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($usuario['username'] ?? 'usuario'))
    . '_'
    . str_pad((string)$mes, 2, '0', STR_PAD_LEFT)
    . '_'
    . $anio;

if ($formato === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nombreBase . '.csv"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, ['RESUMEN'], ';');
    fputcsv($output, ['Usuario', $usuario['comercial'] ?: $usuario['username']], ';');
    fputcsv($output, ['Periodo', str_pad((string)$mes, 2, '0', STR_PAD_LEFT) . '/' . $anio], ';');
    fputcsv($output, [], ';');

    if ($tipo === 'efectivo') {
      fputcsv($output, ['GASTOS EN EFECTIVO'], ';');
      fputcsv($output, ['Fecha', 'Motivo', 'Importe', 'Ticket', 'Archivo'], ';');
    }

    foreach ($efectivo as $row) {
        fputcsv($output, [
            $row['fecha'],
            $row['motivo'],
            number_format((float)$row['importe'], 2, ',', ''),
            $row['drive_file_url'],
            $row['nombre_archivo']
        ], ';');
    }

    if ($tipo === 'kilometraje') {
      fputcsv($output, [], ';');
      fputcsv($output, ['KILOMETRAJE'], ';');
      fputcsv($output, [
        'Fecha', 'Motivo', 'Origen', 'Destino', 'Paradas', 'Kilómetros',
        'Duración minutos', 'Precio/km', 'Importe', 'Enlace Google Maps', 'Cálculo'
      ], ';');
    }

    foreach ($kilometrajes as $row) {
        $paradas = json_decode((string)($row['paradas_json'] ?? '[]'), true);

        fputcsv($output, [
            $row['fecha'],
            $row['motivo'],
            $row['origen'],
            $row['destino'],
            is_array($paradas) ? implode(' | ', $paradas) : '',
            number_format((float)$row['kilometros'], 2, ',', ''),
            (int)$row['duracion_minutos'],
            number_format((float)$row['precio_km'], 4, ',', ''),
            number_format((float)$row['importe'], 2, ',', ''),
            $row['ruta_url'],
            $row['calculo_origen']
        ], ';');
    }

    fclose($output);
    exit;
}

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nombreBase . '.xls"');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: Arial, sans-serif; }
    h1, h2 { color: #003366; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    th { background: #dbeafe; color: #0f172a; }
    th, td { border: 1px solid #94a3b8; padding: 8px; text-align: left; }
    .money { text-align: right; }
  </style>
</head>
<body>
  <h1><?php echo $tipo === 'efectivo' ? 'Gastos en efectivo' : 'Kilometraje'; ?></h1>
  <p><strong>Usuario:</strong> <?php echo h($usuario['comercial'] ?: $usuario['username']); ?></p>
  <p><strong>Periodo:</strong> <?php echo str_pad((string)$mes, 2, '0', STR_PAD_LEFT) . '/' . $anio; ?></p>

  <?php if ($tipo === 'efectivo'): ?><h2>Gastos en efectivo</h2>
  <table>
    <thead>
      <tr><th>Fecha</th><th>Motivo</th><th>Importe</th><th>Ticket</th><th>Archivo</th></tr>
    </thead>
    <tbody>
      <?php foreach ($efectivo as $row): ?>
        <tr>
          <td><?php echo h($row['fecha']); ?></td>
          <td><?php echo h($row['motivo']); ?></td>
          <td class="money"><?php echo number_format((float)$row['importe'], 2, ',', '.'); ?> €</td>
          <td><?php echo h($row['drive_file_url']); ?></td>
          <td><?php echo h($row['nombre_archivo']); ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$efectivo): ?><tr><td colspan="5">Sin registros.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <?php endif; ?>

  <?php if ($tipo === 'kilometraje'): ?><h2>Kilometraje</h2>
  <table>
    <thead>
      <tr>
        <th>Fecha</th><th>Motivo</th><th>Origen</th><th>Destino</th><th>Paradas</th>
        <th>Kms</th><th>Duración</th><th>Precio/km</th><th>Importe</th><th>Ruta</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($kilometrajes as $row): ?>
        <?php $paradas = json_decode((string)($row['paradas_json'] ?? '[]'), true); ?>
        <tr>
          <td><?php echo h($row['fecha']); ?></td>
          <td><?php echo h($row['motivo']); ?></td>
          <td><?php echo h($row['origen']); ?></td>
          <td><?php echo h($row['destino']); ?></td>
          <td><?php echo h(is_array($paradas) ? implode(' | ', $paradas) : ''); ?></td>
          <td><?php echo number_format((float)$row['kilometros'], 2, ',', '.'); ?></td>
          <td><?php echo (int)$row['duracion_minutos']; ?> min</td>
          <td><?php echo number_format((float)$row['precio_km'], 4, ',', '.'); ?> €</td>
          <td class="money"><?php echo number_format((float)$row['importe'], 2, ',', '.'); ?> €</td>
          <td><?php echo h($row['ruta_url']); ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$kilometrajes): ?><tr><td colspan="10">Sin registros.</td></tr><?php endif; ?>
    </tbody>
  </table><?php endif; ?>
</body>
</html>

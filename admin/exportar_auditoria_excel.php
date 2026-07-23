<?php
require_once __DIR__ . "/../admin_guard.php";
requireMasterAccess();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auditoria.php";

function exportAuditoriaExcelFetchAll($conn, $sql, $types = "", $params = []) {
  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return [];
  }

  if ($types !== "" && count($params) > 0) {
    $stmt->bind_param($types, ...$params);
  }

  $stmt->execute();
  $result = $stmt->get_result();

  if (!$result) {
    return [];
  }

  $rows = [];

  while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
  }

  return $rows;
}

function exportAuditoriaExcelBuildWhere(&$types, &$params) {
  $tipo = trim($_GET['tipo'] ?? 'todos');
  $entidad = trim($_GET['entidad'] ?? 'todos');
  $revision = trim($_GET['revision'] ?? 'todos');
  $desde = trim($_GET['desde'] ?? '');
  $hasta = trim($_GET['hasta'] ?? '');
  $buscar = trim($_GET['buscar'] ?? '');
  $comercial = trim($_GET['comercial'] ?? '');
  $usuario = trim($_GET['usuario'] ?? '');

  $where = "1 = 1";

  if ($tipo !== 'todos' && $tipo !== '') {
    $where .= " AND tipo_evento = ?";
    $params[] = $tipo;
    $types .= "s";
  }

  if ($entidad !== 'todos' && $entidad !== '') {
    $where .= " AND entidad = ?";
    $params[] = $entidad;
    $types .= "s";
  }

  if ($revision !== 'todos' && $revision !== '') {
    $where .= " AND estado_revision = ?";
    $params[] = $revision;
    $types .= "s";
  }

  if ($desde !== '') {
    $where .= " AND created_at >= ?";
    $params[] = $desde . " 00:00:00";
    $types .= "s";
  }

  if ($hasta !== '') {
    $where .= " AND created_at <= ?";
    $params[] = $hasta . " 23:59:59";
    $types .= "s";
  }

  if ($buscar !== '') {
    $where .= " AND (
                  accion LIKE ?
                  OR descripcion LIKE ?
                  OR username LIKE ?
                  OR comercial LIKE ?
                  OR estado_anterior LIKE ?
                  OR estado_nuevo LIKE ?
                  OR notas_revision LIKE ?
                )";

    $like = "%" . $buscar . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sssssss";
  }

  return $where;
}

if (!auditoriaTableExists($conn)) {
  header("Location: auditoria.php?type=error&msg=" . urlencode("La tabla auditoria_eventos no existe"));
  exit;
}

$types = "";
$params = [];
$where = exportAuditoriaExcelBuildWhere($types, $params);

$sql = "SELECT id, created_at, tipo_evento, entidad, entidad_id, accion, descripcion,
               usuario_id, username, comercial, rol, estado_anterior, estado_nuevo,
               estado_revision, notas_revision, ip
        FROM auditoria_eventos
        WHERE $where
        ORDER BY created_at DESC, id DESC";

$rows = exportAuditoriaExcelFetchAll($conn, $sql, $types, $params);

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'auditoria',
  'entidad' => 'auditoria',
  'accion' => 'auditoria_exportada_excel',
  'descripcion' => 'Exportación de registros de auditoría.',
  'estado_nuevo' => 'exportado',
  'datos' => [
    'formato' => 'excel',
    'filtros' => $_GET,
    'total_registros' => count($rows)
  ]
]);

$filename = "auditoria_" . date('Ymd_His') . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";
?>
<table border="1">
  <thead>
    <tr>
      <th>ID</th>
      <th>Fecha</th>
      <th>Tipo</th>
      <th>Entidad</th>
      <th>Entidad ID</th>
      <th>Acción</th>
      <th>Descripción</th>
      <th>Usuario ID</th>
      <th>Username</th>
      <th>Comercial</th>
      <th>Rol</th>
      <th>Estado anterior</th>
      <th>Estado nuevo</th>
      <th>Revisión</th>
      <th>Notas revisión</th>
      <th>IP</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><?php echo h($row['id'] ?? ''); ?></td>
        <td><?php echo h($row['created_at'] ?? ''); ?></td>
        <td><?php echo h($row['tipo_evento'] ?? ''); ?></td>
        <td><?php echo h($row['entidad'] ?? ''); ?></td>
        <td><?php echo h($row['entidad_id'] ?? ''); ?></td>
        <td><?php echo h($row['accion'] ?? ''); ?></td>
        <td><?php echo h($row['descripcion'] ?? ''); ?></td>
        <td><?php echo h($row['usuario_id'] ?? ''); ?></td>
        <td><?php echo h($row['username'] ?? ''); ?></td>
        <td><?php echo h($row['comercial'] ?? ''); ?></td>
        <td><?php echo h($row['rol'] ?? ''); ?></td>
        <td><?php echo h($row['estado_anterior'] ?? ''); ?></td>
        <td><?php echo h($row['estado_nuevo'] ?? ''); ?></td>
        <td><?php echo h($row['estado_revision'] ?? ''); ?></td>
        <td><?php echo h($row['notas_revision'] ?? ''); ?></td>
        <td><?php echo h($row['ip'] ?? ''); ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php
exit;
?>

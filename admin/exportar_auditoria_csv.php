<?php
require_once __DIR__ . "/../admin_guard.php";
requireMasterAccess();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auditoria.php";

function exportAuditoriaFetchAll($conn, $sql, $types = "", $params = []) {
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

function exportAuditoriaBuildWhere(&$types, &$params) {
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
$where = exportAuditoriaBuildWhere($types, $params);

$sql = "SELECT id, created_at, tipo_evento, entidad, entidad_id, accion, descripcion,
               usuario_id, username, comercial, rol, estado_anterior, estado_nuevo,
               estado_revision, notas_revision, ip
        FROM auditoria_eventos
        WHERE $where
        ORDER BY created_at DESC, id DESC";

$rows = exportAuditoriaFetchAll($conn, $sql, $types, $params);

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'auditoria',
  'entidad' => 'auditoria',
  'accion' => 'auditoria_exportada_csv',
  'descripcion' => 'Exportación de registros de auditoría.',
  'estado_nuevo' => 'exportado',
  'datos' => [
    'formato' => 'csv',
    'filtros' => $_GET,
    'total_registros' => count($rows)
  ]
]);

$filename = "auditoria_" . date('Ymd_His') . ".csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

fputcsv($output, [
  'ID',
  'Fecha',
  'Tipo',
  'Entidad',
  'Entidad ID',
  'Acción',
  'Descripción',
  'Usuario ID',
  'Username',
  'Comercial',
  'Rol',
  'Estado anterior',
  'Estado nuevo',
  'Revisión',
  'Notas revisión',
  'IP'
], ';');

foreach ($rows as $row) {
  fputcsv($output, [
    $row['id'] ?? '',
    $row['created_at'] ?? '',
    $row['tipo_evento'] ?? '',
    $row['entidad'] ?? '',
    $row['entidad_id'] ?? '',
    $row['accion'] ?? '',
    $row['descripcion'] ?? '',
    $row['usuario_id'] ?? '',
    $row['username'] ?? '',
    $row['comercial'] ?? '',
    $row['rol'] ?? '',
    $row['estado_anterior'] ?? '',
    $row['estado_nuevo'] ?? '',
    $row['estado_revision'] ?? '',
    $row['notas_revision'] ?? '',
    $row['ip'] ?? ''
  ], ';');
}

fclose($output);
exit;
?>

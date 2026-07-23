<?php
require_once __DIR__ . "/../admin_guard.php";
requireMasterAccess();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auditoria.php";

function auditoriaLoteVolver($returnUrl, $type, $msg) {
  $returnUrl = trim((string)$returnUrl);

  if ($returnUrl === '' || strpos($returnUrl, 'auditoria.php') !== 0) {
    $returnUrl = 'auditoria.php';
  }

  $sep = strpos($returnUrl, '?') === false ? '?' : '&';
  header('Location: ' . $returnUrl . $sep . 'type=' . urlencode($type) . '&msg=' . urlencode($msg));
  exit;
}

$returnUrl = $_POST['return_url'] ?? 'auditoria.php';
$ids = $_POST['ids'] ?? [];
$estadoRevision = trim($_POST['estado_revision_lote'] ?? '');
$notasRevision = trim($_POST['notas_revision_lote'] ?? '');

$permitidos = ['normal', 'revisado', 'corregido', 'anulado'];

if (!is_array($ids)) {
  $ids = [];
}

$idsLimpios = [];

foreach ($ids as $id) {
  $idInt = (int)$id;

  if ($idInt > 0) {
    $idsLimpios[$idInt] = $idInt;
  }
}

$idsLimpios = array_values($idsLimpios);

if (count($idsLimpios) === 0 || !in_array($estadoRevision, $permitidos, true)) {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'auditoria',
    'accion' => 'revision_lote_auditoria_no_valida',
    'descripcion' => 'Intento de revisar eventos de auditoría en lote con datos no válidos.',
    'estado_nuevo' => 'error',
    'estado_revision' => 'revisado',
    'datos' => [
      'ids' => $ids,
      'estado_revision' => $estadoRevision
    ]
  ]);

  auditoriaLoteVolver($returnUrl, 'error', 'Selecciona al menos un evento y un estado de revisión válido.');
}

$actualizados = 0;
$errores = 0;
$eventosAnteriores = [];

$sqlEvento = "SELECT id, tipo_evento, entidad, entidad_id, accion, estado_revision
              FROM auditoria_eventos
              WHERE id = ?
              LIMIT 1";
$stmtEvento = $conn->prepare($sqlEvento);

if (!$stmtEvento) {
  auditoriaLoteVolver($returnUrl, 'error', 'No se pudo preparar la revisión en lote.');
}

foreach ($idsLimpios as $id) {
  $stmtEvento->bind_param('i', $id);
  $stmtEvento->execute();
  $eventoAnterior = $stmtEvento->get_result()->fetch_assoc();

  if (!$eventoAnterior) {
    $errores++;
    continue;
  }

  $resultado = auditoriaActualizarRevision(
    $conn,
    $id,
    $estadoRevision,
    $notasRevision,
    $_SESSION['user_id'] ?? null
  );

  if (!empty($resultado['ok'])) {
    $actualizados++;
    $eventosAnteriores[] = [
      'id' => (int)$eventoAnterior['id'],
      'tipo_evento' => $eventoAnterior['tipo_evento'] ?? '',
      'entidad' => $eventoAnterior['entidad'] ?? '',
      'entidad_id' => $eventoAnterior['entidad_id'] ?? null,
      'accion' => $eventoAnterior['accion'] ?? '',
      'estado_anterior' => $eventoAnterior['estado_revision'] ?? 'normal'
    ];
  } else {
    $errores++;
  }
}

auditoriaRegistrarSeguro($conn, [
  'tipo_evento' => 'auditoria',
  'entidad' => 'auditoria',
  'accion' => 'revision_lote_eventos_auditoria',
  'descripcion' => 'Se revisaron eventos de auditoría en lote.',
  'estado_anterior' => 'lote',
  'estado_nuevo' => $estadoRevision,
  'estado_revision' => $estadoRevision === 'normal' ? 'normal' : 'revisado',
  'datos' => [
    'total_solicitados' => count($idsLimpios),
    'total_actualizados' => $actualizados,
    'total_errores' => $errores,
    'estado_revision_aplicado' => $estadoRevision,
    'notas_revision' => $notasRevision,
    'eventos' => $eventosAnteriores
  ]
]);

if ($actualizados === 0) {
  auditoriaLoteVolver($returnUrl, 'error', 'No se pudo actualizar ningún evento de auditoría.');
}

if ($errores > 0) {
  auditoriaLoteVolver($returnUrl, 'success', 'Se actualizaron ' . $actualizados . ' eventos. Algunos registros no pudieron actualizarse.');
}

auditoriaLoteVolver($returnUrl, 'success', 'Se actualizaron ' . $actualizados . ' eventos correctamente.');
?>

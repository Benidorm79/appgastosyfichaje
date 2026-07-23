<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/integraciones.php";
require_once __DIR__ . "/../includes/auditoria.php";
require_once __DIR__ . "/../includes/cierre_firmas.php";
require_once __DIR__ . "/../includes/cierre_firmas_efectivo.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

function procesarEnvioSanitizeReturn($return) {
  $return = trim((string)$return);

  $permitidos = [
    'envios.php',
    'admin/envios.php'
  ];

  if (in_array($return, $permitidos, true)) {
    return $return;
  }

  return 'envios.php';
}

function procesarEnvioBuildRedirect($return, $type, $message) {
  $return = procesarEnvioSanitizeReturn($return);

  if ($return === 'admin/envios.php') {
    $url = 'envios.php';
  } else {
    $url = $return;
  }

  $separator = strpos($url, '?') === false ? '?' : '&';
  $message = appPublicMessage($message, $type === 'error' ? 'No se ha podido completar la operación. Inténtalo de nuevo.' : 'Operación completada correctamente.');

  return $url . $separator . "type=" . urlencode($type) . "&msg=" . urlencode($message);
}

function procesarEnvioColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);

  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

  return $result && $result->num_rows > 0;
}

$id = intval($_POST['id'] ?? 0);
$estado = trim($_POST['estado'] ?? '');
$sistemaExterno = trim($_POST['sistema_externo'] ?? '');
$idExterno = trim($_POST['id_externo'] ?? '');
$comentario = trim($_POST['comentario'] ?? '');
$return = procesarEnvioSanitizeReturn($_POST['return'] ?? 'envios.php');

$estadosPermitidos = ['pendiente', 'enviado', 'error', 'omitido'];

if ($id <= 0 || !in_array($estado, $estadosPermitidos, true)) {
  auditoriaRegistrar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'envio_integracion',
    'entidad_id' => $id,
    'accion' => 'intento_actualizacion_envio_no_valida',
    'descripcion' => 'Intento de actualizar un envío de integración con datos no válidos.',
    'estado_nuevo' => $estado,
    'datos' => $_POST
  ]);

  header("Location: " . procesarEnvioBuildRedirect($return, "error", "Datos de envío no válidos"));
  exit;
}

if (!integracionesTableExists($conn)) {
  header("Location: " . procesarEnvioBuildRedirect($return, "error", "La tabla envios_integraciones no existe"));
  exit;
}

if (
  !procesarEnvioColumnExists($conn, 'envios_integraciones', 'sistema_externo') ||
  !procesarEnvioColumnExists($conn, 'envios_integraciones', 'id_externo')
) {
  header("Location: " . procesarEnvioBuildRedirect($return, "error", "Faltan columnas del módulo de envíos. Ejecuta el SQL de ampliación"));
  exit;
}

$sqlEnvio = "SELECT *
             FROM envios_integraciones
             WHERE id = ?
             LIMIT 1";

$stmtEnvio = $conn->prepare($sqlEnvio);

if (!$stmtEnvio) {
  header("Location: " . procesarEnvioBuildRedirect($return, "error", "No se pudo preparar la consulta del envío"));
  exit;
}

$stmtEnvio->bind_param("i", $id);
$stmtEnvio->execute();

$envio = $stmtEnvio->get_result()->fetch_assoc();

if (!$envio) {
  auditoriaRegistrar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'envio_integracion',
    'entidad_id' => $id,
    'accion' => 'intento_actualizacion_envio_inexistente',
    'descripcion' => 'Intento de actualizar un envío de integración inexistente.',
    'estado_nuevo' => $estado
  ]);

  header("Location: " . procesarEnvioBuildRedirect($return, "error", "No se encontró el registro de envío"));
  exit;
}

$localNow = date('Y-m-d H:i:s');
$enviadoPor = (int)($_SESSION['user_id'] ?? 0);
$enviadoAt = $estado === 'enviado' ? $localNow : null;

$sistemaExternoDb = $sistemaExterno !== '' ? $sistemaExterno : null;
$idExternoDb = $idExterno !== '' ? $idExterno : null;
$comentarioDb = $comentario !== '' ? $comentario : null;

$respuesta = [
  'tipo_actualizacion' => 'manual_admin',
  'estado_anterior' => $envio['estado'] ?? '',
  'estado_nuevo' => $estado,
  'sistema_externo' => $sistemaExternoDb,
  'id_externo' => $idExternoDb,
  'comentario' => $comentarioDb,
  'actualizado_por' => $enviadoPor,
  'actualizado_at' => $localNow
];

$respuestaJson = json_encode($respuesta, JSON_UNESCAPED_UNICODE);

$sqlUpdate = "UPDATE envios_integraciones
              SET estado = ?,
                  sistema_externo = ?,
                  id_externo = ?,
                  respuesta_json = ?,
                  ultimo_error = ?,
                  intentos = intentos + 1,
                  enviado_por = ?,
                  enviado_at = ?,
                  updated_at = ?
              WHERE id = ?
              LIMIT 1";

$stmtUpdate = $conn->prepare($sqlUpdate);

if (!$stmtUpdate) {
  header("Location: " . procesarEnvioBuildRedirect($return, "error", "No se pudo preparar la actualización del envío"));
  exit;
}

$stmtUpdate->bind_param(
  "sssssissi",
  $estado,
  $sistemaExternoDb,
  $idExternoDb,
  $respuestaJson,
  $comentarioDb,
  $enviadoPor,
  $enviadoAt,
  $localNow,
  $id
);

if (!$stmtUpdate->execute()) {
  header("Location: " . procesarEnvioBuildRedirect($return, "error", "No se pudo actualizar el estado del envío"));
  exit;
}

auditoriaRegistrar($conn, [
  'tipo_evento' => 'envio',
  'entidad' => 'envio_integracion',
  'entidad_id' => $id,
  'accion' => 'cambio_estado_envio_integracion',
  'descripcion' => 'Cambio manual del estado de un envío de integración.',
  'estado_anterior' => $envio['estado'] ?? '',
  'estado_nuevo' => $estado,
  'datos' => [
    'envio_id' => $id,
    'tipo_destino' => $envio['tipo_destino'] ?? '',
    'entidad' => $envio['entidad'] ?? '',
    'entidad_id' => $envio['entidad_id'] ?? '',
    'referencia' => $envio['referencia'] ?? '',
    'sistema_externo_anterior' => $envio['sistema_externo'] ?? '',
    'sistema_externo_nuevo' => $sistemaExternoDb,
    'id_externo_anterior' => $envio['id_externo'] ?? '',
    'id_externo_nuevo' => $idExternoDb,
    'comentario' => $comentarioDb
  ]
]);

$firmaWebhookResult = null;

if (
  $estado === 'enviado' &&
  ($envio['estado'] ?? '') !== 'enviado' &&
  ($envio['entidad'] ?? '') === 'cierre' &&
  (int)($envio['entidad_id'] ?? 0) !== 0
) {
  $entidadCierreId = (int)$envio['entidad_id'];
  $tipoCierreEnvio = trim((string)($envio['tipo_cierre'] ?? ''));
  $esCierreEfectivo = $tipoCierreEnvio === 'efectivo'
    || $entidadCierreId < 0
    || stripos((string)($envio['referencia'] ?? ''), 'CIERRE-EFECTIVO-') === 0;
  $actorFirma = [
    'user_id' => $enviadoPor,
    'username' => $_SESSION['user'] ?? '',
    'comercial' => $_SESSION['comercial'] ?? ($_SESSION['user'] ?? ''),
    'rol' => $_SESSION['role'] ?? 'admin'
  ];
  $extrasFirma = [
    'evento' => 'contabilizacion_cierre_enviado',
    'forzar_envio_webhook' => true,
    'envio_integracion_id' => $id,
    'referencia' => $envio['referencia'] ?? '',
    'sistema_externo' => $sistemaExternoDb,
    'id_externo' => $idExternoDb,
    'comentario' => $comentarioDb
  ];

  if (!$esCierreEfectivo) {
    $cierreParaFirma = cierreFirmasFetchCierre($conn, abs($entidadCierreId));

    if ($cierreParaFirma) {
      $firmaWebhookResult = cierreFirmasGenerarYEnviar(
        $conn,
        'contabilidad',
        $cierreParaFirma,
        $actorFirma,
        $extrasFirma
      );
    }
  } else {
    $cierreEfectivoId = abs($entidadCierreId);
    $cierreEfectivoParaFirma = cierreEfectivoFirmasFetchCierre($conn, $cierreEfectivoId);

    if ($cierreEfectivoParaFirma) {
      $extrasFirma['tipo_cierre'] = 'efectivo';
      $firmaWebhookResult = cierreEfectivoFirmasGenerarYEnviar(
        $conn,
        'contabilidad',
        $cierreEfectivoParaFirma,
        $actorFirma,
        $extrasFirma
      );
    }
  }
}

if ($firmaWebhookResult !== null && empty($firmaWebhookResult['ok'])) {
  header("Location: " . procesarEnvioBuildRedirect($return, "error", "El estado se actualizó, pero la firma no pudo confirmarse."));
  exit;
}

header("Location: " . procesarEnvioBuildRedirect($return, "success", "Estado del envío actualizado y firma confirmada correctamente"));
exit;
?>

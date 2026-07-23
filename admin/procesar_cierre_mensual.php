<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/integraciones.php";
require_once __DIR__ . "/../includes/auditoria.php";
require_once __DIR__ . "/../includes/cierre_firmas.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

function procesarAdminCierreColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);

  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

  return $result && $result->num_rows > 0;
}

function procesarAdminCierreTableExists($conn, $table) {
  $table = $conn->real_escape_string($table);

  $result = $conn->query("SHOW TABLES LIKE '$table'");

  return $result && $result->num_rows > 0;
}

function procesarAdminBuildRedirect($return, $type, $message) {
  $separator = strpos($return, '?') === false ? '?' : '&';
  $message = appPublicMessage($message, $type === 'error' ? 'La revisión se ha guardado, pero no se ha podido completar todo el proceso.' : 'Revisión guardada correctamente.');

  return "../" . $return . $separator . "type=" . urlencode($type) . "&msg=" . urlencode($message);
}

function procesarAdminGetMonthName($month) {
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

function procesarAdminFetchGastosPeriodo($conn, $userId, $mes, $anio, $fechaPeriodo) {
  $sql = "SELECT *
          FROM gastos
          WHERE deleted_at IS NULL
            AND estado IN ('procesado', 'editado')
            AND user_id = ?
            AND $fechaPeriodo IS NOT NULL
            AND MONTH($fechaPeriodo) = ?
            AND YEAR($fechaPeriodo) = ?
          ORDER BY $fechaPeriodo ASC, id ASC";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return [];
  }

  $stmt->bind_param("iii", $userId, $mes, $anio);
  $stmt->execute();

  $result = $stmt->get_result();

  if (!$result) {
    return [];
  }

  $gastos = [];

  while ($row = $result->fetch_assoc()) {
    $gastos[] = $row;
  }

  return $gastos;
}

function procesarAdminFetchTicketsGasto($conn, $gastoId, $gastoUid) {
  if (!procesarAdminCierreTableExists($conn, 'gasto_tickets')) {
    return [];
  }

  $sql = "SELECT *
          FROM gasto_tickets
          WHERE gasto_id = ?
            AND gasto_uid = ?
          ORDER BY orden ASC, id ASC";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return [];
  }

  $stmt->bind_param("is", $gastoId, $gastoUid);
  $stmt->execute();

  $result = $stmt->get_result();

  if (!$result) {
    return [];
  }

  $tickets = [];

  while ($row = $result->fetch_assoc()) {
    $tickets[] = [
      'id' => (int)($row['id'] ?? 0),
      'gasto_id' => (int)($row['gasto_id'] ?? 0),
      'gasto_uid' => $row['gasto_uid'] ?? '',
      'nombre_original' => $row['nombre_original'] ?? '',
      'nombre_guardado' => $row['nombre_guardado'] ?? '',
      'mime_type' => $row['mime_type'] ?? '',
      'size_bytes' => isset($row['size_bytes']) ? (int)$row['size_bytes'] : null,
      'drive_file_id' => $row['drive_file_id'] ?? '',
      'drive_file_url' => $row['drive_file_url'] ?? '',
      'orden' => isset($row['orden']) ? (int)$row['orden'] : null
    ];
  }

  return $tickets;
}

function procesarAdminDiferenciaEsCero($diferencia) {
  return abs((float)$diferencia) < 0.005;
}

function procesarAdminCierreContabilizado($conn, $cierreId) {
  if (!integracionesTableExists($conn)) {
    return false;
  }

  $cierreId = (int)$cierreId;

  if ($cierreId <= 0) {
    return false;
  }

  $sql = "SELECT id
          FROM envios_integraciones
          WHERE entidad = 'cierre'
            AND entidad_id = ?
            AND estado = 'enviado'
          LIMIT 1";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return false;
  }

  $stmt->bind_param("i", $cierreId);
  $stmt->execute();

  $result = $stmt->get_result();

  return $result && $result->num_rows > 0;
}

function procesarAdminOmitirIntegracionesPendientesCierre($conn, $cierreId, $motivo) {
  if (!integracionesTableExists($conn)) {
    return [
      'ok' => false,
      'message' => 'La tabla "Envíos e Integraciones" no existe.',
      'affected_rows' => 0
    ];
  }

  $cierreId = (int)$cierreId;

  if ($cierreId <= 0) {
    return [
      'ok' => false,
      'message' => 'ID de cierre no válido.',
      'affected_rows' => 0
    ];
  }

  $now = integracionesNow();

  $sql = "UPDATE envios_integraciones
          SET estado = 'omitido',
              ultimo_error = ?,
              updated_at = ?
          WHERE entidad = 'cierre'
            AND entidad_id = ?
            AND estado = 'pendiente'";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return [
      'ok' => false,
      'message' => 'No se pudo preparar la omisión de integraciones pendientes: ' . $conn->error,
      'affected_rows' => 0
    ];
  }

  $stmt->bind_param("ssi", $motivo, $now, $cierreId);

  if (!$stmt->execute()) {
    return [
      'ok' => false,
      'message' => 'No se pudieron omitir las integraciones pendientes: ' . $stmt->error,
      'affected_rows' => 0
    ];
  }

  return [
    'ok' => true,
    'message' => 'Integraciones pendientes omitidas correctamente.',
    'affected_rows' => (int)$stmt->affected_rows
  ];
}

function procesarAdminAuditar($conn, $data = []) {
  if (!function_exists('auditoriaRegistrar')) {
    return;
  }

  auditoriaRegistrar($conn, $data);
}

function procesarAdminAccionCierre($estadoAnterior, $estadoNuevo) {
  if ($estadoNuevo === 'validado' && $estadoAnterior !== 'validado') {
    return 'cierre_validado';
  }

  if ($estadoAnterior === 'validado' && $estadoNuevo !== 'validado') {
    return 'cierre_reabierto';
  }

  if ($estadoNuevo === 'con_diferencia') {
    return 'cierre_marcado_con_diferencia';
  }

  if ($estadoNuevo === 'rechazado') {
    return 'cierre_rechazado';
  }

  if ($estadoNuevo === 'pendiente_admin') {
    return 'cierre_marcado_pendiente_admin';
  }

  return 'cierre_actualizado';
}

function procesarAdminDescripcionCierre($estadoAnterior, $estadoNuevo, $comercial, $periodoNombre) {
  if ($estadoNuevo === 'validado' && $estadoAnterior !== 'validado') {
    return 'Cierre mensual validado para ' . $comercial . ' - ' . $periodoNombre . '.';
  }

  if ($estadoAnterior === 'validado' && $estadoNuevo !== 'validado') {
    return 'Cierre mensual reabierto para ' . $comercial . ' - ' . $periodoNombre . '.';
  }

  if ($estadoNuevo === 'con_diferencia') {
    return 'Cierre mensual marcado con diferencia para ' . $comercial . ' - ' . $periodoNombre . '.';
  }

  if ($estadoNuevo === 'rechazado') {
    return 'Cierre mensual rechazado para ' . $comercial . ' - ' . $periodoNombre . '.';
  }

  if ($estadoNuevo === 'pendiente_admin') {
    return 'Cierre mensual marcado como pendiente de administración para ' . $comercial . ' - ' . $periodoNombre . '.';
  }

  return 'Cierre mensual actualizado para ' . $comercial . ' - ' . $periodoNombre . '.';
}

$id = intval($_POST['id'] ?? 0);
$estado = trim($_POST['estado'] ?? '');
$comentariosAdmin = trim($_POST['comentarios_admin'] ?? '');
$return = sanitizeRedirect($_POST['return'] ?? 'admin/cierres_mensuales.php');

$localNow = date('Y-m-d H:i:s');

$estadosPermitidos = [
  'pendiente_admin',
  'validado',
  'con_diferencia',
  'rechazado'
];

if ($id <= 0 || !in_array($estado, $estadosPermitidos, true)) {
  procesarAdminAuditar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'cierre',
    'entidad_id' => $id,
    'accion' => 'intento_revision_cierre_no_valida',
    'descripcion' => 'Intento de revisar un cierre mensual con datos no válidos.',
    'estado_nuevo' => $estado,
    'datos' => [
      'post' => $_POST
    ]
  ]);

  header("Location: " . procesarAdminBuildRedirect($return, "error", "Datos de revisión no válidos"));
  exit;
}

$sqlCierre = "SELECT 
                c.*,
                u.email AS user_email
              FROM cierres_mensuales c
              LEFT JOIN users u
                ON u.id = c.user_id
              WHERE c.id = ?
              LIMIT 1";

$stmtCierre = $conn->prepare($sqlCierre);

if (!$stmtCierre) {
  procesarAdminAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'cierre',
    'entidad_id' => $id,
    'accion' => 'error_preparar_revision_cierre',
    'descripcion' => 'No se pudo preparar la consulta de revisión del cierre mensual.',
    'estado_nuevo' => $estado,
    'datos' => [
      'mysql_error' => $conn->error
    ]
  ]);

  header("Location: " . procesarAdminBuildRedirect($return, "error", "No se pudo preparar la revisión del cierre"));
  exit;
}

$stmtCierre->bind_param("i", $id);
$stmtCierre->execute();

$cierre = $stmtCierre->get_result()->fetch_assoc();

if (!$cierre) {
  procesarAdminAuditar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'cierre',
    'entidad_id' => $id,
    'accion' => 'intento_revision_cierre_inexistente',
    'descripcion' => 'Intento de revisar un cierre mensual inexistente.',
    'estado_nuevo' => $estado
  ]);

  header("Location: " . procesarAdminBuildRedirect($return, "error", "No se encontró el cierre mensual"));
  exit;
}

$estadoAnterior = $cierre['estado'] ?? '';

if (procesarAdminCierreContabilizado($conn, $id)) {
  procesarAdminAuditar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'cierre',
    'entidad_id' => $id,
    'accion' => 'intento_modificar_cierre_contabilizado',
    'descripcion' => 'Intento bloqueado de modificar o reabrir un cierre mensual ya contabilizado.',
    'estado_anterior' => $estadoAnterior,
    'estado_nuevo' => $estado,
    'datos' => [
      'cierre_id' => $id,
      'user_id' => (int)($cierre['user_id'] ?? 0),
      'comercial' => $cierre['comercial'] ?? '',
      'mes' => (int)($cierre['mes'] ?? 0),
      'anio' => (int)($cierre['anio'] ?? 0)
    ]
  ]);

  header("Location: " . procesarAdminBuildRedirect(
    $return,
    "error",
    "Este cierre ya está contabilizado como enviado y no puede modificarse ni reabrirse."
  ));
  exit;
}

$fechaImputacionExiste = procesarAdminCierreColumnExists($conn, 'gastos', 'fecha_imputacion');

if ($fechaImputacionExiste) {
  $fechaPeriodo = "COALESCE(fecha_imputacion, fecha_ticket)";
} else {
  $fechaPeriodo = "fecha_ticket";
}

$userId = (int)$cierre['user_id'];
$mes = (int)$cierre['mes'];
$anio = (int)$cierre['anio'];
$importeBanco = (float)$cierre['importe_banco'];

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

if (!$stmtTotal) {
  procesarAdminAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'cierre',
    'entidad_id' => $id,
    'accion' => 'error_recalcular_total_cierre',
    'descripcion' => 'No se pudo recalcular el total de la app antes de revisar el cierre mensual.',
    'estado_anterior' => $estadoAnterior,
    'estado_nuevo' => $estado,
    'datos' => [
      'mysql_error' => $conn->error,
      'user_id' => $userId,
      'mes' => $mes,
      'anio' => $anio
    ]
  ]);

  header("Location: " . procesarAdminBuildRedirect($return, "error", "No se pudo recalcular el total de la app"));
  exit;
}

$stmtTotal->bind_param("iii", $userId, $mes, $anio);
$stmtTotal->execute();

$rowTotal = $stmtTotal->get_result()->fetch_assoc();

$totalGastos = (int)($rowTotal['total_gastos'] ?? 0);
$importeApp = (float)($rowTotal['total_importe'] ?? 0);
$diferencia = round($importeBanco - $importeApp, 2);
$revisadoPor = (int)($_SESSION['user_id'] ?? 0);
$periodoTexto = str_pad((string)$mes, 2, '0', STR_PAD_LEFT) . '/' . $anio;
$periodKey = $userId . '_' . str_pad((string)$mes, 2, '0', STR_PAD_LEFT) . '_' . $anio;
$periodoNombre = procesarAdminGetMonthName($mes) . ' ' . $anio;

if ($estado === 'validado' && !procesarAdminDiferenciaEsCero($diferencia)) {
  procesarAdminAuditar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'cierre',
    'entidad_id' => $id,
    'accion' => 'intento_validar_cierre_con_diferencia',
    'descripcion' => 'Intento bloqueado de validar un cierre mensual con diferencia distinta de 0,00 €.',
    'estado_anterior' => $estadoAnterior,
    'estado_nuevo' => $estado,
    'datos' => [
      'cierre_id' => $id,
      'user_id' => $userId,
      'comercial' => $cierre['comercial'] ?? '',
      'periodo' => $periodoTexto,
      'importe_app' => $importeApp,
      'importe_banco' => $importeBanco,
      'diferencia' => $diferencia,
      'total_gastos' => $totalGastos
    ]
  ]);

  header("Location: " . procesarAdminBuildRedirect(
    $return,
    "error",
    "No se puede validar el cierre porque la diferencia actual no es 0,00 €. Revisa los gastos o marca el cierre como Con diferencia."
  ));
  exit;
}

$sqlUpdate = "UPDATE cierres_mensuales
              SET estado = ?,
                  importe_app = ?,
                  diferencia = ?,
                  comentarios_admin = ?,
                  revisado_por = ?,
                  revisado_at = ?,
                  updated_at = ?
              WHERE id = ?
              LIMIT 1";

$stmtUpdate = $conn->prepare($sqlUpdate);

if (!$stmtUpdate) {
  procesarAdminAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'cierre',
    'entidad_id' => $id,
    'accion' => 'error_preparar_guardado_revision_cierre',
    'descripcion' => 'No se pudo preparar el guardado de la revisión del cierre mensual.',
    'estado_anterior' => $estadoAnterior,
    'estado_nuevo' => $estado,
    'datos' => [
      'mysql_error' => $conn->error
    ]
  ]);

  header("Location: " . procesarAdminBuildRedirect($return, "error", "No se pudo preparar el guardado de la revisión"));
  exit;
}

$stmtUpdate->bind_param(
  "sddsissi",
  $estado,
  $importeApp,
  $diferencia,
  $comentariosAdmin,
  $revisadoPor,
  $localNow,
  $localNow,
  $id
);

if (!$stmtUpdate->execute()) {
  procesarAdminAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'cierre',
    'entidad_id' => $id,
    'accion' => 'error_guardar_revision_cierre',
    'descripcion' => 'No se pudo guardar la revisión del cierre mensual.',
    'estado_anterior' => $estadoAnterior,
    'estado_nuevo' => $estado,
    'datos' => [
      'mysql_error' => $stmtUpdate->error
    ]
  ]);

  header("Location: " . procesarAdminBuildRedirect($return, "error", "No se pudo guardar la revisión del cierre"));
  exit;
}

$firmaWebhookResult = null;

if ($estado === 'validado' && $estadoAnterior !== 'validado') {
  $cierreParaFirma = $cierre;
  $cierreParaFirma['estado'] = $estado;
  $cierreParaFirma['importe_app'] = $importeApp;
  $cierreParaFirma['diferencia'] = $diferencia;
  $cierreParaFirma['comentarios_admin'] = $comentariosAdmin;
  $cierreParaFirma['revisado_por'] = $revisadoPor;
  $cierreParaFirma['revisado_at'] = $localNow;

  $firmaWebhookResult = cierreFirmasGenerarYEnviar($conn, 'admin', $cierreParaFirma, [
    'user_id' => $revisadoPor,
    'username' => $_SESSION['user'] ?? '',
    'comercial' => $_SESSION['comercial'] ?? ($_SESSION['user'] ?? ''),
    'rol' => $_SESSION['role'] ?? 'admin'
  ], [
    'evento' => 'validacion_admin_cierre',
    'forzar_envio_webhook' => true,
    'estado_anterior' => $estadoAnterior,
    'estado_nuevo' => $estado,
    'comentarios_admin' => $comentariosAdmin,
    'total_gastos' => $totalGastos
  ]);
}

$gastosPeriodo = procesarAdminFetchGastosPeriodo($conn, $userId, $mes, $anio, $fechaPeriodo);
$gastosPayload = [];

foreach ($gastosPeriodo as $gasto) {
  $gastoId = (int)($gasto['id'] ?? 0);
  $gastoUid = $gasto['gasto_uid'] ?? '';

  $tickets = [];

  if ($gastoId > 0 && $gastoUid !== '') {
    $tickets = procesarAdminFetchTicketsGasto($conn, $gastoId, $gastoUid);
  }

  $gastosPayload[] = [
    'id' => $gastoId,
    'gasto_uid' => $gastoUid,
    'user_id' => (int)($gasto['user_id'] ?? 0),
    'username' => $gasto['username'] ?? '',
    'comercial' => $gasto['comercial'] ?? '',
    'viaje' => $gasto['viaje'] ?? '',
    'motivo' => $gasto['motivo'] ?? '',
    'comentarios' => $gasto['comentarios'] ?? '',
    'importe_detectado' => isset($gasto['importe_detectado']) ? (float)$gasto['importe_detectado'] : 0,
    'fecha_ticket' => $gasto['fecha_ticket'] ?? '',
    'fecha_imputacion' => $gasto['fecha_imputacion'] ?? null,
    'estado' => $gasto['estado'] ?? '',
    'origen' => $gasto['origen'] ?? '',
    'excel_file_id' => $gasto['excel_file_id'] ?? '',
    'excel_file_url' => $gasto['excel_file_url'] ?? '',
    'excel_sheet_name' => $gasto['excel_sheet_name'] ?? '',
    'excel_row_id' => $gasto['excel_row_id'] ?? '',
    'drive_folder_id' => $gasto['drive_folder_id'] ?? '',
    'drive_folder_url' => $gasto['drive_folder_url'] ?? '',
    'tickets' => $tickets
  ];
}

$debeCerrarPeriodo = $estado === 'validado';
$debeAbrirPeriodo = $estado !== 'validado';

$debeEnviarEmailsValidacion = $estado === 'validado' && $estadoAnterior !== 'validado';
$debeRegistrarIntegracion = $estado === 'validado' && $estadoAnterior !== 'validado';
$debeOmitirIntegracion = $estadoAnterior === 'validado' && $estado !== 'validado';

$payload = [
  'accion' => 'cierre_mensual_estado_actualizado',
  'tipo_cierre' => 'visa',

  'cierre' => [
    'tipo_cierre' => 'visa',
    'id' => (int)$cierre['id'],
    'user_id' => $userId,
    'username' => $cierre['username'],
    'comercial' => $cierre['comercial'],
    'email_comercial' => $cierre['user_email'] ?? '',

    'mes' => $mes,
    'anio' => $anio,
    'periodo' => $periodoTexto,
    'periodo_nombre' => $periodoNombre,
    'period_key' => $periodKey,

    'importe_app' => $importeApp,
    'importe_banco' => $importeBanco,
    'diferencia' => $diferencia,
    'total_gastos' => $totalGastos,

    'estado_anterior' => $estadoAnterior,
    'estado_nuevo' => $estado,

    'comentarios_comercial' => $cierre['comentarios_comercial'] ?? '',
    'comentarios_admin' => $comentariosAdmin,

    'revisado_por' => $revisadoPor,
    'revisado_at' => $localNow
  ],

  'periodo_control' => [
    'period_key' => $periodKey,
    'cerrado' => $debeCerrarPeriodo,
    'debe_cerrar_periodo' => $debeCerrarPeriodo,
    'debe_abrir_periodo' => $debeAbrirPeriodo,
    'estado_anterior' => $estadoAnterior,
    'estado_nuevo' => $estado
  ],

  'gastos' => $gastosPayload,

  'make' => [
    'debe_actualizar_data_store_periodos' => true,
    'debe_cerrar_periodo' => $debeCerrarPeriodo,
    'debe_abrir_periodo' => $debeAbrirPeriodo,

    'debe_enviar_email_comercial' => $debeEnviarEmailsValidacion,
    'debe_enviar_email_administracion' => $debeEnviarEmailsValidacion,
    'debe_generar_o_adjuntar_nota_gastos_pdf' => $debeEnviarEmailsValidacion,
    'debe_generar_o_adjuntar_justificantes_pdf' => $debeEnviarEmailsValidacion
  ]
];

procesarAdminAuditar($conn, [
  'tipo_evento' => 'cierre',
  'entidad' => 'cierre',
  'entidad_id' => $id,
  'accion' => procesarAdminAccionCierre($estadoAnterior, $estado),
  'descripcion' => procesarAdminDescripcionCierre($estadoAnterior, $estado, $cierre['comercial'] ?? '', $periodoNombre),
  'estado_anterior' => $estadoAnterior,
  'estado_nuevo' => $estado,
  'datos' => [
    'cierre_id' => $id,
    'user_id' => $userId,
    'username' => $cierre['username'] ?? '',
    'comercial' => $cierre['comercial'] ?? '',
    'periodo' => $periodoTexto,
    'period_key' => $periodKey,
    'importe_app' => $importeApp,
    'importe_banco' => $importeBanco,
    'diferencia' => $diferencia,
    'total_gastos' => $totalGastos,
    'comentarios_admin' => $comentariosAdmin
  ]
]);

$integracionMessage = "";
$integracionType = "success";

if ($debeRegistrarIntegracion) {
  $resultadoIntegracion = integracionesRegistrar($conn, [
    'tipo_destino' => 'contabilidad',
    'entidad' => 'cierre',
    'entidad_id' => (int)$cierre['id'],
    'referencia' => 'CIERRE-' . $periodKey,
    'descripcion' => 'Cierre mensual validado de ' . $cierre['comercial'] . ' - ' . $periodoNombre,
    'estado' => 'pendiente',
    'payload' => [
      'tipo' => 'cierre_mensual_validado',
      'destino_previsto' => 'contabilidad_erp_a3',
      'generado_desde' => 'admin_procesar_cierre_mensual',
      'fecha_generacion' => $localNow,
      'datos' => $payload
    ],
    'creado_por' => $revisadoPor
  ]);

  if (!empty($resultadoIntegracion['ok'])) {
    $integracionMessage = " Registro de integración creado correctamente.";

    procesarAdminAuditar($conn, [
      'tipo_evento' => 'envio',
      'entidad' => 'envio_integracion',
      'entidad_id' => (int)($resultadoIntegracion['id'] ?? 0),
      'accion' => 'integracion_pendiente_creada',
      'descripcion' => 'Se creó un envío pendiente de integración para un cierre mensual validado.',
      'estado_nuevo' => 'pendiente',
      'datos' => [
        'envio_integracion_id' => (int)($resultadoIntegracion['id'] ?? 0),
        'cierre_id' => (int)$cierre['id'],
        'referencia' => 'CIERRE-' . $periodKey,
        'tipo_destino' => 'contabilidad',
        'periodo' => $periodoTexto,
        'period_key' => $periodKey,
        'comercial' => $cierre['comercial'] ?? ''
      ]
    ]);
  } else {
    $integracionType = "error";
    $integracionMessage = " La revisión se guardó, pero no se pudo crear el registro de integración: " . ($resultadoIntegracion['message'] ?? 'error desconocido') . ".";

    procesarAdminAuditar($conn, [
      'tipo_evento' => 'envio',
      'entidad' => 'cierre',
      'entidad_id' => (int)$cierre['id'],
      'accion' => 'error_crear_integracion_pendiente',
      'descripcion' => 'No se pudo crear el envío pendiente de integración para un cierre mensual validado.',
      'estado_nuevo' => 'error',
      'datos' => [
        'cierre_id' => (int)$cierre['id'],
        'referencia' => 'CIERRE-' . $periodKey,
        'message' => $resultadoIntegracion['message'] ?? 'error desconocido'
      ]
    ]);
  }
}

if ($debeOmitirIntegracion) {
  $motivoOmitido = "Cierre reabierto automáticamente el " . $localNow . ". Estado anterior: validado. Nuevo estado: " . $estado . ". Envío pendiente anulado.";

  $resultadoOmitirIntegracion = procesarAdminOmitirIntegracionesPendientesCierre(
    $conn,
    (int)$cierre['id'],
    $motivoOmitido
  );

  if (!empty($resultadoOmitirIntegracion['ok'])) {
    if ((int)($resultadoOmitirIntegracion['affected_rows'] ?? 0) > 0) {
      $integracionMessage = " Envío pendiente anterior marcado como omitido.";

      procesarAdminAuditar($conn, [
        'tipo_evento' => 'envio',
        'entidad' => 'cierre',
        'entidad_id' => (int)$cierre['id'],
        'accion' => 'integracion_pendiente_omitida',
        'descripcion' => 'Se omitieron envíos pendientes de integración porque el cierre mensual fue reabierto.',
        'estado_anterior' => 'pendiente',
        'estado_nuevo' => 'omitido',
        'datos' => [
          'cierre_id' => (int)$cierre['id'],
          'affected_rows' => (int)($resultadoOmitirIntegracion['affected_rows'] ?? 0),
          'motivo' => $motivoOmitido
        ]
      ]);
    } else {
      $integracionMessage = " No había envíos pendientes de integración que omitir.";

      procesarAdminAuditar($conn, [
        'tipo_evento' => 'envio',
        'entidad' => 'cierre',
        'entidad_id' => (int)$cierre['id'],
        'accion' => 'integracion_pendiente_no_encontrada_al_reabrir',
        'descripcion' => 'El cierre se reabrió, pero no había envíos pendientes de integración que omitir.',
        'datos' => [
          'cierre_id' => (int)$cierre['id'],
          'motivo' => $motivoOmitido
        ]
      ]);
    }
  } else {
    $integracionType = "error";
    $integracionMessage = " La revisión se guardó, pero no se pudo omitir el envío pendiente de integración: " . ($resultadoOmitirIntegracion['message'] ?? 'error desconocido') . ".";

    procesarAdminAuditar($conn, [
      'tipo_evento' => 'envio',
      'entidad' => 'cierre',
      'entidad_id' => (int)$cierre['id'],
      'accion' => 'error_omitir_integracion_pendiente',
      'descripcion' => 'No se pudo omitir el envío pendiente de integración al reabrir un cierre mensual.',
      'estado_nuevo' => 'error',
      'datos' => [
        'cierre_id' => (int)$cierre['id'],
        'message' => $resultadoOmitirIntegracion['message'] ?? 'error desconocido'
      ]
    ]);
  }
}

$webhookUrl = '';

if (defined('MAKE_WEBHOOK_CIERRE_ESTADO')) {
  $webhookUrl = MAKE_WEBHOOK_CIERRE_ESTADO;
} elseif (defined('MAKE_WEBHOOK_CIERRE_VALIDADO')) {
  $webhookUrl = MAKE_WEBHOOK_CIERRE_VALIDADO;
}

$webhookMessage = "";
$webhookType = "success";

if (trim((string)$webhookUrl) !== '') {
  $webhookResult = callMakeWebhook($webhookUrl, $payload, 120);

  if ($webhookResult['ok']) {
    if ($estado === 'validado') {
      $webhookMessage = " Periodo cerrado correctamente.";
    } else {
      $webhookMessage = " Periodo reabierto correctamente.";
    }
  } else {
    $webhookType = "error";
    $webhookMessage = " La revisión se guardó, pero no se pudo completar la actualización. Inténtalo de nuevo.";

    procesarAdminAuditar($conn, [
      'tipo_evento' => 'sistema',
      'entidad' => 'cierre',
      'entidad_id' => (int)$cierre['id'],
      'accion' => 'error_make_cierre',
      'descripcion' => 'No se pudo completar la actualización del periodo tras revisar el cierre mensual.',
      'estado_anterior' => $estadoAnterior,
      'estado_nuevo' => $estado,
      'datos' => [
        'cierre_id' => (int)$cierre['id'],
        'period_key' => $periodKey,
        'message' => $webhookResult['message'] ?? 'error desconocido',
        'webhook_ok' => false
      ]
    ]);
  }
} else {
  $webhookType = "error";
  $webhookMessage = " La revisión se guardó, pero no se pudo completar la actualización.";

  procesarAdminAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'cierre',
    'entidad_id' => (int)$cierre['id'],
    'accion' => 'webhook_cierre_no_configurado',
    'descripcion' => 'La actualización automática del cierre todavía no está disponible.',
    'estado_anterior' => $estadoAnterior,
    'estado_nuevo' => $estado,
    'datos' => [
      'cierre_id' => (int)$cierre['id'],
      'period_key' => $periodKey
    ]
  ]);
}

$firmaType = ($firmaWebhookResult !== null && empty($firmaWebhookResult['ok'])) ? 'error' : 'success';
$firmaMessage = $firmaType === 'error' ? ' La revisión se guardó, pero la firma no pudo confirmarse.' : '';
$finalType = ($webhookType === 'error' || $integracionType === 'error' || $firmaType === 'error') ? 'error' : 'success';

header("Location: " . procesarAdminBuildRedirect(
  $return,
  $finalType,
  "Revisión del cierre mensual guardada correctamente." . $webhookMessage . $integracionMessage . $firmaMessage
));
exit;
?>

<?php
ob_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$__ticketsPdfAuditContext = [
  'user_id' => null,
  'username' => '',
  'comercial' => '',
  'mes' => null,
  'anio' => null,
  'accion' => 'procesar_tickets_pdf'
];

register_shutdown_function(function () {
  $error = error_get_last();

  if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    global $conn;
    global $__ticketsPdfAuditContext;

    if (function_exists('procesarTicketsPdfAuditar')) {
      procesarTicketsPdfAuditar($conn, [
        'tipo_evento' => 'sistema',
        'entidad' => 'tickets_pdf',
        'accion' => 'error_fatal_procesar_tickets_pdf',
        'descripcion' => 'Error fatal en procesar_tickets_pdf.php.',
        'estado_nuevo' => 'error',
        'datos' => [
          'contexto' => $__ticketsPdfAuditContext,
          'fatal_error' => [
            'type' => $error['type'],
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
          ]
        ]
      ]);
    }

    if (ob_get_length()) {
      ob_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);

    echo json_encode([
      "ok" => false,
      "message" => "No se ha podido preparar el documento. Inténtalo de nuevo."
    ], JSON_UNESCAPED_UNICODE);

    exit;
  }
});

require __DIR__ . "/session.php";
require __DIR__ . "/db.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/auditoria.php";

header('Content-Type: application/json; charset=utf-8');

function responderJsonTickets($data, $httpCode = 200) {
  if (ob_get_length()) {
    ob_clean();
  }

  http_response_code($httpCode);
  header('Content-Type: application/json; charset=utf-8');

  $ok = !empty($data['ok']);
  $safe = $ok ? $data : [
    'ok' => false,
    'message' => appPublicMessage($data['message'] ?? '', 'No se ha podido preparar el documento. Inténtalo de nuevo.')
  ];

  foreach (['debug', 'fatal_error', 'raw', 'json_error', 'sql_error', 'historico_error', 'payload'] as $privateKey) {
    unset($safe[$privateKey]);
  }

  echo json_encode($safe, JSON_UNESCAPED_UNICODE);
  exit;
}

function asegurarConexionMysqlTicketsPdf(&$conn) {
  try {
    if ($conn instanceof mysqli && @$conn->ping()) {
      return true;
    }
  } catch (Throwable $e) {
  }

  try {
    if ($conn instanceof mysqli) {
      @$conn->close();
    }
  } catch (Throwable $e) {
  }

  require __DIR__ . "/db.php";

  try {
    return $conn instanceof mysqli && !$conn->connect_errno;
  } catch (Throwable $e) {
    return false;
  }
}

function procesarTicketsPdfAuditar(&$conn, $data = []) {
  if (!function_exists('auditoriaRegistrar')) {
    return false;
  }

  try {
    asegurarConexionMysqlTicketsPdf($conn);

    if (!isset($data['tipo_evento']) || trim((string)$data['tipo_evento']) === '') {
      $data['tipo_evento'] = 'sistema';
    }

    if (!isset($data['entidad']) || trim((string)$data['entidad']) === '') {
      $data['entidad'] = 'tickets_pdf';
    }

    if (!isset($data['accion']) || trim((string)$data['accion']) === '') {
      $data['accion'] = 'procesar_tickets_pdf';
    }

    auditoriaRegistrar($conn, $data);

    return true;
  } catch (Throwable $e) {
    error_log("No se pudo registrar auditoría en procesar_tickets_pdf.php: " . $e->getMessage());
    return false;
  }
}

function getNombreMesTicketsPdf($mes) {
  $meses = [
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

  return $meses[$mes] ?? '';
}

function limpiarNombreArchivoTicketsPdf($texto) {
  $texto = trim((string)$texto);

  $buscar = ['Á','É','Í','Ó','Ú','Ü','Ñ','á','é','í','ó','ú','ü','ñ'];
  $reemplazar = ['A','E','I','O','U','U','N','a','e','i','o','u','u','n'];

  $texto = str_replace($buscar, $reemplazar, $texto);
  $texto = preg_replace('/[^A-Za-z0-9_-]+/', '_', $texto);
  $texto = trim($texto, '_');

  return $texto !== '' ? $texto : 'usuario';
}

function llamarWebhookTicketsPdf($url, $payload, $timeout = 240) {
  return callMakeWebhook($url, $payload, $timeout);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data) {
  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'tickets_pdf',
    'accion' => 'json_invalido_tickets_pdf',
    'descripcion' => 'Intento de generar PDF de justificantes con JSON inválido o cuerpo vacío.',
    'estado_nuevo' => 'error',
    'datos' => [
      'raw' => $raw,
      'json_error' => json_last_error_msg()
    ]
  ]);

  responderJsonTickets([
    "ok" => false,
    "message" => "Revisa los datos enviados."
  ], 400);
}

$userId = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['user'] ?? '';
$comercial = $_SESSION['comercial'] ?? '';

$mes = intval($data['mes'] ?? 0);
$anio = intval($data['año'] ?? $data['anio'] ?? 0);

$__ticketsPdfAuditContext = [
  'user_id' => $userId,
  'username' => $username,
  'comercial' => $comercial,
  'mes' => $mes,
  'anio' => $anio,
  'accion' => 'procesar_tickets_pdf'
];

if ($userId <= 0 || $username === '' || $comercial === '') {
  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'tickets_pdf',
    'accion' => 'sesion_no_valida_tickets_pdf',
    'descripcion' => 'Intento de generar PDF de justificantes sin una sesión válida.',
    'estado_nuevo' => 'error',
    'datos' => [
      'user_id' => $userId,
      'username' => $username,
      'comercial' => $comercial
    ]
  ]);

  responderJsonTickets([
    "ok" => false,
    "message" => "Sesión no válida. Vuelve a iniciar sesión.",
    "debug" => [
      "user_id" => $userId,
      "username" => $username,
      "comercial" => $comercial
    ]
  ], 401);
}

if ($mes < 1 || $mes > 12 || $anio < 2000) {
  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'tickets_pdf',
    'accion' => 'periodo_no_valido_tickets_pdf',
    'descripcion' => 'Intento de generar PDF de justificantes con mes o año no válido.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mes' => $mes,
      'anio' => $anio
    ]
  ]);

  responderJsonTickets([
    "ok" => false,
    "message" => "Mes o año no válido",
    "debug" => [
      "mes" => $mes,
      "anio" => $anio
    ]
  ], 400);
}

if (!defined('MAKE_WEBHOOK_TICKETS_PDF') || trim(MAKE_WEBHOOK_TICKETS_PDF) === '') {
  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'tickets_pdf',
    'accion' => 'webhook_tickets_pdf_no_configurado',
    'descripcion' => 'La generación del documento todavía no está disponible.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mes' => $mes,
      'anio' => $anio,
      'user_id' => $userId
    ]
  ]);

  responderJsonTickets([
    "ok" => false,
    "message" => "Esta descarga no está disponible en este momento."
  ], 500);
}

if (!defined('DRIVE_TEMPLATE_PORTADA_JUSTIFICANTES_ID') || trim(DRIVE_TEMPLATE_PORTADA_JUSTIFICANTES_ID) === '') {
  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'tickets_pdf',
    'accion' => 'plantilla_portada_no_configurada',
    'descripcion' => 'La plantilla de portada de justificantes no está configurada.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mes' => $mes,
      'anio' => $anio,
      'user_id' => $userId
    ]
  ]);

  responderJsonTickets([
    "ok" => false,
    "message" => "Esta descarga no está disponible en este momento."
  ], 500);
}

$mesNombre = getNombreMesTicketsPdf($mes);

if ($mesNombre === '') {
  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'tickets_pdf',
    'accion' => 'mes_no_valido_tickets_pdf',
    'descripcion' => 'Intento de generar PDF de justificantes con mes no válido.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mes' => $mes,
      'anio' => $anio
    ]
  ]);

  responderJsonTickets([
    "ok" => false,
    "message" => "Mes no válido"
  ], 400);
}

/*
  Comprobación opcional de tabla histórica.
  Si no existe, NO paramos antes de Make.
  Solo se usará al final si se puede.
*/
$tablaHistoricoExiste = false;

try {
  $checkTable = $conn->query("SHOW TABLES LIKE 'tickets_pdf_generados'");

  if ($checkTable && $checkTable->num_rows > 0) {
    $tablaHistoricoExiste = true;
  }
} catch (Throwable $e) {
  $tablaHistoricoExiste = false;

  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'tickets_pdf',
    'accion' => 'error_comprobar_tabla_historico_tickets_pdf',
    'descripcion' => 'No se pudo comprobar si existe la tabla tickets_pdf_generados.',
    'estado_nuevo' => 'error',
    'datos' => [
      'error' => $e->getMessage(),
      'mes' => $mes,
      'anio' => $anio,
      'user_id' => $userId
    ]
  ]);
}

/*
  1. Total gastos.
*/
$sqlTotal = "SELECT COUNT(*) AS total_gastos
             FROM gastos
             WHERE user_id = ?
               AND deleted_at IS NULL
               AND estado IN ('procesado', 'editado')
               AND fecha_ticket IS NOT NULL
               AND MONTH(fecha_ticket) = ?
               AND YEAR(fecha_ticket) = ?";

$stmtTotal = $conn->prepare($sqlTotal);

if (!$stmtTotal) {
  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'tickets_pdf',
    'accion' => 'error_sql_preparar_total_gastos_tickets_pdf',
    'descripcion' => 'Error SQL preparando total de gastos para PDF de justificantes.',
    'estado_nuevo' => 'error',
    'datos' => [
      'sql_error' => $conn->error,
      'mes' => $mes,
      'anio' => $anio,
      'user_id' => $userId
    ]
  ]);

  responderJsonTickets([
    "ok" => false,
    "message" => "Error SQL preparando total de gastos",
    "sql_error" => $conn->error
  ], 500);
}

$stmtTotal->bind_param("iii", $userId, $mes, $anio);

if (!$stmtTotal->execute()) {
  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'tickets_pdf',
    'accion' => 'error_sql_ejecutar_total_gastos_tickets_pdf',
    'descripcion' => 'Error SQL ejecutando total de gastos para PDF de justificantes.',
    'estado_nuevo' => 'error',
    'datos' => [
      'sql_error' => $stmtTotal->error,
      'mes' => $mes,
      'anio' => $anio,
      'user_id' => $userId
    ]
  ]);

  responderJsonTickets([
    "ok" => false,
    "message" => "Error SQL ejecutando total de gastos",
    "sql_error" => $stmtTotal->error
  ], 500);
}

$totalGastos = intval($stmtTotal->get_result()->fetch_assoc()['total_gastos'] ?? 0);

if ($totalGastos === 0) {
  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'gasto',
    'entidad' => 'tickets_pdf',
    'accion' => 'tickets_pdf_sin_gastos_periodo',
    'descripcion' => 'No hay gastos registrados para generar PDF de justificantes en el periodo seleccionado.',
    'estado_nuevo' => 'sin_gastos',
    'datos' => [
      'mes' => $mes,
      'anio' => $anio,
      'user_id' => $userId,
      'username' => $username,
      'comercial' => $comercial
    ]
  ]);

  responderJsonTickets([
    "ok" => false,
    "message" => "No hay gastos registrados para el periodo seleccionado"
  ]);
}

/*
  2. Gastos con justificante.
*/
$sqlConJustificante = "SELECT COUNT(DISTINCT g.id) AS gastos_con_justificante
                       FROM gastos g
                       INNER JOIN gasto_tickets gt
                         ON gt.gasto_id = g.id
                         AND gt.gasto_uid = g.gasto_uid
                       WHERE g.user_id = ?
                         AND g.deleted_at IS NULL
                         AND g.estado IN ('procesado', 'editado')
                         AND g.fecha_ticket IS NOT NULL
                         AND MONTH(g.fecha_ticket) = ?
                         AND YEAR(g.fecha_ticket) = ?
                         AND gt.drive_file_id IS NOT NULL
                         AND gt.drive_file_id <> ''";

$stmtCon = $conn->prepare($sqlConJustificante);

if (!$stmtCon) {
  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'tickets_pdf',
    'accion' => 'error_sql_preparar_gastos_con_justificante',
    'descripcion' => 'Error SQL preparando gastos con justificante para PDF.',
    'estado_nuevo' => 'error',
    'datos' => [
      'sql_error' => $conn->error,
      'mes' => $mes,
      'anio' => $anio,
      'user_id' => $userId
    ]
  ]);

  responderJsonTickets([
    "ok" => false,
    "message" => "Error SQL preparando gastos con justificante",
    "sql_error" => $conn->error
  ], 500);
}

$stmtCon->bind_param("iii", $userId, $mes, $anio);

if (!$stmtCon->execute()) {
  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'tickets_pdf',
    'accion' => 'error_sql_ejecutar_gastos_con_justificante',
    'descripcion' => 'Error SQL ejecutando gastos con justificante para PDF.',
    'estado_nuevo' => 'error',
    'datos' => [
      'sql_error' => $stmtCon->error,
      'mes' => $mes,
      'anio' => $anio,
      'user_id' => $userId
    ]
  ]);

  responderJsonTickets([
    "ok" => false,
    "message" => "Error SQL ejecutando gastos con justificante",
    "sql_error" => $stmtCon->error
  ], 500);
}

$gastosConJustificante = intval($stmtCon->get_result()->fetch_assoc()['gastos_con_justificante'] ?? 0);
$gastosSinJustificante = max(0, $totalGastos - $gastosConJustificante);

/*
  3. Tickets.
*/
$sqlTickets = "SELECT 
                 g.id,
                 g.gasto_uid,
                 g.comercial,
                 g.viaje,
                 g.motivo,
                 g.importe_detectado,
                 g.fecha_ticket,
                 gt.filename,
                 gt.mime_type,
                 gt.drive_file_id,
                 gt.drive_file_url
               FROM gastos g
               INNER JOIN gasto_tickets gt 
                 ON gt.gasto_id = g.id
                 AND gt.gasto_uid = g.gasto_uid
               WHERE g.user_id = ?
                 AND g.deleted_at IS NULL
                 AND g.estado IN ('procesado', 'editado')
                 AND g.fecha_ticket IS NOT NULL
                 AND gt.drive_file_id IS NOT NULL
                 AND gt.drive_file_id <> ''
                 AND MONTH(g.fecha_ticket) = ?
                 AND YEAR(g.fecha_ticket) = ?
               ORDER BY g.fecha_ticket ASC, g.id ASC";

$stmtTickets = $conn->prepare($sqlTickets);

if (!$stmtTickets) {
  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'tickets_pdf',
    'accion' => 'error_sql_preparar_tickets_pdf',
    'descripcion' => 'Error SQL preparando listado de tickets para PDF.',
    'estado_nuevo' => 'error',
    'datos' => [
      'sql_error' => $conn->error,
      'mes' => $mes,
      'anio' => $anio,
      'user_id' => $userId
    ]
  ]);

  responderJsonTickets([
    "ok" => false,
    "message" => "Error SQL preparando tickets",
    "sql_error" => $conn->error
  ], 500);
}

$stmtTickets->bind_param("iii", $userId, $mes, $anio);

if (!$stmtTickets->execute()) {
  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'tickets_pdf',
    'accion' => 'error_sql_ejecutar_tickets_pdf',
    'descripcion' => 'Error SQL ejecutando listado de tickets para PDF.',
    'estado_nuevo' => 'error',
    'datos' => [
      'sql_error' => $stmtTickets->error,
      'mes' => $mes,
      'anio' => $anio,
      'user_id' => $userId
    ]
  ]);

  responderJsonTickets([
    "ok" => false,
    "message" => "Error SQL ejecutando tickets",
    "sql_error" => $stmtTickets->error
  ], 500);
}

$resultTickets = $stmtTickets->get_result();

$tickets = [];

while ($row = $resultTickets->fetch_assoc()) {
  $tickets[] = [
    "gasto_id" => (int)$row['id'],
    "gasto_uid" => $row['gasto_uid'],
    "comercial" => $row['comercial'],
    "viaje" => $row['viaje'],
    "motivo" => $row['motivo'],
    "importe" => $row['importe_detectado'],
    "fecha_ticket" => $row['fecha_ticket'],
    "fecha_ticket_web" => formatFechaWeb($row['fecha_ticket']),
    "filename" => $row['filename'],
    "mime_type" => $row['mime_type'],
    "drive_file_id" => $row['drive_file_id'],
    "drive_file_url" => $row['drive_file_url']
  ];
}

$fechaGeneracion = formatFechaWeb(date('Y-m-d H:i:s'), true);

$filename = "Justificantes_" .
            limpiarNombreArchivoTicketsPdf($comercial) . "_" .
            str_pad((string)$mes, 2, "0", STR_PAD_LEFT) . "_" .
            $anio . ".pdf";

$payload = [
  "accion" => "generar_pdf_justificantes",

  "usuario" => [
    "user_id" => $userId,
    "username" => $username,
    "comercial" => $comercial
  ],

  "periodo" => [
    "mes" => $mes,
    "mes_nombre" => $mesNombre,
    "anio" => $anio
  ],

  "resumen" => [
    "total_gastos" => $totalGastos,
    "gastos_con_justificante" => $gastosConJustificante,
    "gastos_sin_justificante" => $gastosSinJustificante,
    "total_tickets" => count($tickets),
    "fecha_generacion" => $fechaGeneracion
  ],

  "plantilla" => [
    "portada_template_id" => DRIVE_TEMPLATE_PORTADA_JUSTIFICANTES_ID
  ],

  "tickets" => $tickets,

  "salida" => [
    "filename" => $filename
  ]
];

/*
  4. Llamar a Make.
*/
$makeResult = llamarWebhookTicketsPdf(MAKE_WEBHOOK_TICKETS_PDF, $payload, 240);

/*
  Después de una llamada larga a Make, ByetHost puede cerrar la conexión MySQL.
  La reabrimos antes de cualquier INSERT/UPDATE posterior.
*/
asegurarConexionMysqlTicketsPdf($conn);

if (!$makeResult['ok']) {
  procesarTicketsPdfAuditar($conn, [
    'tipo_evento' => 'sistema',
    'entidad' => 'tickets_pdf',
    'accion' => 'error_make_tickets_pdf',
    'descripcion' => 'No se pudo completar la generación del documento de justificantes.',
    'estado_nuevo' => 'error',
    'datos' => [
      'mes' => $mes,
      'anio' => $anio,
      'user_id' => $userId,
      'username' => $username,
      'comercial' => $comercial,
      'make_result' => $makeResult
    ]
  ]);

  responderJsonTickets([
    "ok" => false,
    "message" => "No se ha podido preparar el documento. Inténtalo de nuevo."
  ], 500);
}

$responseJson = $makeResult['response_json'] ?? [];

$pdfFileUrl = $responseJson['pdf_file_url'] 
  ?? $responseJson['file_url'] 
  ?? $responseJson['webViewLink'] 
  ?? $responseJson['web_view_link'] 
  ?? null;

$pdfFileId = $responseJson['pdf_file_id'] 
  ?? $responseJson['file_id'] 
  ?? null;

$responseFilename = $responseJson['filename'] ?? $filename;

$makeResponseJson = json_encode($makeResult, JSON_UNESCAPED_UNICODE);

/*
  5. Guardar histórico solo si existe la tabla.
  Si falla, NO se devuelve error al usuario porque el PDF ya se ha generado correctamente.
*/
$historicoGuardado = false;
$historicoError = '';

if ($tablaHistoricoExiste) {
  try {
    asegurarConexionMysqlTicketsPdf($conn);

    $sqlHist = "INSERT INTO tickets_pdf_generados
                (user_id, username, comercial, mes, anio, total_gastos, gastos_con_justificante, gastos_sin_justificante, total_tickets, pdf_file_id, pdf_file_url, filename, make_response)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmtHist = $conn->prepare($sqlHist);

    if (!$stmtHist) {
      throw new Exception("No se pudo preparar el histórico de PDF: " . $conn->error);
    }

    $totalTickets = count($tickets);

    $stmtHist->bind_param(
      "issiiiiiissss",
      $userId,
      $username,
      $comercial,
      $mes,
      $anio,
      $totalGastos,
      $gastosConJustificante,
      $gastosSinJustificante,
      $totalTickets,
      $pdfFileId,
      $pdfFileUrl,
      $responseFilename,
      $makeResponseJson
    );

    if (!$stmtHist->execute()) {
      throw new Exception("No se pudo ejecutar el histórico de PDF: " . $stmtHist->error);
    }

    $historicoGuardado = true;
  } catch (Throwable $e) {
    $historicoGuardado = false;
    $historicoError = $e->getMessage();

    procesarTicketsPdfAuditar($conn, [
      'tipo_evento' => 'sistema',
      'entidad' => 'tickets_pdf',
      'accion' => 'error_guardar_historico_tickets_pdf',
      'descripcion' => 'El PDF de justificantes se generó correctamente, pero no se pudo guardar el histórico en MySQL.',
      'estado_nuevo' => 'error',
      'datos' => [
        'error' => $historicoError,
        'mes' => $mes,
        'anio' => $anio,
        'user_id' => $userId,
        'username' => $username,
        'comercial' => $comercial,
        'pdf_file_id' => $pdfFileId,
        'pdf_file_url' => $pdfFileUrl,
        'filename' => $responseFilename
      ]
    ]);
  }
}

/*
  6. Auditoría de éxito.
*/
procesarTicketsPdfAuditar($conn, [
  'tipo_evento' => 'gasto',
  'entidad' => 'tickets_pdf',
  'accion' => 'pdf_justificantes_generado',
  'descripcion' => 'PDF de justificantes generado correctamente.',
  'estado_nuevo' => 'generado',
  'datos' => [
    'mes' => $mes,
    'anio' => $anio,
    'user_id' => $userId,
    'username' => $username,
    'comercial' => $comercial,
    'pdf_file_id' => $pdfFileId,
    'pdf_file_url' => $pdfFileUrl,
    'filename' => $responseFilename,
    'total_gastos' => $totalGastos,
    'gastos_con_justificante' => $gastosConJustificante,
    'gastos_sin_justificante' => $gastosSinJustificante,
    'total_tickets' => count($tickets),
    'historico_guardado' => $historicoGuardado,
    'historico_error' => $historicoError
  ]
]);

responderJsonTickets([
  "ok" => true,
  "message" => "PDF de justificantes generado correctamente",
  "pdf_file_id" => $pdfFileId,
  "pdf_file_url" => $pdfFileUrl,
  "filename" => $responseFilename,
  "total_gastos" => $totalGastos,
  "gastos_con_justificante" => $gastosConJustificante,
  "gastos_sin_justificante" => $gastosSinJustificante,
  "total_tickets" => count($tickets),
  "historico_guardado" => $historicoGuardado,
  "historico_error" => $historicoError
]);
?>

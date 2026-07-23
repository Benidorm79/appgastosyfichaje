<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/integraciones.php";
require_once __DIR__ . "/../includes/auditoria.php";
require_once __DIR__ . "/../includes/gastos_unificados.php";
require_once __DIR__ . "/../includes/cierre_firmas_efectivo.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

function adminCierreEfectivoRedirect($mes, $anio, $type, $message)
{
    $message = appPublicMessage($message, $type === 'error' ? 'La revisión se ha guardado, pero no se ha podido completar todo el proceso.' : 'Revisión guardada correctamente.');
    header(
        "Location: cierres_mensuales.php?mes=" . urlencode((string)$mes) .
        "&anio=" . urlencode((string)$anio) .
        "&type=" . urlencode($type) .
        "&msg=" . urlencode($message) .
        "#cierres-efectivo"
    );
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$estado = trim((string)($_POST['estado'] ?? ''));
$comentariosAdmin = trim((string)($_POST['comentarios_admin'] ?? ''));
$permitidos = ['pendiente_admin', 'validado', 'con_diferencia', 'rechazado'];
$actorId = (int)($_SESSION['user_id'] ?? 0);
$actorRole = (string)($_SESSION['role'] ?? 'admin');
$now = date('Y-m-d H:i:s');

if ($id <= 0 || !in_array($estado, $permitidos, true)) {
    adminCierreEfectivoRedirect((int)date('n'), (int)date('Y'), 'error', 'Datos de revisión no válidos.');
}

if (!gastosUnificadosTableExists($conn, 'cierres_mensuales_efectivo')) {
    adminCierreEfectivoRedirect((int)date('n'), (int)date('Y'), 'error', 'Falta instalar la tabla de cierres de Efectivo y Kilometraje.');
}

$stmt = $conn->prepare("SELECT * FROM cierres_mensuales_efectivo WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$cierre = $stmt->get_result()->fetch_assoc();

if (!$cierre) {
    adminCierreEfectivoRedirect((int)date('n'), (int)date('Y'), 'error', 'No se encontró el cierre.');
}

$mes = (int)$cierre['mes'];
$anio = (int)$cierre['anio'];
$estadoAnterior = (string)$cierre['estado'];
$contabilizado = gastosUnificadosCierreEfectivoContabilizado($conn, $id);

if ($contabilizado && $actorRole !== 'master') {
    adminCierreEfectivoRedirect($mes, $anio, 'error', 'El cierre está contabilizado. Solo el perfil Máster puede modificarlo.');
}

$resumen = gastosUnificadosTotalEfectivo($conn, (int)$cierre['user_id'], $mes, $anio);
$importeApp = (float)$resumen['total_importe'];
$importeBanco = (float)$cierre['importe_banco'];
$diferencia = round($importeBanco - $importeApp, 2);

if ($estado === 'validado' && abs($diferencia) > 0.009) {
    $estado = 'con_diferencia';
}

$stmtUpdate = $conn->prepare(
    "UPDATE cierres_mensuales_efectivo
     SET estado = ?, importe_app = ?, diferencia = ?, comentarios_admin = ?,
         revisado_por = ?, revisado_at = ?, updated_at = ?
     WHERE id = ?
     LIMIT 1"
);
$stmtUpdate->bind_param(
    'sddsissi',
    $estado,
    $importeApp,
    $diferencia,
    $comentariosAdmin,
    $actorId,
    $now,
    $now,
    $id
);

if (!$stmtUpdate->execute()) {
    adminCierreEfectivoRedirect($mes, $anio, 'error', 'No se pudo guardar la revisión del cierre.');
}

/*
 * Cuando Máster reabre un cierre ya contabilizado, la integración enviada
 * deja de bloquearlo. No se elimina el histórico: queda como omitido.
 */
if ($contabilizado && $actorRole === 'master' && $estado !== 'validado') {
    $entityId = integracionesEnsureTipoCierreColumn($conn) ? abs($id) : -abs($id);
    $motivo = 'Reabierto por Máster';
    $stmtOmitir = $conn->prepare(
        "UPDATE envios_integraciones
         SET estado = 'omitido', ultimo_error = ?, updated_at = ?
         WHERE entidad = 'cierre' AND entidad_id = ? AND (tipo_cierre = 'efectivo' OR tipo_cierre IS NULL) AND estado = 'enviado'"
    );
    if ($stmtOmitir) {
        $stmtOmitir->bind_param('ssi', $motivo, $now, $entityId);
        $stmtOmitir->execute();
    }
}

$periodKey = (int)$cierre['user_id'] . '_' .
    str_pad((string)$mes, 2, '0', STR_PAD_LEFT) . '_' . $anio . '_efectivo';

$payload = [
    'tipo' => 'cierre_mensual_estado',
    'tipo_cierre' => 'efectivo',
    'cierre_id' => $id,
    'user_id' => (int)$cierre['user_id'],
    'username' => (string)$cierre['username'],
    'comercial' => (string)$cierre['comercial'],
    'mes' => $mes,
    'anio' => $anio,
    'period_key' => $periodKey,
    'estado_anterior' => $estadoAnterior,
    'estado' => $estado,
    'cerrar_periodo' => $estado === 'validado',
    'abrir_periodo' => $estado !== 'validado',
    'importe_app' => $importeApp,
    'importe_banco' => $importeBanco,
    'diferencia' => $diferencia,
    'total_efectivo' => (float)$resumen['total_efectivo'],
    'total_kilometraje' => (float)$resumen['total_kilometraje'],
    'total_registros' => (int)$resumen['total_registros'],
    'comentarios_admin' => $comentariosAdmin
];

$webhookUrl = '';
if (defined('MAKE_WEBHOOK_CIERRE_ESTADO')) {
    $webhookUrl = (string)MAKE_WEBHOOK_CIERRE_ESTADO;
} elseif (defined('MAKE_WEBHOOK_CIERRE_VALIDADO')) {
    $webhookUrl = (string)MAKE_WEBHOOK_CIERRE_VALIDADO;
}

$webhookWarning = '';
$firmaWebhookResult = null;
if (trim($webhookUrl) !== '') {
    $webhookResult = callMakeWebhook($webhookUrl, $payload, 120);
    if (empty($webhookResult['ok'])) {
        $webhookWarning = ' El cierre se guardó, pero no se pudo completar la actualización.';
    }
}

if ($estado === 'validado' && $estadoAnterior !== 'validado') {
    $cierreFirmado = cierreEfectivoFirmasFetchCierre($conn, $id);
    if ($cierreFirmado) {
        $firmaWebhookResult = cierreEfectivoFirmasGenerarYEnviar(
            $conn,
            'admin',
            $cierreFirmado,
            [
                'user_id' => $actorId,
                'username' => (string)($_SESSION['user'] ?? ''),
                'comercial' => (string)($_SESSION['comercial'] ?? ''),
                'rol' => $actorRole
            ],
            [
                'evento' => 'validacion_cierre_efectivo',
                'tipo_cierre' => 'efectivo',
                'forzar_envio_webhook' => true
            ]
        );
    }

    $tieneTipoCierre = integracionesEnsureTipoCierreColumn($conn);
    $entityId = $tieneTipoCierre ? abs($id) : -abs($id);
    $sqlExiste = $tieneTipoCierre
        ? "SELECT id FROM envios_integraciones
           WHERE entidad = 'cierre' AND entidad_id = ? AND tipo_cierre = 'efectivo'
             AND estado IN ('pendiente','enviado') LIMIT 1"
        : "SELECT id FROM envios_integraciones
           WHERE entidad = 'cierre' AND entidad_id = ?
             AND estado IN ('pendiente','enviado') LIMIT 1";
    $stmtExiste = $conn->prepare($sqlExiste);
    $stmtExiste->bind_param('i', $entityId);
    $stmtExiste->execute();
    $existe = $stmtExiste->get_result()->fetch_assoc();

    if (!$existe) {
        integracionesRegistrar($conn, [
            'tipo_destino' => 'contabilidad',
            'entidad' => 'cierre',
            'entidad_id' => $entityId,
            'tipo_cierre' => 'efectivo',
            'referencia' => 'CIERRE-EFECTIVO-' . $periodKey,
            'descripcion' => 'Cierre de Efectivo y Kilometraje validado de ' .
                $cierre['comercial'] . ' - ' . str_pad((string)$mes, 2, '0', STR_PAD_LEFT) . '/' . $anio,
            'estado' => 'pendiente',
            'payload' => [
                'tipo' => 'cierre_mensual_validado',
                'tipo_cierre' => 'efectivo',
                'destino_previsto' => 'contabilidad_erp_a3',
                'fecha_generacion' => $now,
                'datos' => $payload
            ],
            'creado_por' => $actorId
        ]);
    }
}

auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'cierre',
    'entidad' => 'cierre_efectivo',
    'entidad_id' => $id,
    'accion' => 'revision_cierre_efectivo',
    'descripcion' => 'Revisión administrativa del cierre mensual de Efectivo y Kilometraje.',
    'estado_anterior' => $estadoAnterior,
    'estado_nuevo' => $estado,
    'datos' => [
        'tipo_cierre' => 'efectivo',
        'mes' => $mes,
        'anio' => $anio,
        'importe_app' => $importeApp,
        'importe_banco' => $importeBanco,
        'diferencia' => $diferencia
    ]
]);

$firmaWarning = '';
$finalType = 'success';

if ($firmaWebhookResult !== null && empty($firmaWebhookResult['ok'])) {
    $finalType = 'error';
    $firmaWarning = ' La revisión se guardó, pero la firma no pudo confirmarse.';
}

if ($webhookWarning !== '') {
    $finalType = 'error';
}

adminCierreEfectivoRedirect(
    $mes,
    $anio,
    $finalType,
    'Cierre de Efectivo y Kilometraje actualizado correctamente.' . $webhookWarning . $firmaWarning
);

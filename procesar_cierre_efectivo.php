<?php
require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/auditoria.php";
require_once __DIR__ . "/includes/gastos_unificados.php";
require_once __DIR__ . "/includes/cierre_firmas_efectivo.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

function cierreEfectivoNormalizarImporte($valor)
{
    $valor = trim((string)$valor);
    if ($valor === '') return 0.0;
    $valor = str_replace(' ', '', $valor);

    if (strpos($valor, ',') !== false && strpos($valor, '.') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (strpos($valor, ',') !== false) {
        $valor = str_replace(',', '.', $valor);
    }

    return (float)$valor;
}

function cierreEfectivoRedirect($mes, $anio, $type, $msg)
{
    $msg = appPublicMessage($msg, $type === 'error' ? 'El cierre se ha guardado, pero no se ha podido completar todo el proceso.' : 'Cierre guardado correctamente.');
    header(
        "Location: cierre_mensual.php?mes=" . urlencode((string)$mes) .
        "&anio=" . urlencode((string)$anio) .
        "&tab=efectivo" .
        "&type=" . urlencode($type) .
        "&msg=" . urlencode($msg) .
        "#cierre-efectivo"
    );
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = (string)($_SESSION['user'] ?? '');
$comercial = (string)($_SESSION['comercial'] ?? $username);
$mes = (int)($_POST['mes'] ?? 0);
$anio = (int)($_POST['anio'] ?? 0);
$importeDeclarado = cierreEfectivoNormalizarImporte($_POST['importe_declarado'] ?? '');
$comentarios = trim((string)($_POST['comentarios_comercial_efectivo'] ?? ''));
$now = date('Y-m-d H:i:s');

if (
    $userId <= 0 ||
    $mes < 1 || $mes > 12 ||
    $anio < 2000 || $anio > 2100 ||
    $importeDeclarado < 0
) {
    cierreEfectivoRedirect($mes, $anio, 'error', 'Los datos del cierre de Efectivo y Kilometraje no son válidos.');
}

if (!gastosUnificadosTableExists($conn, 'cierres_mensuales_efectivo')) {
    cierreEfectivoRedirect($mes, $anio, 'error', 'Falta instalar la tabla de cierres de Efectivo y Kilometraje.');
}

$cierreActual = gastosUnificadosCierreEfectivo($conn, $userId, $mes, $anio);

if ($cierreActual) {
    if (gastosUnificadosCierreEfectivoContabilizado($conn, (int)$cierreActual['id'])) {
        cierreEfectivoRedirect($mes, $anio, 'error', 'Este cierre ya está contabilizado y no puede modificarse.');
    }

    if (in_array((string)$cierreActual['estado'], ['validado', 'con_diferencia', 'rechazado'], true)) {
        cierreEfectivoRedirect($mes, $anio, 'error', 'Este cierre ya ha sido revisado por dirección y no puede modificarse.');
    }
}

$resumen = gastosUnificadosTotalEfectivo($conn, $userId, $mes, $anio);
$importeApp = (float)$resumen['total_importe'];
$diferencia = round($importeDeclarado - $importeApp, 2);
$estado = 'pendiente_admin';

$sql = "INSERT INTO cierres_mensuales_efectivo
        (
          user_id, username, comercial, mes, anio,
          importe_banco, comentarios_comercial, estado,
          importe_app, diferencia, created_at, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          username = VALUES(username),
          comercial = VALUES(comercial),
          importe_banco = VALUES(importe_banco),
          comentarios_comercial = VALUES(comentarios_comercial),
          estado = 'pendiente_admin',
          importe_app = VALUES(importe_app),
          diferencia = VALUES(diferencia),
          comentarios_admin = NULL,
          revisado_por = NULL,
          revisado_at = NULL,
          updated_at = VALUES(updated_at)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    cierreEfectivoRedirect($mes, $anio, 'error', 'No se pudo preparar el cierre de Efectivo y Kilometraje.');
}

$stmt->bind_param(
    'issiidssddss',
    $userId,
    $username,
    $comercial,
    $mes,
    $anio,
    $importeDeclarado,
    $comentarios,
    $estado,
    $importeApp,
    $diferencia,
    $now,
    $now
);

if (!$stmt->execute()) {
    cierreEfectivoRedirect($mes, $anio, 'error', 'No se pudo guardar el cierre de Efectivo y Kilometraje.');
}

$cierre = gastosUnificadosCierreEfectivo($conn, $userId, $mes, $anio);

$firmaWebhookResult = null;

if ($cierre) {
    $cierreCompleto = cierreEfectivoFirmasFetchCierre($conn, (int)$cierre['id']) ?: $cierre;
    $firmaWebhookResult = cierreEfectivoFirmasGenerarYEnviar(
        $conn,
        'comercial',
        $cierreCompleto,
        [
            'user_id' => $userId,
            'username' => $username,
            'comercial' => $comercial,
            'rol' => (string)($_SESSION['role'] ?? 'user')
        ],
        [
            'evento' => 'confirmacion_cierre_efectivo_comercial',
            'forzar_envio_webhook' => true,
            'tipo_cierre' => 'efectivo',
            'total_efectivo' => (float)$resumen['total_efectivo'],
            'total_kilometraje' => (float)$resumen['total_kilometraje'],
            'total_registros' => (int)$resumen['total_registros']
        ]
    );
}

auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'cierre',
    'entidad' => 'cierre_efectivo',
    'entidad_id' => (int)($cierre['id'] ?? 0),
    'accion' => 'cierre_efectivo_enviado_comercial',
    'descripcion' => 'Cierre mensual de Efectivo y Kilometraje enviado para revisión.',
    'estado_nuevo' => 'pendiente_admin',
    'datos' => [
        'tipo_cierre' => 'efectivo',
        'mes' => $mes,
        'anio' => $anio,
        'importe_app' => $importeApp,
        'importe_declarado' => $importeDeclarado,
        'diferencia' => $diferencia
    ]
]);

if (!$firmaWebhookResult || empty($firmaWebhookResult['ok'])) {
    cierreEfectivoRedirect($mes, $anio, 'error', 'El cierre se ha guardado, pero la firma no ha podido confirmarse.');
}

cierreEfectivoRedirect($mes, $anio, 'success', 'Cierre de Efectivo y Kilometraje guardado y firmado correctamente.');

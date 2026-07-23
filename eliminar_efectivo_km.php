<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/auditoria.php';
require_once __DIR__ . '/includes/gastos_unificados.php';

securitySendHeaders();
requirePostMethod();

$tipo = trim((string)($_POST['tipo'] ?? ''));
$id = (int)($_POST['id'] ?? 0);
$returnUrl = sanitizeRedirect($_POST['return'] ?? 'gestionar_gastos.php');

if (!hash_equals(csrfToken(), (string)($_POST['csrf_token'] ?? ''))) {
    header('Location: ' . $returnUrl . (strpos($returnUrl, '?') === false ? '?' : '&') . 'type=error&msg=' . urlencode('La sesión del formulario ha caducado.'));
    exit;
}

$registro = gastosUnificadosGetEfectivoKmRecord(
    $conn,
    $tipo,
    $id,
    (int)($_SESSION['user_id'] ?? 0),
    isAdmin()
);

if (!$registro) {
    header('Location: ' . $returnUrl . (strpos($returnUrl, '?') === false ? '?' : '&') . 'type=error&msg=' . urlencode('Registro no encontrado o sin permisos.'));
    exit;
}

$bloqueo = gastosUnificadosPeriodoEfectivoBloqueado(
    $conn,
    (int)$registro['user_id'],
    (string)$registro['fecha']
);

if (!empty($bloqueo['bloqueado'])) {
    header('Location: ' . $returnUrl . (strpos($returnUrl, '?') === false ? '?' : '&') . 'type=error&msg=' . urlencode($bloqueo['motivo']));
    exit;
}

if (
    !defined('MAKE_WEBHOOK_ELIMINAR_EFECTIVO_KMS')
    || trim((string)MAKE_WEBHOOK_ELIMINAR_EFECTIVO_KMS) === ''
) {
    header('Location: ' . $returnUrl . (strpos($returnUrl, '?') === false ? '?' : '&') . 'type=error&msg=' . urlencode('Esta operación no está disponible en este momento.'));
    exit;
}

$payload = [
    'accion' => 'eliminar',
    'tipo' => $tipo,
    'registro_id' => $id,
    'user_id' => (int)$registro['user_id'],
    'username' => (string)$registro['username'],
    'comercial' => (string)$registro['comercial'],
    'fecha' => (string)$registro['fecha'],
    'motivo' => (string)$registro['motivo'],
    'importe' => (float)$registro['importe'],
    'drive_file_id' => (string)($registro['drive_file_id'] ?? ''),
    'drive_file_url' => (string)($registro['drive_file_url'] ?? ''),
    'ruta_url' => (string)($registro['ruta_url'] ?? ''),
    'origen' => (string)($registro['origen'] ?? ''),
    'destino' => (string)($registro['destino'] ?? ''),
    'kilometros' => isset($registro['kilometros']) ? (float)$registro['kilometros'] : null,
    'origen_app' => 'gestion_gastos'
];

$resultado = callMakeWebhook(
    MAKE_WEBHOOK_ELIMINAR_EFECTIVO_KMS,
    $payload,
    120
);

if (empty($resultado['ok'])) {
    auditoriaRegistrarSeguro($conn, [
        'tipo_evento' => 'gasto',
        'entidad' => $tipo === 'efectivo' ? 'efectivo' : 'kilometraje',
        'entidad_id' => $id,
        'accion' => 'error_eliminar_efectivo_km',
        'descripcion' => 'No se pudo completar la eliminación del registro.',
        'estado_nuevo' => 'error',
        'datos' => [
            'tipo' => $tipo,
            'resultado' => $resultado
        ]
    ]);

    header('Location: ' . $returnUrl . (strpos($returnUrl, '?') === false ? '?' : '&') . 'type=error&msg=' . urlencode('No se ha podido eliminar el registro. Inténtalo de nuevo.'));
    exit;
}

$table = $tipo === 'efectivo' ? 'efectivo_gastos' : 'kilometrajes';
$stmt = $conn->prepare("DELETE FROM `$table` WHERE id = ? AND user_id = ? LIMIT 1");

if (!$stmt) {
    header('Location: ' . $returnUrl . (strpos($returnUrl, '?') === false ? '?' : '&') . 'type=error&msg=' . urlencode('No se ha podido eliminar el registro. Inténtalo de nuevo.'));
    exit;
}

$registroUserId = (int)$registro['user_id'];
$stmt->bind_param('ii', $id, $registroUserId);

if (!$stmt->execute()) {
    header('Location: ' . $returnUrl . (strpos($returnUrl, '?') === false ? '?' : '&') . 'type=error&msg=' . urlencode('No se ha podido eliminar el registro. Inténtalo de nuevo.'));
    exit;
}

auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'gasto',
    'entidad' => $tipo === 'efectivo' ? 'efectivo' : 'kilometraje',
    'entidad_id' => $id,
    'accion' => 'registro_efectivo_km_eliminado',
    'descripcion' => 'Registro de Efectivo y Kilometraje eliminado desde Gestión de gastos.',
    'estado_anterior' => (string)($registro['estado'] ?? ''),
    'estado_nuevo' => 'eliminado',
    'datos' => [
        'tipo' => $tipo,
        'fecha' => $registro['fecha'] ?? '',
        'importe' => $registro['importe'] ?? null
    ]
]);

header('Location: ' . $returnUrl . (strpos($returnUrl, '?') === false ? '?' : '&') . 'type=success&msg=' . urlencode('Registro eliminado correctamente.'));
exit;

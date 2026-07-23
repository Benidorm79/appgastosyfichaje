<?php
declare(strict_types=1);

/**
 * Configuración pública de la aplicación.
 *
 * Los secretos NO se guardan en este archivo. Se cargan, por este orden, desde:
 *  1. La ruta indicada en APP_LOCAL_CONFIG_PATH.
 *  2. ../private/config.local.php (recomendado, fuera del directorio público).
 *  3. ./private/config.local.php (alternativa protegida por .htaccess).
 */

define('APP_NAME', 'APP Gestión de Gastos y Fichaje');
define('APP_VERSION', '5.6.4');
define('APP_TIMEZONE', 'Europe/Madrid');

$localConfigCandidates = [];
$configuredPath = getenv('APP_LOCAL_CONFIG_PATH');

if (is_string($configuredPath) && trim($configuredPath) !== '') {
    $localConfigCandidates[] = trim($configuredPath);
}

$localConfigCandidates[] = dirname(__DIR__) . '/private/config.local.php';
$localConfigCandidates[] = __DIR__ . '/private/config.local.php';

$localConfig = [];

foreach (array_unique($localConfigCandidates) as $candidate) {
    if (!is_file($candidate) || !is_readable($candidate)) {
        continue;
    }

    $loadedConfig = require $candidate;

    if (is_array($loadedConfig)) {
        $localConfig = $loadedConfig;
        break;
    }
}

$configValue = static function (string $key, $default = '') use ($localConfig) {
    return array_key_exists($key, $localConfig) ? $localConfig[$key] : $default;
};

// Conexiones y credenciales privadas.
define('DB_HOST', (string)$configValue('DB_HOST'));
define('DB_USER', (string)$configValue('DB_USER'));
define('DB_PASS', (string)$configValue('DB_PASS'));
define('DB_NAME', (string)$configValue('DB_NAME'));
define('APP_BASE_URL', rtrim((string)$configValue('APP_BASE_URL'), '/'));
define('MAIL_FROM', (string)$configValue('MAIL_FROM'));

define('MAKE_WEBHOOK_NUEVO_GASTO', (string)$configValue('MAKE_WEBHOOK_NUEVO_GASTO'));
define('MAKE_WEBHOOK_DESCARGAR_NOTA', (string)$configValue('MAKE_WEBHOOK_DESCARGAR_NOTA'));
define('MAKE_WEBHOOK_EDITAR_GASTO', (string)$configValue('MAKE_WEBHOOK_EDITAR_GASTO'));
define('MAKE_WEBHOOK_ELIMINAR_GASTO', (string)$configValue('MAKE_WEBHOOK_ELIMINAR_GASTO'));
define('MAKE_WEBHOOK_RESINCRONIZAR_GASTO', (string)$configValue('MAKE_WEBHOOK_RESINCRONIZAR_GASTO'));
define('MAKE_WEBHOOK_GASTO_MANUAL', (string)$configValue('MAKE_WEBHOOK_GASTO_MANUAL'));
define('MAKE_WEBHOOK_TICKETS_PDF', (string)$configValue('MAKE_WEBHOOK_TICKETS_PDF'));
define('MAKE_WEBHOOK_VACACIONES_CALENDARIO', (string)$configValue('MAKE_WEBHOOK_VACACIONES_CALENDARIO'));
define('MAKE_WEBHOOK_AGENDA_CALENDARIO', (string)$configValue('MAKE_WEBHOOK_AGENDA_CALENDARIO'));
define('MAKE_WEBHOOK_FICHAJE', (string)$configValue('MAKE_WEBHOOK_FICHAJE'));
define('MAKE_WEBHOOK_DESCARGAR_FICHAJE', (string)$configValue('MAKE_WEBHOOK_DESCARGAR_FICHAJE'));
define('MAKE_WEBHOOK_FIRMA_CIERRE', (string)$configValue('MAKE_WEBHOOK_FIRMA_CIERRE'));
define('MAKE_WEBHOOK_CIERRE_ESTADO', (string)$configValue('MAKE_WEBHOOK_CIERRE_ESTADO'));
define('MAKE_WEBHOOK_CIERRE_VALIDADO', (string)$configValue('MAKE_WEBHOOK_CIERRE_VALIDADO'));
define('MAKE_WEBHOOK_EFECTIVO_KMS', (string)$configValue('MAKE_WEBHOOK_EFECTIVO_KMS'));
define('MAKE_WEBHOOK_DESCARGAR_EFECTIVO_KMS', (string)$configValue('MAKE_WEBHOOK_DESCARGAR_EFECTIVO_KMS'));
define('MAKE_WEBHOOK_ELIMINAR_EFECTIVO_KMS', (string)$configValue('MAKE_WEBHOOK_ELIMINAR_EFECTIVO_KMS'));

define('DRIVE_TEMPLATE_PORTADA_JUSTIFICANTES_ID', (string)$configValue('DRIVE_TEMPLATE_PORTADA_JUSTIFICANTES_ID'));
define('VACACIONES_CALENDAR_TARGET', (string)$configValue('VACACIONES_CALENDAR_TARGET'));
define('FICHAJE_SIGNATURE_SECRET', (string)$configValue('FICHAJE_SIGNATURE_SECRET'));
define('CIERRE_SIGNATURE_SECRET', (string)$configValue('CIERRE_SIGNATURE_SECRET'));
define('GOOGLE_MAPS_ROUTES_API_KEY', (string)$configValue('GOOGLE_MAPS_ROUTES_API_KEY'));
define('API_PERIODOS_TOKEN', (string)$configValue('API_PERIODOS_TOKEN'));

// Servicio privado del asistente técnico. La clave de OpenAI vive solo en ese servicio.
define('AI_SERVICE_URL', rtrim((string)$configValue('AI_SERVICE_URL'), '/'));
define('AI_SERVICE_HMAC_SECRET', (string)$configValue('AI_SERVICE_HMAC_SECRET'));
define('AI_SERVICE_TIMEOUT_SECONDS', max(10, (int)$configValue('AI_SERVICE_TIMEOUT_SECONDS', 300)));
define('AI_SERVICE_OCR_TIMEOUT_SECONDS', min(350, max(60, (int)$configValue('AI_SERVICE_OCR_TIMEOUT_SECONDS', 330))));

// Valores funcionales no secretos.
define('KILOMETRAJE_EUR_KM', (float)$configValue('KILOMETRAJE_EUR_KM', 0.35));
define('EFECTIVO_MAX_FILE_BYTES', max(1, (int)$configValue('EFECTIVO_MAX_FILE_BYTES', 8388608)));
define('CHAT_MAX_FILE_BYTES', max(1, (int)$configValue('CHAT_MAX_FILE_BYTES', 20971520)));
define('AI_DOCUMENT_MAX_FILE_BYTES', max(1, (int)$configValue('AI_DOCUMENT_MAX_FILE_BYTES', 27262976)));
define('AI_DOCUMENT_UPLOAD_STALE_MINUTES', max(2, (int)$configValue('AI_DOCUMENT_UPLOAD_STALE_MINUTES', 10)));
define('AI_DOCUMENT_PROCESSING_TIMEOUT_MINUTES', max(5, (int)$configValue('AI_DOCUMENT_PROCESSING_TIMEOUT_MINUTES', 30)));
define('AI_USD_TO_EUR_RATE', max(0.0001, (float)$configValue('AI_USD_TO_EUR_RATE', 0.88)));
define('SESSION_TIMEOUT_SECONDS', max(300, (int)$configValue('SESSION_TIMEOUT_SECONDS', 1800)));
define('GASTOS_PER_PAGE', max(5, (int)$configValue('GASTOS_PER_PAGE', 20)));

unset($configValue, $localConfig, $loadedConfig, $localConfigCandidates, $configuredPath, $candidate);

require_once __DIR__ . '/includes/public_messages.php';

<?php
declare(strict_types=1);

function appPublicError(string $fallback = 'No se ha podido completar la operación. Inténtalo de nuevo.'): string
{
    return $fallback;
}

function appPublicMessage($message, string $fallback = 'No se ha podido completar la operación. Inténtalo de nuevo.'): string
{
    $message = trim((string)$message);
    if ($message === '') return '';

    $technicalTerms = '/\b(make|webhook|mysql|sql|servidor|hosting|api|drive|json|http|curl|payload|base de datos|config\.php)\b/iu';
    if (preg_match($technicalTerms, $message)) return $fallback;

    return mb_strlen($message, 'UTF-8') > 500 ? $fallback : $message;
}

function appPlainTechnicalText($message): string
{
    $message = str_replace('_', ' ', trim((string)$message));
    if ($message === '') return '';

    $patterns = [
        '/\bmake\b/iu' => 'actualización',
        '/\bwebhooks?\b/iu' => 'actualización',
        '/\b(base de datos|mysql|sql|servidor|hosting)\b/iu' => 'sistema',
        '/\b(drive)\b/iu' => 'archivo',
        '/\b(api|http|curl|json|payload|config\.php)\b/iu' => 'detalle interno'
    ];
    $message = preg_replace(array_keys($patterns), array_values($patterns), $message) ?? '';
    $message = preg_replace('/\s+/u', ' ', $message) ?? '';
    return trim($message);
}

function appLogError(string $context, $detail = null): void
{
    $message = '[APP] ' . $context;

    if ($detail instanceof Throwable) {
        $message .= ': ' . $detail->getMessage();
    } elseif (is_scalar($detail) && (string)$detail !== '') {
        $message .= ': ' . (string)$detail;
    }

    error_log($message);
}

function appJson(array $payload, int $status = 200): void
{
    if (array_key_exists('message', $payload)) {
        $payload['message'] = appPublicMessage(
            $payload['message'],
            !empty($payload['ok']) ? 'Operación completada correctamente.' : 'No se ha podido completar la operación. Inténtalo de nuevo.'
        );
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

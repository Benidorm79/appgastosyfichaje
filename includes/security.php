<?php
function securitySendHeaders() {
  if (headers_sent()) return;
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: SAMEORIGIN');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header('Permissions-Policy: camera=(self), geolocation=(), microphone=()');
  header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https://disvent.com https://*.googleusercontent.com https://drive.google.com https://*.googleapis.com https://*.gstatic.com https://tile.openstreetmap.org; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; connect-src 'self' https://tile.openstreetmap.org; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

function csrfToken() {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrfValidate($token) {
  return !empty($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrfFromRequest($payload = null) {
  $token = '';
  if (is_array($payload)) $token = (string)($payload['csrf_token'] ?? '');
  if ($token === '') $token = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if (!csrfValidate($token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'message'=>'La sesión de seguridad ha caducado. Recarga la página e inténtalo de nuevo.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

function requirePostMethod() {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
  }
}

function validateDateYmd($value) {
  $d = DateTime::createFromFormat('Y-m-d', (string)$value);
  return $d && $d->format('Y-m-d') === $value;
}

function normalizeMoney($value) {
  $value = str_replace([' ', ','], ['', '.'], trim((string)$value));
  if (!is_numeric($value)) return null;
  $n = round((float)$value, 2);
  return $n > 0 ? number_format($n, 2, '.', '') : null;
}

function safeJsonBody() {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}
?>

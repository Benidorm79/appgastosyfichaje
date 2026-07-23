<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/auditoria.php";

$email = $_POST['email'] ?? '';

$sql = "SELECT id, username, comercial, role, email FROM users WHERE email=? AND activo=1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
  $user = $result->fetch_assoc();

  $token = bin2hex(random_bytes(32));
  $tokenHash = hash('sha256', $token);
  $expires = date("Y-m-d H:i:s", time() + 3600);

  $sql = "UPDATE users SET reset_token=?, reset_expires=? WHERE id=?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ssi", $tokenHash, $expires, $user['id']);
  $stmt->execute();

  $reset_link = APP_BASE_URL !== ''
    ? APP_BASE_URL . "/reset_password.php?token=" . urlencode($token)
    : '';

  $subject = "Recuperación de contraseña";
  $message = "Haz clic en este enlace para restablecer tu contraseña:\n\n" . $reset_link . "\n\nEste enlace caduca en 1 hora.";
  $mailFrom = filter_var(MAIL_FROM, FILTER_VALIDATE_EMAIL) ? MAIL_FROM : '';
  $headers = $mailFrom !== '' ? "From: " . $mailFrom . "\r\n" : '';

  $mailSent = $reset_link !== '' && @mail($email, $subject, $message, $headers);
  if (!$mailSent) {
    appLogError('No se pudo enviar el correo de recuperación', 'Revisa APP_BASE_URL, MAIL_FROM y la función mail del alojamiento.');
  }

  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'usuario',
    'entidad_id' => (int)$user['id'],
    'accion' => 'recuperacion_password_solicitada',
    'descripcion' => 'Solicitud de recuperación de contraseña generada para un usuario activo.',
    'usuario_id' => (int)$user['id'],
    'username' => $user['username'] ?? null,
    'comercial' => $user['comercial'] ?? null,
    'rol' => $user['role'] ?? null,
    'estado_nuevo' => 'token_generado',
    'datos' => [
      'email' => $email,
      'expires' => $expires,
      'mail_enviado' => $mailSent
    ]
  ]);
} else {
  auditoriaRegistrarSeguro($conn, [
    'tipo_evento' => 'seguridad',
    'entidad' => 'usuario',
    'accion' => 'recuperacion_password_email_no_encontrado',
    'descripcion' => 'Solicitud de recuperación de contraseña para un email no encontrado o inactivo.',
    'estado_nuevo' => 'no_encontrado',
    'datos' => [
      'email' => $email
    ]
  ]);
}

header("Location: recuperar_password.php?ok=1");
exit;
?>

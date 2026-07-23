<?php
require_once "config.php";
require_once "includes/functions.php";

$isHttps = (
  (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
  (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
);

if (session_status() === PHP_SESSION_NONE) {
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
  ]);

  session_start();
}

header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

$redirect = sanitizeRedirect($_GET['redirect'] ?? 'home.php');
$error = $_GET['error'] ?? '';
$timeout = isset($_GET['timeout']);

if (empty($_SESSION['login_csrf_token'])) {
  $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['login_csrf_token'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Acceso</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="css/estilos.css?v=<?php echo APP_VERSION; ?>">

  <style>
    .login-error-message {
      margin: 0 0 28px 0;
      padding: 17px 18px;
      border-radius: 18px;
      background: rgba(255, 255, 255, 0.96);
      border: 2px solid #ef4444;
      color: #dc2626;
      font-size: 18px;
      font-weight: 900;
      line-height: 1.42;
      text-align: center;
      box-shadow: 0 14px 34px rgba(0, 0, 0, 0.18);
    }

    .login-error-message strong {
      display: block;
      margin-bottom: 4px;
      color: #b91c1c;
      font-size: 19px;
    }

    .login-timeout-message {
      margin: 0 0 28px 0;
      padding: 17px 18px;
      border-radius: 18px;
      background: rgba(255, 255, 255, 0.96);
      border: 2px solid #f59e0b;
      color: #b45309;
      font-size: 17px;
      font-weight: 900;
      line-height: 1.42;
      text-align: center;
      box-shadow: 0 14px 34px rgba(0, 0, 0, 0.18);
    }
  </style>
</head>

<body>
  <div class="container">

    <img id="logo" src="https://disvent.com/media/logo-disvent-doofinder.png" alt="Disvent Ingenieros">

    <h1>Acceso</h1>

    <?php if ($error === 'inactive'): ?>
      <div class="login-error-message">
        <strong>Usuario desactivado</strong>
        Este usuario está desactivado. Contacta con administración.
      </div>
    <?php elseif ($error === 'blocked'): ?>
      <div class="login-error-message">
        <strong>Acceso bloqueado temporalmente</strong>
        Se han producido demasiados intentos fallidos. Espera unos minutos antes de volver a intentarlo.
      </div>
    <?php elseif ($error === 'csrf'): ?>
      <div class="login-error-message">
        <strong>Solicitud no válida</strong>
        Por seguridad, vuelve a intentarlo desde el formulario de acceso.
      </div>
    <?php elseif ($error === '1'): ?>
      <div class="login-error-message">
        <strong>Acceso no válido</strong>
        Usuario o contraseña incorrectos.
      </div>
    <?php endif; ?>

    <?php if ($timeout): ?>
      <div class="login-timeout-message">
        La sesión ha caducado. Vuelve a iniciar sesión.
      </div>
    <?php endif; ?>

    <form action="auth.php" method="POST">
      <input type="hidden" name="redirect" value="<?php echo h($redirect); ?>">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">

      <div>
        <label for="username">Usuario</label>
        <input type="text" id="username" name="username" required autocomplete="username">
      </div>

      <div>
        <label for="password">Contraseña</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>

      <button type="submit">Entrar</button>
    </form>

    <div class="login-links">
      <a href="cambiar_password.php">Cambiar contraseña</a>
      <a href="recuperar_password.php">¿Has olvidado tu contraseña?</a>
    </div>

  </div>
</body>
</html>
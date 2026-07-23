<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cambiar contraseña</title>
  <link rel="stylesheet" href="css/estilos.css?v=3">
</head>
<body>
  <div class="container">
    <img id="logo" src="https://disvent.com/media/logo-disvent-doofinder.png" alt="Disvent Ingenieros">

    <h1>Cambiar contraseña</h1>

    <form action="procesar_cambiar_password.php" method="POST">
      <div>
        <label for="username">Usuario</label>
        <input type="text" id="username" name="username" required>
      </div>

      <div>
        <label for="password_actual">Contraseña actual</label>
        <input type="password" id="password_actual" name="password_actual" required>
      </div>

      <div>
        <label for="password_nueva">Nueva contraseña</label>
        <input type="password" id="password_nueva" name="password_nueva" minlength="10" required>
      </div>

      <div>
        <label for="password_confirmar">Repetir nueva contraseña</label>
        <input type="password" id="password_confirmar" name="password_confirmar" minlength="10" required>
      </div>

      <button type="submit">Cambiar contraseña</button>
    </form>

    <?php if (isset($_GET['ok'])): ?>
      <p class="success">Contraseña cambiada correctamente.</p>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
      <p class="error">No se ha podido cambiar la contraseña.</p>
    <?php endif; ?>

    <?php if (isset($_GET['wrong'])): ?>
      <p class="error">Usuario o contraseña actual incorrectos.</p>
    <?php endif; ?>

    <?php if (isset($_GET['nomatch'])): ?>
      <p class="error">Las contraseñas nuevas no coinciden.</p>
    <?php endif; ?>

    <?php if (isset($_GET['weak'])): ?>
      <p class="error">La nueva contraseña debe tener al menos 10 caracteres.</p>
    <?php endif; ?>
  </div>

  <div class="volver-container">
    <a href="login.php" class="volver-btn">Volver</a>
  </div>
</body>
</html>

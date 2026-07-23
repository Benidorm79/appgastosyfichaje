<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recuperar contraseña</title>
  <link rel="stylesheet" href="css/estilos.css?v=3">
</head>
<body>
  <div class="container">
    <img id="logo" src="https://disvent.com/media/logo-disvent-doofinder.png" alt="Disvent Ingenieros">

    <h1>Recuperar contraseña</h1>

    <form action="procesar_recuperar_password.php" method="POST">
      <div>
        <label for="email">Email del usuario</label>
        <input type="text" id="email" name="email" required>
      </div>

      <button type="submit">Enviar enlace</button>
    </form>

    <?php if (isset($_GET['ok'])): ?>
      <p class="success">Si el email existe, recibirás un enlace para cambiar la contraseña.</p>
    <?php endif; ?>
  </div>

  <div class="volver-container">
    <a href="login.php" class="volver-btn">Volver</a>
  </div>
</body>
</html>
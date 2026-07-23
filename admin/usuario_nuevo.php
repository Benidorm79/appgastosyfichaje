<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";

$mensaje = appPublicMessage($_GET['msg'] ?? '');
$esMaster = isset($_SESSION['role']) && $_SESSION['role'] === 'master';
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nuevo usuario - Panel Admin</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="../css/admin.css?v=<?php echo APP_VERSION; ?>">
</head>

<body class="admin-body">
  <div class="admin-wrapper admin-wrapper-narrow">

    <header class="admin-header">
      <div>
        <h1>Nuevo usuario</h1>
        <p>Crea un nuevo acceso y asígnale un comercial y un rol.</p>
      </div>

      <div class="top-actions">
        <a class="btn" href="usuarios.php">Volver</a>
        <a class="btn" href="../home.php">Inicio</a>
      </div>
    </header>

    <section class="panel">

      <?php if ($mensaje !== ''): ?>
        <div class="message error"><?php echo h($mensaje); ?></div>
      <?php endif; ?>

      <form method="post" action="usuario_guardar.php" autocomplete="off">
        <input type="hidden" name="accion" value="crear">

        <div class="form-grid">

          <div class="form-group">
            <label for="username">Usuario</label>
            <input type="text" id="username" name="username" required>
            <div class="help">Nombre con el que iniciará sesión.</div>
          </div>

          <div class="form-group">
            <label for="password">Contraseña inicial</label>
            <input type="password" id="password" name="password" minlength="10" required>
            <div class="help">Mínimo: 10 caracteres.</div>
          </div>

          <div class="form-group full">
            <label for="comercial">Nombre comercial / empleado</label>
            <input type="text" id="comercial" name="comercial" required>
          </div>

          <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email">
          </div>

          <div class="form-group">
            <label for="role">Rol</label>
            <select id="role" name="role" required>
              <option value="user">Usuario</option>
              <option value="admin">Administrador</option>
              <?php if ($esMaster): ?>
                <option value="master">Máster</option>
              <?php endif; ?>
            </select>
            <?php if ($esMaster): ?>
              <div class="help">Solo puede existir un usuario Máster en el sistema.</div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label for="activo">Estado</label>
            <select id="activo" name="activo" required>
              <option value="1">Activo</option>
              <option value="0">Inactivo</option>
            </select>
          </div>

        </div>

        <div class="form-footer">
          <a class="btn" href="usuarios.php">Cancelar</a>
          <button class="btn primary" type="submit">Crear usuario</button>
        </div>
      </form>

    </section>

  </div>

<?php include __DIR__ . '/../includes/admin_chat_launcher.php'; ?>
</body>
</html>

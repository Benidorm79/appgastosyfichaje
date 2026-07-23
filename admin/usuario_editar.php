<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";

$id = intval($_GET['id'] ?? 0);
$rolSesion = $_SESSION['role'] ?? 'user';
$esMaster = $rolSesion === 'master';

if ($id <= 0) {
  header("Location: usuarios.php?type=error&msg=" . urlencode("Usuario no válido"));
  exit;
}

$stmt = $conn->prepare("SELECT id, username, comercial, role, email, activo, ultimo_login, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$usuario = $stmt->get_result()->fetch_assoc();

if (!$usuario) {
  header("Location: usuarios.php?type=error&msg=" . urlencode("Usuario no encontrado"));
  exit;
}

if (!$esMaster && in_array($usuario['role'] ?? '', ['admin', 'master'], true) && (int)$usuario['id'] !== (int)($_SESSION['user_id'] ?? 0)) {
  header("Location: usuarios.php?type=error&msg=" . urlencode("No puedes modificar usuarios administradores ni Máster."));
  exit;
}

$mensaje = appPublicMessage($_GET['msg'] ?? '');
$esUsuarioActual = (int)$usuario['id'] === (int)($_SESSION['user_id'] ?? 0);
$esUsuarioMaster = ($usuario['role'] ?? '') === 'master';
$bloquearEstado = $esUsuarioActual || $esUsuarioMaster;
$bloquearRolMaster = $esUsuarioMaster;
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Editar usuario - Panel Admin</title>

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
        <h1>Editar usuario</h1>
        <p>Modifica datos de acceso, rol, estado o contraseña.</p>
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

      <div class="info-box">
        <strong>ID usuario:</strong> <?php echo (int)$usuario['id']; ?><br>
        <strong>Creado:</strong> <?php echo $usuario['created_at'] ? h(formatFechaWeb($usuario['created_at'], true)) : '-'; ?><br>
        <strong>Último acceso:</strong> <?php echo $usuario['ultimo_login'] ? h(formatFechaWeb($usuario['ultimo_login'], true)) : 'Nunca'; ?>
        <?php if ($esUsuarioMaster): ?>
          <br><strong>Protección:</strong> Usuario Máster. Su rol y estado no pueden modificarse desde esta pantalla.
        <?php endif; ?>
      </div>

      <form method="post" action="usuario_guardar.php" autocomplete="off">
        <input type="hidden" name="accion" value="editar">
        <input type="hidden" name="id" value="<?php echo (int)$usuario['id']; ?>">

        <div class="form-grid">

          <div class="form-group">
            <label for="username">Usuario</label>
            <input type="text" id="username" name="username" value="<?php echo h($usuario['username']); ?>" required>
          </div>

          <div class="form-group">
            <label for="password">Nueva contraseña</label>
            <input type="password" id="password" name="password" minlength="10">
            <div class="help">Déjalo vacío si no quieres cambiarla.</div>
          </div>

          <div class="form-group full">
            <label for="comercial">Nombre comercial / empleado</label>
            <input type="text" id="comercial" name="comercial" value="<?php echo h($usuario['comercial']); ?>" required>
          </div>

          <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?php echo h($usuario['email']); ?>">
          </div>

          <div class="form-group">
            <label for="role">Rol</label>
            <select id="role" name="role" required <?php echo $bloquearRolMaster ? 'disabled' : ''; ?>>
              <option value="user" <?php echo $usuario['role'] === 'user' ? 'selected' : ''; ?>>Usuario</option>
              <option value="admin" <?php echo $usuario['role'] === 'admin' ? 'selected' : ''; ?>>Administrador</option>
              <?php if ($esMaster): ?>
                <option value="master" <?php echo $usuario['role'] === 'master' ? 'selected' : ''; ?>>Máster</option>
              <?php endif; ?>
            </select>

            <?php if ($bloquearRolMaster): ?>
              <input type="hidden" name="role" value="master">
              <div class="help">El rol Máster queda protegido para evitar degradaciones accidentales.</div>
            <?php elseif ($esUsuarioActual): ?>
              <div class="help">Cuidado: estás editando tu propio usuario.</div>
            <?php elseif ($esMaster): ?>
              <div class="help">Solo puede existir un usuario Máster en el sistema.</div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label for="activo">Estado</label>
            <select id="activo" name="activo" required <?php echo $bloquearEstado ? 'disabled' : ''; ?>>
              <option value="1" <?php echo (int)$usuario['activo'] === 1 ? 'selected' : ''; ?>>Activo</option>
              <option value="0" <?php echo (int)$usuario['activo'] === 0 ? 'selected' : ''; ?>>Inactivo</option>
            </select>

            <?php if ($bloquearEstado): ?>
              <input type="hidden" name="activo" value="1">
              <?php if ($esUsuarioMaster): ?>
                <div class="help">El usuario Máster no puede desactivarse.</div>
              <?php else: ?>
                <div class="help">No puedes desactivar tu propio usuario desde aquí.</div>
              <?php endif; ?>
            <?php endif; ?>
          </div>

        </div>

        <div class="form-footer">
          <a class="btn" href="usuarios.php">Cancelar</a>
          <button class="btn primary" type="submit">Guardar cambios</button>
        </div>
      </form>

    </section>

  </div>

<?php include __DIR__ . '/../includes/admin_chat_launcher.php'; ?>
</body>
</html>

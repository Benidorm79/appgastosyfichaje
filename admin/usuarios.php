<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";

$mensaje = appPublicMessage($_GET['msg'] ?? '');
$tipoMensaje = $_GET['type'] ?? 'success';

$buscar = trim($_GET['buscar'] ?? '');
$rolSesion = $_SESSION['role'] ?? 'user';
$esMaster = $rolSesion === 'master';
$usuarioSesionId = (int)($_SESSION['user_id'] ?? 0);

$where = "";
$params = [];
$types = "";

if ($buscar !== '') {
  $where = "WHERE username LIKE ? OR comercial LIKE ? OR email LIKE ?";
  $like = "%" . $buscar . "%";
  $params = [$like, $like, $like];
  $types = "sss";
}

$sql = "SELECT id, username, comercial, role, email, activo, ultimo_login, created_at
        FROM users
        $where
        ORDER BY FIELD(role, 'master', 'admin', 'user'), activo DESC, comercial ASC, username ASC";

$stmt = $conn->prepare($sql);

if ($where !== '') {
  $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$usuarios = [];

while ($row = $result->fetch_assoc()) {
  $usuarios[] = $row;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Usuarios - Panel Admin</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="../css/admin.css?v=<?php echo APP_VERSION; ?>">
</head>

<body class="admin-body">
  <div class="admin-wrapper">

    <header class="admin-header">
      <div>
        <h1>Gestión de usuarios</h1>
        <p>Administra usuarios, roles, estado de acceso y datos asociados a cada comercial.</p>
      </div>

      <div class="top-actions">
        <a class="btn" href="index.php">Panel Admin</a>
        <a class="btn" href="../home.php">Inicio</a>
        <a class="btn" href="../logout.php">Cerrar sesión</a>
      </div>
    </header>

    <section class="panel">

      <?php if ($mensaje !== ''): ?>
        <div class="message <?php echo h($tipoMensaje === 'error' ? 'error' : 'success'); ?>">
          <?php echo h($mensaje); ?>
        </div>
      <?php endif; ?>

      <div class="toolbar">
        <form class="search-form" method="get" action="usuarios.php">
          <input type="text" name="buscar" value="<?php echo h($buscar); ?>" placeholder="Buscar por usuario, comercial o email">
          <button class="btn" type="submit">Buscar</button>

          <?php if ($buscar !== ''): ?>
            <a class="btn" href="usuarios.php">Limpiar</a>
          <?php endif; ?>
        </form>

        <a class="btn warning" href="usuario_nuevo.php">Añadir usuario</a>
      </div>

      <div class="table-wrap">
        <table class="admin-table-wide">
          <thead>
            <tr>
              <th>ID</th>
              <th>Usuario</th>
              <th>Comercial</th>
              <th>Email</th>
              <th>Rol</th>
              <th>Estado</th>
              <th>Último acceso</th>
              <th>Creado</th>
              <th>Acciones</th>
            </tr>
          </thead>

          <tbody>
            <?php if (count($usuarios) === 0): ?>
              <tr>
                <td colspan="9" class="muted">No se han encontrado usuarios.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($usuarios as $usuario): ?>
              <?php
                $usuarioId = (int)$usuario['id'];
                $rolUsuario = $usuario['role'] ?? 'user';
                $esUsuarioActual = $usuarioId === $usuarioSesionId;
                $esUsuarioMaster = $rolUsuario === 'master';
                $esUsuarioAdmin = $rolUsuario === 'admin';

                $puedeEditar = true;
                $puedeCambiarEstado = !$esUsuarioActual && !$esUsuarioMaster;

                if (!$esMaster && in_array($rolUsuario, ['admin', 'master'], true) && !$esUsuarioActual) {
                  $puedeEditar = false;
                  $puedeCambiarEstado = false;
                }

                if (!$esMaster && $esUsuarioAdmin) {
                  $puedeCambiarEstado = false;
                }
              ?>

              <tr>
                <td><?php echo $usuarioId; ?></td>
                <td><strong><?php echo h($usuario['username']); ?></strong></td>
                <td><?php echo h($usuario['comercial']); ?></td>
                <td><?php echo h($usuario['email'] ?: '-'); ?></td>
                <td>
                  <span class="pill <?php echo h($rolUsuario); ?>">
                    <?php echo h(formatRoleWeb($rolUsuario)); ?>
                  </span>
                </td>
                <td>
                  <?php if ((int)$usuario['activo'] === 1): ?>
                    <span class="pill active">Activo</span>
                  <?php else: ?>
                    <span class="pill inactive">Inactivo</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php echo $usuario['ultimo_login'] ? h(formatFechaWeb($usuario['ultimo_login'], true)) : '<span class="muted">Nunca</span>'; ?>
                </td>
                <td>
                  <?php echo $usuario['created_at'] ? h(formatFechaWeb($usuario['created_at'], true)) : '<span class="muted">-</span>'; ?>
                </td>
                <td>
                  <div class="actions">
                    <?php if ($puedeEditar): ?>
                      <a class="btn small primary" href="usuario_editar.php?id=<?php echo $usuarioId; ?>">Editar</a>
                    <?php else: ?>
                      <span class="muted">Protegido</span>
                    <?php endif; ?>

                    <?php if ($esUsuarioActual): ?>
                      <span class="muted">Usuario actual</span>
                    <?php elseif ($esUsuarioMaster): ?>
                      <span class="muted">Máster protegido</span>
                    <?php elseif ($puedeCambiarEstado): ?>
                      <?php if ((int)$usuario['activo'] === 1): ?>
                        <a class="btn small danger" href="usuario_estado.php?id=<?php echo $usuarioId; ?>&estado=0" onclick="return confirm('¿Seguro que quieres desactivar este usuario?');">Desactivar</a>
                      <?php else: ?>
                        <a class="btn small" href="usuario_estado.php?id=<?php echo $usuarioId; ?>&estado=1" onclick="return confirm('¿Seguro que quieres activar este usuario?');">Activar</a>
                      <?php endif; ?>
                    <?php elseif ($esUsuarioAdmin): ?>
                      <span class="muted">Solo Máster</span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </section>

  </div>

<?php include __DIR__ . '/../includes/admin_chat_launcher.php'; ?>
</body>
</html>

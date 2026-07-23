<?php
require_once __DIR__ . '/../admin_guard.php';
requireMasterAccess();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fichaje.php';

$anio = max(2020, min(2100, (int)($_GET['anio'] ?? date('Y'))));
$ok = trim((string)($_GET['ok'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

if (empty($_SESSION['vacaciones_saldo_csrf'])) {
  $_SESSION['vacaciones_saldo_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['vacaciones_saldo_csrf'];

$rows = [];
$sql = "SELECT u.id, u.username, u.comercial, u.role, u.activo,
               COALESCE(s.dias_asignados, 0) dias_asignados
        FROM users u
        LEFT JOIN fichaje_vacaciones_saldos s
          ON s.user_id = u.id AND s.anio = ?
        ORDER BY u.activo DESC, u.comercial ASC, u.username ASC";
$stmt = $conn->prepare($sql);

if ($stmt) {
  $stmt->bind_param('i', $anio);
  $stmt->execute();
  $result = $stmt->get_result();

  while ($result && ($row = $result->fetch_assoc())) {
    $row['saldo'] = fichajeVacacionesSaldo($conn, (int)$row['id'], $anio);
    $rows[] = $row;
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Vacaciones anuales - Admin</title>

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
        <h1>Vacaciones anuales</h1>
        <p>Define los días disponibles para cada usuario durante el año seleccionado.</p>
      </div>

      <div class="top-actions">
        <a class="btn" href="index.php">Panel Admin</a>
        <a class="btn" href="../home.php">Inicio</a>
        <a class="btn" href="../logout.php">Cerrar sesión</a>
      </div>
    </header>

    <?php if ($ok !== ''): ?>
      <div class="message success"><?php echo h($ok); ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="message error"><?php echo h($error); ?></div>
    <?php endif; ?>

    <section class="panel">
      <form method="get" class="filters">
        <div>
          <label for="anio">Año</label>
          <input id="anio" type="number" name="anio" min="2020" max="2100" value="<?php echo $anio; ?>">
        </div>
        <button class="btn primary" type="submit">Consultar</button>
      </form>
    </section>

    <section class="panel">
      <h2 class="section-title">Asignación general</h2>
      <p>Aplica la misma cantidad anual a todos los usuarios activos. Después podrás ajustar cualquier usuario individualmente.</p>

      <form method="post" action="procesar_vacaciones_saldo.php" class="filters">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="accion" value="aplicar_todos">
        <input type="hidden" name="anio" value="<?php echo $anio; ?>">

        <div>
          <label for="dias_asignados_todos">Días para todos</label>
          <input id="dias_asignados_todos" type="number" name="dias_asignados" min="0" max="366" step="0.5" required>
        </div>

        <button class="btn primary" type="submit" onclick="return confirm('¿Aplicar esta cantidad a todos los usuarios activos para <?php echo $anio; ?>?');">
          Aplicar a todos
        </button>
      </form>
    </section>

    <section class="panel">
      <h2 class="section-title">Asignación individual</h2>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Usuario</th>
              <th>Rol</th>
              <th>Días asignados</th>
              <th>Disfrutados</th>
              <th>Créditos</th>
              <th>Pendientes</th>
              <th>Guardar</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td>
                  <strong><?php echo h($row['comercial'] ?: $row['username']); ?></strong><br>
                  <small>
                    <?php echo h($row['username']); ?>
                    <?php if ((int)$row['activo'] !== 1): ?> · Inactivo<?php endif; ?>
                  </small>
                </td>
                <td><?php echo h($row['role']); ?></td>
                <td>
                  <form method="post" action="procesar_vacaciones_saldo.php" style="display:flex;gap:8px;align-items:center">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="accion" value="individual">
                    <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
                    <input type="hidden" name="anio" value="<?php echo $anio; ?>">
                    <input type="number" name="dias_asignados" min="0" max="366" step="0.5"
                           value="<?php echo h(number_format((float)$row['dias_asignados'], 2, '.', '')); ?>"
                           style="width:95px">
                </td>
                <td><?php echo number_format((float)$row['saldo']['disfrutados'], 2, ',', '.'); ?></td>
                <td><?php echo number_format((float)$row['saldo']['creditos'], 2, ',', '.'); ?></td>
                <td><strong><?php echo number_format((float)$row['saldo']['disponibles'], 2, ',', '.'); ?></strong></td>
                <td>
                    <button class="btn small primary" type="submit">Guardar</button>
                  </form>
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

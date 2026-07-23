<?php
require_once __DIR__ . "/../admin_guard.php";
requireMasterAccess();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";

function backupFetchAll($conn, $sql) {
  $result = $conn->query($sql);

  if (!$result) {
    return [];
  }

  $rows = [];

  while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
  }

  return $rows;
}

$mesActual = (int)date('n');
$anioActual = (int)date('Y');

$mes = intval($_GET['mes'] ?? $mesActual);
$anio = intval($_GET['anio'] ?? $anioActual);
$comercial = trim($_GET['comercial'] ?? '');

if ($mes < 1 || $mes > 12) {
  $mes = $mesActual;
}

if ($anio < 2020 || $anio > 2100) {
  $anio = $anioActual;
}

$usuariosBackup = backupFetchAll(
  $conn,
  "SELECT id, username, comercial, activo
   FROM users
   WHERE comercial IS NOT NULL
     AND comercial <> ''
   ORDER BY comercial ASC, username ASC"
);
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Backup mensual - Panel Admin</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="../css/admin.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/admin_cierres.css?v=<?php echo APP_VERSION; ?>">

  <style>
    .backup-action-buttons {
      display: flex !important;
      align-items: center !important;
      gap: 12px !important;
      flex-wrap: wrap !important;
    }

    .backup-export-button {
      background: linear-gradient(135deg, #b7f7cf, #8ef0b6) !important;
      color: #004c46 !important;
      border: 1px solid rgba(183, 247, 207, 0.85) !important;
      box-shadow: 0 8px 18px rgba(142, 240, 182, 0.22) !important;
      font-weight: 800 !important;
    }

    .backup-export-button:hover {
      background: linear-gradient(135deg, #a8f3c5, #7ce9a9) !important;
      color: #003f3a !important;
      transform: translateY(-1px);
    }

    .backup-export-button:visited,
    .backup-export-button:active,
    .backup-export-button:focus {
      color: #004c46 !important;
    }
  </style>
</head>

<body class="admin-body">
  <div class="admin-wrapper">
    <header class="admin-header">
      <div>
        <h1>Backup mensual</h1>
        <p>Exportación mensual de gastos, cierres, envíos, incidencias y auditoría.</p>
      </div>

      <div class="top-actions">
        <a class="btn" href="centro_control.php">Centro de control</a>
        <a class="btn" href="index.php">Panel Admin</a>
        <a class="btn" href="../home.php">Inicio</a>
        <a class="btn" href="../logout.php">Cerrar sesión</a>
      </div>
    </header>

    <section class="panel">
      <form method="get" action="export_backup_mensual.php" class="filters">
        <div>
          <label for="mes">Mes</label>
          <select id="mes" name="mes">
            <?php for ($i = 1; $i <= 12; $i++): ?>
              <option value="<?php echo $i; ?>" <?php echo $mes === $i ? 'selected' : ''; ?>>
                <?php echo str_pad((string)$i, 2, '0', STR_PAD_LEFT); ?>
              </option>
            <?php endfor; ?>
          </select>
        </div>

        <div>
          <label for="anio">Año</label>
          <input type="number" id="anio" name="anio" value="<?php echo (int)$anio; ?>" min="2020" max="2100">
        </div>

        <div>
          <label for="comercial">Comercial</label>
          <select id="comercial" name="comercial">
            <option value="" <?php echo $comercial === '' ? 'selected' : ''; ?>>Todos</option>

            <?php foreach ($usuariosBackup as $usuario): ?>
              <?php
                $comercialUsuario = trim((string)($usuario['comercial'] ?? ''));
                $usernameUsuario = trim((string)($usuario['username'] ?? ''));
                $activoUsuario = (int)($usuario['activo'] ?? 0);
              ?>

              <?php if ($comercialUsuario !== ''): ?>
                <option value="<?php echo h($comercialUsuario); ?>" <?php echo $comercial === $comercialUsuario ? 'selected' : ''; ?>>
                  <?php echo h($comercialUsuario); ?>
                  <?php if ($usernameUsuario !== ''): ?>
                    <?php echo h(' (' . $usernameUsuario . ')'); ?>
                  <?php endif; ?>
                  <?php if ($activoUsuario !== 1): ?>
                    <?php echo h(' - Inactivo'); ?>
                  <?php endif; ?>
                </option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="backup-action-buttons">
          <button class="btn backup-export-button" type="submit">Descargar CSV mensual</button>
        </div>
      </form>

      <div class="note">
        El archivo CSV incluye secciones separadas para poder conservar una copia mensual de control sin depender de permisos especiales del hosting.
      </div>
    </section>
  </div>

<?php include __DIR__ . '/../includes/admin_chat_launcher.php'; ?>
</body>
</html>

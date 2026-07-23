<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
$esMaster = (string)($_SESSION['role'] ?? '') === 'master';
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel Admin - Gastos</title>

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
      <div class="admin-title-block">
        <h1>Panel Admin de Gastos</h1>
        <p>Área privada para administración, revisión y control profesional de notas de gastos.</p>
      </div>

      <div class="admin-user-box">
        <strong><?php echo h($_SESSION['comercial'] ?? $_SESSION['user'] ?? 'Administrador'); ?></strong>
        Rol: <?php echo h($_SESSION['role'] ?? ''); ?>

        <div class="admin-actions-top">
          <a class="admin-link" href="../home.php">Inicio</a>
          <a class="admin-link" href="../logout.php">Cerrar sesión</a>
        </div>
      </div>
    </header>

    <section class="admin-grid">

      <article class="admin-card">
        <div>
          <div class="admin-card-icon">U</div>
          <h2>Usuarios</h2>
          <p>Editar usuarios existentes, crear nuevos usuarios, asignar rol y activar o desactivar accesos.</p>
        </div>
        <div class="admin-card-footer">
          <a class="admin-card-button" href="usuarios.php">Gestionar usuarios</a>
        </div>
      </article>

      <article class="admin-card">
        <div>
          <div class="admin-card-icon">G</div>
          <h2>Dashboard</h2>
          <p>Resumen mensual, comparativas, totales por comercial, categorías y evolución de gastos.</p>
        </div>
        <div class="admin-card-footer">
          <a class="admin-card-button" href="dashboard.php">Ver dashboard</a>
        </div>
      </article>

      <article class="admin-card">
        <div>
          <div class="admin-card-icon">!</div>
          <h2>Incidencias</h2>
          <p>Gastos sin justificante, errores de sincronización, gastos pendientes y gastos con error.</p>
        </div>
        <div class="admin-card-footer">
          <a class="admin-card-button" href="incidencias.php">Ver incidencias</a>
        </div>
      </article>

      <article class="admin-card">
        <div>
          <div class="admin-card-icon">C</div>
          <h2>Cierre mensual</h2>
          <p>Revisar liquidaciones bancarias de comerciales, comparar importes y validar cierres mensuales.</p>
        </div>
        <div class="admin-card-footer">
          <a class="admin-card-button" href="cierres_mensuales.php">Ver cierres</a>
        </div>
      </article>

      <article class="admin-card">
        <div>
          <div class="admin-card-icon">M</div>
          <h2>Envíos e integraciones</h2>
          <p>Preparado para envíos automáticos, exportaciones y futura conexión con A3, ERP o VERI*FACTU.</p>
        </div>
        <div class="admin-card-footer">
          <a class="admin-card-button" href="envios.php">Ver envíos</a>
        </div>
      </article>

      <article class="admin-card">
        <div>
          <div class="admin-card-icon">€</div>
          <h2>Efectivo y Kilometraje</h2>
          <p>Consulta general de gastos en efectivo, tickets y desplazamientos por kilometraje.</p>
        </div>
        <div class="admin-card-footer">
          <a class="admin-card-button" href="efectivo_kms.php">Ver registros</a>
        </div>
      </article>

      <article class="admin-card">
        <div>
          <div class="admin-card-icon">AI</div>
          <h2>ELÍAS</h2>
          <p>Gestiona marcas, documentos aprobados y disponibilidad de la base de conocimiento.</p>
        </div>
        <div class="admin-card-footer">
          <a class="admin-card-button" href="asistente.php">Gestionar ELÍAS</a>
        </div>
      </article>

      <?php if ($esMaster): ?>
      <article class="admin-card">
        <div>
          <div class="admin-card-icon">✓</div>
          <h2>Centro de control</h2>
          <p>Estado del sistema, auditoría, eventos sensibles, integridad operativa y backup mensual en una única pantalla.</p>
        </div>
        <div class="admin-card-footer">
          <a class="admin-card-button" href="centro_control.php">Abrir centro de control</a>
        </div>
      </article>

      <article class="admin-card">
        <div>
          <div class="admin-card-icon">V</div>
          <h2>Vacaciones anuales</h2>
          <p>Asigna los días de vacaciones de cada usuario y consulta saldos, días disfrutados y compensaciones.</p>
        </div>
        <div class="admin-card-footer">
          <a class="admin-card-button" href="vacaciones_saldos.php">Gestionar vacaciones</a>
        </div>
      </article>
      <?php endif; ?>

    </section>



  </div>

<?php include __DIR__ . '/../includes/admin_chat_launcher.php'; ?>
</body>
</html>

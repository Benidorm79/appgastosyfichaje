<?php
require_once __DIR__ . "/session.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/functions.php";

$esAdmin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'master'], true);
$rolUsuario = $_SESSION['role'] ?? 'user';
$nombreUsuario = $_SESSION['comercial'] ?? $_SESSION['user'] ?? 'Usuario';
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>App Gastos y Fichaje</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="css/home.css?v=<?php echo APP_VERSION; ?>">
</head>

<body>
  <main class="app-shell">

    <div class="user-topbar">
      <div class="user-topbar-identity">
        <strong><?php echo h($nombreUsuario); ?></strong>
        <span class="role-badge role-<?php echo h($rolUsuario); ?>"><?php echo h(formatRoleWeb($rolUsuario)); ?></span>
      </div>

      <a class="logout-link" href="logout.php">Cerrar sesión</a>
    </div>

    <section class="home-card">

      <div class="logo-chat-row">
        <div class="logo-box">
          <img src="https://disvent.com/media/logo-disvent-doofinder.png" alt="Disvent Ingenieros">
        </div>
        <a href="mensajeria.php" class="home-chat-launcher" data-chat-open data-chat-api="api/mensajeria_estado.php" aria-label="Abrir mensajería interna" title="Mensajería interna">
          <span aria-hidden="true">💬</span>
          <span class="chat-unread-badge home-chat-badge" data-chat-badge hidden>0</span>
        </a>
      </div>

      <div class="banner-carousel">
        <img src="images/banner-app-1.png" alt="Gestión de gastos y fichaje horario">
        <img src="images/banner-app-2.png" alt="Aplicación corporativa de gastos y fichaje">
      </div>

      <div class="home-title">
        <h1>Gestión interna</h1>
        <p>Registro de gastos, descargas, cierres mensuales y fichaje horario desde una única aplicación.</p>
      </div>

      <nav class="home-menu" id="homeMenu">

        <div class="dropdown-menu">
          <button class="menu-toggle" type="button">
            <span>Gastos Empresa</span>
            <span class="menu-arrow">⌄</span>
          </button>

          <div class="menu-options">
            <a class="menu-option" href="nota_gastos.php">
              <span>Gasto con ticket</span>
              <span>›</span>
            </a>

            <a class="menu-option" href="gasto_manual.php">
              <span>Gasto manual</span>
              <span>›</span>
            </a>

            <a class="menu-option" href="gestionar_gastos.php">
              <span>Gestión de gastos</span>
              <span>›</span>
            </a>

            <a class="menu-option" href="cierre_mensual.php">
              <span>Cierre mensual</span>
              <span>›</span>
            </a>

            <a class="menu-option" href="efectivo_kms.php">
              <span>Efectivo y Kms</span>
              <span>›</span>
            </a>
          </div>
        </div>

        <div class="dropdown-menu">
          <button class="menu-toggle" type="button">
            <span>Área Fichaje</span>
            <span class="menu-arrow">⌄</span>
          </button>

          <div class="menu-options">
            <a class="menu-option" href="fichaje.php">
              <span>Fichaje</span>
              <span>›</span>
            </a>

            <a class="menu-option" href="gestion_fichajes.php">
              <span>Gestión de fichaje</span>
              <span>›</span>
            </a>

            <a class="menu-option" href="fichaje_ausencias.php">
              <span>Vacaciones y días libres</span>
              <span>›</span>
            </a>
          </div>
        </div>

        <a class="menu-link" href="descargar_nota.php">
          <span>Descargas</span>
          <span>›</span>
        </a>

        <?php if ($esAdmin): ?>
          <a class="menu-link admin" href="admin/index.php">
            <span>Panel Admin</span>
            <span>›</span>
          </a>
        <?php endif; ?>

      </nav>

      <div class="app-version">
        <?php echo h(APP_NAME); ?> · v<?php echo h(APP_VERSION); ?>
      </div>

    </section>
  </main>

  <script src="js/mensajeria_badge.js?v=<?php echo APP_VERSION; ?>" defer></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const homeMenu = document.getElementById('homeMenu');
      const dropdowns = document.querySelectorAll('.dropdown-menu');

      dropdowns.forEach(function (dropdown) {
        const button = dropdown.querySelector('.menu-toggle');

        button.addEventListener('click', function () {
          const isOpen = dropdown.classList.contains('open');

          dropdowns.forEach(function (item) {
            item.classList.remove('open');
          });

          if (isOpen) {
            dropdown.classList.remove('open');
            homeMenu.classList.remove('menu-open');
          } else {
            dropdown.classList.add('open');
            homeMenu.classList.add('menu-open');
          }
        });
      });

      document.addEventListener('click', function (event) {
        if (!homeMenu.contains(event.target)) {
          dropdowns.forEach(function (item) {
            item.classList.remove('open');
          });

          homeMenu.classList.remove('menu-open');
        }
      });
    });
  </script>
</body>
</html>

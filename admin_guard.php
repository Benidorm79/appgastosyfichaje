<?php
require_once __DIR__ . "/session.php";

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'master'], true)) {
  http_response_code(403);
  ?>
  <!DOCTYPE html>
  <html lang="es">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso no autorizado</title>
    <style>
      * {
        box-sizing: border-box;
      }

      body {
        margin: 0;
        min-height: 100vh;
        font-family: Arial, sans-serif;
        background: radial-gradient(circle at top, #0f4c81 0%, #003366 55%, #001d3d 100%);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
      }

      .access-denied-card {
        width: 100%;
        max-width: 420px;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.22);
        border-radius: 22px;
        padding: 28px;
        text-align: center;
        box-shadow: 0 22px 60px rgba(0, 0, 0, 0.35);
        backdrop-filter: blur(12px);
      }

      .access-denied-icon {
        width: 72px;
        height: 72px;
        margin: 0 auto 18px auto;
        border-radius: 50%;
        background: rgba(239, 68, 68, 0.18);
        border: 1px solid rgba(248, 113, 113, 0.45);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 36px;
      }

      h1 {
        margin: 0 0 12px 0;
        font-size: 24px;
      }

      p {
        margin: 0 0 22px 0;
        line-height: 1.5;
        color: rgba(255, 255, 255, 0.86);
      }

      a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 44px;
        padding: 0 20px;
        border-radius: 999px;
        background: #ffffff;
        color: #003366;
        text-decoration: none;
        font-weight: 800;
      }
    </style>
  </head>
  <body>
    <div class="access-denied-card">
      <div class="access-denied-icon">!</div>
      <h1>Acceso no autorizado</h1>
      <p>Esta zona está reservada exclusivamente para usuarios con Rol Administrador o Máster.</p>
      <a href="../home.php">Volver al inicio</a>
    </div>
  </body>
  </html>
  <?php
  exit;
}

function requireMasterAccess() {
  if (isset($_SESSION['role']) && $_SESSION['role'] === 'master') {
    return;
  }

  http_response_code(403);
  ?>
  <!DOCTYPE html>
  <html lang="es">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso restringido</title>
    <style>
      * { box-sizing: border-box; }
      body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; font-family: Arial, sans-serif; color: #fff; background: radial-gradient(circle at top, #0f4c81 0%, #003366 55%, #001d3d 100%); }
      .master-access-card { width: min(440px, 100%); padding: 30px; border: 1px solid rgba(255,255,255,.22); border-radius: 22px; background: rgba(255,255,255,.12); box-shadow: 0 22px 60px rgba(0,0,0,.35); text-align: center; }
      .master-access-card span { width: 68px; height: 68px; margin: 0 auto 18px; display: grid; place-items: center; border-radius: 50%; background: rgba(251,191,36,.2); font-size: 32px; }
      .master-access-card h1 { margin: 0 0 10px; font-size: 24px; }
      .master-access-card p { margin: 0 0 22px; color: rgba(255,255,255,.82); line-height: 1.5; }
      .master-access-card a { display: inline-flex; min-height: 44px; align-items: center; padding: 0 20px; border-radius: 999px; color: #003366; background: #fff; text-decoration: none; font-weight: 900; }
    </style>
  </head>
  <body>
    <main class="master-access-card">
      <span aria-hidden="true">🔒</span>
      <h1>Acceso reservado a Máster</h1>
      <p>Tu perfil no tiene permiso para abrir este apartado.</p>
      <a href="index.php">Volver al Panel Admin</a>
    </main>
  </body>
  </html>
  <?php
  exit;
}
?>

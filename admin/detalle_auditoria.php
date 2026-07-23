<?php
require_once __DIR__ . "/../admin_guard.php";
requireMasterAccess();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auditoria.php";

header('Content-Type: text/html; charset=UTF-8');

function detalleAuditoriaFetchOne($conn, $id) {
  $sql = "SELECT * FROM auditoria_eventos WHERE id = ? LIMIT 1";
  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return null;
  }

  $stmt->bind_param("i", $id);
  $stmt->execute();
  $result = $stmt->get_result();

  if (!$result) {
    return null;
  }

  return $result->fetch_assoc();
}

function detalleAuditoriaTexto($value) {
  $value = appPlainTechnicalText($value);
  return $value !== '' ? $value : '—';
}

function detalleAuditoriaTipoTexto($tipo) {
  $map = [
    'cierre' => 'Cierre',
    'envio' => 'Envío',
    'usuario' => 'Usuario',
    'gasto' => 'Gasto',
    'seguridad' => 'Seguridad',
    'sistema' => 'Sistema',
    'auditoria' => 'Auditoría'
  ];

  return $map[$tipo] ?? ucfirst((string)$tipo);
}

function detalleAuditoriaRevisionTexto($estado) {
  if ($estado === 'normal') return 'Normal';
  if ($estado === 'revisado') return 'Revisado';
  if ($estado === 'corregido') return 'Corregido';
  if ($estado === 'anulado') return 'Anulado';
  return 'Normal';
}

$id = intval($_GET['id'] ?? 0);
$error = '';
$evento = null;

if (!auditoriaTableExists($conn)) {
  $error = 'Este apartado todavía no está disponible.';
} elseif ($id <= 0) {
  $error = 'ID de auditoría no válido.';
} else {
  $evento = detalleAuditoriaFetchOne($conn, $id);

  if (!$evento) {
    $error = 'No se encontró el evento de auditoría.';
  }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Detalle de auditoría - Panel Admin</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="../css/admin.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/admin_cierres.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/admin_auditoria.css?v=<?php echo APP_VERSION; ?>">
</head>

<body class="admin-body">
  <div class="admin-wrapper">

    <header class="admin-header">
      <div>
        <h1>Detalle de auditoría</h1>
        <p>Consulta limpia del evento registrado sin modificar su contenido.</p>
      </div>

      <div class="top-actions">
        <?php if ($evento): ?>
          <a class="btn primary" href="editar_auditoria.php?id=<?php echo (int)$evento['id']; ?>">Revisar</a>
        <?php endif; ?>
        <a class="btn" href="auditoria.php">Volver a auditoría</a>
        <a class="btn" href="index.php">Panel Admin</a>
      </div>
    </header>

    <?php if ($error !== ''): ?>
      <section class="panel">
        <div class="message error"><?php echo h($error); ?></div>
      </section>
    <?php endif; ?>

    <?php if ($evento): ?>
      <section class="kpi-grid auditoria-kpi-grid">
        <article class="kpi-card">
          <span>ID evento</span>
          <strong><?php echo (int)$evento['id']; ?></strong>
          <small><?php echo h(formatFechaWeb($evento['created_at'] ?? '', true)); ?></small>
        </article>
        <article class="kpi-card">
          <span>Tipo</span>
          <strong><?php echo h(detalleAuditoriaTipoTexto($evento['tipo_evento'] ?? '')); ?></strong>
          <small><?php echo h(detalleAuditoriaTexto($evento['entidad'] ?? '')); ?></small>
        </article>
        <article class="kpi-card">
          <span>Usuario</span>
          <strong><?php echo h(detalleAuditoriaTexto($evento['comercial'] ?? $evento['username'] ?? 'Sistema')); ?></strong>
          <small><?php echo h(detalleAuditoriaTexto($evento['rol'] ?? '')); ?></small>
        </article>
        <article class="kpi-card">
          <span>Estado</span>
          <strong><?php echo h(detalleAuditoriaTexto($evento['estado_nuevo'] ?? '')); ?></strong>
          <small>Anterior: <?php echo h(detalleAuditoriaTexto($evento['estado_anterior'] ?? '')); ?></small>
        </article>
        <article class="kpi-card">
          <span>Revisión</span>
          <strong><?php echo h(detalleAuditoriaRevisionTexto($evento['estado_revision'] ?? 'normal')); ?></strong>
          <small><?php echo h(formatFechaWeb($evento['revisado_at'] ?? '', true)); ?></small>
        </article>
      </section>

      <section class="panel">
        <h2 class="section-title">Información principal</h2>
        <div class="table-wrap">
          <table class="admin-table-wide">
            <tbody>
              <tr><th>Acción</th><td><?php echo h(detalleAuditoriaTexto($evento['accion'] ?? '')); ?></td></tr>
              <tr><th>Descripción</th><td><?php echo nl2br(h(detalleAuditoriaTexto($evento['descripcion'] ?? ''))); ?></td></tr>
              <tr><th>Entidad</th><td><?php echo h(detalleAuditoriaTexto($evento['entidad'] ?? '')); ?> <?php echo !empty($evento['entidad_id']) ? '#' . (int)$evento['entidad_id'] : ''; ?></td></tr>
              <tr><th>Usuario</th><td><?php echo h(detalleAuditoriaTexto($evento['username'] ?? '')); ?></td></tr>
              <tr><th>Comercial</th><td><?php echo h(detalleAuditoriaTexto($evento['comercial'] ?? '')); ?></td></tr>
              <tr><th>IP</th><td><?php echo h(detalleAuditoriaTexto($evento['ip'] ?? '')); ?></td></tr>
              <tr><th>User Agent</th><td><?php echo h(detalleAuditoriaTexto($evento['user_agent'] ?? '')); ?></td></tr>
              <tr><th>Notas revisión</th><td><?php echo nl2br(h(detalleAuditoriaTexto($evento['notas_revision'] ?? ''))); ?></td></tr>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

  </div>

<?php include __DIR__ . '/../includes/admin_chat_launcher.php'; ?>
</body>
</html>

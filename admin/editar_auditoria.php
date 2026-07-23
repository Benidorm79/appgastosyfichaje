<?php
require_once __DIR__ . "/../admin_guard.php";
requireMasterAccess();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auditoria.php";

header('Content-Type: text/html; charset=UTF-8');

function editarAuditoriaFetchOne($conn, $id) {
  $sql = "SELECT *
          FROM auditoria_eventos
          WHERE id = ?
          LIMIT 1";

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

function editarAuditoriaTipoTexto($tipo) {
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

function editarAuditoriaRevisionTexto($estado) {
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
  $evento = editarAuditoriaFetchOne($conn, $id);

  if (!$evento) {
    $error = 'No se encontró el evento de auditoría.';
  }
}

$mensaje = appPublicMessage($_GET['msg'] ?? '');
$tipoMensaje = $_GET['type'] ?? 'success';
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Revisar auditoría - Panel Admin</title>

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
        <h1>Revisar evento de auditoría</h1>
        <p>Los datos originales no se eliminan ni se sobrescriben. Solo se añade estado de revisión y notas.</p>
      </div>

      <div class="top-actions">
        <a class="btn" href="auditoria.php">Volver a auditoría</a>
        <a class="btn" href="index.php">Panel Admin</a>
        <a class="btn" href="../home.php">Inicio</a>
      </div>
    </header>

    <?php if ($mensaje !== ''): ?>
      <div class="message <?php echo $tipoMensaje === 'error' ? 'error' : 'success'; ?>">
        <?php echo h($mensaje); ?>
      </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <section class="panel">
        <div class="message error">
          <?php echo h($error); ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($evento): ?>
      <section class="panel">
        <h2 class="section-title">Evento original</h2>

        <div class="table-wrap">
          <table class="admin-table-wide">
            <tbody>
              <tr>
                <th>ID</th>
                <td><?php echo (int)$evento['id']; ?></td>
              </tr>
              <tr>
                <th>Fecha</th>
                <td><?php echo h(formatFechaWeb($evento['created_at'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Tipo</th>
                <td><?php echo h(editarAuditoriaTipoTexto($evento['tipo_evento'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Entidad</th>
                <td>
                  <?php echo h($evento['entidad'] ?? ''); ?>
                  <?php if (!empty($evento['entidad_id'])): ?>
                    #<?php echo (int)$evento['entidad_id']; ?>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <th>Acción</th>
                <td><?php echo h(appPlainTechnicalText($evento['accion'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Descripción</th>
                <td>
                  <?php if (!empty($evento['descripcion'])): ?>
                    <?php echo nl2br(h(appPlainTechnicalText($evento['descripcion']))); ?>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <th>Usuario</th>
                <td>
                  <?php if (!empty($evento['comercial'])): ?>
                    <?php echo h($evento['comercial']); ?>
                  <?php elseif (!empty($evento['username'])): ?>
                    <?php echo h($evento['username']); ?>
                  <?php else: ?>
                    Sistema
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <th>Rol</th>
                <td><?php echo h($evento['rol'] ?? ''); ?></td>
              </tr>
              <tr>
                <th>Estado anterior</th>
                <td><?php echo h(appPlainTechnicalText($evento['estado_anterior'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Estado nuevo</th>
                <td><?php echo h(appPlainTechnicalText($evento['estado_nuevo'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Estado revisión actual</th>
                <td><?php echo h(editarAuditoriaRevisionTexto($evento['estado_revision'] ?? 'normal')); ?></td>
              </tr>
              <tr>
                <th>IP</th>
                <td><?php echo h($evento['ip'] ?? ''); ?></td>
              </tr>
              <tr>
                <th>User Agent</th>
                <td><?php echo h($evento['user_agent'] ?? ''); ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel">
        <h2 class="section-title">Revisión administrativa</h2>

        <form method="POST" action="procesar_auditoria.php" class="filters">
          <input type="hidden" name="id" value="<?php echo (int)$evento['id']; ?>">

          <div>
            <label for="estado_revision">Estado revisión</label>
            <select id="estado_revision" name="estado_revision" required>
              <option value="normal" <?php echo ($evento['estado_revision'] ?? '') === 'normal' ? 'selected' : ''; ?>>Normal</option>
              <option value="revisado" <?php echo ($evento['estado_revision'] ?? '') === 'revisado' ? 'selected' : ''; ?>>Revisado</option>
              <option value="corregido" <?php echo ($evento['estado_revision'] ?? '') === 'corregido' ? 'selected' : ''; ?>>Corregido</option>
              <option value="anulado" <?php echo ($evento['estado_revision'] ?? '') === 'anulado' ? 'selected' : ''; ?>>Anulado</option>
            </select>
          </div>

          <div>
            <label for="notas_revision">Notas revisión</label>
            <textarea id="notas_revision" name="notas_revision" rows="5" placeholder="Añade una nota interna de revisión..."><?php echo h($evento['notas_revision'] ?? ''); ?></textarea>
          </div>

          <div>
            <button class="btn primary" type="submit">Guardar revisión</button>
            <a class="btn" href="auditoria.php">Cancelar</a>
          </div>
        </form>

        <div class="note">
          No se permite borrar eventos de auditoría. Si un evento queda sin efecto, márcalo como Anulado y añade una explicación.
        </div>
      </section>
    <?php endif; ?>

  </div>

<?php include __DIR__ . '/../includes/admin_chat_launcher.php'; ?>
</body>
</html>

<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/integraciones.php";

function editarEnvioColumnExists($conn, $table, $column) {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);

  $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

  return $result && $result->num_rows > 0;
}

function editarEnvioEstadoTexto($estado) {
  if ($estado === 'pendiente') {
    return 'Pendiente';
  }

  if ($estado === 'enviado') {
    return 'Enviado';
  }

  if ($estado === 'error') {
    return 'Error';
  }

  if ($estado === 'omitido') {
    return 'Omitido';
  }

  return ucfirst((string)$estado);
}

function editarEnvioSanitizeReturn($return) {
  $return = trim((string)$return);

  $permitidos = [
    'envios.php',
    'admin/envios.php'
  ];

  if (in_array($return, $permitidos, true)) {
    return $return;
  }

  return 'envios.php';
}

$id = intval($_GET['id'] ?? 0);
$return = editarEnvioSanitizeReturn($_GET['return'] ?? 'envios.php');

$tablaExiste = integracionesTableExists($conn);
$tieneSistemaExterno = $tablaExiste ? editarEnvioColumnExists($conn, 'envios_integraciones', 'sistema_externo') : false;
$tieneIdExterno = $tablaExiste ? editarEnvioColumnExists($conn, 'envios_integraciones', 'id_externo') : false;

$envio = null;
$error = '';

if (!$tablaExiste) {
  $error = 'Este apartado todavía no está disponible.';
} elseif (!$tieneSistemaExterno || !$tieneIdExterno) {
  $error = 'Este apartado todavía no está disponible.';
} elseif ($id <= 0) {
  $error = 'ID de envío no válido.';
} else {
  $sql = "SELECT *
          FROM envios_integraciones
          WHERE id = ?
          LIMIT 1";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    $error = 'No se pudo preparar la consulta del envío.';
  } else {
    $stmt->bind_param("i", $id);
    $stmt->execute();

    $envio = $stmt->get_result()->fetch_assoc();

    if (!$envio) {
      $error = 'No se encontró el registro de envío.';
    }
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
  <title>Cambiar estado de envío - Panel Admin</title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#003366">

  <link rel="stylesheet" href="../css/admin.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/processing_overlay.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/admin_cierres.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/admin_envios.css?v=<?php echo APP_VERSION; ?>">
</head>

<body class="admin-body">
  <div class="admin-wrapper">

    <header class="admin-header">
      <div>
        <h1>Cambiar estado de envío</h1>
        <p>Actualización manual del estado de integración mientras no exista conexión automática con sistemas externos.</p>
      </div>

      <div class="top-actions">
        <a class="btn" href="envios.php">Volver a envíos</a>
        <a class="btn" href="index.php">Panel Admin</a>
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

    <?php if ($envio): ?>
      <section class="panel">
        <h2 class="section-title">Datos del registro</h2>

        <div class="table-wrap">
          <table class="admin-table-wide">
            <tbody>
              <tr>
                <th>ID</th>
                <td><?php echo (int)$envio['id']; ?></td>
              </tr>
              <tr>
                <th>Destino</th>
                <td><?php echo h($envio['tipo_destino'] ?? ''); ?></td>
              </tr>
              <tr>
                <th>Entidad</th>
                <td>
                  <?php echo h($envio['entidad'] ?? ''); ?>
                  <?php if (!empty($envio['entidad_id'])): ?>
                    #<?php echo (int)$envio['entidad_id']; ?>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <th>Referencia</th>
                <td><?php echo h($envio['referencia'] ?? ''); ?></td>
              </tr>
              <tr>
                <th>Descripción</th>
                <td><?php echo h($envio['descripcion'] ?? ''); ?></td>
              </tr>
              <tr>
                <th>Estado actual</th>
                <td><?php echo h(editarEnvioEstadoTexto($envio['estado'] ?? 'pendiente')); ?></td>
              </tr>
              <tr>
                <th>Creado</th>
                <td><?php echo h(formatFechaWeb($envio['created_at'] ?? '')); ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel">
        <h2 class="section-title">Nuevo estado</h2>

        <form method="POST" action="procesar_envio_integracion.php" class="filters" data-processing-overlay data-processing-message="Estamos actualizando el envío de integración. Espera unos segundos, por favor.">
          <input type="hidden" name="id" value="<?php echo (int)$envio['id']; ?>">
          <input type="hidden" name="return" value="<?php echo h($return); ?>">

          <div>
            <label for="estado">Estado</label>
            <select id="estado" name="estado" required>
              <option value="pendiente" <?php echo ($envio['estado'] ?? '') === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
              <option value="enviado" <?php echo ($envio['estado'] ?? '') === 'enviado' ? 'selected' : ''; ?>>Enviado</option>
              <option value="error" <?php echo ($envio['estado'] ?? '') === 'error' ? 'selected' : ''; ?>>Error</option>
              <option value="omitido" <?php echo ($envio['estado'] ?? '') === 'omitido' ? 'selected' : ''; ?>>Omitido</option>
            </select>
          </div>

          <div>
            <label for="sistema_externo">Sistema externo</label>
            <input type="text"
                   id="sistema_externo"
                   name="sistema_externo"
                   value="<?php echo h($envio['sistema_externo'] ?? ''); ?>"
                   placeholder="Ejemplo: A3ERP, VERI*FACTU, Contabilidad">
          </div>

          <div>
            <label for="id_externo">ID externo / referencia</label>
            <input type="text"
                   id="id_externo"
                   name="id_externo"
                   value="<?php echo h($envio['id_externo'] ?? ''); ?>"
                   placeholder="Ejemplo: asiento, documento, referencia externa">
          </div>

          <div>
            <label for="comentario">Comentario / respuesta</label>
            <textarea id="comentario"
                      name="comentario"
                      placeholder="Ejemplo: enviado manualmente a contabilidad, asiento creado, rechazado por falta de datos..."><?php echo h(!empty($envio['ultimo_error']) ? appPlainTechnicalText($envio['ultimo_error']) : ''); ?></textarea>
          </div>

          <div>
            <button class="btn primary" type="submit">Guardar estado</button>
            <a class="btn" href="envios.php">Cancelar</a>
          </div>
        </form>

        <div class="note">
          Si marcas el registro como Enviado, se guardará la fecha de envío con la hora local del sistema. Si lo marcas como Error u Omitido, quedará reflejado como nota de seguimiento.
        </div>
      </section>
    <?php endif; ?>

  </div>
  <script src="../js/processing_overlay.js?v=<?php echo APP_VERSION; ?>"></script>

<?php include __DIR__ . '/../includes/admin_chat_launcher.php'; ?>
</body>
</html>

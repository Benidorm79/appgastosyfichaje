<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin_guard.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/asistente_tecnico.php';

function aiAdminIniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') return PHP_INT_MAX;
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    if ($number <= 0) return PHP_INT_MAX;
    if ($unit === 'g') return (int)round($number * 1024 * 1024 * 1024);
    if ($unit === 'm') return (int)round($number * 1024 * 1024);
    if ($unit === 'k') return (int)round($number * 1024);
    return max(1, (int)$number);
}

function aiAdminUsageBreakdown(mysqli $conn, string $dimension, array $settings): array
{
    if ($dimension === 'user') {
        $questionSql = "SELECT c.user_id AS item_id,
                               COALESCE(NULLIF(u.comercial, ''), u.username, CONCAT('Usuario ', c.user_id)) AS item_name,
                               COUNT(*) AS questions
                        FROM ai_messages m
                        INNER JOIN ai_conversations c ON c.id = m.conversation_id
                        LEFT JOIN users u ON u.id = c.user_id
                        WHERE m.role = 'user'
                          AND m.created_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
                        GROUP BY c.user_id, item_name";
        $costSql = "SELECT c.user_id AS item_id, m.model,
                           COALESCE(SUM(m.input_tokens), 0) AS input_tokens,
                           COALESCE(SUM(m.output_tokens), 0) AS output_tokens
                FROM ai_messages m
                INNER JOIN ai_conversations c ON c.id = m.conversation_id
                WHERE m.role = 'assistant' AND m.status IN ('completed', 'error')
                  AND m.model IS NOT NULL AND m.model <> ''
                  AND m.created_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
                GROUP BY c.user_id, m.model";
    } else {
        $questionSql = "SELECT c.brand_id AS item_id, b.name AS item_name, COUNT(*) AS questions
                        FROM ai_messages m
                        INNER JOIN ai_conversations c ON c.id = m.conversation_id
                        INNER JOIN ai_brands b ON b.id = c.brand_id
                        WHERE m.role = 'user'
                          AND m.created_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
                        GROUP BY c.brand_id, b.name";
        $costSql = "SELECT c.brand_id AS item_id, m.model,
                           COALESCE(SUM(m.input_tokens), 0) AS input_tokens,
                           COALESCE(SUM(m.output_tokens), 0) AS output_tokens
                FROM ai_messages m
                INNER JOIN ai_conversations c ON c.id = m.conversation_id
                WHERE m.role = 'assistant' AND m.status IN ('completed', 'error')
                  AND m.model IS NOT NULL AND m.model <> ''
                  AND m.created_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
                GROUP BY c.brand_id, m.model";
    }

    $items = [];
    $result = $conn->query($questionSql);
    if (!$result) return [];
    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['item_id'];
        $items[$id] = [
            'name' => (string)$row['item_name'],
            'questions' => (int)$row['questions'],
            'estimated_cost_eur' => 0.0,
        ];
    }

    $costResult = $conn->query($costSql);
    if ($costResult) while ($row = $costResult->fetch_assoc()) {
        $id = (int)$row['item_id'];
        if (!isset($items[$id])) continue;
        $inputTokens = (int)$row['input_tokens'];
        $outputTokens = (int)$row['output_tokens'];
        $items[$id]['estimated_cost_eur'] += aiEstimatedCostEur(
            (string)$row['model'],
            $inputTokens,
            $outputTokens,
            (float)$settings['usd_to_eur_rate'],
            $settings
        );
    }
    usort($items, static fn(array $left, array $right): int =>
        ($right['questions'] <=> $left['questions'])
        ?: ($right['estimated_cost_eur'] <=> $left['estimated_cost_eur'])
    );
    return array_slice($items, 0, 12);
}

function aiAdminDocumentStatusClass(string $status): string
{
    return match ($status) {
        'published' => 'active',
        'processing', 'uploading' => 'processing',
        'needs_ocr' => 'needs-ocr',
        'error' => 'error',
        default => 'inactive',
    };
}

securitySendHeaders();
$ready = aiTablesReady($conn);
$message = appPublicMessage($_GET['msg'] ?? '');
$messageType = ($_GET['type'] ?? '') === 'error' ? 'error' : 'success';
$isMaster = (string)($_SESSION['role'] ?? '') === 'master';
$brands = [];
$documentsByBrand = [];
$priceCountsByDocument = [];
$users = [];
$usageSettingsReady = false;
$priceCatalogReady = false;
$documentRecoveryReady = false;
$usageSettings = aiGetUsageSettings($conn);
$monthUsage = aiCurrentMonthUsage($conn, $usageSettings);
$usageByUser = [];
$usageByBrand = [];
$uploadLimitBytes = min(
    AI_DOCUMENT_MAX_FILE_BYTES,
    aiAdminIniBytes((string)ini_get('upload_max_filesize')),
    max(1, aiAdminIniBytes((string)ini_get('post_max_size')) - 1048576)
);

if ($ready) {
    $usageSettingsReady = aiTableExists($conn, 'ai_usage_settings');
    $priceCatalogReady = aiTableExists($conn, 'ai_price_items');
    $documentRecoveryReady = aiColumnExists($conn, 'ai_documents', 'vector_store_id');
    $staleUploadNote = 'La carga quedó interrumpida. Selecciona de nuevo el mismo PDF para continuar.';
    $stalePrepareNote = 'La preparación quedó incompleta. Selecciona de nuevo el mismo PDF para reiniciarla.';
    $stale = $conn->prepare(
        "UPDATE ai_documents
         SET status = 'error', ingestion_note = ?
         WHERE status = 'uploading'
           AND TIMESTAMPDIFF(MINUTE, updated_at, NOW()) >= ?"
    );
    if ($stale) {
        $staleMinutes = AI_DOCUMENT_UPLOAD_STALE_MINUTES;
        $stale->bind_param('si', $staleUploadNote, $staleMinutes);
        $stale->execute();
    }
    $stale = $conn->prepare(
        "UPDATE ai_documents
         SET status = 'error', ingestion_note = ?
         WHERE status = 'processing'
           AND (vector_file_id IS NULL OR vector_file_id = '')
           AND TIMESTAMPDIFF(MINUTE, updated_at, NOW()) >= ?"
    );
    if ($stale) {
        $staleMinutes = AI_DOCUMENT_UPLOAD_STALE_MINUTES;
        $stale->bind_param('si', $stalePrepareNote, $staleMinutes);
        $stale->execute();
    }
    if ($priceCatalogReady) {
        $priceCountResult = $conn->query('SELECT document_id, COUNT(*) AS total FROM ai_price_items GROUP BY document_id');
        if ($priceCountResult) {
            while ($row = $priceCountResult->fetch_assoc()) {
                $priceCountsByDocument[(int)$row['document_id']] = (int)$row['total'];
            }
        }
    }
    $usageSettings = aiGetUsageSettings($conn);
    $monthUsage = aiCurrentMonthUsage($conn, $usageSettings);
    $usageByUser = aiAdminUsageBreakdown($conn, 'user', $usageSettings);
    $usageByBrand = aiAdminUsageBreakdown($conn, 'brand', $usageSettings);
    $result = $conn->query('SELECT * FROM ai_brands ORDER BY name ASC');
    if ($result) while ($row = $result->fetch_assoc()) $brands[] = $row;
    $result = $conn->query('SELECT id, username, comercial FROM users WHERE activo = 1 ORDER BY comercial ASC, username ASC');
    if ($result) while ($row = $result->fetch_assoc()) $users[] = $row;
    $result = $conn->query('SELECT * FROM ai_documents ORDER BY brand_id ASC, created_at DESC');
    if ($result) while ($row = $result->fetch_assoc()) $documentsByBrand[(int)$row['brand_id']][] = $row;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>ELÍAS - Administración</title>
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="stylesheet" href="../css/admin.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="../css/asistente_admin.css?v=<?php echo APP_VERSION; ?>">
</head>
<body class="admin-body assistant-admin-page">
  <div class="admin-wrapper assistant-admin-wrapper">
    <header class="admin-header">
      <div><h1>Gestión documental de ELÍAS</h1><p>Administra marcas, documentos, cargas por lote y permisos de consulta.</p></div>
      <div class="top-actions"><a class="btn" href="index.php">Panel Admin</a><a class="btn" href="../mensajeria.php?assistant=1">Abrir ELÍAS</a></div>
    </header>

    <?php if ($message !== ''): ?><div class="message <?php echo h($messageType); ?>"><?php echo h($message); ?></div><?php endif; ?>

    <?php if (!$ready): ?>
      <section class="panel"><h2>Primer paso pendiente</h2><p>Completa la instalación inicial indicada en la guía de puesta en marcha.</p></section>
    <?php else: ?>
      <?php
        $monthlyBudget = (float)$usageSettings['monthly_budget_eur'];
        $estimatedCost = (float)$monthUsage['estimated_cost_eur'];
        $budgetPercent = $monthlyBudget > 0 ? min(100, ($estimatedCost / $monthlyBudget) * 100) : 0;
        $budgetWarning = $monthlyBudget > 0 && $budgetPercent >= (int)$usageSettings['warning_percent'];
      ?>
      <section class="panel assistant-usage-panel assistant-surface">
        <div class="assistant-section-heading">
          <div>
            <span class="assistant-eyebrow">Control de consumo</span>
            <h2>Uso mensual de ELÍAS</h2>
            <p>Estimación de modelos calculada con los tokens registrados. Las consultas normales usan Terra y los proyectos completos usan Sol.</p>
          </div>
          <span class="assistant-routing-state">Terra → consultas · Sol → proyectos</span>
        </div>

        <div class="assistant-usage-cards">
          <article><span>Consultas recibidas</span><strong><?php echo number_format((int)$monthUsage['questions'], 0, ',', '.'); ?></strong><small><?php echo number_format((int)$monthUsage['completed_answers'], 0, ',', '.'); ?> respondidas · <?php echo number_format((int)$monthUsage['failed_answers'], 0, ',', '.'); ?> con incidencia</small></article>
          <article><span>Entrada</span><strong><?php echo number_format((int)$monthUsage['input_tokens'], 0, ',', '.'); ?></strong><small>tokens</small></article>
          <article><span>Salida</span><strong><?php echo number_format((int)$monthUsage['output_tokens'], 0, ',', '.'); ?></strong><small>tokens</small></article>
          <article class="<?php echo $budgetWarning ? 'warning' : ''; ?>"><span>Coste estimado</span><strong><?php echo number_format($estimatedCost, 2, ',', '.'); ?> €</strong><small>sin impuestos</small></article>
        </div>

        <?php if ($monthlyBudget > 0): ?>
          <div class="assistant-budget <?php echo $budgetWarning ? 'warning' : ''; ?>">
            <div><strong><?php echo number_format($budgetPercent, 0, ',', '.'); ?> % del presupuesto</strong><span><?php echo number_format($estimatedCost, 2, ',', '.'); ?> € de <?php echo number_format($monthlyBudget, 2, ',', '.'); ?> €</span></div>
            <progress max="100" value="<?php echo h((string)$budgetPercent); ?>"></progress>
          </div>
        <?php else: ?>
          <p class="assistant-budget-empty">Todavía no se ha fijado un presupuesto mensual. El consumo se registra igualmente.</p>
        <?php endif; ?>

        <div class="assistant-usage-grid">
          <div>
            <h3>Por modelo</h3>
            <div class="table-wrap assistant-usage-table">
              <table>
                <thead><tr><th>Modelo</th><th>Consultas</th><th>Estimación</th></tr></thead>
                <tbody>
                  <?php foreach ($monthUsage['models'] as $modelUsage): ?>
                    <?php
                      $modelLabels = [
                          'catalog-local' => 'Catálogo local',
                          'error-unattributed' => 'Incidencia anterior sin modelo',
                          'local-no-model' => 'Sin llamada al modelo',
                      ];
                      $modelLabel = $modelLabels[(string)$modelUsage['model']] ?? (string)$modelUsage['model'];
                    ?>
                    <tr><td><?php echo h($modelLabel); ?></td><td><?php echo number_format((int)$modelUsage['questions'], 0, ',', '.'); ?></td><td><?php echo number_format((float)$modelUsage['estimated_cost_eur'], 2, ',', '.'); ?> €</td></tr>
                  <?php endforeach; ?>
                  <?php if (!$monthUsage['models']): ?><tr><td colspan="3" class="muted">Todavía no hay consultas facturables este mes.</td></tr><?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
          <div>
            <h3>Por usuario</h3>
            <div class="table-wrap assistant-usage-table">
              <table>
                <thead><tr><th>Usuario</th><th>Consultas</th><th>Estimación</th></tr></thead>
                <tbody>
                  <?php foreach ($usageByUser as $item): ?>
                    <tr><td><?php echo h($item['name']); ?></td><td><?php echo number_format((int)$item['questions'], 0, ',', '.'); ?></td><td><?php echo number_format((float)$item['estimated_cost_eur'], 2, ',', '.'); ?> €</td></tr>
                  <?php endforeach; ?>
                  <?php if (!$usageByUser): ?><tr><td colspan="3" class="muted">Sin consumo por usuario.</td></tr><?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
          <div>
            <h3>Por marca</h3>
            <div class="table-wrap assistant-usage-table">
              <table>
                <thead><tr><th>Marca</th><th>Consultas</th><th>Estimación</th></tr></thead>
                <tbody>
                  <?php foreach ($usageByBrand as $item): ?>
                    <tr><td><?php echo h($item['name']); ?></td><td><?php echo number_format((int)$item['questions'], 0, ',', '.'); ?></td><td><?php echo number_format((float)$item['estimated_cost_eur'], 2, ',', '.'); ?> €</td></tr>
                  <?php endforeach; ?>
                  <?php if (!$usageByBrand): ?><tr><td colspan="3" class="muted">Sin consumo por marca.</td></tr><?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <?php if (!$usageSettingsReady): ?>
          <div class="assistant-usage-notice">Ejecuta <strong>sql_actualizacion_elias_v5_4.sql</strong> para activar presupuestos y límites. El panel de consumo ya puede mostrar los datos existentes.</div>
        <?php elseif ($isMaster): ?>
          <details class="assistant-usage-settings">
            <summary>Presupuestos, avisos y límites</summary>
            <form method="post" action="asistente_accion.php" class="assistant-usage-settings-form">
              <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
              <input type="hidden" name="action" value="save_usage_settings">
              <div><label>Presupuesto mensual (€)</label><input type="number" name="monthly_budget_eur" min="0" max="100000" step="0.01" value="<?php echo h(number_format($monthlyBudget, 2, '.', '')); ?>"><small>0 significa sin presupuesto.</small></div>
              <div><label>Avisar al alcanzar (%)</label><input type="number" name="warning_percent" min="1" max="100" step="1" value="<?php echo (int)$usageSettings['warning_percent']; ?>"></div>
              <div><label>Límite diario por usuario</label><input type="number" name="daily_user_limit" min="0" max="10000" step="1" value="<?php echo (int)$usageSettings['daily_user_limit']; ?>"><small>0 significa sin límite.</small></div>
              <div><label>Conversión de 1 USD a EUR</label><input type="number" name="usd_to_eur_rate" min="0.0001" max="10" step="0.0001" value="<?php echo h(number_format((float)$usageSettings['usd_to_eur_rate'], 4, '.', '')); ?>"></div>
              <div><label>Terra: entrada (USD/M tokens)</label><input type="number" name="terra_input_usd_million" min="0" max="1000" step="0.0001" value="<?php echo h(number_format((float)$usageSettings['terra_input_usd_million'], 4, '.', '')); ?>"></div>
              <div><label>Terra: salida (USD/M tokens)</label><input type="number" name="terra_output_usd_million" min="0" max="1000" step="0.0001" value="<?php echo h(number_format((float)$usageSettings['terra_output_usd_million'], 4, '.', '')); ?>"></div>
              <div><label>Sol: entrada (USD/M tokens)</label><input type="number" name="sol_input_usd_million" min="0" max="1000" step="0.0001" value="<?php echo h(number_format((float)$usageSettings['sol_input_usd_million'], 4, '.', '')); ?>"></div>
              <div><label>Sol: salida (USD/M tokens)</label><input type="number" name="sol_output_usd_million" min="0" max="1000" step="0.0001" value="<?php echo h(number_format((float)$usageSettings['sol_output_usd_million'], 4, '.', '')); ?>"></div>
              <label class="assistant-checkbox"><input type="checkbox" name="block_on_budget" value="1" <?php echo (int)$usageSettings['block_on_budget'] === 1 ? 'checked' : ''; ?>> Bloquear nuevas consultas cuando se alcance el presupuesto</label>
              <button class="btn primary" type="submit">Guardar control de consumo</button>
            </form>
            <p class="assistant-usage-footnote">Las tarifas quedan configurables para poder actualizarlas si el proveedor cambia precios. La estimación no incluye almacenamiento documental, impuestos ni posibles servicios ajenos al modelo.</p>
          </details>
        <?php else: ?>
          <p class="assistant-usage-readonly">Solo el usuario Máster puede modificar presupuestos y límites.</p>
        <?php endif; ?>
      </section>

      <?php if (!$priceCatalogReady): ?>
        <section class="panel assistant-surface">
          <h2>Buscador de precios pendiente</h2>
          <p>Ejecuta <strong>sql_actualizacion_elias_v5_6.sql</strong> para activar las búsquedas exactas por producto y part number.</p>
        </section>
      <?php endif; ?>

      <?php if (!$documentRecoveryReady): ?>
        <section class="panel assistant-surface">
          <h2>Recuperación documental pendiente</h2>
          <p>Ejecuta <strong>sql_actualizacion_elias_v5_6_2.sql</strong> para completar el seguimiento y la recuperación de documentos.</p>
        </section>
      <?php endif; ?>

      <section class="panel assistant-admin-create assistant-surface">
        <div><h2>Nueva marca</h2><p>Cada marca mantiene su propia documentación y sus conversaciones separadas.</p></div>
        <form method="post" action="asistente_accion.php" class="assistant-admin-form-grid">
          <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
          <input type="hidden" name="action" value="create_brand">
          <div><label for="brand_name">Nombre</label><input id="brand_name" name="name" maxlength="160" required></div>
          <div><label for="brand_description">Descripción</label><input id="brand_description" name="description" maxlength="500"></div>
          <button class="btn primary" type="submit">Crear marca</button>
        </form>
      </section>

      <?php foreach ($brands as $brand): ?>
        <?php
          $brandId = (int)$brand['id'];
          $brandDocuments = $documentsByBrand[$brandId] ?? [];
          $publishedDocuments = count(array_filter($brandDocuments, static fn($document) => ($document['status'] ?? '') === 'published'));
          $pendingDocumentIds = array_map(
              static fn(array $document): int => (int)$document['id'],
              array_values(array_filter(
                  $brandDocuments,
                  static fn($document): bool => in_array(($document['status'] ?? ''), ['uploading', 'processing'], true)
              ))
          );
          $permissions = [];
          if (aiTableExists($conn, 'ai_brand_permissions')) {
              $stmt = $conn->prepare('SELECT user_id FROM ai_brand_permissions WHERE brand_id = ?');
              if ($stmt) { $stmt->bind_param('i', $brandId); $stmt->execute(); foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $permissions[] = (int)$row['user_id']; }
          }
        ?>
        <section class="panel assistant-brand-panel assistant-surface">
          <div class="assistant-brand-title">
            <div><span class="assistant-eyebrow">Marca documental</span><h2><?php echo h($brand['name']); ?></h2><p><?php echo h($brand['description'] ?: 'Sin descripción.'); ?></p></div>
            <div class="assistant-brand-stats">
              <span><strong><?php echo count($brandDocuments); ?></strong> documentos</span>
              <span><strong><?php echo $publishedDocuments; ?></strong> publicados</span>
              <span class="pill <?php echo $brand['status'] === 'active' ? 'active' : 'inactive'; ?>"><?php echo $brand['status'] === 'active' ? 'Activa' : 'Inactiva'; ?></span>
            </div>
          </div>

          <div class="assistant-admin-columns">
            <form method="post" action="asistente_accion.php" class="assistant-admin-stack">
              <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
              <input type="hidden" name="action" value="save_brand">
              <input type="hidden" name="brand_id" value="<?php echo $brandId; ?>">
              <div class="assistant-form-heading"><span>Configuración</span><h3>Datos de la marca</h3><p>Información visible y reglas específicas que ELÍAS debe aplicar.</p></div>
              <div><label>Nombre</label><input name="name" maxlength="160" value="<?php echo h($brand['name']); ?>" required></div>
              <div><label>Descripción</label><textarea name="description" rows="2" maxlength="1000"><?php echo h($brand['description']); ?></textarea></div>
              <div><label>Indicaciones adicionales</label><textarea name="system_instructions" rows="5" maxlength="5000" placeholder="Terminología propia, tono o reglas específicas de esta marca."><?php echo h($brand['system_instructions']); ?></textarea></div>
              <div><label>Estado</label><select name="status"><option value="active" <?php echo $brand['status'] === 'active' ? 'selected' : ''; ?>>Activa</option><option value="inactive" <?php echo $brand['status'] !== 'active' ? 'selected' : ''; ?>>Inactiva</option></select></div>
              <button class="btn primary" type="submit">Guardar marca</button>
            </form>

            <form method="post" action="asistente_accion.php" enctype="multipart/form-data" class="assistant-admin-stack">
              <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
              <input type="hidden" name="action" value="upload_document">
              <input type="hidden" name="brand_id" value="<?php echo $brandId; ?>">
              <div class="assistant-form-heading"><span>Documento individual</span><h3>Añadir un PDF</h3><p>Para cargar un único manual, tarifa o ficha técnica.</p></div>
              <div><label>Título del documento</label><input name="title" maxlength="240" required></div>
              <div class="assistant-admin-row"><div><label>Tipo</label><input name="document_type" maxlength="80" placeholder="Manual, tarifa, ficha…"></div><div><label>Versión</label><input name="version_label" maxlength="80"></div></div>
              <div><label>Fecha de vigencia</label><input type="date" name="effective_date"></div>
              <div><label>Archivo PDF</label><input type="file" name="document" accept="application/pdf,.pdf" required><small>Máximo efectivo <?php echo number_format($uploadLimitBytes / 1048576, 0); ?> MB. Los PDF escaneados se detectan antes de publicarse.</small></div>
              <button class="btn warning" type="submit">Añadir documento</button>
            </form>
          </div>

          <section class="assistant-batch-panel" aria-labelledby="batch-title-<?php echo $brandId; ?>">
            <div class="assistant-section-heading">
              <div><span class="assistant-eyebrow">Carga masiva</span><h3 id="batch-title-<?php echo $brandId; ?>">Procesar un lote completo</h3><p>Selecciona varios PDF, una carpeta completa o arrastra los archivos. ELÍAS comprobará primero el servicio, publicará un PDF de prueba y después procesará hasta dos simultáneamente.</p></div>
              <span class="assistant-batch-safe">Comprobación previa · máximo 2 en paralelo</span>
            </div>
            <form class="assistant-batch-form" data-assistant-batch data-max-bytes="<?php echo $uploadLimitBytes; ?>" data-batch-concurrency="2" data-pending-documents="<?php echo h(implode(',', $pendingDocumentIds)); ?>">
              <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
              <input type="hidden" name="brand_id" value="<?php echo $brandId; ?>">
              <div><label>Tipo</label><input name="document_type" maxlength="80" value="Manual"></div>
              <div><label>Versión común (opcional)</label><input name="version_label" maxlength="80"></div>
              <div><label>Fecha de vigencia (opcional)</label><input type="date" name="effective_date"></div>
              <div class="assistant-batch-files">
                <label>Selecciona el lote</label>
                <div class="assistant-batch-pickers">
                  <label class="assistant-file-picker"><input type="file" data-batch-files accept="application/pdf,.pdf" multiple><span>📄 Seleccionar varios PDF</span></label>
                  <label class="assistant-file-picker"><input type="file" data-batch-folder accept="application/pdf,.pdf" multiple webkitdirectory directory><span>📁 Seleccionar una carpeta</span></label>
                </div>
                <div class="assistant-drop-zone" data-batch-drop tabindex="0" role="button" aria-label="Seleccionar o arrastrar archivos PDF"><strong>También puedes arrastrar aquí todos los PDF</strong><span data-batch-selection>No hay archivos seleccionados.</span></div>
                <small>Máximo efectivo por PDF: <?php echo number_format($uploadLimitBytes / 1048576, 0); ?> MB. Cada archivo mostrará Publicado únicamente después de completar todo el proceso.</small>
              </div>
              <div class="assistant-batch-actions">
                <button class="btn warning" type="submit" disabled>Procesar lote</button>
                <button class="btn assistant-clear-batch" type="button" data-batch-clear disabled>Vaciar selección</button>
                <button class="btn" type="button" data-batch-refresh hidden>Actualizar lista de documentos</button>
              </div>
              <div class="assistant-batch-progress" hidden>
                <progress value="0" max="1"></progress>
                <strong data-batch-summary>Listo para iniciar.</strong>
                <ol data-batch-results></ol>
              </div>
            </form>
          </section>

          <details class="assistant-permissions">
            <summary>Permisos de usuarios</summary>
            <form method="post" action="asistente_accion.php">
              <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
              <input type="hidden" name="action" value="save_permissions">
              <input type="hidden" name="brand_id" value="<?php echo $brandId; ?>">
              <p>Sin usuarios marcados, la marca estará disponible para todo el grupo.</p>
              <div class="assistant-user-grid">
                <?php foreach ($users as $user): ?><label><input type="checkbox" name="user_ids[]" value="<?php echo (int)$user['id']; ?>" <?php echo in_array((int)$user['id'], $permissions, true) ? 'checked' : ''; ?>> <?php echo h($user['comercial'] ?: $user['username']); ?></label><?php endforeach; ?>
              </div>
              <button class="btn" type="submit">Guardar permisos</button>
            </form>
          </details>

          <div class="assistant-section-heading assistant-documents-heading">
            <div><span class="assistant-eyebrow">Biblioteca de la marca</span><h3>Documentos cargados</h3><p>Consulta el estado, reintenta los documentos erróneos y reprocesa las tarifas cuando cambie el buscador de precios.</p></div>
          </div>
          <div class="table-wrap assistant-documents">
            <table>
              <thead><tr><th>Documento</th><th>Versión</th><th>Páginas</th><th>Estado</th><th>Acciones</th></tr></thead>
              <tbody>
                <?php foreach ($brandDocuments as $document): ?>
                  <?php
                    $priceDocumentText = aiSlug(
                        (string)($document['title'] ?? '') . ' '
                        . (string)($document['original_filename'] ?? '') . ' '
                        . (string)($document['document_type'] ?? '')
                    );
                    $isPriceDocument = str_contains($priceDocumentText, 'tarifa')
                        || str_contains($priceDocumentText, 'precio')
                        || str_contains($priceDocumentText, 'price');
                    $priceRowCount = $priceCountsByDocument[(int)$document['id']] ?? 0;
                  ?>
                  <tr data-document-row="<?php echo (int)$document['id']; ?>">
                    <td>
                      <strong><?php echo h($document['title']); ?></strong>
                      <small><?php echo h($document['original_filename']); ?></small>
                      <?php if ($priceRowCount > 0): ?>
                        <small class="assistant-price-count ready"><?php echo number_format($priceRowCount, 0, ',', '.'); ?> referencias disponibles</small>
                      <?php elseif ($isPriceDocument && $document['status'] === 'published'): ?>
                        <small class="assistant-price-count warning">Sin referencias locales. Reprocesa el catálogo.</small>
                      <?php endif; ?>
                    </td>
                    <td><?php echo h($document['version_label'] ?: '—'); ?></td>
                    <td><?php echo $document['page_count'] !== null ? (int)$document['page_count'] : '—'; ?></td>
                    <td data-document-status><span class="pill <?php echo h(aiAdminDocumentStatusClass((string)$document['status'])); ?>"><?php echo h(formatEstadoWeb($document['status'])); ?></span><?php if ($document['ingestion_note']): ?><small><?php echo h($document['ingestion_note']); ?></small><?php endif; ?></td>
                    <td>
                      <?php if ($document['status'] !== 'deleted'): ?>
                        <div class="assistant-document-actions">
                          <?php if (in_array((string)$document['status'], ['error', 'uploading', 'processing', 'needs_ocr'], true)): ?>
                            <?php
                              $retrySummary = match ((string)$document['status']) {
                                  'uploading' => 'Reiniciar carga',
                                  'processing' => 'Reiniciar preparación',
                                  'needs_ocr' => 'Reconocer texto',
                                  default => 'Reintentar subida',
                              };
                              $retryButton = (string)$document['status'] === 'needs_ocr'
                                  ? 'Iniciar reconocimiento'
                                  : 'Volver a intentar';
                              $retryHelp = match ((string)$document['status']) {
                                  'uploading' => 'Utiliza esta opción si la carga no avanza.',
                                  'processing' => 'El intento anterior se retirará antes de iniciar el nuevo.',
                                  'needs_ocr' => 'El reconocimiento se ejecuta sin utilizar Terra ni Sol y puede tardar varios minutos.',
                                  default => 'El intento anterior se limpiará antes de volver a preparar el documento.',
                              };
                            ?>
                            <details class="assistant-document-retry">
                              <summary><?php echo h($retrySummary); ?></summary>
                              <form method="post" action="asistente_accion.php" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                <input type="hidden" name="action" value="upload_document">
                                <input type="hidden" name="brand_id" value="<?php echo $brandId; ?>">
                                <input type="hidden" name="retry_document_id" value="<?php echo (int)$document['id']; ?>">
                                <?php if ((string)$document['status'] === 'needs_ocr'): ?><input type="hidden" name="force_ocr" value="1"><?php endif; ?>
                                <label for="retry-document-<?php echo (int)$document['id']; ?>">Selecciona de nuevo el mismo PDF</label>
                                <input id="retry-document-<?php echo (int)$document['id']; ?>" type="file" name="document" accept="application/pdf,.pdf" required>
                                <small><?php echo h($document['original_filename']); ?></small>
                                <small><?php echo h($retryHelp); ?></small>
                                <button class="btn small warning" type="submit"><?php echo h($retryButton); ?></button>
                              </form>
                            </details>
                          <?php endif; ?>
                          <?php if ($priceCatalogReady && $document['status'] === 'published' && $isPriceDocument): ?>
                            <details class="assistant-document-retry">
                              <summary>Reprocesar catálogo</summary>
                              <form method="post" action="asistente_accion.php" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                <input type="hidden" name="action" value="upload_document">
                                <input type="hidden" name="brand_id" value="<?php echo $brandId; ?>">
                                <input type="hidden" name="retry_document_id" value="<?php echo (int)$document['id']; ?>">
                                <label for="reprocess-document-<?php echo (int)$document['id']; ?>">Selecciona de nuevo la misma tarifa PDF</label>
                                <input id="reprocess-document-<?php echo (int)$document['id']; ?>" type="file" name="document" accept="application/pdf,.pdf" required>
                                <small>La tarifa actual seguirá activa si el reprocesado falla.</small>
                                <button class="btn small primary" type="submit">Preparar buscador de precios</button>
                              </form>
                            </details>
                          <?php endif; ?>
                          <form method="post" action="asistente_accion.php" class="assistant-inline-actions">
                            <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>"><input type="hidden" name="action" value="document_status"><input type="hidden" name="brand_id" value="<?php echo $brandId; ?>"><input type="hidden" name="document_id" value="<?php echo (int)$document['id']; ?>">
                            <?php if ($document['status'] === 'published'): ?><button class="btn small" name="status" value="inactive" type="submit">Desactivar</button><?php elseif ($document['status'] === 'inactive'): ?><button class="btn small primary" name="status" value="published" type="submit">Activar</button><?php endif; ?>
                            <button class="btn small danger" name="status" value="deleted" type="submit" onclick="return confirm('¿Retirar este documento del asistente?');">Retirar</button>
                          </form>
                        </div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$brandDocuments): ?><tr><td colspan="5" class="muted">Todavía no hay documentos.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php if ($ready && $brands): ?><script src="../js/asistente_admin.js?v=<?php echo APP_VERSION; ?>"></script><?php endif; ?>
</body>
</html>

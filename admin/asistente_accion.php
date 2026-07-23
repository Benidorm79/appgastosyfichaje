<?php
declare(strict_types=1);

$aiBatchRequest = (string)($_POST['batch_mode'] ?? '') === '1';
$GLOBALS['ai_batch_response_completed'] = false;
$GLOBALS['ai_active_document_id'] = 0;

if ($aiBatchRequest) {
    ini_set('display_errors', '0');
    register_shutdown_function(static function (): void {
        if (!empty($GLOBALS['ai_batch_response_completed'])) return;
        $error = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!$error || !in_array((int)($error['type'] ?? 0), $fatalTypes, true)) return;

        $documentId = (int)($GLOBALS['ai_active_document_id'] ?? 0);
        $connection = $GLOBALS['conn'] ?? null;
        if ($documentId > 0 && $connection instanceof mysqli) {
            $note = 'La carga se ha interrumpido. Puedes volver a intentarlo.';
            $stmt = $connection->prepare("UPDATE ai_documents SET status = 'error', ingestion_note = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $note, $documentId);
                $stmt->execute();
            }
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code(500);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => 'La carga se ha interrumpido. Inténtalo de nuevo.',
            'code' => 'upload_interrupted',
            'retryable' => true,
            'systemic' => true,
            'document_id' => $documentId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    });
}

require_once __DIR__ . '/../admin_guard.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/asistente_tecnico.php';

requirePostMethod();
requireCsrfFromRequest();

function aiAdminRedirect(string $type, string $message, array $extra = []): void
{
    if ((string)($_POST['batch_mode'] ?? '') === '1') {
        aiAdminJson(
            array_merge(['ok' => $type === 'success', 'message' => $message], $extra),
            $type === 'success' ? 200 : 422
        );
    }
    header('Location: asistente.php?type=' . rawurlencode($type) . '&msg=' . rawurlencode($message));
    exit;
}

function aiAdminJson(array $payload, int $status = 200): void
{
    $GLOBALS['ai_batch_response_completed'] = true;
    appJson($payload, $status);
}

function aiEnsureBrandVectorStore(mysqli $conn, array $brand): array
{
    $service = aiServiceRequest('/v1/vector-stores/ensure', [
        'brand_id' => (int)$brand['id'],
        'brand_name' => (string)$brand['name'],
        'vector_store_id' => (string)($brand['vector_store_id'] ?? ''),
    ], 45);
    if (empty($service['ok'])) {
        appLogError(
            'No se pudo validar el espacio documental de ELÍAS',
            $service['internal_error'] ?? json_encode($service)
        );
        return [
            'ok' => false,
            'message' => 'ELÍAS no está disponible para recibir documentos en este momento.',
            'code' => 'ingestion_unavailable',
            'retryable' => true,
            'systemic' => true,
        ];
    }

    $vectorStoreId = trim((string)($service['vector_store_id'] ?? ''));
    if ($vectorStoreId === '') {
        appLogError('ELÍAS devolvió una preparación documental incompleta');
        return [
            'ok' => false,
            'message' => 'No se ha podido iniciar la carga de documentos.',
            'code' => 'ingestion_preflight_failed',
            'retryable' => true,
            'systemic' => true,
        ];
    }

    if ($vectorStoreId !== trim((string)($brand['vector_store_id'] ?? ''))) {
        $stmt = $conn->prepare('UPDATE ai_brands SET vector_store_id = ? WHERE id = ?');
        if (!$stmt) {
            return [
                'ok' => false,
                'message' => 'No se ha podido iniciar la carga de documentos.',
                'code' => 'ingestion_preflight_failed',
                'retryable' => true,
                'systemic' => true,
            ];
        }
        $brandId = (int)$brand['id'];
        $stmt->bind_param('si', $vectorStoreId, $brandId);
        if (!$stmt->execute()) {
            return [
                'ok' => false,
                'message' => 'No se ha podido iniciar la carga de documentos.',
                'code' => 'ingestion_preflight_failed',
                'retryable' => true,
                'systemic' => true,
            ];
        }
    }

    return ['ok' => true, 'vector_store_id' => $vectorStoreId];
}

if (!aiTablesReady($conn)) aiAdminRedirect('error', 'Completa primero la instalación inicial.');
$action = (string)($_POST['action'] ?? '');
$actorId = (int)($_SESSION['user_id'] ?? 0);
$documentVectorStoreReady = aiColumnExists($conn, 'ai_documents', 'vector_store_id');

if ($action === 'save_usage_settings') {
    if ((string)($_SESSION['role'] ?? '') !== 'master') {
        aiAdminRedirect('error', 'Solo el usuario Máster puede modificar estos límites.');
    }
    if (!aiTableExists($conn, 'ai_usage_settings')) {
        aiAdminRedirect('error', 'Completa primero la actualización de ELÍAS.');
    }
    $monthlyBudget = min(100000.0, max(0.0, (float)($_POST['monthly_budget_eur'] ?? 0)));
    $warningPercent = min(100, max(1, (int)($_POST['warning_percent'] ?? 80)));
    $dailyLimit = min(10000, max(0, (int)($_POST['daily_user_limit'] ?? 0)));
    $usdToEurRate = min(10.0, max(0.0001, (float)($_POST['usd_to_eur_rate'] ?? AI_USD_TO_EUR_RATE)));
    $terraInput = min(1000.0, max(0.0, (float)($_POST['terra_input_usd_million'] ?? 2.5)));
    $terraOutput = min(1000.0, max(0.0, (float)($_POST['terra_output_usd_million'] ?? 15.0)));
    $solInput = min(1000.0, max(0.0, (float)($_POST['sol_input_usd_million'] ?? 5.0)));
    $solOutput = min(1000.0, max(0.0, (float)($_POST['sol_output_usd_million'] ?? 30.0)));
    $blockOnBudget = isset($_POST['block_on_budget']) ? 1 : 0;
    $stmt = $conn->prepare(
        'INSERT INTO ai_usage_settings
           (id, monthly_budget_eur, warning_percent, block_on_budget, daily_user_limit, usd_to_eur_rate,
            terra_input_usd_million, terra_output_usd_million, sol_input_usd_million, sol_output_usd_million, updated_by)
         VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           monthly_budget_eur = VALUES(monthly_budget_eur),
           warning_percent = VALUES(warning_percent),
           block_on_budget = VALUES(block_on_budget),
           daily_user_limit = VALUES(daily_user_limit),
           usd_to_eur_rate = VALUES(usd_to_eur_rate),
           terra_input_usd_million = VALUES(terra_input_usd_million),
           terra_output_usd_million = VALUES(terra_output_usd_million),
           sol_input_usd_million = VALUES(sol_input_usd_million),
           sol_output_usd_million = VALUES(sol_output_usd_million),
           updated_by = VALUES(updated_by)'
    );
    if (!$stmt) aiAdminRedirect('error', appPublicError());
    $stmt->bind_param(
        'diiidddddi',
        $monthlyBudget,
        $warningPercent,
        $blockOnBudget,
        $dailyLimit,
        $usdToEurRate,
        $terraInput,
        $terraOutput,
        $solInput,
        $solOutput,
        $actorId
    );
    if (!$stmt->execute()) aiAdminRedirect('error', appPublicError());
    aiAdminRedirect('success', 'Control de consumo actualizado correctamente.');
}

if ($action === 'create_brand') {
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $slug = aiSlug($name);
    if ($name === '' || $slug === '') aiAdminRedirect('error', 'Indica un nombre válido.');
    $stmt = $conn->prepare('INSERT INTO ai_brands (name, slug, description, created_by) VALUES (?, ?, ?, ?)');
    if (!$stmt) aiAdminRedirect('error', appPublicError());
    $stmt->bind_param('sssi', $name, $slug, $description, $actorId);
    if (!$stmt->execute()) {
        appLogError('No se pudo crear la marca', $stmt->error);
        aiAdminRedirect('error', 'No se ha podido crear la marca. Comprueba que no exista otra igual.');
    }
    aiAdminRedirect('success', 'Marca creada correctamente.');
}

$brandId = (int)($_POST['brand_id'] ?? 0);
$brand = aiGetBrand($conn, $brandId);
if (!$brand) aiAdminRedirect('error', 'No se ha encontrado la marca.');

if ($action === 'prepare_batch') {
    $prepared = aiEnsureBrandVectorStore($conn, $brand);
    if (empty($prepared['ok'])) {
        aiAdminRedirect('error', (string)$prepared['message'], [
            'code' => (string)$prepared['code'],
            'retryable' => (bool)$prepared['retryable'],
            'systemic' => true,
        ]);
    }
    aiAdminRedirect('success', 'ELÍAS está preparado para recibir el lote.', [
        'status' => 'ready',
        'vector_store_id' => (string)$prepared['vector_store_id'],
    ]);
}

if ($action === 'batch_status') {
    $vectorStoreColumn = $documentVectorStoreReady
        ? "COALESCE(NULLIF(vector_store_id, ''), '') AS document_vector_store_id"
        : "'' AS document_vector_store_id";
    $stmt = $conn->prepare(
        "SELECT id, status, vector_file_id, {$vectorStoreColumn},
                GREATEST(0, TIMESTAMPDIFF(SECOND, updated_at, NOW())) AS age_seconds
         FROM ai_documents
         WHERE brand_id = ? AND status IN ('uploading', 'processing')
         ORDER BY id ASC LIMIT 100"
    );
    if (!$stmt) aiAdminJson(['ok' => false, 'message' => 'No se ha podido comprobar la preparación.'], 500);
    $stmt->bind_param('i', $brandId);
    $stmt->execute();
    $documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$documents) aiAdminJson(['ok' => true, 'items' => [], 'pending' => 0]);

    $items = [];
    $pending = 0;
    $uploadTimeoutSeconds = AI_DOCUMENT_UPLOAD_STALE_MINUTES * 60;
    $brandVectorStoreId = trim((string)($brand['vector_store_id'] ?? ''));
    $remoteGroups = [];
    $updateDocument = static function (int $documentId, int $brandId, string $status, string $note) use ($conn): void {
        $update = $conn->prepare('UPDATE ai_documents SET status = ?, ingestion_note = ? WHERE id = ? AND brand_id = ?');
        if (!$update) return;
        $update->bind_param('ssii', $status, $note, $documentId, $brandId);
        $update->execute();
    };

    foreach ($documents as $document) {
        $documentId = (int)$document['id'];
        $currentStatus = (string)$document['status'];
        $ageSeconds = (int)$document['age_seconds'];
        if ($currentStatus === 'uploading') {
            if ($ageSeconds >= $uploadTimeoutSeconds) {
                $note = 'La carga quedó interrumpida. Selecciona de nuevo el mismo PDF para continuar.';
                $updateDocument($documentId, $brandId, 'error', $note);
                $items[] = [
                    'document_id' => $documentId,
                    'status' => 'error',
                    'message' => $note,
                    'retryable' => true,
                ];
            } else {
                $note = 'Recibiendo el documento.';
                $pending++;
                $items[] = [
                    'document_id' => $documentId,
                    'status' => 'uploading',
                    'message' => $note,
                    'retryable' => false,
                ];
            }
            continue;
        }

        $vectorFileId = trim((string)($document['vector_file_id'] ?? ''));
        $vectorStoreId = trim((string)($document['document_vector_store_id'] ?? ''));
        if ($vectorStoreId === '') $vectorStoreId = $brandVectorStoreId;
        if ($vectorFileId === '' || $vectorStoreId === '') {
            $note = 'La publicación anterior quedó incompleta. Selecciona de nuevo el mismo PDF.';
            $updateDocument($documentId, $brandId, 'error', $note);
            $items[] = [
                'document_id' => $documentId,
                'status' => 'error',
                'message' => $note,
                'retryable' => true,
            ];
            continue;
        }

        $remoteGroups[$vectorStoreId][] = $document + [
            'resolved_vector_store_id' => $vectorStoreId,
            'resolved_vector_file_id' => $vectorFileId,
        ];
    }

    foreach ($remoteGroups as $vectorStoreId => $groupDocuments) {
        $remote = aiServiceRequest('/v1/documents/index-status', [
            'vector_store_id' => $vectorStoreId,
            'vector_file_ids' => array_values(array_map(
                static fn(array $document): string => (string)$document['resolved_vector_file_id'],
                $groupDocuments
            )),
        ], 45);
        $remoteByVectorFile = [];
        if (!empty($remote['ok']) && is_array($remote['items'] ?? null)) {
            foreach ($remote['items'] as $remoteItem) {
                $remoteByVectorFile[(string)($remoteItem['vector_file_id'] ?? '')] = $remoteItem;
            }
        } else {
            appLogError('No se pudo comprobar el lote de ELÍAS', $remote['internal_error'] ?? json_encode($remote));
        }

        foreach ($groupDocuments as $document) {
            $documentId = (int)$document['id'];
            $vectorFileId = (string)$document['resolved_vector_file_id'];
            $remoteItem = $remoteByVectorFile[$vectorFileId] ?? [];
            $status = (string)($remoteItem['status'] ?? 'processing');
            if ($status === 'published') {
                $note = 'Documento publicado y disponible para consultas.';
                $updateDocument($documentId, $brandId, 'published', $note);
            } else {
                $status = 'error';
                $note = 'La publicación anterior no se ha completado. Selecciona de nuevo el mismo PDF.';
                $updateDocument($documentId, $brandId, 'error', $note);
            }
            $items[] = [
                'document_id' => $documentId,
                'status' => $status,
                'message' => $note,
                'retryable' => $status === 'error',
            ];
        }
    }

    aiAdminJson(['ok' => true, 'items' => $items, 'pending' => $pending]);
}

if ($action === 'save_brand') {
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $instructions = trim((string)($_POST['system_instructions'] ?? ''));
    $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
    if ($name === '') aiAdminRedirect('error', 'Indica un nombre válido.');
    $stmt = $conn->prepare('UPDATE ai_brands SET name = ?, description = ?, system_instructions = ?, status = ? WHERE id = ?');
    if (!$stmt) aiAdminRedirect('error', appPublicError());
    $stmt->bind_param('ssssi', $name, $description, $instructions, $status, $brandId);
    if (!$stmt->execute()) aiAdminRedirect('error', appPublicError());
    aiAdminRedirect('success', 'Marca actualizada correctamente.');
}

if ($action === 'save_permissions') {
    $ids = is_array($_POST['user_ids'] ?? null) ? array_values(array_unique(array_map('intval', $_POST['user_ids']))) : [];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('DELETE FROM ai_brand_permissions WHERE brand_id = ?');
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('i', $brandId); $stmt->execute();
        foreach ($ids as $id) {
            if ($id <= 0) continue;
            $stmt = $conn->prepare('INSERT INTO ai_brand_permissions (brand_id, user_id) VALUES (?, ?)');
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('ii', $brandId, $id); $stmt->execute();
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback(); appLogError('No se pudieron guardar los permisos', $error); aiAdminRedirect('error', appPublicError());
    }
    aiAdminRedirect('success', 'Permisos actualizados correctamente.');
}

if ($action === 'upload_document') {
    $retryDocumentId = max(0, (int)($_POST['retry_document_id'] ?? 0));
    $forceOcr = (string)($_POST['force_ocr'] ?? '') === '1';
    $stableReprocess = false;
    $oldVectorStoreId = '';
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 240, 'UTF-8');
    $documentType = trim((string)($_POST['document_type'] ?? ''));
    $versionLabel = trim((string)($_POST['version_label'] ?? ''));
    $effectiveDate = trim((string)($_POST['effective_date'] ?? ''));
    $file = $_FILES['document'] ?? null;
    if (($retryDocumentId <= 0 && $title === '') || !is_array($file)) {
        aiAdminRedirect('error', 'Selecciona un PDF válido.', ['code' => 'missing_file', 'retryable' => false]);
    }
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            aiAdminRedirect('error', 'El archivo supera el límite de carga disponible.', ['code' => 'upload_limit', 'retryable' => false]);
        }
        if ($uploadError === UPLOAD_ERR_PARTIAL) {
            aiAdminRedirect('error', 'La carga del archivo ha quedado incompleta.', ['code' => 'partial_upload', 'retryable' => true]);
        }
        aiAdminRedirect('error', 'No se ha podido recibir el archivo.', ['code' => 'upload_failed', 'retryable' => true]);
    }
    $tmp = (string)$file['tmp_name'];
    $size = (int)$file['size'];
    if ($size <= 0 || $size > AI_DOCUMENT_MAX_FILE_BYTES) {
        aiAdminRedirect('error', 'El archivo supera el tamaño permitido.', ['code' => 'file_too_large', 'retryable' => false]);
    }
    $head = file_get_contents($tmp, false, null, 0, 5);
    $mime = class_exists('finfo') ? ((new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '') : 'application/pdf';
    if ($head !== '%PDF-' || !in_array($mime, ['application/pdf', 'application/octet-stream'], true)) {
        aiAdminRedirect('error', 'El archivo seleccionado no es un PDF válido.', ['code' => 'invalid_pdf', 'retryable' => false]);
    }
    $sha256 = hash_file('sha256', $tmp);
    if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
        aiAdminRedirect('error', 'No se ha podido comprobar el PDF seleccionado.');
    }
    $filename = basename((string)$file['name']);
    $dateValue = preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate) ? $effectiveDate : null;
    $existingDocument = null;

    if ($retryDocumentId > 0) {
        $retryStmt = $conn->prepare(
            "SELECT * FROM ai_documents
             WHERE id = ? AND brand_id = ?
               AND status IN ('error', 'published', 'inactive', 'uploading', 'processing', 'needs_ocr')
             LIMIT 1"
        );
        if (!$retryStmt) aiAdminRedirect('error', appPublicError());
        $retryStmt->bind_param('ii', $retryDocumentId, $brandId);
        $retryStmt->execute();
        $existingDocument = $retryStmt->get_result()->fetch_assoc();
        if (!$existingDocument) {
            aiAdminRedirect('error', 'Este documento ya no está pendiente de reintento.');
        }
        $stableReprocess = in_array((string)$existingDocument['status'], ['published', 'inactive'], true);
        if ((string)$existingDocument['status'] === 'needs_ocr') $forceOcr = true;
        if (!hash_equals(strtolower((string)$existingDocument['sha256']), strtolower($sha256))) {
            aiAdminRedirect(
                'error',
                'Selecciona exactamente el mismo PDF asociado a este documento.',
                ['code' => 'retry_file_mismatch', 'retryable' => true]
            );
        }
        $title = (string)$existingDocument['title'];
        $filename = (string)$existingDocument['original_filename'];
        $documentType = (string)($existingDocument['document_type'] ?? '');
        $versionLabel = (string)($existingDocument['version_label'] ?? '');
        $dateValue = $existingDocument['effective_date'] ?: null;
    } else {
        $existingStmt = $conn->prepare('SELECT * FROM ai_documents WHERE brand_id = ? AND sha256 = ? LIMIT 1');
        if (!$existingStmt) aiAdminRedirect('error', appPublicError());
        $existingStmt->bind_param('is', $brandId, $sha256);
        $existingStmt->execute();
        $existingDocument = $existingStmt->get_result()->fetch_assoc();
    }

    if ($retryDocumentId <= 0 && $existingDocument && !in_array($existingDocument['status'], ['error', 'deleted'], true)) {
        aiAdminRedirect('error', 'Este documento ya se había añadido.', ['code' => 'duplicate', 'retryable' => false]);
    }

    // La carga individual valida siempre el espacio documental. En un lote,
    // prepare_batch ya lo ha validado una sola vez antes de enviar el primer PDF.
    if (!$aiBatchRequest || trim((string)($brand['vector_store_id'] ?? '')) === '') {
        $prepared = aiEnsureBrandVectorStore($conn, $brand);
        if (empty($prepared['ok'])) {
            aiAdminRedirect('error', (string)$prepared['message'], [
                'code' => (string)$prepared['code'],
                'retryable' => (bool)$prepared['retryable'],
                'systemic' => true,
            ]);
        }
        $brand['vector_store_id'] = (string)$prepared['vector_store_id'];
    }

    if ($existingDocument) {
        $documentId = (int)$existingDocument['id'];
        $oldVectorFileId = trim((string)($existingDocument['vector_file_id'] ?? ''));
        $oldOpenaiFileId = trim((string)($existingDocument['openai_file_id'] ?? ''));
        $vectorStoreId = $documentVectorStoreReady
            ? trim((string)($existingDocument['vector_store_id'] ?? ''))
            : '';
        if ($vectorStoreId === '') $vectorStoreId = trim((string)($brand['vector_store_id'] ?? ''));
        $oldVectorStoreId = $vectorStoreId;
        if (!$stableReprocess && $vectorStoreId !== '' && $oldVectorFileId !== '') {
            $cleanup = aiServiceRequest('/v1/documents/status', [
                'vector_store_id' => $vectorStoreId,
                'vector_file_id' => $oldVectorFileId,
                'openai_file_id' => $oldOpenaiFileId,
                'document_id' => $documentId,
                'brand_id' => $brandId,
                'original_filename' => (string)$existingDocument['original_filename'],
                'document_type' => (string)($existingDocument['document_type'] ?? ''),
                'version_label' => (string)($existingDocument['version_label'] ?? ''),
                'effective_date' => $existingDocument['effective_date'] ?: null,
                'status' => 'deleted',
            ], 90);
            if (empty($cleanup['ok'])) {
                appLogError('No se pudo limpiar el intento anterior de ELÍAS', $cleanup['internal_error'] ?? json_encode($cleanup));
            }
        }
        if (!$stableReprocess) {
            $resetVectorStore = $documentVectorStoreReady ? ', vector_store_id = NULL' : '';
            $stmt = $conn->prepare("UPDATE ai_documents SET title = ?, original_filename = ?, mime_type = 'application/pdf', size_bytes = ?, document_type = ?, version_label = ?, effective_date = ?, status = 'uploading', openai_file_id = NULL{$resetVectorStore}, vector_file_id = NULL, ingestion_note = NULL, uploaded_by = ? WHERE id = ?");
            if (!$stmt) aiAdminRedirect('error', appPublicError());
            $stmt->bind_param('ssisssii', $title, $filename, $size, $documentType, $versionLabel, $dateValue, $actorId, $documentId);
            if (!$stmt->execute()) aiAdminRedirect('error', appPublicError());
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO ai_documents (brand_id, title, original_filename, sha256, mime_type, size_bytes, document_type, version_label, effective_date, status, uploaded_by) VALUES (?, ?, ?, ?, 'application/pdf', ?, ?, ?, ?, 'uploading', ?)");
        if (!$stmt) aiAdminRedirect('error', appPublicError());
        $stmt->bind_param('isssisssi', $brandId, $title, $filename, $sha256, $size, $documentType, $versionLabel, $dateValue, $actorId);
        if (!$stmt->execute()) aiAdminRedirect('error', 'Este documento ya se había añadido.', ['code' => 'duplicate', 'retryable' => false]);
        $documentId = (int)$stmt->insert_id;
    }
    $GLOBALS['ai_active_document_id'] = $documentId;

    $content = file_get_contents($tmp);
    if ($content === false) {
        $note = 'No se ha podido leer el archivo recibido. Selecciónalo de nuevo.';
        $stmt = $conn->prepare("UPDATE ai_documents SET status = 'error', ingestion_note = ? WHERE id = ?");
        if ($stmt) { $stmt->bind_param('si', $note, $documentId); $stmt->execute(); }
        aiAdminRedirect('error', $note, ['code' => 'read_failed', 'retryable' => true, 'document_id' => $documentId]);
    }

    $serviceTimeout = $forceOcr ? AI_SERVICE_OCR_TIMEOUT_SECONDS : AI_SERVICE_TIMEOUT_SECONDS;
    if (function_exists('set_time_limit')) {
        @set_time_limit($serviceTimeout + 20);
    }

    $service = aiServiceBinaryRequest('/v1/documents/upload-binary', [
        'document_id' => $documentId,
        'brand_id' => $brandId,
        'brand_name' => (string)$brand['name'],
        'vector_store_id' => (string)($brand['vector_store_id'] ?? ''),
        'title' => $title,
        'filename' => $filename,
        'sha256' => $sha256,
        'document_type' => $documentType,
        'version_label' => $versionLabel,
        'effective_date' => $dateValue,
        'force_ocr' => $forceOcr,
    ], $content, $serviceTimeout);

    if (empty($service['ok'])) {
        $internalError = (string)($service['internal_error'] ?? '');
        $responseStatus = (int)($service['http_status'] ?? 0);
        $remoteCode = (string)($service['response']['error_code'] ?? '');
        appLogError('No se pudo procesar el documento ' . $documentId, $internalError !== '' ? $internalError : json_encode($service));
        $code = 'processing_failed';
        $retryable = false;
        $systemic = in_array(
            $remoteCode,
            ['VECTOR_STORE_UNAVAILABLE', 'VECTOR_STORE_CREATE_FAILED', 'INGESTION_FAILED'],
            true
        );
        $note = 'No se ha podido preparar el documento.';
        if (
            stripos($internalError, 'timed out') !== false
            || stripos($internalError, 'timeout') !== false
            || preg_match('/HTTP (408|504)/', $internalError)
        ) {
            $code = 'processing_timeout';
            $retryable = true;
            $systemic = true;
            $note = 'El documento ha tardado demasiado en prepararse.';
        } elseif (
            preg_match('/HTTP (429|500|502|503)/', $internalError)
            || in_array($responseStatus, [429, 500, 502, 503], true)
        ) {
            $code = 'temporary_unavailable';
            $retryable = true;
            // INDEX_NOT_COMPLETED pertenece al PDF actual. Cualquier otro 5xx
            // se trata como incidencia general para no repetirlo en todo el lote.
            $systemic = $remoteCode !== 'INDEX_NOT_COMPLETED';
            $note = 'La preparación se ha interrumpido temporalmente.';
        } elseif (preg_match('/HTTP (413)/', $internalError)) {
            $code = 'file_too_large';
            $note = 'El archivo supera el tamaño permitido.';
        } elseif (preg_match('/HTTP (422)/', $internalError)) {
            $code = 'invalid_pdf';
            $note = 'No se ha podido leer correctamente este PDF.';
        }
        if (!$stableReprocess) {
            $stmt = $conn->prepare("UPDATE ai_documents SET status = 'error', ingestion_note = ? WHERE id = ?");
            if ($stmt) { $stmt->bind_param('si', $note, $documentId); $stmt->execute(); }
        }
        if ($stableReprocess) {
            $note = 'No se ha podido reprocesar el catálogo. La versión anterior sigue activa.';
        }
        aiAdminRedirect('error', $note . ($retryable ? ' Puedes reintentarlo.' : ''), [
            'code' => $code,
            'retryable' => $retryable,
            'systemic' => $systemic,
            'document_id' => $documentId,
        ]);
    }

    $vectorStoreId = (string)($service['vector_store_id'] ?? '');
    $openaiFileId = (string)($service['file_id'] ?? '');
    $vectorFileId = (string)($service['vector_file_id'] ?? $openaiFileId);
    $storageUri = (string)($service['storage_uri'] ?? '');
    $pageCount = (int)($service['page_count'] ?? 0);
    $pagesWithText = (int)($service['pages_with_text'] ?? 0);
    $ocrPages = (int)($service['ocr_pages'] ?? 0);
    $catalogRows = (int)($service['catalog_rows'] ?? 0);
    $catalogItems = is_array($service['catalog_items'] ?? null) ? $service['catalog_items'] : [];
    $catalogSyncFailed = false;
    $serviceStatus = (string)($service['status'] ?? '');
    if (!in_array($serviceStatus, ['published', 'needs_ocr'], true)) {
        if ($vectorStoreId !== '' && $vectorFileId !== '') {
            aiServiceRequest('/v1/documents/status', [
                'vector_store_id' => $vectorStoreId,
                'vector_file_id' => $vectorFileId,
                'openai_file_id' => $openaiFileId,
                'document_id' => $documentId,
                'brand_id' => $brandId,
                'original_filename' => $filename,
                'document_type' => $documentType,
                'version_label' => $versionLabel,
                'effective_date' => $dateValue,
                'status' => 'deleted',
            ], 90);
        }
        $note = $stableReprocess
            ? 'No se ha podido reprocesar el catálogo. La versión anterior sigue activa.'
            : 'No se ha podido completar la publicación del documento.';
        if (!$stableReprocess) {
            $stmt = $conn->prepare("UPDATE ai_documents SET status = 'error', ingestion_note = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $note, $documentId);
                $stmt->execute();
            }
        }
        aiAdminRedirect('error', $note . ' Puedes reintentarlo.', [
            'code' => 'publication_incomplete',
            'retryable' => true,
            'document_id' => $documentId,
        ]);
    }
    $documentStatus = $serviceStatus;
    $note = (string)($service['message'] ?? '');
    if ($catalogRows > 0) {
        $note = 'Documento publicado con ' . $catalogRows . ' referencias de precio preparadas para consulta.';
    } elseif ($ocrPages > 0 && $documentStatus === 'published') {
        $note = 'Documento publicado después de reconocer el texto de ' . $ocrPages . ' páginas.';
    }
    if ($stableReprocess && ($catalogRows <= 0 || $documentStatus !== 'published')) {
        if ($vectorFileId !== '' && trim((string)($brand['vector_store_id'] ?? '')) !== '') {
            aiServiceRequest('/v1/documents/status', [
                'vector_store_id' => (string)$brand['vector_store_id'],
                'vector_file_id' => $vectorFileId,
                'openai_file_id' => $openaiFileId,
                'document_id' => $documentId,
                'brand_id' => $brandId,
                'original_filename' => $filename,
                'document_type' => $documentType,
                'version_label' => $versionLabel,
                'effective_date' => $dateValue,
                'status' => 'deleted',
            ], 90);
        }
        aiAdminRedirect('error', 'No se ha podido preparar el nuevo catálogo. La versión anterior sigue activa.');
    }
    if ($documentVectorStoreReady) {
        $stmt = $conn->prepare('UPDATE ai_documents SET status = ?, openai_file_id = ?, vector_store_id = ?, vector_file_id = ?, storage_uri = ?, page_count = ?, pages_with_text = ?, ingestion_note = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('sssssiisi', $documentStatus, $openaiFileId, $vectorStoreId, $vectorFileId, $storageUri, $pageCount, $pagesWithText, $note, $documentId);
            $stmt->execute();
        }
    } else {
        $stmt = $conn->prepare('UPDATE ai_documents SET status = ?, openai_file_id = ?, vector_file_id = ?, storage_uri = ?, page_count = ?, pages_with_text = ?, ingestion_note = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('ssssiisi', $documentStatus, $openaiFileId, $vectorFileId, $storageUri, $pageCount, $pagesWithText, $note, $documentId);
            $stmt->execute();
        }
    }
    if ($catalogRows > 0 && $catalogItems) {
        try {
            aiReplacePriceItems($conn, $brandId, $documentId, $catalogItems);
        } catch (Throwable $error) {
            $catalogSyncFailed = true;
            appLogError('No se pudo actualizar el catálogo local del documento ' . $documentId, $error);
            $catalogNote = 'Documento publicado, pero el buscador de precios necesita volver a prepararse.';
            $noteStmt = $conn->prepare('UPDATE ai_documents SET ingestion_note = ? WHERE id = ?');
            if ($noteStmt) {
                $noteStmt->bind_param('si', $catalogNote, $documentId);
                $noteStmt->execute();
            }
        }
    }
    if ($vectorStoreId !== '') {
        $stmt = $conn->prepare('UPDATE ai_brands SET vector_store_id = ? WHERE id = ?');
        if ($stmt) { $stmt->bind_param('si', $vectorStoreId, $brandId); $stmt->execute(); }
    }
    if (
        $stableReprocess
        && $oldVectorFileId !== ''
        && $oldVectorFileId !== $vectorFileId
        && $oldVectorStoreId !== ''
    ) {
        $cleanup = aiServiceRequest('/v1/documents/status', [
            'vector_store_id' => $oldVectorStoreId,
            'vector_file_id' => $oldVectorFileId,
            'openai_file_id' => $oldOpenaiFileId,
            'document_id' => $documentId,
            'brand_id' => $brandId,
            'original_filename' => (string)$existingDocument['original_filename'],
            'document_type' => (string)($existingDocument['document_type'] ?? ''),
            'version_label' => (string)($existingDocument['version_label'] ?? ''),
            'effective_date' => $existingDocument['effective_date'] ?: null,
            'status' => 'deleted',
        ], 90);
        if (empty($cleanup['ok'])) {
            appLogError('No se pudo retirar el índice anterior del documento ' . $documentId, $cleanup['internal_error'] ?? json_encode($cleanup));
        }
    }
    if ($catalogSyncFailed) {
        aiAdminRedirect('error', 'El documento se ha publicado, pero el buscador de precios necesita volver a prepararse.');
    }
    if ($documentStatus === 'needs_ocr') {
        $ocrNotice = $forceOcr
            ? 'No se ha podido obtener texto suficiente. Divide el PDF en partes más pequeñas o utiliza una copia con texto seleccionable.'
            : 'El PDF parece escaneado. Inicia el reconocimiento de texto para poder publicarlo.';
        aiAdminRedirect('error', $ocrNotice, [
            'code' => 'needs_ocr',
            'retryable' => true,
            'document_id' => $documentId,
        ]);
    }
    aiAdminRedirect('success', 'Documento añadido y publicado correctamente.', [
        'status' => 'published',
        'document_id' => $documentId,
    ]);
}

if ($action === 'document_status') {
    $documentId = (int)($_POST['document_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    if (!in_array($status, ['published', 'inactive', 'deleted'], true)) aiAdminRedirect('error', 'Acción no válida.');
    $stmt = $conn->prepare('SELECT * FROM ai_documents WHERE id = ? AND brand_id = ? LIMIT 1');
    if (!$stmt) aiAdminRedirect('error', appPublicError());
    $stmt->bind_param('ii', $documentId, $brandId); $stmt->execute(); $document = $stmt->get_result()->fetch_assoc();
    if (!$document) aiAdminRedirect('error', 'No se ha encontrado el documento.');
    $documentVectorStoreId = $documentVectorStoreReady
        ? trim((string)($document['vector_store_id'] ?? ''))
        : '';
    if ($documentVectorStoreId === '') $documentVectorStoreId = trim((string)($brand['vector_store_id'] ?? ''));
    $hasRemoteFile = $documentVectorStoreId !== '' && trim((string)($document['vector_file_id'] ?? '')) !== '';
    if ($hasRemoteFile) {
        $service = aiServiceRequest('/v1/documents/status', [
            'vector_store_id' => $documentVectorStoreId,
            'vector_file_id' => (string)$document['vector_file_id'],
            'openai_file_id' => (string)($document['openai_file_id'] ?? ''),
            'document_id' => $documentId,
            'brand_id' => $brandId,
            'original_filename' => (string)$document['original_filename'],
            'document_type' => (string)($document['document_type'] ?? ''),
            'version_label' => (string)($document['version_label'] ?? ''),
            'effective_date' => $document['effective_date'] ?: null,
            'status' => $status
        ], 90);
        if (empty($service['ok'])) {
            appLogError('No se pudo cambiar el documento ' . $documentId, $service['internal_error'] ?? json_encode($service));
            aiAdminRedirect('error', 'No se ha podido actualizar el documento. Inténtalo de nuevo.');
        }
    } elseif ($status !== 'deleted') {
        aiAdminRedirect('error', 'Este documento debe volver a añadirse antes de activarlo.');
    }
    $stmt = $conn->prepare('UPDATE ai_documents SET status = ? WHERE id = ?');
    if (!$stmt) aiAdminRedirect('error', appPublicError());
    $stmt->bind_param('si', $status, $documentId); $stmt->execute();
    aiAdminRedirect('success', 'Documento actualizado correctamente.');
}

aiAdminRedirect('error', 'Acción no válida.');

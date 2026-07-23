<?php
declare(strict_types=1);

require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/asistente_tecnico.php';

securitySendHeaders();
requirePostMethod();
$payload = safeJsonBody();
requireCsrfFromRequest($payload);

$userId = (int)($_SESSION['user_id'] ?? 0);
$action = (string)($payload['action'] ?? 'send');
$brandId = (int)($payload['brand_id'] ?? 0);
$conversationId = (int)($payload['conversation_id'] ?? 0);

if ($userId <= 0) appJson(['ok' => false, 'message' => 'Tu sesión ha caducado. Vuelve a iniciar sesión.'], 401);
if (!aiTablesReady($conn)) appJson(['ok' => false, 'message' => 'ELÍAS todavía no está disponible.'], 503);

if ($action === 'list_conversations') {
    if (!aiUserCanAccessBrand($conn, $userId, $brandId)) {
        appJson(['ok' => false, 'message' => 'No tienes acceso a esta marca.'], 403);
    }
    $conversations = [];
    $stmt = $conn->prepare(
        "SELECT id, title, last_message_at, created_at FROM ai_conversations
         WHERE user_id = ? AND brand_id = ? AND status = 'active'
         ORDER BY last_message_at DESC, id DESC LIMIT 50"
    );
    if (!$stmt) appJson(['ok' => false, 'message' => appPublicError()], 500);
    $stmt->bind_param('ii', $userId, $brandId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && ($row = $result->fetch_assoc())) {
        $conversations[] = [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'last_message_at' => (string)$row['last_message_at'],
            'created_at' => (string)$row['created_at']
        ];
    }
    appJson(['ok' => true, 'conversations' => $conversations]);
}

if ($action === 'get_conversation') {
    $conversation = aiConversationForUser($conn, $conversationId, $userId);
    if (!$conversation || !aiUserCanAccessBrand($conn, $userId, (int)$conversation['brand_id'])) {
        appJson(['ok' => false, 'message' => 'La conversación ya no está disponible.'], 404);
    }
    $hasProjectColumns = aiColumnExists($conn, 'ai_messages', 'answer_type') && aiColumnExists($conn, 'ai_messages', 'project_json');
    $projectSelect = $hasProjectColumns ? ', answer_type, project_json' : ", NULL AS answer_type, NULL AS project_json";
    $stmt = $conn->prepare(
        "SELECT recent.* FROM (
           SELECT id, role, content, citations_json, created_at{$projectSelect}
           FROM ai_messages WHERE conversation_id = ? AND status IN ('completed','error')
           ORDER BY id DESC LIMIT 250
         ) recent ORDER BY recent.id ASC"
    );
    if (!$stmt) appJson(['ok' => false, 'message' => appPublicError()], 500);
    $stmt->bind_param('i', $conversationId);
    $stmt->execute();
    $messages = [];
    $result = $stmt->get_result();
    while ($result && ($row = $result->fetch_assoc())) {
        $messages[] = [
            'id' => (int)$row['id'],
            'role' => (string)$row['role'],
            'content' => (string)$row['content'],
            'citations' => json_decode((string)($row['citations_json'] ?? ''), true) ?: [],
            'answer_type' => (string)($row['answer_type'] ?? ''),
            'project' => json_decode((string)($row['project_json'] ?? ''), true) ?: null,
            'created_at' => (string)$row['created_at']
        ];
    }
    appJson([
        'ok' => true,
        'conversation' => [
            'id' => (int)$conversation['id'],
            'brand_id' => (int)$conversation['brand_id'],
            'title' => (string)$conversation['title'],
            'last_message_at' => (string)$conversation['last_message_at']
        ],
        'messages' => $messages
    ]);
}

if ($action === 'delete_conversation') {
    $conversation = aiConversationForUser($conn, $conversationId, $userId);
    if (!$conversation) appJson(['ok' => false, 'message' => 'La conversación ya no está disponible.'], 404);
    $stmt = $conn->prepare("UPDATE ai_conversations SET status = 'deleted', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND status = 'active'");
    if (!$stmt) appJson(['ok' => false, 'message' => appPublicError()], 500);
    $stmt->bind_param('ii', $conversationId, $userId);
    $stmt->execute();
    if ($stmt->affected_rows < 1) appJson(['ok' => false, 'message' => 'No se pudo eliminar la conversación.'], 400);
    appJson(['ok' => true, 'message' => 'Conversación eliminada.']);
}

if ($action === 'feedback') {
    $messageId = (int)($payload['message_id'] ?? 0);
    $rating = (int)($payload['rating'] ?? 0);
    if ($messageId <= 0 || !in_array($rating, [-1, 1], true)) appJson(['ok' => false, 'message' => 'No se ha podido guardar la valoración.'], 422);
    $stmt = $conn->prepare(
        "SELECT m.id FROM ai_messages m INNER JOIN ai_conversations c ON c.id = m.conversation_id
         WHERE m.id = ? AND c.user_id = ? AND m.role = 'assistant' LIMIT 1"
    );
    if (!$stmt) appJson(['ok' => false, 'message' => appPublicError()], 500);
    $stmt->bind_param('ii', $messageId, $userId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) appJson(['ok' => false, 'message' => 'No se ha podido guardar la valoración.'], 404);
    $stmt = $conn->prepare(
        'INSERT INTO ai_feedback (message_id, user_id, rating) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_at = CURRENT_TIMESTAMP'
    );
    if (!$stmt) appJson(['ok' => false, 'message' => appPublicError()], 500);
    $stmt->bind_param('iii', $messageId, $userId, $rating);
    $stmt->execute();
    appJson(['ok' => true, 'message' => 'Gracias por tu valoración.']);
}

$question = trim((string)($payload['message'] ?? ''));
$requestId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($payload['request_id'] ?? '')) ?? '';

if (!aiUserCanAccessBrand($conn, $userId, $brandId)) appJson(['ok' => false, 'message' => 'No tienes acceso a esta marca.'], 403);
if ($question === '' || mb_strlen($question, 'UTF-8') > 4000) appJson(['ok' => false, 'message' => 'Escribe una pregunta de hasta 4.000 caracteres.'], 422);
if ($requestId === '' || strlen($requestId) > 56) $requestId = bin2hex(random_bytes(16));
$requestHash = hash('sha256', $userId . '|' . $brandId . '|' . $requestId);
$userRequestId = 'u' . substr($requestHash, 0, 63);
$assistantRequestId = 'a' . substr($requestHash, 0, 63);

$hasProjectColumns = aiColumnExists($conn, 'ai_messages', 'answer_type') && aiColumnExists($conn, 'ai_messages', 'project_json');
$projectSelect = $hasProjectColumns ? ', m.answer_type, m.project_json' : ", NULL AS answer_type, NULL AS project_json";
$duplicate = $conn->prepare(
    "SELECT m.id, m.conversation_id, m.content, m.citations_json" . $projectSelect . "
     FROM ai_messages m
     INNER JOIN ai_conversations c ON c.id = m.conversation_id
     WHERE m.client_request_id = ? AND m.role = 'assistant' AND c.user_id = ? AND c.status = 'active' LIMIT 1"
);
if ($duplicate) {
    $duplicate->bind_param('si', $assistantRequestId, $userId);
    $duplicate->execute();
    $existing = $duplicate->get_result()->fetch_assoc();
    if ($existing) {
        appJson([
            'ok' => true,
            'conversation_id' => (int)$existing['conversation_id'],
            'message_id' => (int)$existing['id'],
            'answer' => $existing['content'],
            'answer_type' => (string)($existing['answer_type'] ?? ''),
            'project' => json_decode((string)($existing['project_json'] ?? ''), true) ?: null,
            'citations' => json_decode((string)$existing['citations_json'], true) ?: []
        ]);
    }
}

$pending = $conn->prepare(
    "SELECT m.id FROM ai_messages m
     INNER JOIN ai_conversations c ON c.id = m.conversation_id
     WHERE m.client_request_id = ? AND m.role = 'user' AND c.user_id = ? AND c.status = 'active' LIMIT 1"
);
if ($pending) {
    $pending->bind_param('si', $userRequestId, $userId);
    $pending->execute();
    if ($pending->get_result()->fetch_assoc()) {
        appJson(['ok' => false, 'message' => 'Esta consulta ya se está procesando. Espera unos segundos.'], 409);
    }
}

$usageSettings = aiGetUsageSettings($conn);
$dailyLimit = (int)$usageSettings['daily_user_limit'];
if ($dailyLimit > 0 && aiDailyQuestionCount($conn, $userId) >= $dailyLimit) {
    appJson([
        'ok' => false,
        'message' => 'Has alcanzado el límite diario de consultas a ELÍAS. Podrás volver a preguntar mañana.'
    ], 429);
}

$monthlyBudget = (float)$usageSettings['monthly_budget_eur'];
if ((int)$usageSettings['block_on_budget'] === 1 && $monthlyBudget > 0) {
    $monthUsage = aiCurrentMonthUsage($conn, $usageSettings);
    if ((float)$monthUsage['estimated_cost_eur'] >= $monthlyBudget) {
        appJson([
            'ok' => false,
            'message' => 'ELÍAS ha alcanzado el límite mensual configurado. Contacta con administración.'
        ], 429);
    }
}

$conversation = $conversationId > 0 ? aiConversationForUser($conn, $conversationId, $userId) : null;
if ($conversation && (int)$conversation['brand_id'] !== $brandId) $conversation = null;

if (!$conversation) {
    $title = aiConversationTitle($question) ?: 'Nueva conversación';
    $stmt = $conn->prepare('INSERT INTO ai_conversations (brand_id, user_id, title) VALUES (?, ?, ?)');
    if (!$stmt) appJson(['ok' => false, 'message' => appPublicError()], 500);
    $stmt->bind_param('iis', $brandId, $userId, $title);
    $stmt->execute();
    $conversationId = (int)$stmt->insert_id;
    $conversationTitle = $title;
} else {
    $conversationId = (int)$conversation['id'];
    $conversationTitle = (string)$conversation['title'];
}

$stmt = $conn->prepare(
    "SELECT role, content FROM ai_messages WHERE conversation_id = ? AND status = 'completed'
     ORDER BY id DESC LIMIT 10"
);
$history = [];
if ($stmt) {
    $stmt->bind_param('i', $conversationId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach (array_reverse($rows) as $row) $history[] = ['role' => $row['role'], 'content' => $row['content']];
}

$stmt = $conn->prepare("INSERT INTO ai_messages (conversation_id, user_id, role, content, client_request_id) VALUES (?, ?, 'user', ?, ?)");
if (!$stmt) appJson(['ok' => false, 'message' => appPublicError()], 500);
$stmt->bind_param('iiss', $conversationId, $userId, $question, $userRequestId);
if (!$stmt->execute()) appJson(['ok' => false, 'message' => appPublicError()], 500);

$brand = aiGetBrand($conn, $brandId);
$started = microtime(true);
$vectorStoreId = trim((string)($brand['vector_store_id'] ?? ''));
$catalogAnswer = null;
try {
    $catalogAnswer = aiPriceCatalogAnswer($conn, $brandId, $question);
} catch (Throwable $error) {
    appLogError('No se pudo consultar el catálogo local de ELÍAS', $error);
}
if (is_array($catalogAnswer)) {
    $service = $catalogAnswer + ['ok' => true];
} elseif ($vectorStoreId === '') {
    $service = [
        'ok' => true,
        'answer' => 'No encuentro información suficiente en la documentación disponible para responder con seguridad.',
        'answer_type' => 'insufficient',
        'project' => null,
        'citations' => [],
        'retrieval' => [],
        'model' => null,
        'response_id' => null,
        'usage' => []
    ];
} else {
    $service = aiServiceRequest('/v1/query', [
        'brand_id' => $brandId,
        'brand_name' => (string)($brand['name'] ?? ''),
        'vector_store_id' => $vectorStoreId,
        'instructions' => (string)($brand['system_instructions'] ?? ''),
        'question' => $question,
        'history' => $history
    ]);
}
$latency = (int)round((microtime(true) - $started) * 1000);

if (empty($service['ok']) || !isset($service['answer'])) {
    appLogError('El asistente no pudo responder', $service['internal_error'] ?? json_encode($service));
    $errorText = 'Ahora mismo no puedo responder. Inténtalo de nuevo en unos minutos.';
    $errorModel = trim((string)($service['response']['model'] ?? $service['model'] ?? ''));
    $stmt = $conn->prepare("INSERT INTO ai_messages (conversation_id, role, content, status, client_request_id, model, latency_ms) VALUES (?, 'assistant', ?, 'error', ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('isssi', $conversationId, $errorText, $assistantRequestId, $errorModel, $latency);
        $stmt->execute();
    }
    appJson(['ok' => false, 'message' => $errorText, 'conversation_id' => $conversationId], 502);
}

$answer = trim((string)$service['answer']);
$citations = is_array($service['citations'] ?? null) ? $service['citations'] : [];
$retrieval = is_array($service['retrieval'] ?? null) ? $service['retrieval'] : [];
$answerType = in_array($service['answer_type'] ?? '', ['answer', 'clarify', 'insufficient'], true) ? (string)$service['answer_type'] : 'insufficient';
$project = is_array($service['project'] ?? null) ? $service['project'] : null;
$citationsJson = json_encode($citations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
$retrievalJson = json_encode($retrieval, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
$projectJson = $project === null ? null : (json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null);
$model = (string)($service['model'] ?? '');
$responseId = (string)($service['response_id'] ?? '');
$inputTokens = (int)($service['usage']['input_tokens'] ?? 0);
$outputTokens = (int)($service['usage']['output_tokens'] ?? 0);

$sql = $hasProjectColumns
    ? "INSERT INTO ai_messages
       (conversation_id, role, content, client_request_id, model, response_id, latency_ms, input_tokens, output_tokens, citations_json, retrieval_json, answer_type, project_json)
       VALUES (?, 'assistant', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    : "INSERT INTO ai_messages
       (conversation_id, role, content, client_request_id, model, response_id, latency_ms, input_tokens, output_tokens, citations_json, retrieval_json)
       VALUES (?, 'assistant', ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
if (!$stmt) appJson(['ok' => false, 'message' => appPublicError()], 500);
if ($hasProjectColumns) {
    $stmt->bind_param('issssiiissss', $conversationId, $answer, $assistantRequestId, $model, $responseId, $latency, $inputTokens, $outputTokens, $citationsJson, $retrievalJson, $answerType, $projectJson);
} else {
    $stmt->bind_param('issssiiiss', $conversationId, $answer, $assistantRequestId, $model, $responseId, $latency, $inputTokens, $outputTokens, $citationsJson, $retrievalJson);
}
$stmt->execute();
$messageId = (int)$stmt->insert_id;

if (!empty($project['is_project']) && aiTableExists($conn, 'ai_project_briefs')) {
    $requirementsJson = json_encode([
        'requirements' => $project['requirements'] ?? [],
        'bom' => $project['bom'] ?? [],
        'copy_list' => $project['copy_list'] ?? [],
        'open_items' => $project['open_items'] ?? [],
        'diagram_ready' => false
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    $assumptionsJson = json_encode($project['assumptions'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    $completenessStatus = in_array($project['status'] ?? '', ['clarification_needed', 'preliminary_proposal'], true)
        ? (string)$project['status'] : 'draft';
    $briefStmt = $conn->prepare(
        'INSERT INTO ai_project_briefs (conversation_id, brand_id, user_id, title, requirements_json, assumptions_json, completeness_status)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE title = VALUES(title), requirements_json = VALUES(requirements_json), assumptions_json = VALUES(assumptions_json), completeness_status = VALUES(completeness_status), updated_at = CURRENT_TIMESTAMP'
    );
    if ($briefStmt) {
        $briefStmt->bind_param('iiissss', $conversationId, $brandId, $userId, $conversationTitle, $requirementsJson, $assumptionsJson, $completenessStatus);
        $briefStmt->execute();
    }
}

$stmt = $conn->prepare('UPDATE ai_conversations SET last_message_at = CURRENT_TIMESTAMP WHERE id = ?');
if ($stmt) { $stmt->bind_param('i', $conversationId); $stmt->execute(); }

appJson([
    'ok' => true,
    'conversation_id' => $conversationId,
    'message_id' => $messageId,
    'answer' => $answer,
    'answer_type' => $answerType,
    'project' => $project,
    'citations' => $citations
]);

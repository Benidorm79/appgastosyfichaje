<?php
declare(strict_types=1);

require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/chat.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    chatJsonResponse(['ok' => false, 'message' => 'La operación no está disponible.'], 405);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$conversationId = (int)($_POST['conversation_id'] ?? 0);
$token = (string)($_POST['csrf_token'] ?? '');

if ($userId <= 0) chatJsonResponse(['ok' => false, 'message' => 'Tu sesión ha caducado.'], 401);
if (!chatCsrfValid($token)) chatJsonResponse(['ok' => false, 'message' => 'Recarga la página e inténtalo de nuevo.'], 403);
if ($conversationId <= 0 || !chatHideConversationForUser($conn, $conversationId, $userId)) {
    chatJsonResponse(['ok' => false, 'message' => 'No se pudo eliminar la conversación.'], 400);
}

chatJsonResponse([
    'ok' => true,
    'message' => 'Conversación eliminada.',
    'unread' => chatUnreadCount($conn, $userId)
]);

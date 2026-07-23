<?php
define('SESSION_NO_ACTIVITY_TOUCH', true);
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/chat.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) chatJsonResponse(['ok'=>false,'unread'=>0], 401);
if (!chatTablesReady($conn)) chatJsonResponse(['ok'=>true,'ready'=>false,'unread'=>0]);

chatMarkDelivered($conn, $userId);
$latest = chatLatestUnread($conn, $userId);
chatJsonResponse([
  'ok' => true,
  'ready' => true,
  'unread' => chatUnreadCount($conn, $userId),
  'latest' => $latest
]);

<?php
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/chat.php';
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) chatJsonResponse(['ok'=>false,'message'=>'Sesión no válida.'],401);
if (!chatTablesReady($conn)) chatJsonResponse(['ok'=>false,'ready'=>false,'message'=>'Falta instalar el chat interno.']);
chatMarkDelivered($conn,$userId);
chatJsonResponse(['ok'=>true,'ready'=>true,'conversations'=>chatConversationList($conn,$userId),'unread'=>chatUnreadCount($conn,$userId)]);

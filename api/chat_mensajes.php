<?php
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/chat.php';
$userId=(int)($_SESSION['user_id']??0);
$conversationId=(int)($_GET['conversation_id']??0);
$afterId=(int)($_GET['after_id']??0);
if($userId<=0)chatJsonResponse(['ok'=>false,'message'=>'Sesión no válida.'],401);
if(!chatTablesReady($conn))chatJsonResponse(['ok'=>false,'message'=>'Falta instalar el chat interno.']);
$conversation=chatConversation($conn,$conversationId,$userId);
if(!$conversation)chatJsonResponse(['ok'=>false,'message'=>'No tienes acceso a esta conversación.'],403);
chatMarkDelivered($conn,$userId);
chatMarkRead($conn,$conversationId,$userId);
chatJsonResponse([
  'ok'=>true,
  'conversation'=>$conversation,
  'messages'=>chatMessages($conn,$conversationId,$userId,$afterId),
  'unread'=>chatUnreadCount($conn,$userId)
]);

<?php
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/chat.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')chatJsonResponse(['ok'=>false,'message'=>'Método no permitido.'],405);
$userId=(int)($_SESSION['user_id']??0);
$token=(string)($_POST['csrf_token']??'');
$targetId=(int)($_POST['target_user_id']??0);
if($userId<=0)chatJsonResponse(['ok'=>false,'message'=>'Sesión no válida.'],401);
if(!chatCsrfValid($token))chatJsonResponse(['ok'=>false,'message'=>'La sesión del chat ha caducado.'],403);
$id=chatEnsureDirect($conn,$userId,$targetId);
if($id<=0)chatJsonResponse(['ok'=>false,'message'=>'No se pudo abrir la conversación.'],400);
chatJsonResponse(['ok'=>true,'conversation_id'=>$id]);

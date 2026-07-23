<?php
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/chat.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')chatJsonResponse(['ok'=>false,'message'=>'Método no permitido.'],405);
$userId=(int)($_SESSION['user_id']??0);
$role=(string)($_SESSION['role']??'user');
$token=(string)($_POST['csrf_token']??'');
if($userId<=0)chatJsonResponse(['ok'=>false,'message'=>'Sesión no válida.'],401);
if(!in_array($role,['admin','master'],true))chatJsonResponse(['ok'=>false,'message'=>'Solo Admin o Máster pueden crear grupos.'],403);
if(!chatCsrfValid($token))chatJsonResponse(['ok'=>false,'message'=>'La sesión del chat ha caducado.'],403);
$name=(string)($_POST['name']??'');
$members=$_POST['members']??[];
if(!is_array($members))$members=[];
$id=chatCreateGroup($conn,$userId,$name,$members);
if($id<=0)chatJsonResponse(['ok'=>false,'message'=>'Indica un nombre y selecciona al menos otro usuario.'],400);
chatJsonResponse(['ok'=>true,'conversation_id'=>$id]);

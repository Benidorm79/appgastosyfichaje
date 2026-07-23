<?php
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/chat.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')chatJsonResponse(['ok'=>false,'message'=>'Método no permitido.'],405);
$userId=(int)($_SESSION['user_id']??0);
$conversationId=(int)($_POST['conversation_id']??0);
$token=(string)($_POST['csrf_token']??'');
$message=(string)($_POST['message']??'');
if($userId<=0)chatJsonResponse(['ok'=>false,'message'=>'Sesión no válida.'],401);
if(!chatCsrfValid($token))chatJsonResponse(['ok'=>false,'message'=>'La sesión del chat ha caducado.'],403);
if(!chatIsMember($conn,$conversationId,$userId))chatJsonResponse(['ok'=>false,'message'=>'No tienes acceso a esta conversación.'],403);

$uploads=[];
if(isset($_FILES['attachments']) && is_array($_FILES['attachments']['name']??null)){
  $count=count($_FILES['attachments']['name']);
  if($count>5)chatJsonResponse(['ok'=>false,'message'=>'Puedes adjuntar un máximo de 5 archivos por envío.'],400);
  for($i=0;$i<$count;$i++){
    if((int)($_FILES['attachments']['error'][$i]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
    $uploads[]=[
      'name'=>$_FILES['attachments']['name'][$i]??'',
      'type'=>$_FILES['attachments']['type'][$i]??'',
      'tmp_name'=>$_FILES['attachments']['tmp_name'][$i]??'',
      'error'=>$_FILES['attachments']['error'][$i]??UPLOAD_ERR_NO_FILE,
      'size'=>$_FILES['attachments']['size'][$i]??0
    ];
  }
} elseif(isset($_FILES['attachments']) && (int)($_FILES['attachments']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
  $uploads[]=$_FILES['attachments'];
}
if(trim($message)==='' && !$uploads)chatJsonResponse(['ok'=>false,'message'=>'Escribe un mensaje o adjunta un archivo.'],400);

$created=[];
try{
  if(trim($message)!==''){
    $id=chatInsertMessage($conn,$conversationId,$userId,'texto',$message,null);
    if($id<=0)throw new RuntimeException('No se pudo guardar el mensaje.');
    $created[]=$id;
  }
  foreach($uploads as $upload){
    $stored=chatStoreUploadedFile($upload);
    $id=chatInsertMessage($conn,$conversationId,$userId,$stored['type'],'',$stored);
    if($id<=0){
      $absolute=dirname(__DIR__).'/'.$stored['path'];
      if(is_file($absolute))@unlink($absolute);
      throw new RuntimeException('No se pudo guardar uno de los archivos.');
    }
    $created[]=$id;
  }
}catch(Throwable $e){
  chatJsonResponse(['ok'=>false,'message'=>$e->getMessage()],400);
}
chatJsonResponse(['ok'=>true,'message_ids'=>$created,'messages'=>chatMessages($conn,$conversationId,$userId,max(0,min($created)-1))]);

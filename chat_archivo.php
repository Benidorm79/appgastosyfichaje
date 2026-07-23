<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/chat.php';
$userId=(int)($_SESSION['user_id']??0);
$token=trim((string)($_GET['token']??''));
$file=chatFindAttachmentByToken($conn,$token,$userId);
if(!$file){http_response_code(404);exit('Archivo no encontrado.');}
$relative=(string)($file['archivo_path']??'');
$base=realpath(__DIR__.'/storage/chat');
$absolute=realpath(__DIR__.'/'.$relative);
if(!$base||!$absolute||!str_starts_with($absolute,$base.DIRECTORY_SEPARATOR)||!is_file($absolute)){http_response_code(404);exit('Archivo no encontrado.');}
$mime=(string)($file['archivo_mime']?:'application/octet-stream');
$name=(string)($file['archivo_nombre']?:'archivo');
$inline=str_starts_with($mime,'image/')||str_starts_with($mime,'video/')||str_starts_with($mime,'audio/')||$mime==='application/pdf';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($absolute));
header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.str_replace(['"','\r','\n'],'_',$name).'"; filename*=UTF-8\'\''.rawurlencode($name));
readfile($absolute);
exit;

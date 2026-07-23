<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';

$params = ['assistant' => '1'];
$brandId = (int)($_GET['brand_id'] ?? 0);
$conversationId = (int)($_GET['conversation_id'] ?? 0);
if ($brandId > 0) $params['brand_id'] = (string)$brandId;
if ($conversationId > 0) $params['assistant_conversation_id'] = (string)$conversationId;

header('Location: mensajeria.php?' . http_build_query($params), true, 302);
exit;

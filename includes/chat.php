<?php

function chatNow(): string {
  $tz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');
  return (new DateTime('now', $tz))->format('Y-m-d H:i:s');
}

function chatTableExists($conn, string $table): bool {
  static $cache = [];
  if (!$conn) return false;
  if (array_key_exists($table, $cache)) return $cache[$table];
  try {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    $cache[$table] = $result && $result->num_rows > 0;
  } catch (Throwable $e) {
    $cache[$table] = false;
  }
  return $cache[$table];
}

function chatTablesReady($conn): bool {
  foreach (['chat_conversaciones','chat_miembros','chat_mensajes','chat_recepciones'] as $table) {
    if (!chatTableExists($conn, $table)) return false;
  }
  return true;
}

function chatCsrfToken(): string {
  if (empty($_SESSION['chat_csrf'])) {
    $_SESSION['chat_csrf'] = bin2hex(random_bytes(32));
  }
  return (string)$_SESSION['chat_csrf'];
}

function chatCsrfValid(string $token): bool {
  return !empty($_SESSION['chat_csrf']) && hash_equals((string)$_SESSION['chat_csrf'], $token);
}

function chatJsonResponse(array $payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function chatUserIsActive($conn, int $userId): bool {
  if ($userId <= 0) return false;
  try {
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND activo = 1 LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result && $result->num_rows > 0;
  } catch (Throwable $e) {
    return false;
  }
}

function chatUsers($conn, int $excludeUserId = 0): array {
  $rows = [];
  try {
    $sql = "SELECT id, username, comercial, role FROM users WHERE activo = 1";
    if ($excludeUserId > 0) $sql .= " AND id <> ?";
    $sql .= " ORDER BY comercial ASC, username ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $rows;
    if ($excludeUserId > 0) $stmt->bind_param('i', $excludeUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && ($row = $result->fetch_assoc())) $rows[] = $row;
  } catch (Throwable $e) {
    return [];
  }
  return $rows;
}

function chatIsMember($conn, int $conversationId, int $userId): bool {
  if ($conversationId <= 0 || $userId <= 0 || !chatTablesReady($conn)) return false;
  try {
    $stmt = $conn->prepare("SELECT 1 FROM chat_miembros cm INNER JOIN chat_conversaciones cc ON cc.id = cm.conversacion_id WHERE cm.conversacion_id = ? AND cm.user_id = ? AND cm.activo = 1 AND cc.activo = 1 LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $conversationId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result && $result->num_rows > 0;
  } catch (Throwable $e) {
    return false;
  }
}

function chatConversation($conn, int $conversationId, int $userId): ?array {
  if (!chatIsMember($conn, $conversationId, $userId)) return null;
  try {
    $stmt = $conn->prepare("SELECT * FROM chat_conversaciones WHERE id = ? AND activo = 1 LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $conversationId);
    $stmt->execute();
    $result = $stmt->get_result();
    $conversation = $result ? $result->fetch_assoc() : null;
    if (!$conversation) return null;

    $members = [];
    $memberFilter = $conversation['tipo'] === 'directo' ? '' : ' AND cm.activo = 1';
    $stmt = $conn->prepare("SELECT cm.user_id, cm.rol, u.username, u.comercial, u.role FROM chat_miembros cm INNER JOIN users u ON u.id = cm.user_id WHERE cm.conversacion_id = ?{$memberFilter} ORDER BY u.comercial ASC, u.username ASC");
    if ($stmt) {
      $stmt->bind_param('i', $conversationId);
      $stmt->execute();
      $result = $stmt->get_result();
      while ($result && ($row = $result->fetch_assoc())) $members[] = $row;
    }
    $conversation['members'] = $members;
    if ($conversation['tipo'] === 'directo') {
      foreach ($members as $member) {
        if ((int)$member['user_id'] !== $userId) {
          $conversation['display_name'] = trim((string)($member['comercial'] ?: $member['username']));
          $conversation['other_user_id'] = (int)$member['user_id'];
          break;
        }
      }
      if (empty($conversation['display_name'])) $conversation['display_name'] = 'Conversación';
    } else {
      $conversation['display_name'] = trim((string)$conversation['nombre']) ?: 'Grupo';
    }
    return $conversation;
  } catch (Throwable $e) {
    return null;
  }
}

function chatUnreadCount($conn, int $userId): int {
  if ($userId <= 0 || !chatTablesReady($conn)) return 0;
  try {
    $stmt = $conn->prepare("SELECT COUNT(*) total FROM chat_recepciones cr INNER JOIN chat_mensajes msg ON msg.id = cr.mensaje_id INNER JOIN chat_conversaciones cc ON cc.id = msg.conversacion_id INNER JOIN chat_miembros mship ON mship.conversacion_id = cc.id AND mship.user_id = cr.user_id AND mship.activo = 1 WHERE cr.user_id = ? AND cr.read_at IS NULL AND msg.deleted_at IS NULL AND cc.activo = 1");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    return (int)($row['total'] ?? 0);
  } catch (Throwable $e) {
    return 0;
  }
}

function chatMarkDelivered($conn, int $userId): void {
  if ($userId <= 0 || !chatTablesReady($conn)) return;
  try {
    $now = chatNow();
    $stmt = $conn->prepare("UPDATE chat_recepciones cr INNER JOIN chat_mensajes msg ON msg.id = cr.mensaje_id INNER JOIN chat_miembros mship ON mship.conversacion_id = msg.conversacion_id AND mship.user_id = cr.user_id AND mship.activo = 1 SET cr.delivered_at = ? WHERE cr.user_id = ? AND cr.delivered_at IS NULL");
    if ($stmt) {
      $stmt->bind_param('si', $now, $userId);
      $stmt->execute();
    }
  } catch (Throwable $e) {
  }
}

function chatMarkRead($conn, int $conversationId, int $userId): void {
  if (!chatIsMember($conn, $conversationId, $userId)) return;
  try {
    $now = chatNow();
    $stmt = $conn->prepare("UPDATE chat_recepciones cr INNER JOIN chat_mensajes cm ON cm.id = cr.mensaje_id SET cr.delivered_at = COALESCE(cr.delivered_at, ?), cr.read_at = ? WHERE cr.user_id = ? AND cm.conversacion_id = ? AND cr.read_at IS NULL");
    if ($stmt) {
      $stmt->bind_param('ssii', $now, $now, $userId, $conversationId);
      $stmt->execute();
    }
  } catch (Throwable $e) {
  }
}

function chatLatestUnread($conn, int $userId): ?array {
  if ($userId <= 0 || !chatTablesReady($conn)) return null;
  try {
    $stmt = $conn->prepare("SELECT msg.id, msg.conversacion_id, msg.mensaje, msg.tipo, msg.created_at, u.comercial, u.username FROM chat_recepciones cr INNER JOIN chat_mensajes msg ON msg.id = cr.mensaje_id INNER JOIN users u ON u.id = msg.remitente_id INNER JOIN chat_miembros mship ON mship.conversacion_id = msg.conversacion_id AND mship.user_id = cr.user_id AND mship.activo = 1 WHERE cr.user_id = ? AND cr.read_at IS NULL AND msg.deleted_at IS NULL ORDER BY msg.id DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if (!$row) return null;
    $row['sender_name'] = trim((string)($row['comercial'] ?: $row['username']));
    return $row;
  } catch (Throwable $e) {
    return null;
  }
}

function chatConversationList($conn, int $userId): array {
  $rows = [];
  if ($userId <= 0 || !chatTablesReady($conn)) return $rows;
  try {
    $stmt = $conn->prepare("SELECT cc.* FROM chat_miembros cm INNER JOIN chat_conversaciones cc ON cc.id = cm.conversacion_id WHERE cm.user_id = ? AND cm.activo = 1 AND cc.activo = 1 ORDER BY COALESCE(cc.updated_at, cc.created_at) DESC, cc.id DESC");
    if (!$stmt) return $rows;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && ($conversation = $result->fetch_assoc())) {
      $id = (int)$conversation['id'];
      $displayName = trim((string)$conversation['nombre']);
      $memberCount = 0;
      $otherUserId = 0;
      $memberFilter = $conversation['tipo'] === 'directo' ? '' : ' AND cm.activo = 1';
      $stmtMembers = $conn->prepare("SELECT cm.user_id, u.username, u.comercial FROM chat_miembros cm INNER JOIN users u ON u.id = cm.user_id WHERE cm.conversacion_id = ?{$memberFilter} ORDER BY u.comercial ASC");
      if ($stmtMembers) {
        $stmtMembers->bind_param('i', $id);
        $stmtMembers->execute();
        $membersResult = $stmtMembers->get_result();
        while ($membersResult && ($member = $membersResult->fetch_assoc())) {
          $memberCount++;
          if ($conversation['tipo'] === 'directo' && (int)$member['user_id'] !== $userId) {
            $displayName = trim((string)($member['comercial'] ?: $member['username']));
            $otherUserId = (int)$member['user_id'];
          }
        }
      }
      if ($displayName === '') $displayName = $conversation['tipo'] === 'grupo' ? 'Grupo' : 'Conversación';

      $last = null;
      $stmtLast = $conn->prepare("SELECT cm.id, cm.tipo, cm.mensaje, cm.archivo_nombre, cm.created_at, cm.remitente_id, u.comercial, u.username FROM chat_mensajes cm INNER JOIN users u ON u.id = cm.remitente_id WHERE cm.conversacion_id = ? AND cm.deleted_at IS NULL ORDER BY cm.id DESC LIMIT 1");
      if ($stmtLast) {
        $stmtLast->bind_param('i', $id);
        $stmtLast->execute();
        $lastResult = $stmtLast->get_result();
        $last = $lastResult ? $lastResult->fetch_assoc() : null;
      }

      $unread = 0;
      $stmtUnread = $conn->prepare("SELECT COUNT(*) total FROM chat_recepciones cr INNER JOIN chat_mensajes cm ON cm.id = cr.mensaje_id WHERE cm.conversacion_id = ? AND cr.user_id = ? AND cr.read_at IS NULL AND cm.deleted_at IS NULL");
      if ($stmtUnread) {
        $stmtUnread->bind_param('ii', $id, $userId);
        $stmtUnread->execute();
        $unreadResult = $stmtUnread->get_result();
        $unreadRow = $unreadResult ? $unreadResult->fetch_assoc() : null;
        $unread = (int)($unreadRow['total'] ?? 0);
      }

      $preview = 'Sin mensajes todavía';
      if ($last) {
        if (trim((string)$last['mensaje']) !== '') $preview = trim((string)$last['mensaje']);
        elseif (trim((string)$last['archivo_nombre']) !== '') $preview = '📎 ' . trim((string)$last['archivo_nombre']);
        else $preview = 'Nuevo mensaje';
      }
      $rows[] = [
        'id' => $id,
        'tipo' => (string)$conversation['tipo'],
        'name' => $displayName,
        'other_user_id' => $otherUserId,
        'member_count' => $memberCount,
        'preview' => mb_substr($preview, 0, 90, 'UTF-8'),
        'last_at' => $last['created_at'] ?? $conversation['created_at'],
        'unread' => $unread
      ];
    }
  } catch (Throwable $e) {
    return [];
  }
  usort($rows, static function ($a, $b) {
    return strcmp((string)$b['last_at'], (string)$a['last_at']);
  });
  return $rows;
}

function chatEnsureDirect($conn, int $userId, int $targetUserId): int {
  if ($userId <= 0 || $targetUserId <= 0 || $userId === $targetUserId || !chatTablesReady($conn)) return 0;
  if (!chatUserIsActive($conn, $targetUserId)) return 0;
  $ids = [$userId, $targetUserId];
  sort($ids, SORT_NUMERIC);
  $key = $ids[0] . ':' . $ids[1];
  try {
    $stmt = $conn->prepare("SELECT id FROM chat_conversaciones WHERE clave_directa = ? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param('s', $key);
      $stmt->execute();
      $result = $stmt->get_result();
      if ($result && ($row = $result->fetch_assoc())) {
        $conversationId = (int)$row['id'];
        $now = chatNow();
        $reactivate = $conn->prepare("INSERT INTO chat_miembros (conversacion_id, user_id, rol, activo, joined_at, updated_at) VALUES (?, ?, 'miembro', 1, ?, ?) ON DUPLICATE KEY UPDATE activo = 1, updated_at = VALUES(updated_at)");
        if ($reactivate) {
          $reactivate->bind_param('iiss', $conversationId, $userId, $now, $now);
          $reactivate->execute();
        }
        $activateConversation = $conn->prepare('UPDATE chat_conversaciones SET activo = 1, updated_at = ? WHERE id = ?');
        if ($activateConversation) {
          $activateConversation->bind_param('si', $now, $conversationId);
          $activateConversation->execute();
        }
        return $conversationId;
      }
    }

    $now = chatNow();
    $conn->begin_transaction();
    $stmt = $conn->prepare("INSERT INTO chat_conversaciones (tipo, nombre, clave_directa, creado_por, activo, created_at, updated_at) VALUES ('directo', NULL, ?, ?, 1, ?, ?)");
    if (!$stmt) throw new Exception('No se pudo crear la conversación.');
    $stmt->bind_param('siss', $key, $userId, $now, $now);
    $stmt->execute();
    $conversationId = (int)$conn->insert_id;
    $stmt = $conn->prepare("INSERT INTO chat_miembros (conversacion_id, user_id, rol, activo, joined_at, updated_at) VALUES (?, ?, 'miembro', 1, ?, ?)");
    if (!$stmt) throw new Exception('No se pudieron añadir los miembros.');
    foreach ($ids as $memberId) {
      $stmt->bind_param('iiss', $conversationId, $memberId, $now, $now);
      $stmt->execute();
    }
    $conn->commit();
    return $conversationId;
  } catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    try {
      $stmt = $conn->prepare("SELECT id FROM chat_conversaciones WHERE clave_directa = ? LIMIT 1");
      if ($stmt) {
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && ($row = $result->fetch_assoc())) return (int)$row['id'];
      }
    } catch (Throwable $ignored) {}
    return 0;
  }
}

function chatCreateGroup($conn, int $creatorId, string $name, array $memberIds): int {
  $name = mb_substr(trim($name), 0, 150, 'UTF-8');
  if ($creatorId <= 0 || $name === '' || !chatTablesReady($conn)) return 0;
  $memberIds[] = $creatorId;
  $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn($id) => $id > 0)));
  if (count($memberIds) < 2) return 0;
  try {
    $now = chatNow();
    $conn->begin_transaction();
    $stmt = $conn->prepare("INSERT INTO chat_conversaciones (tipo, nombre, clave_directa, creado_por, activo, created_at, updated_at) VALUES ('grupo', ?, NULL, ?, 1, ?, ?)");
    if (!$stmt) throw new Exception('No se pudo crear el grupo.');
    $stmt->bind_param('siss', $name, $creatorId, $now, $now);
    $stmt->execute();
    $conversationId = (int)$conn->insert_id;
    $stmt = $conn->prepare("INSERT INTO chat_miembros (conversacion_id, user_id, rol, activo, joined_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)");
    if (!$stmt) throw new Exception('No se pudieron añadir los miembros.');
    foreach ($memberIds as $memberId) {
      if (!chatUserIsActive($conn, $memberId)) continue;
      $memberRole = $memberId === $creatorId ? 'administrador' : 'miembro';
      $stmt->bind_param('iisss', $conversationId, $memberId, $memberRole, $now, $now);
      $stmt->execute();
    }
    $conn->commit();
    return $conversationId;
  } catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    return 0;
  }
}

function chatHideConversationForUser($conn, int $conversationId, int $userId): bool {
  if (!chatIsMember($conn, $conversationId, $userId)) return false;
  try {
    $now = chatNow();
    $conn->begin_transaction();
    $stmt = $conn->prepare('UPDATE chat_miembros SET activo = 0, updated_at = ? WHERE conversacion_id = ? AND user_id = ?');
    if (!$stmt) throw new RuntimeException('No se pudo eliminar la conversación.');
    $stmt->bind_param('sii', $now, $conversationId, $userId);
    $stmt->execute();
    $stmt = $conn->prepare('UPDATE chat_recepciones cr INNER JOIN chat_mensajes msg ON msg.id = cr.mensaje_id SET cr.delivered_at = COALESCE(cr.delivered_at, ?), cr.read_at = COALESCE(cr.read_at, ?) WHERE cr.user_id = ? AND msg.conversacion_id = ?');
    if ($stmt) {
      $stmt->bind_param('ssii', $now, $now, $userId, $conversationId);
      $stmt->execute();
    }
    $conn->commit();
    return true;
  } catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    return false;
  }
}

function chatMessages($conn, int $conversationId, int $userId, int $afterId = 0, int $limit = 150): array {
  $rows = [];
  if (!chatIsMember($conn, $conversationId, $userId)) return $rows;
  $limit = max(1, min(250, $limit));
  $reverseRows = $afterId <= 0;
  try {
    $sql = "SELECT cm.*, u.username, u.comercial FROM chat_mensajes cm INNER JOIN users u ON u.id = cm.remitente_id WHERE cm.conversacion_id = ? AND cm.deleted_at IS NULL";
    if ($afterId > 0) $sql .= " AND cm.id > ?";
    $sql .= $afterId > 0 ? " ORDER BY cm.id ASC LIMIT {$limit}" : " ORDER BY cm.id DESC LIMIT {$limit}";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $rows;
    if ($afterId > 0) $stmt->bind_param('ii', $conversationId, $afterId);
    else $stmt->bind_param('i', $conversationId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && ($row = $result->fetch_assoc())) {
      $totalRecipients = 0;
      $delivered = 0;
      $read = 0;
      $stmtReceipt = $conn->prepare("SELECT COUNT(*) total, SUM(delivered_at IS NOT NULL) delivered, SUM(read_at IS NOT NULL) read_count FROM chat_recepciones WHERE mensaje_id = ?");
      if ($stmtReceipt) {
        $messageId = (int)$row['id'];
        $stmtReceipt->bind_param('i', $messageId);
        $stmtReceipt->execute();
        $receiptResult = $stmtReceipt->get_result();
        $receipt = $receiptResult ? $receiptResult->fetch_assoc() : null;
        $totalRecipients = (int)($receipt['total'] ?? 0);
        $delivered = (int)($receipt['delivered'] ?? 0);
        $read = (int)($receipt['read_count'] ?? 0);
      }
      $status = 'sent';
      if ($totalRecipients > 0 && $read >= $totalRecipients) $status = 'read';
      elseif ($totalRecipients > 0 && $delivered >= $totalRecipients) $status = 'delivered';
      $row['sender_name'] = trim((string)($row['comercial'] ?: $row['username']));
      $row['is_mine'] = (int)$row['remitente_id'] === $userId;
      $row['receipt_status'] = $status;
      $row['attachment_url'] = $row['archivo_token'] ? 'mensajeria_archivo.php?token=' . rawurlencode((string)$row['archivo_token']) : '';
      unset($row['archivo_path']);
      $rows[] = $row;
    }
  } catch (Throwable $e) {
    return [];
  }
  return $reverseRows ? array_reverse($rows) : $rows;
}

function chatCreateReceipts($conn, int $messageId, int $conversationId, int $senderId): void {
  $stmt = $conn->prepare("SELECT user_id FROM chat_miembros WHERE conversacion_id = ? AND activo = 1 AND user_id <> ?");
  if (!$stmt) return;
  $stmt->bind_param('ii', $conversationId, $senderId);
  $stmt->execute();
  $result = $stmt->get_result();
  $insert = $conn->prepare("INSERT IGNORE INTO chat_recepciones (mensaje_id, user_id, delivered_at, read_at) VALUES (?, ?, NULL, NULL)");
  if (!$insert) return;
  while ($result && ($row = $result->fetch_assoc())) {
    $recipientId = (int)$row['user_id'];
    $insert->bind_param('ii', $messageId, $recipientId);
    $insert->execute();
  }
}

function chatInsertMessage($conn, int $conversationId, int $senderId, string $type, string $message = '', ?array $file = null): int {
  if (!chatIsMember($conn, $conversationId, $senderId)) return 0;
  $message = mb_substr(trim($message), 0, 6000, 'UTF-8');
  $allowedTypes = ['texto','imagen','video','audio','archivo'];
  if (!in_array($type, $allowedTypes, true)) $type = 'texto';
  if ($message === '' && !$file) return 0;
  try {
    $now = chatNow();
    $token = $file['token'] ?? null;
    $path = $file['path'] ?? null;
    $name = $file['name'] ?? null;
    $mime = $file['mime'] ?? null;
    $bytes = isset($file['bytes']) ? (int)$file['bytes'] : null;
    $conn->begin_transaction();
    $stmt = $conn->prepare("UPDATE chat_miembros mship INNER JOIN chat_conversaciones cc ON cc.id = mship.conversacion_id SET mship.activo = 1, mship.updated_at = ? WHERE mship.conversacion_id = ? AND mship.user_id <> ? AND cc.tipo = 'directo'");
    if ($stmt) {
      $stmt->bind_param('sii', $now, $conversationId, $senderId);
      $stmt->execute();
    }
    $stmt = $conn->prepare("INSERT INTO chat_mensajes (conversacion_id, remitente_id, tipo, mensaje, archivo_token, archivo_path, archivo_nombre, archivo_mime, archivo_bytes, created_at) VALUES (?, ?, ?, NULLIF(?,''), ?, ?, ?, ?, ?, ?)");
    if (!$stmt) throw new Exception('No se pudo preparar el mensaje.');
    $stmt->bind_param('iissssssis', $conversationId, $senderId, $type, $message, $token, $path, $name, $mime, $bytes, $now);
    $stmt->execute();
    $messageId = (int)$conn->insert_id;
    chatCreateReceipts($conn, $messageId, $conversationId, $senderId);
    $stmt = $conn->prepare("UPDATE chat_conversaciones SET updated_at = ? WHERE id = ?");
    if ($stmt) {
      $stmt->bind_param('si', $now, $conversationId);
      $stmt->execute();
    }
    $conn->commit();
    return $messageId;
  } catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    return 0;
  }
}

function chatBlockedExtension(string $extension): bool {
  return in_array(strtolower($extension), [
    'php','php3','php4','php5','phtml','phar','cgi','pl','py','rb','sh','bash','bat','cmd','com','exe','msi','dll','js','mjs','html','htm','xhtml','svg','shtml','htaccess','ini'
  ], true);
}

function chatStoreUploadedFile(array $upload): array {
  if (!isset($upload['error']) || (int)$upload['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException('No se pudo recibir el archivo.');
  }
  $maxBytes = defined('CHAT_MAX_FILE_BYTES') ? (int)CHAT_MAX_FILE_BYTES : 20971520;
  $size = (int)($upload['size'] ?? 0);
  if ($size <= 0 || $size > $maxBytes) {
    throw new RuntimeException('El archivo supera el tamaño máximo permitido.');
  }
  $originalName = trim((string)($upload['name'] ?? 'archivo'));
  $originalName = preg_replace('/[\x00-\x1F\x7F\\\/]+/u', '_', $originalName) ?: 'archivo';
  $originalName = mb_substr($originalName, 0, 240, 'UTF-8');
  $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
  if ($extension !== '' && chatBlockedExtension($extension)) {
    throw new RuntimeException('Ese tipo de archivo no está permitido por seguridad.');
  }
  $tmp = (string)($upload['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Archivo de subida no válido.');
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = (string)($finfo->file($tmp) ?: 'application/octet-stream');
  $dangerousMimes = ['text/html','application/x-httpd-php','application/x-php','application/x-sh','application/x-executable','application/x-msdownload'];
  if (in_array(strtolower($mime), $dangerousMimes, true)) {
    throw new RuntimeException('Ese contenido no está permitido por seguridad.');
  }
  $type = 'archivo';
  if (str_starts_with($mime, 'image/')) $type = 'imagen';
  elseif (str_starts_with($mime, 'video/')) $type = 'video';
  elseif (str_starts_with($mime, 'audio/')) $type = 'audio';

  $token = bin2hex(random_bytes(24));
  $relativeDir = date('Y/m');
  $base = dirname(__DIR__) . '/storage/chat/' . $relativeDir;
  if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
    throw new RuntimeException('No se pudo preparar el almacenamiento del chat.');
  }
  $storedName = $token . ($extension !== '' ? '.' . $extension : '.bin');
  $absolutePath = $base . '/' . $storedName;
  if (!move_uploaded_file($tmp, $absolutePath)) {
    throw new RuntimeException('No se pudo guardar el archivo.');
  }
  @chmod($absolutePath, 0600);
  return [
    'token' => $token,
    'path' => 'storage/chat/' . $relativeDir . '/' . $storedName,
    'name' => $originalName,
    'mime' => $mime,
    'bytes' => $size,
    'type' => $type
  ];
}

function chatFindAttachmentByToken($conn, string $token, int $userId): ?array {
  if ($token === '' || $userId <= 0 || !chatTablesReady($conn)) return null;
  try {
    $stmt = $conn->prepare("SELECT cm.* FROM chat_mensajes cm INNER JOIN chat_miembros cmi ON cmi.conversacion_id = cm.conversacion_id WHERE cm.archivo_token = ? AND cmi.user_id = ? AND cmi.activo = 1 AND cm.deleted_at IS NULL LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('si', $token, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_assoc() : null;
  } catch (Throwable $e) {
    return null;
  }
}

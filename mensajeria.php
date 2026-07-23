<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/chat.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/asistente_tecnico.php';

securitySendHeaders();

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');
$comercial = trim((string)($_SESSION['comercial'] ?? $_SESSION['user'] ?? 'Usuario'));
$ready = chatTablesReady($conn);
$csrf = chatCsrfToken();
$aiCsrf = csrfToken();
$aiReady = aiTablesReady($conn);
$aiBrands = $aiReady ? aiListBrands($conn, $userId) : [];
$users = $ready ? chatUsers($conn, $userId) : [];
$conversations = $ready ? chatConversationList($conn, $userId) : [];
$assistantSelected = (string)($_GET['assistant'] ?? '') === '1';
$selected = (int)($_GET['conversation_id'] ?? 0);
if ($assistantSelected) $selected = 0;
elseif ($selected <= 0 && $conversations) $selected = (int)$conversations[0]['id'];
$aiBrandId = (int)($_GET['brand_id'] ?? ($aiBrands[0]['id'] ?? 0));
$aiConversationId = (int)($_GET['assistant_conversation_id'] ?? 0);
$aiBrandsForJs = array_map(static fn($brand) => ['id' => (int)$brand['id'], 'name' => (string)$brand['name']], $aiBrands);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mensajería interna</title>
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="stylesheet" href="css/estilos.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/mensajeria.css?v=<?php echo APP_VERSION; ?>">
  <link rel="stylesheet" href="css/mensajeria_asistente.css?v=<?php echo APP_VERSION; ?>">
</head>
<body class="chat-page">
  <main class="chat-popup-shell"
        id="chatApp"
        data-user-id="<?php echo $userId; ?>"
        data-role="<?php echo h($role); ?>"
        data-csrf="<?php echo h($csrf); ?>"
        data-selected="<?php echo $selected; ?>"
        data-initial-mode="<?php echo $assistantSelected ? 'elias' : 'human'; ?>">
    <header class="chat-topbar">
      <div class="chat-brand">
        <img src="https://disvent.com/media/logo-disvent-doofinder.png" alt="Disvent Ingenieros">
        <div>
          <strong>Mensajería interna</strong>
          <span><?php echo h($comercial); ?></span>
        </div>
      </div>
      <div class="chat-top-actions">
        <button type="button" class="chat-icon-action" id="chatNotifyButton" title="Activar notificaciones" aria-label="Activar notificaciones">🔔</button>
        <a class="chat-close" href="home.php" id="chatCloseButton" aria-label="Cerrar mensajería">×</a>
      </div>
    </header>

    <?php if (!$ready): ?>
      <section class="chat-install-card">
        <div class="chat-install-icon">💬</div>
        <h1>Falta instalar la mensajería interna</h1>
        <p>Este apartado todavía no está disponible.</p>
        <a href="home.php" class="button">Volver al inicio</a>
      </section>
    <?php else: ?>
      <div class="chat-layout">
        <aside class="chat-sidebar" id="chatSidebar">
          <div class="chat-sidebar-heading">
            <div>
              <span>Conversaciones</span>
              <strong>Chat privado</strong>
            </div>
            <button type="button" class="chat-new-button" id="chatNewDirect" title="Nuevo chat">＋</button>
          </div>

          <?php if (in_array($role, ['admin','master'], true)): ?>
            <button type="button" class="chat-group-button" id="chatNewGroup">Crear grupo</button>
          <?php endif; ?>

          <label class="chat-search">
            <span>🔎</span>
            <input type="search" id="chatSearch" placeholder="Buscar conversación">
          </label>

          <div class="chat-conversation-list" id="chatConversationList">
            <button type="button" class="chat-conversation-item assistant-contact<?php echo $assistantSelected ? ' active' : ''; ?>" data-assistant-contact data-search-name="elías asistente técnico documentación marcas">
              <span class="chat-avatar assistant">E</span>
              <span class="chat-conversation-copy"><strong>ELÍAS</strong><small><?php echo $aiReady && $aiBrands ? 'Disponible' : 'Pendiente de activación'; ?> · Documentación aprobada</small></span>
            </button>
            <?php foreach ($conversations as $conversation): ?>
              <button type="button" class="chat-conversation-item<?php echo (int)$conversation['id'] === $selected ? ' active' : ''; ?>" data-conversation-id="<?php echo (int)$conversation['id']; ?>" data-search-name="<?php echo h(mb_strtolower((string)$conversation['name'], 'UTF-8')); ?>">
                <span class="chat-avatar<?php echo $conversation['tipo'] === 'grupo' ? ' group' : ''; ?>"><?php echo $conversation['tipo'] === 'grupo' ? '👥' : '👤'; ?></span>
                <span class="chat-conversation-copy">
                  <strong><?php echo h($conversation['name']); ?></strong>
                  <small><?php echo h($conversation['preview']); ?></small>
                </span>
                <?php if ((int)$conversation['unread'] > 0): ?><span class="chat-list-badge"><?php echo (int)$conversation['unread']; ?></span><?php endif; ?>
              </button>
            <?php endforeach; ?>
            <?php if (!$conversations): ?><div class="chat-empty-list">Todavía no tienes conversaciones.</div><?php endif; ?>
          </div>
        </aside>

        <section class="chat-main">
          <header class="chat-conversation-header" id="chatConversationHeader">
            <button type="button" class="chat-mobile-back" id="chatMobileBack" aria-label="Volver a conversaciones">‹</button>
            <div class="chat-avatar" id="chatHeaderAvatar">💬</div>
            <div class="chat-header-copy">
              <strong id="chatHeaderName">Selecciona una conversación</strong>
              <span id="chatHeaderMeta">Mensajes privados de la aplicación</span>
            </div>
            <div class="chat-header-actions">
              <button type="button" class="chat-delete-button" id="chatDeleteConversation" title="Eliminar conversación" aria-label="Eliminar conversación" hidden>🗑️</button>
            </div>
          </header>

          <div class="elias-toolbar" id="eliasToolbar" hidden>
            <label><span>Marca</span><select id="eliasBrandSelect" aria-label="Marca de documentación"></select></label>
            <label class="elias-conversation-field"><span>Chat de ELÍAS</span><select id="eliasConversationSelect" aria-label="Conversación con ELÍAS"></select></label>
            <button type="button" id="eliasNewConversation">＋ Nuevo chat</button>
            <small>Las respuestas se limitan a la documentación aprobada. Las propuestas deben validarse antes de ejecutarse.</small>
          </div>

          <div class="chat-messages" id="chatMessages">
            <div class="chat-welcome">
              <div>💬</div>
              <strong>Comunicación interna segura</strong>
              <span>Elige un usuario o grupo para comenzar.</span>
            </div>
          </div>

          <form class="chat-composer" id="chatComposer" enctype="multipart/form-data">
            <input type="hidden" name="conversation_id" id="chatConversationId" value="<?php echo $selected; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <label class="chat-attach-button" id="chatAttachLabel" title="Adjuntar archivos">
              <input type="file" name="attachments[]" id="chatAttachments" multiple>
              <span>📎</span>
            </label>
            <textarea name="message" id="chatMessageInput" rows="1" maxlength="6000" placeholder="Escribe un mensaje"></textarea>
            <button type="submit" class="chat-send-button" aria-label="Enviar mensaje">➤</button>
            <div class="chat-file-preview" id="chatFilePreview"></div>
          </form>
        </section>
      </div>

      <div class="chat-modal" id="chatDirectModal" hidden>
        <div class="chat-modal-backdrop" data-close-modal></div>
        <section class="chat-modal-card">
          <button type="button" class="chat-modal-close" data-close-modal>×</button>
          <h2>Nuevo chat</h2>
          <p>Selecciona un usuario de la aplicación.</p>
          <div class="chat-user-picker">
            <button type="button" class="chat-user-option assistant-contact" data-assistant-contact>
              <span class="chat-avatar assistant">E</span>
              <span><strong>ELÍAS</strong><small>Asistente técnico virtual</small></span>
            </button>
            <?php foreach ($users as $user): ?>
              <button type="button" class="chat-user-option" data-target-user="<?php echo (int)$user['id']; ?>">
                <span class="chat-avatar">👤</span>
                <span><strong><?php echo h($user['comercial'] ?: $user['username']); ?></strong><small><?php echo h($user['username']); ?></small></span>
              </button>
            <?php endforeach; ?>
          </div>
        </section>
      </div>

      <?php if (in_array($role, ['admin','master'], true)): ?>
      <div class="chat-modal" id="chatGroupModal" hidden>
        <div class="chat-modal-backdrop" data-close-modal></div>
        <section class="chat-modal-card">
          <button type="button" class="chat-modal-close" data-close-modal>×</button>
          <h2>Crear grupo</h2>
          <p>Solo Admin y Máster pueden crear grupos.</p>
          <form id="chatGroupForm" class="chat-group-form">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <div><label for="chatGroupName">Nombre del grupo</label><input id="chatGroupName" name="name" type="text" maxlength="150" required></div>
            <div class="chat-group-members">
              <?php foreach ($users as $user): ?>
                <label><input type="checkbox" name="members[]" value="<?php echo (int)$user['id']; ?>"> <span><?php echo h($user['comercial'] ?: $user['username']); ?></span></label>
              <?php endforeach; ?>
            </div>
            <button type="submit">Crear grupo</button>
          </form>
        </section>
      </div>
      <?php endif; ?>

      <div class="chat-modal" id="chatDeleteModal" hidden>
        <div class="chat-modal-backdrop" data-close-modal></div>
        <section class="chat-modal-card chat-confirm-card" role="dialog" aria-modal="true" aria-labelledby="chatDeleteTitle">
          <button type="button" class="chat-modal-close" data-close-modal aria-label="Cerrar">×</button>
          <h2 id="chatDeleteTitle">Eliminar conversación</h2>
          <p id="chatDeleteText">La conversación dejará de aparecer en tu lista.</p>
          <div class="chat-confirm-actions">
            <button type="button" class="chat-cancel-button" data-close-modal>Cancelar</button>
            <button type="button" class="chat-confirm-delete" id="chatConfirmDelete">Eliminar</button>
          </div>
        </section>
      </div>

      <div class="chat-toast" id="chatToast" hidden></div>
    <?php endif; ?>
  </main>

  <script>
    window.ELIAS_CONFIG = {
      csrfToken: <?php echo json_encode($aiCsrf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
      ready: <?php echo $aiReady ? 'true' : 'false'; ?>,
      brands: <?php echo json_encode($aiBrandsForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
      initialBrandId: <?php echo $aiBrandId; ?>,
      initialConversationId: <?php echo $aiConversationId; ?>
    };
  </script>
  <?php if ($ready): ?><script src="js/mensajeria.js?v=<?php echo APP_VERSION; ?>"></script><?php endif; ?>
</body>
</html>

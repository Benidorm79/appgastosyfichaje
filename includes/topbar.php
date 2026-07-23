<div class="topbar">

    <div class="topbar-user">
        👤 <?php echo htmlspecialchars($_SESSION['comercial'], ENT_QUOTES, 'UTF-8'); ?>
    </div>

    <a href="logout.php" class="topbar-logout">
        Cerrar sesión
    </a>

    <a href="mensajeria.php"
       class="chat-launcher"
       data-chat-open
       data-chat-api="api/mensajeria_estado.php"
       aria-label="Abrir mensajería interna"
       title="Mensajería interna">
        <span class="chat-launcher-icon" aria-hidden="true">💬</span>
        <span class="chat-launcher-text">Mensajería</span>
        <span class="chat-unread-badge" data-chat-badge hidden>0</span>
    </a>

</div>
<script src="js/mensajeria_badge.js?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1'; ?>" defer></script>

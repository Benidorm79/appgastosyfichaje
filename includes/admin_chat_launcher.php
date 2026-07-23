<a href="../mensajeria.php"
   class="admin-chat-launcher"
   data-chat-open
   data-chat-api="../api/mensajeria_estado.php"
   aria-label="Abrir mensajería interna"
   title="Mensajería interna">
  <span aria-hidden="true">💬</span>
  <span class="chat-unread-badge" data-chat-badge hidden>0</span>
</a>
<script>
(function(){
  var launcher=document.currentScript.previousElementSibling;
  var host=document.querySelector('.top-actions')||document.querySelector('.admin-actions-top')||document.querySelector('.admin-actions')||document.querySelector('.admin-header');
  if(launcher&&host) host.appendChild(launcher);
})();
</script>
<script src="../js/mensajeria_badge.js?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1'; ?>" defer></script>

(() => {
  const app = document.getElementById('chatApp');
  if (!app) return;
  const csrf = app.dataset.csrf || '';
  const currentUserId = Number(app.dataset.userId || 0);
  let selectedConversation = Number(app.dataset.selected || 0);
  let pollTimer = null;
  let conversationsTimer = null;
  let shouldStickBottom = true;

  const list = document.getElementById('chatConversationList');
  const messages = document.getElementById('chatMessages');
  const headerName = document.getElementById('chatHeaderName');
  const headerMeta = document.getElementById('chatHeaderMeta');
  const headerAvatar = document.getElementById('chatHeaderAvatar');
  const conversationInput = document.getElementById('chatConversationId');
  const composer = document.getElementById('chatComposer');
  const messageInput = document.getElementById('chatMessageInput');
  const attachments = document.getElementById('chatAttachments');
  const filePreview = document.getElementById('chatFilePreview');
  const toast = document.getElementById('chatToast');

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
  const showToast = (text, error = false) => {
    toast.textContent = text;
    toast.classList.toggle('error', error);
    toast.hidden = false;
    clearTimeout(showToast.timer);
    showToast.timer = setTimeout(() => { toast.hidden = true; }, 3500);
  };
  const formatTime = (dateTime) => {
    if (!dateTime) return '';
    const parts = String(dateTime).split(' ');
    return (parts[1] || '').slice(0, 5);
  };
  const attachmentHtml = (message) => {
    if (!message.attachment_url) return '';
    const url = escapeHtml(message.attachment_url);
    const name = escapeHtml(message.archivo_nombre || 'Archivo');
    const mime = String(message.archivo_mime || '');
    if (message.tipo === 'imagen') return `<div class="chat-attachment"><a href="${url}" target="_blank" rel="noopener"><img src="${url}" alt="${name}" loading="lazy"></a><a class="chat-file-link" href="${url}" target="_blank" rel="noopener">📷 <span>${name}</span></a></div>`;
    if (message.tipo === 'video') return `<div class="chat-attachment"><video controls preload="metadata" src="${url}"></video><a class="chat-file-link" href="${url}" target="_blank" rel="noopener">🎬 <span>${name}</span></a></div>`;
    if (message.tipo === 'audio') return `<div class="chat-attachment"><audio controls preload="metadata" src="${url}"></audio><a class="chat-file-link" href="${url}" target="_blank" rel="noopener">🎵 <span>${name}</span></a></div>`;
    const icon = mime.includes('pdf') ? '📄' : '📎';
    return `<div class="chat-attachment"><a class="chat-file-link" href="${url}" target="_blank" rel="noopener">${icon} <span>${name}</span></a></div>`;
  };
  const messageHtml = (message) => {
    const mine = Boolean(message.is_mine) || Number(message.remitente_id) === currentUserId;
    const status = String(message.receipt_status || 'sent');
    const ticks = mine ? `<span class="chat-ticks ${status === 'read' ? 'read' : ''}">${status === 'sent' ? '✓' : '✓✓'}</span>` : '';
    const sender = !mine ? `<div class="chat-sender">${escapeHtml(message.sender_name || '')}</div>` : '';
    const text = message.mensaje ? `<div class="chat-message-text">${escapeHtml(message.mensaje)}</div>` : '';
    return `<article class="chat-message ${mine ? 'mine' : ''}" data-message-id="${Number(message.id)}"><div class="chat-bubble">${sender}${text}${attachmentHtml(message)}<div class="chat-message-meta"><span>${escapeHtml(formatTime(message.created_at))}</span>${ticks}</div></div></article>`;
  };

  async function api(url, options = {}) {
    const response = await fetch(url, {credentials: 'same-origin', ...options});
    let data = {};
    try { data = await response.json(); } catch (_) {}
    if (!response.ok || data.ok === false) throw new Error(data.message || 'No se pudo completar la operación.');
    return data;
  }

  function isNearBottom() {
    return messages.scrollHeight - messages.scrollTop - messages.clientHeight < 100;
  }
  function scrollBottom(force = false) {
    if (force || shouldStickBottom) messages.scrollTop = messages.scrollHeight;
  }

  async function loadConversation(id, forceBottom = false) {
    id = Number(id || 0);
    if (!id) return;
    selectedConversation = id;
    conversationInput.value = String(id);
    document.querySelectorAll('.chat-conversation-item').forEach(item => item.classList.toggle('active', Number(item.dataset.conversationId) === id));
    if (window.innerWidth <= 760) app.classList.add('conversation-open');
    try {
      shouldStickBottom = forceBottom || isNearBottom();
      const data = await api(`api/chat_mensajes.php?conversation_id=${encodeURIComponent(id)}`);
      const conversation = data.conversation || {};
      headerName.textContent = conversation.display_name || 'Conversación';
      headerMeta.textContent = conversation.tipo === 'grupo' ? `${(conversation.members || []).length} participantes` : 'Conversación privada';
      headerAvatar.textContent = conversation.tipo === 'grupo' ? '👥' : '👤';
      messages.innerHTML = (data.messages || []).length ? data.messages.map(messageHtml).join('') : '<div class="chat-welcome"><div>👋</div><strong>Empieza la conversación</strong><span>Los mensajes solo son visibles para sus participantes.</span></div>';
      scrollBottom(forceBottom);
      updateGlobalBadge(data.unread || 0);
    } catch (error) {
      showToast(error.message, true);
    }
  }

  function conversationItemHtml(c) {
    return `<button type="button" class="chat-conversation-item ${Number(c.id) === selectedConversation ? 'active' : ''}" data-conversation-id="${Number(c.id)}" data-search-name="${escapeHtml(String(c.name || '').toLowerCase())}"><span class="chat-avatar ${c.tipo === 'grupo' ? 'group' : ''}">${c.tipo === 'grupo' ? '👥' : '👤'}</span><span class="chat-conversation-copy"><strong>${escapeHtml(c.name)}</strong><small>${escapeHtml(c.preview)}</small></span>${Number(c.unread) > 0 ? `<span class="chat-list-badge">${Number(c.unread)}</span>` : ''}</button>`;
  }
  async function loadConversations() {
    try {
      const data = await api('api/chat_conversaciones.php');
      const conversations = data.conversations || [];
      list.innerHTML = conversations.length ? conversations.map(conversationItemHtml).join('') : '<div class="chat-empty-list">Todavía no tienes conversaciones.</div>';
      updateGlobalBadge(data.unread || 0);
      if (!selectedConversation && conversations.length) loadConversation(conversations[0].id, true);
    } catch (_) {}
  }
  function updateGlobalBadge(count) {
    try { if (window.opener && !window.opener.closed) window.opener.postMessage({type:'chat-unread', count:Number(count || 0)}, window.location.origin); } catch (_) {}
  }

  list.addEventListener('click', event => {
    const button = event.target.closest('[data-conversation-id]');
    if (button) loadConversation(button.dataset.conversationId, true);
  });
  messages.addEventListener('scroll', () => { shouldStickBottom = isNearBottom(); });

  composer.addEventListener('submit', async event => {
    event.preventDefault();
    if (!selectedConversation) { showToast('Selecciona primero una conversación.', true); return; }
    const formData = new FormData(composer);
    const submit = composer.querySelector('button[type="submit"]');
    submit.disabled = true;
    try {
      await api('api/chat_enviar.php', {method:'POST', body:formData});
      messageInput.value = '';
      attachments.value = '';
      filePreview.innerHTML = '';
      await loadConversation(selectedConversation, true);
      await loadConversations();
    } catch (error) {
      showToast(error.message, true);
    } finally {
      submit.disabled = false;
      messageInput.focus();
    }
  });

  messageInput.addEventListener('keydown', event => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      composer.requestSubmit();
    }
  });
  messageInput.addEventListener('input', () => {
    messageInput.style.height = 'auto';
    messageInput.style.height = `${Math.min(messageInput.scrollHeight, 130)}px`;
  });
  attachments.addEventListener('change', () => {
    filePreview.innerHTML = Array.from(attachments.files || []).map(file => `<span class="chat-file-chip">${escapeHtml(file.name)}</span>`).join('');
  });

  const directModal = document.getElementById('chatDirectModal');
  const groupModal = document.getElementById('chatGroupModal');
  const openModal = modal => { if (modal) modal.hidden = false; };
  const closeModals = () => document.querySelectorAll('.chat-modal').forEach(modal => { modal.hidden = true; });
  document.getElementById('chatNewDirect')?.addEventListener('click', () => openModal(directModal));
  document.getElementById('chatNewGroup')?.addEventListener('click', () => openModal(groupModal));
  document.querySelectorAll('[data-close-modal]').forEach(el => el.addEventListener('click', closeModals));
  document.querySelectorAll('[data-target-user]').forEach(button => button.addEventListener('click', async () => {
    const body = new FormData();
    body.append('csrf_token', csrf);
    body.append('target_user_id', button.dataset.targetUser);
    try {
      const data = await api('api/chat_abrir.php', {method:'POST', body});
      closeModals();
      await loadConversations();
      await loadConversation(data.conversation_id, true);
    } catch (error) { showToast(error.message, true); }
  }));
  document.getElementById('chatGroupForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const body = new FormData(event.currentTarget);
    try {
      const data = await api('api/chat_grupo.php', {method:'POST', body});
      event.currentTarget.reset();
      closeModals();
      await loadConversations();
      await loadConversation(data.conversation_id, true);
    } catch (error) { showToast(error.message, true); }
  });

  document.getElementById('chatSearch')?.addEventListener('input', event => {
    const term = String(event.target.value || '').toLowerCase().trim();
    document.querySelectorAll('.chat-conversation-item').forEach(item => { item.hidden = term !== '' && !String(item.dataset.searchName || '').includes(term); });
  });
  document.getElementById('chatMobileBack')?.addEventListener('click', () => app.classList.remove('conversation-open'));
  document.getElementById('chatCloseButton')?.addEventListener('click', event => {
    if (window.opener && !window.opener.closed) { event.preventDefault(); window.close(); }
  });
  document.getElementById('chatNotifyButton')?.addEventListener('click', async () => {
    if (!('Notification' in window)) { showToast('Este navegador no admite notificaciones.', true); return; }
    const permission = await Notification.requestPermission();
    showToast(permission === 'granted' ? 'Notificaciones activadas.' : 'No se han activado las notificaciones.', permission !== 'granted');
  });

  if (selectedConversation) loadConversation(selectedConversation, true);
  pollTimer = setInterval(() => { if (selectedConversation && !document.hidden) loadConversation(selectedConversation, false); }, 5000);
  conversationsTimer = setInterval(() => { if (!document.hidden) loadConversations(); }, 10000);
  window.addEventListener('beforeunload', () => { clearInterval(pollTimer); clearInterval(conversationsTimer); });
})();

(() => {
  const app = document.getElementById('chatApp');
  if (!app) return;

  const csrf = app.dataset.csrf || '';
  const currentUserId = Number(app.dataset.userId || 0);
  const eliasConfig = window.ELIAS_CONFIG || {ready: false, brands: []};
  let mode = app.dataset.initialMode === 'elias' ? 'elias' : 'human';
  let selectedConversation = Number(app.dataset.selected || 0);
  let selectedEliasConversation = Number(eliasConfig.initialConversationId || 0);
  let selectedEliasBrand = Number(eliasConfig.initialBrandId || (eliasConfig.brands[0] || {}).id || 0);
  let eliasOpened = false;
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
  const attachLabel = document.getElementById('chatAttachLabel');
  const filePreview = document.getElementById('chatFilePreview');
  const toast = document.getElementById('chatToast');
  const deleteButton = document.getElementById('chatDeleteConversation');
  const deleteModal = document.getElementById('chatDeleteModal');
  const deleteText = document.getElementById('chatDeleteText');
  const confirmDelete = document.getElementById('chatConfirmDelete');
  const eliasToolbar = document.getElementById('eliasToolbar');
  const eliasBrandSelect = document.getElementById('eliasBrandSelect');
  const eliasConversationSelect = document.getElementById('eliasConversationSelect');
  const eliasNewConversation = document.getElementById('eliasNewConversation');

  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
  const showToast = (text, error = false) => {
    toast.textContent = text;
    toast.classList.toggle('error', error);
    toast.hidden = false;
    clearTimeout(showToast.timer);
    showToast.timer = setTimeout(() => { toast.hidden = true; }, 3500);
  };
  const formatTime = dateTime => {
    if (!dateTime) return '';
    const parts = String(dateTime).split(' ');
    return (parts[1] || '').slice(0, 5);
  };
  const formatDate = dateTime => {
    if (!dateTime) return '';
    const date = new Date(String(dateTime).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(dateTime);
    return new Intl.DateTimeFormat('es-ES', {day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit'}).format(date);
  };
  const requestId = () => window.crypto && crypto.randomUUID
    ? crypto.randomUUID()
    : Date.now().toString(36) + Math.random().toString(36).slice(2);

  async function api(url, options = {}) {
    const response = await fetch(url, {credentials: 'same-origin', ...options});
    let data = {};
    try { data = await response.json(); } catch (_) {}
    if (!response.ok || data.ok === false) throw new Error(data.message || 'No se pudo completar la operación.');
    return data;
  }

  async function eliasApi(payload) {
    return api('api/asistente.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': eliasConfig.csrfToken || ''},
      body: JSON.stringify({csrf_token: eliasConfig.csrfToken || '', ...payload})
    });
  }

  function isNearBottom() {
    return messages.scrollHeight - messages.scrollTop - messages.clientHeight < 100;
  }

  function scrollBottom(force = false) {
    if (force || shouldStickBottom) messages.scrollTop = messages.scrollHeight;
  }

  function updateUrl() {
    const url = new URL(window.location.href);
    if (mode === 'elias') {
      url.searchParams.set('assistant', '1');
      url.searchParams.delete('conversation_id');
      if (selectedEliasBrand) url.searchParams.set('brand_id', String(selectedEliasBrand));
      else url.searchParams.delete('brand_id');
      if (selectedEliasConversation) url.searchParams.set('assistant_conversation_id', String(selectedEliasConversation));
      else url.searchParams.delete('assistant_conversation_id');
    } else {
      url.searchParams.delete('assistant');
      url.searchParams.delete('brand_id');
      url.searchParams.delete('assistant_conversation_id');
      if (selectedConversation) url.searchParams.set('conversation_id', String(selectedConversation));
      else url.searchParams.delete('conversation_id');
    }
    window.history.replaceState({}, '', url);
  }

  function setComposerMode(nextMode) {
    const isElias = nextMode === 'elias';
    composer.classList.toggle('elias-mode', isElias);
    attachLabel.hidden = isElias;
    attachments.disabled = isElias;
    if (isElias) {
      attachments.value = '';
      filePreview.innerHTML = '';
      messageInput.maxLength = 4000;
      messageInput.placeholder = 'Pregunta o describe el proyecto que quieres dimensionar…';
      messageInput.disabled = !eliasConfig.ready || !(eliasConfig.brands || []).length;
    } else {
      messageInput.maxLength = 6000;
      messageInput.placeholder = 'Escribe un mensaje';
      messageInput.disabled = false;
    }
  }

  const attachmentHtml = message => {
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

  const humanMessageHtml = message => {
    const mine = Boolean(message.is_mine) || Number(message.remitente_id) === currentUserId;
    const status = String(message.receipt_status || 'sent');
    const ticks = mine ? `<span class="chat-ticks ${status === 'read' ? 'read' : ''}">${status === 'sent' ? '✓' : '✓✓'}</span>` : '';
    const sender = !mine ? `<div class="chat-sender">${escapeHtml(message.sender_name || '')}</div>` : '';
    const text = message.mensaje ? `<div class="chat-message-text">${escapeHtml(message.mensaje)}</div>` : '';
    return `<article class="chat-message ${mine ? 'mine' : ''}" data-message-id="${Number(message.id)}"><div class="chat-bubble">${sender}${text}${attachmentHtml(message)}<div class="chat-message-meta"><span>${escapeHtml(formatTime(message.created_at))}</span>${ticks}</div></div></article>`;
  };

  const eliasMessageHtml = message => {
    const mine = message.role === 'user';
    const content = String(message.content || '');
    const citations = Array.isArray(message.citations) ? message.citations : [];
    const sources = !mine && citations.length
      ? `<div class="elias-sources"><strong>Fuentes</strong>${citations.map(citation => `<span>${escapeHtml(citation.filename || 'Documento')}${citation.page ? ` · pág. ${escapeHtml(citation.page)}` : ''}</span>`).join('')}</div>`
      : '';
    const feedback = !mine && Number(message.id) > 0
      ? `<div class="elias-feedback"><button type="button" data-elias-rating="1" aria-label="Respuesta útil">👍</button><button type="button" data-elias-rating="-1" aria-label="Respuesta no útil">👎</button></div>`
      : '';
    const copyList = !mine && /\bLISTA PARA COPIAR\b/i.test(content)
      ? '<button type="button" class="elias-copy-list" data-elias-copy-list>Copiar lista de referencias</button>'
      : '';
    const sender = mine ? '' : '<div class="chat-sender elias-sender">ELÍAS</div>';
    return `<article class="chat-message elias-message ${mine ? 'mine' : ''}" data-message-id="${Number(message.id || 0)}"><div class="chat-bubble">${sender}<div class="chat-message-text">${escapeHtml(content)}</div>${copyList}${sources}${feedback}<div class="chat-message-meta"><span>${escapeHtml(formatTime(message.created_at))}</span></div></div></article>`;
  };

  async function loadConversation(id, forceBottom = false) {
    id = Number(id || 0);
    if (!id) return;
    mode = 'human';
    selectedConversation = id;
    conversationInput.value = String(id);
    setComposerMode('human');
    eliasToolbar.hidden = true;
    deleteButton.hidden = false;
    document.querySelectorAll('.chat-conversation-item').forEach(item => item.classList.toggle('active', Number(item.dataset.conversationId) === id));
    if (window.innerWidth <= 760) app.classList.add('conversation-open');
    try {
      shouldStickBottom = forceBottom || isNearBottom();
      const data = await api(`api/mensajeria_mensajes.php?conversation_id=${encodeURIComponent(id)}`);
      if (mode !== 'human' || selectedConversation !== id) return;
      const conversation = data.conversation || {};
      headerName.textContent = conversation.display_name || 'Conversación';
      headerMeta.textContent = conversation.tipo === 'grupo' ? `${(conversation.members || []).length} participantes` : 'Conversación privada';
      headerAvatar.textContent = conversation.tipo === 'grupo' ? '👥' : '👤';
      headerAvatar.className = `chat-avatar${conversation.tipo === 'grupo' ? ' group' : ''}`;
      messages.innerHTML = (data.messages || []).length
        ? data.messages.map(humanMessageHtml).join('')
        : '<div class="chat-welcome"><div>👋</div><strong>Empieza la conversación</strong><span>Los mensajes solo son visibles para sus participantes.</span></div>';
      scrollBottom(forceBottom);
      updateGlobalBadge(data.unread || 0);
      updateUrl();
    } catch (error) {
      if (mode === 'human' && selectedConversation === id) showToast(error.message, true);
    }
  }

  function conversationItemHtml(conversation) {
    const active = mode === 'human' && Number(conversation.id) === selectedConversation;
    return `<button type="button" class="chat-conversation-item ${active ? 'active' : ''}" data-conversation-id="${Number(conversation.id)}" data-search-name="${escapeHtml(String(conversation.name || '').toLowerCase())}"><span class="chat-avatar ${conversation.tipo === 'grupo' ? 'group' : ''}">${conversation.tipo === 'grupo' ? '👥' : '👤'}</span><span class="chat-conversation-copy"><strong>${escapeHtml(conversation.name)}</strong><small>${escapeHtml(conversation.preview)}</small></span>${Number(conversation.unread) > 0 ? `<span class="chat-list-badge">${Number(conversation.unread)}</span>` : ''}</button>`;
  }

  function assistantItemHtml() {
    const readyText = eliasConfig.ready && (eliasConfig.brands || []).length ? 'Disponible' : 'Pendiente de activación';
    return `<button type="button" class="chat-conversation-item assistant-contact ${mode === 'elias' ? 'active' : ''}" data-assistant-contact data-search-name="elías asistente técnico documentación marcas"><span class="chat-avatar assistant">E</span><span class="chat-conversation-copy"><strong>ELÍAS</strong><small>${readyText} · Documentación aprobada</small></span></button>`;
  }

  async function loadConversations() {
    try {
      const data = await api('api/mensajeria_conversaciones.php');
      const conversations = data.conversations || [];
      list.innerHTML = assistantItemHtml() + (conversations.length ? conversations.map(conversationItemHtml).join('') : '<div class="chat-empty-list">Todavía no tienes conversaciones personales.</div>');
      updateGlobalBadge(data.unread || 0);
      if (mode === 'human' && !selectedConversation && conversations.length) loadConversation(conversations[0].id, true);
    } catch (_) {}
  }

  function updateGlobalBadge(count) {
    try {
      if (window.opener && !window.opener.closed) {
        window.opener.postMessage({type:'chat-unread', count:Number(count || 0)}, window.location.origin);
      }
    } catch (_) {}
  }

  function populateBrands() {
    const brands = Array.isArray(eliasConfig.brands) ? eliasConfig.brands : [];
    eliasBrandSelect.innerHTML = brands.map(brand => `<option value="${Number(brand.id)}">${escapeHtml(brand.name)}</option>`).join('');
    if (!brands.some(brand => Number(brand.id) === selectedEliasBrand)) selectedEliasBrand = Number((brands[0] || {}).id || 0);
    eliasBrandSelect.value = String(selectedEliasBrand || '');
  }

  function showEliasWelcome() {
    selectedEliasConversation = 0;
    deleteButton.hidden = true;
    headerMeta.textContent = 'Asistente técnico · Solo documentación aprobada';
    if (!eliasConfig.ready) {
      messages.innerHTML = '<div class="chat-welcome elias-welcome"><div>E</div><strong>ELÍAS todavía no está disponible</strong><span>Podrás consultarlo desde aquí cuando se complete su activación.</span></div>';
    } else if (!(eliasConfig.brands || []).length) {
      messages.innerHTML = '<div class="chat-welcome elias-welcome"><div>E</div><strong>No tienes marcas disponibles</strong><span>Solicita acceso a la documentación que necesites consultar.</span></div>';
    } else {
      messages.innerHTML = '<div class="chat-welcome elias-welcome"><div>E</div><strong>¿Qué necesitas consultar?</strong><span>Pregunta por equipos, procedimientos, especificaciones, precios documentados o describe un proyecto para preparar una propuesta preliminar.</span></div>';
    }
    updateUrl();
  }

  async function loadEliasConversation(id, forceBottom = true) {
    id = Number(id || 0);
    if (!id) {
      showEliasWelcome();
      return;
    }
    try {
      const data = await eliasApi({action: 'get_conversation', conversation_id: id});
      if (mode !== 'elias') return;
      selectedEliasConversation = Number(data.conversation.id || 0);
      selectedEliasBrand = Number(data.conversation.brand_id || selectedEliasBrand);
      eliasBrandSelect.value = String(selectedEliasBrand);
      eliasConversationSelect.value = String(selectedEliasConversation);
      headerMeta.textContent = `${data.conversation.title || 'Conversación'} · Solo documentación aprobada`;
      deleteButton.hidden = false;
      messages.innerHTML = (data.messages || []).length
        ? data.messages.map(eliasMessageHtml).join('')
        : '<div class="chat-welcome elias-welcome"><div>E</div><strong>Empieza a consultar a ELÍAS</strong><span>La conversación quedará guardada en tu historial técnico.</span></div>';
      scrollBottom(forceBottom);
      updateUrl();
    } catch (error) {
      if (mode === 'elias') {
        selectedEliasConversation = 0;
        showEliasWelcome();
        showToast(error.message, true);
      }
    }
  }

  async function refreshEliasHistory(preferredId = 0, useLatest = true) {
    if (!selectedEliasBrand) {
      eliasConversationSelect.innerHTML = '<option value="0">Nueva conversación</option>';
      showEliasWelcome();
      return;
    }
    const requestedBrand = selectedEliasBrand;
    const data = await eliasApi({action: 'list_conversations', brand_id: requestedBrand});
    if (mode !== 'elias' || selectedEliasBrand !== requestedBrand) return;
    const conversations = data.conversations || [];
    eliasConversationSelect.innerHTML = '<option value="0">Nueva conversación</option>' + conversations.map(conversation => `<option value="${Number(conversation.id)}">${escapeHtml(conversation.title)} · ${escapeHtml(formatDate(conversation.last_message_at))}</option>`).join('');
    const requested = Number(preferredId || 0);
    const target = conversations.find(conversation => Number(conversation.id) === requested)
      || (useLatest ? conversations[0] : null);
    if (target) {
      eliasConversationSelect.value = String(target.id);
      await loadEliasConversation(target.id, true);
    } else {
      eliasConversationSelect.value = '0';
      showEliasWelcome();
    }
  }

  async function openElias(options = {}) {
    mode = 'elias';
    selectedConversation = 0;
    conversationInput.value = '0';
    setComposerMode('elias');
    eliasToolbar.hidden = false;
    headerName.textContent = 'ELÍAS';
    headerAvatar.textContent = 'E';
    headerAvatar.className = 'chat-avatar assistant';
    document.querySelectorAll('.chat-conversation-item').forEach(item => item.classList.toggle('active', Boolean(item.dataset.assistantContact)));
    if (window.innerWidth <= 760) app.classList.add('conversation-open');
    populateBrands();
    const preferredId = Object.prototype.hasOwnProperty.call(options, 'conversationId')
      ? Number(options.conversationId || 0)
      : Number(eliasOpened ? selectedEliasConversation : eliasConfig.initialConversationId || 0);
    eliasOpened = true;
    if (!eliasConfig.ready || !(eliasConfig.brands || []).length) {
      eliasConversationSelect.innerHTML = '<option value="0">Nueva conversación</option>';
      eliasNewConversation.disabled = true;
      showEliasWelcome();
      return;
    }
    eliasNewConversation.disabled = false;
    try {
      await refreshEliasHistory(preferredId, options.useLatest !== false);
    } catch (error) {
      if (mode === 'elias') {
        showEliasWelcome();
        showToast(error.message, true);
      }
    }
  }

  list.addEventListener('click', event => {
    const assistant = event.target.closest('[data-assistant-contact]');
    if (assistant) {
      openElias();
      return;
    }
    const button = event.target.closest('[data-conversation-id]');
    if (button) loadConversation(button.dataset.conversationId, true);
  });

  messages.addEventListener('scroll', () => { shouldStickBottom = isNearBottom(); });
  messages.addEventListener('click', async event => {
    const copyListButton = event.target.closest('[data-elias-copy-list]');
    if (copyListButton) {
      const text = copyListButton.closest('.chat-bubble')?.querySelector('.chat-message-text')?.textContent || '';
      const section = text.match(/LISTA PARA COPIAR\s*([\s\S]*)$/i);
      const lines = section ? section[1].split(/\r?\n/).map(line => line.trim()).filter(line => /^[A-Za-z0-9][A-Za-z0-9._/-]*#\d+$/.test(line)) : [];
      if (!lines.length) { showToast('No hay referencias preparadas para copiar.', true); return; }
      try {
        await navigator.clipboard.writeText(lines.join('\n'));
        showToast('Lista de referencias copiada.');
      } catch (_) {
        showToast('No se ha podido copiar automáticamente.', true);
      }
      return;
    }
    const button = event.target.closest('[data-elias-rating]');
    if (!button) return;
    const article = button.closest('[data-message-id]');
    if (!article) return;
    try {
      await eliasApi({action:'feedback', message_id:Number(article.dataset.messageId), rating:Number(button.dataset.eliasRating)});
      article.querySelectorAll('[data-elias-rating]').forEach(item => item.classList.toggle('selected', item === button));
    } catch (_) {}
  });

  composer.addEventListener('submit', async event => {
    event.preventDefault();
    const submit = composer.querySelector('button[type="submit"]');
    if (mode === 'elias') {
      const question = messageInput.value.trim();
      if (!selectedEliasBrand) { showToast('Selecciona una marca.', true); return; }
      if (!question) return;
      const requestBrand = selectedEliasBrand;
      const requestConversation = selectedEliasConversation;
      messageInput.value = '';
      submit.disabled = true;
      messageInput.disabled = true;
      eliasBrandSelect.disabled = true;
      eliasConversationSelect.disabled = true;
      eliasNewConversation.disabled = true;
      deleteButton.disabled = true;
      const welcome = messages.querySelector('.chat-welcome');
      if (welcome) welcome.remove();
      messages.insertAdjacentHTML('beforeend', eliasMessageHtml({role:'user', content:question, created_at:''}));
      messages.insertAdjacentHTML('beforeend', '<article class="chat-message elias-message elias-pending" id="eliasPending"><div class="chat-bubble"><div class="chat-sender elias-sender">ELÍAS</div><div class="chat-message-text">Consultando la documentación…</div></div></article>');
      scrollBottom(true);
      try {
        const data = await eliasApi({action:'send', brand_id:requestBrand, conversation_id:requestConversation, message:question, request_id:requestId()});
        if (mode !== 'elias' || selectedEliasBrand !== requestBrand || selectedEliasConversation !== requestConversation) return;
        selectedEliasConversation = Number(data.conversation_id || 0);
        document.getElementById('eliasPending')?.remove();
        messages.insertAdjacentHTML('beforeend', eliasMessageHtml({id:data.message_id, role:'assistant', content:data.answer, citations:data.citations || [], created_at:''}));
        scrollBottom(true);
        await refreshEliasHistory(selectedEliasConversation, true);
      } catch (error) {
        const pending = document.getElementById('eliasPending');
        if (pending) {
          pending.classList.remove('elias-pending');
          pending.classList.add('elias-error');
          const copy = pending.querySelector('.chat-message-text');
          if (copy) copy.textContent = error.message;
        }
      } finally {
        submit.disabled = false;
        messageInput.disabled = mode === 'elias' && (!eliasConfig.ready || !(eliasConfig.brands || []).length);
        eliasBrandSelect.disabled = false;
        eliasConversationSelect.disabled = false;
        eliasNewConversation.disabled = !eliasConfig.ready || !(eliasConfig.brands || []).length;
        deleteButton.disabled = false;
        if (mode === 'elias') messageInput.focus();
      }
      return;
    }

    if (!selectedConversation) { showToast('Selecciona primero una conversación.', true); return; }
    const formData = new FormData(composer);
    submit.disabled = true;
    try {
      await api('api/mensajeria_enviar.php', {method:'POST', body:formData});
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
  document.querySelectorAll('[data-close-modal]').forEach(element => element.addEventListener('click', closeModals));
  document.querySelectorAll('[data-target-user]').forEach(button => button.addEventListener('click', async () => {
    const body = new FormData();
    body.append('csrf_token', csrf);
    body.append('target_user_id', button.dataset.targetUser);
    try {
      const data = await api('api/mensajeria_abrir.php', {method:'POST', body});
      closeModals();
      await loadConversations();
      await loadConversation(data.conversation_id, true);
    } catch (error) { showToast(error.message, true); }
  }));
  document.getElementById('chatGroupForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const body = new FormData(event.currentTarget);
    try {
      const data = await api('api/mensajeria_grupo.php', {method:'POST', body});
      event.currentTarget.reset();
      closeModals();
      await loadConversations();
      await loadConversation(data.conversation_id, true);
    } catch (error) { showToast(error.message, true); }
  });
  document.querySelectorAll('#chatDirectModal [data-assistant-contact]').forEach(button => button.addEventListener('click', () => {
    closeModals();
    openElias();
  }));

  eliasBrandSelect?.addEventListener('change', async event => {
    selectedEliasBrand = Number(event.target.value || 0);
    selectedEliasConversation = 0;
    try { await refreshEliasHistory(0, true); }
    catch (error) {
      if (mode === 'elias') { showEliasWelcome(); showToast(error.message, true); }
    }
  });
  eliasConversationSelect?.addEventListener('change', event => loadEliasConversation(Number(event.target.value || 0), true));
  eliasNewConversation?.addEventListener('click', async () => {
    selectedEliasConversation = 0;
    eliasConversationSelect.value = '0';
    showEliasWelcome();
    messageInput.focus();
  });

  deleteButton?.addEventListener('click', () => {
    if (mode === 'elias' && !selectedEliasConversation) return;
    if (mode === 'human' && !selectedConversation) return;
    deleteText.textContent = mode === 'elias'
      ? 'Este chat con ELÍAS dejará de aparecer en tu historial.'
      : 'La conversación dejará de aparecer en tu lista. Los demás participantes conservarán sus mensajes.';
    openModal(deleteModal);
  });
  confirmDelete?.addEventListener('click', async () => {
    confirmDelete.disabled = true;
    try {
      if (mode === 'elias') {
        await eliasApi({action:'delete_conversation', conversation_id:selectedEliasConversation});
        selectedEliasConversation = 0;
        closeModals();
        await refreshEliasHistory(0, true);
      } else {
        const body = new FormData();
        body.append('csrf_token', csrf);
        body.append('conversation_id', String(selectedConversation));
        const data = await api('api/mensajeria_eliminar.php', {method:'POST', body});
        selectedConversation = 0;
        conversationInput.value = '0';
        deleteButton.hidden = true;
        closeModals();
        updateGlobalBadge(data.unread || 0);
        await loadConversations();
      }
      showToast('Conversación eliminada.');
    } catch (error) {
      showToast(error.message, true);
    } finally {
      confirmDelete.disabled = false;
    }
  });

  document.getElementById('chatSearch')?.addEventListener('input', event => {
    const term = String(event.target.value || '').toLowerCase().trim();
    document.querySelectorAll('.chat-conversation-item').forEach(item => {
      item.hidden = term !== '' && !String(item.dataset.searchName || '').includes(term);
    });
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

  loadConversations();
  if (mode === 'elias') openElias();
  else if (selectedConversation) loadConversation(selectedConversation, true);
  else setComposerMode('human');

  pollTimer = setInterval(() => {
    if (mode === 'human' && selectedConversation && !document.hidden) loadConversation(selectedConversation, false);
  }, 5000);
  conversationsTimer = setInterval(() => { if (!document.hidden) loadConversations(); }, 10000);
  window.addEventListener('beforeunload', () => {
    clearInterval(pollTimer);
    clearInterval(conversationsTimer);
  });
})();

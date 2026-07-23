document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('assistant-form');
  const input = document.getElementById('assistant-input');
  const messages = document.getElementById('assistant-messages');
  if (!form || !input || !messages) return;

  function requestId() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return Date.now().toString(36) + Math.random().toString(36).slice(2);
  }

  async function api(payload) {
    const response = await fetch('api/asistente.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.ASSISTANT_CONFIG.csrfToken },
      body: JSON.stringify(Object.assign({ csrf_token: window.ASSISTANT_CONFIG.csrfToken }, payload))
    });
    let data = null;
    try { data = await response.json(); } catch (error) { data = null; }
    if (!response.ok || !data || data.ok !== true) throw new Error(data && data.message ? data.message : 'No se ha podido completar la consulta.');
    return data;
  }

  function addMessage(role, content, citations, messageId) {
    const welcome = messages.querySelector('.assistant-welcome');
    if (welcome) welcome.remove();
    const article = document.createElement('article');
    article.className = 'assistant-message ' + (role === 'user' ? 'user' : 'bot');
    if (messageId) article.dataset.messageId = messageId;
    const body = document.createElement('div');
    body.className = 'assistant-message-content';
    body.textContent = content;
    article.appendChild(body);
    if (role === 'assistant' && citations && citations.length) {
      const sources = document.createElement('div');
      sources.className = 'assistant-sources';
      const title = document.createElement('strong'); title.textContent = 'Fuentes'; sources.appendChild(title);
      citations.forEach(function (citation) {
        const item = document.createElement('span');
        item.textContent = (citation.filename || 'Documento') + (citation.page ? ' · pág. ' + citation.page : '');
        sources.appendChild(item);
      });
      article.appendChild(sources);
    }
    if (role === 'assistant' && messageId) {
      const feedback = document.createElement('div'); feedback.className = 'assistant-feedback';
      [[1, '👍', 'Respuesta útil'], [-1, '👎', 'Respuesta no útil']].forEach(function (entry) {
        const button = document.createElement('button'); button.type = 'button'; button.dataset.rating = entry[0]; button.textContent = entry[1]; button.setAttribute('aria-label', entry[2]); feedback.appendChild(button);
      });
      article.appendChild(feedback);
    }
    messages.appendChild(article);
    messages.scrollTop = messages.scrollHeight;
    return article;
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    const question = input.value.trim();
    if (!question) return;
    const submit = form.querySelector('button[type="submit"]');
    input.value = ''; submit.disabled = true; input.disabled = true;
    addMessage('user', question, [], null);
    const pending = addMessage('assistant', 'Consultando la documentación…', [], null);
    pending.classList.add('pending');
    try {
      const data = await api({ action: 'send', brand_id: window.ASSISTANT_CONFIG.brandId, conversation_id: window.ASSISTANT_CONFIG.conversationId, message: question, request_id: requestId() });
      window.ASSISTANT_CONFIG.conversationId = data.conversation_id;
      pending.remove();
      addMessage('assistant', data.answer, data.citations || [], data.message_id);
      const url = new URL(window.location.href);
      url.searchParams.set('brand_id', window.ASSISTANT_CONFIG.brandId);
      url.searchParams.set('conversation_id', data.conversation_id);
      window.history.replaceState({}, '', url);
    } catch (error) {
      pending.querySelector('.assistant-message-content').textContent = error.message;
      pending.classList.remove('pending'); pending.classList.add('error');
    } finally {
      submit.disabled = false; input.disabled = false; input.focus();
    }
  });

  messages.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-rating]');
    if (!button) return;
    const article = button.closest('[data-message-id]');
    if (!article) return;
    try {
      await api({ action: 'feedback', message_id: Number(article.dataset.messageId), rating: Number(button.dataset.rating) });
      article.querySelectorAll('[data-rating]').forEach(function (item) { item.classList.toggle('selected', item === button); });
    } catch (error) { /* La valoración no interrumpe la conversación. */ }
  });

  messages.scrollTop = messages.scrollHeight;
});

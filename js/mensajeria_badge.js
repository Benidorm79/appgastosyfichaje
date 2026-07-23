(() => {
  const launchers = Array.from(document.querySelectorAll('[data-chat-open]'));
  if (!launchers.length) return;
  const apiUrl = launchers[0].dataset.chatApi || 'api/mensajeria_estado.php';
  let lastCount = Number(sessionStorage.getItem('chatUnreadCount') || 0);
  let initialized = false;

  function updateBadge(count) {
    count = Math.max(0, Number(count || 0));
    document.querySelectorAll('[data-chat-badge]').forEach(badge => {
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.hidden = count <= 0;
    });
    sessionStorage.setItem('chatUnreadCount', String(count));
  }

  function showToast(latest) {
    if (!latest) return;
    let toast = document.getElementById('globalChatToast');
    if (!toast) {
      toast = document.createElement('button');
      toast.type = 'button';
      toast.id = 'globalChatToast';
      toast.className = 'global-chat-toast';
      document.body.appendChild(toast);
      toast.addEventListener('click', () => launchers[0]?.click());
    }
    const preview = latest.mensaje ? latest.mensaje : (latest.tipo === 'imagen' ? 'Ha enviado una imagen' : latest.tipo === 'video' ? 'Ha enviado un vídeo' : 'Ha enviado un archivo');
    toast.innerHTML = `<strong>${escapeHtml(latest.sender_name || 'Nuevo mensaje')}</strong><span>${escapeHtml(preview)}</span>`;
    toast.classList.add('show');
    clearTimeout(showToast.timer);
    showToast.timer = setTimeout(() => toast.classList.remove('show'), 6000);

    if ('Notification' in window && Notification.permission === 'granted') {
      try { new Notification(latest.sender_name || 'Nuevo mensaje', {body: preview, icon: '/android-chrome-192x192.png'}); } catch (_) {}
    }
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
  }

  async function poll() {
    try {
      const response = await fetch(apiUrl, {credentials: 'same-origin', cache: 'no-store'});
      if (!response.ok) return;
      const data = await response.json();
      const count = Number(data.unread || 0);
      updateBadge(count);
      if (initialized && count > lastCount) showToast(data.latest || null);
      lastCount = count;
      initialized = true;
    } catch (_) {}
  }

  launchers.forEach(link => {
    link.addEventListener('click', event => {
      const href = link.getAttribute('href') || 'mensajeria.php';
      if (window.innerWidth > 760) {
        event.preventDefault();
        const width = Math.min(1180, Math.max(820, window.screen.availWidth - 160));
        const height = Math.min(820, Math.max(650, window.screen.availHeight - 100));
        const left = Math.max(0, Math.round((window.screen.availWidth - width) / 2));
        const top = Math.max(0, Math.round((window.screen.availHeight - height) / 2));
        const popup = window.open(href, 'appInternalChat', `popup=yes,width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`);
        if (!popup) window.location.href = href;
        else popup.focus();
      }
    });
  });

  window.addEventListener('message', event => {
    if (event.origin !== window.location.origin || event.data?.type !== 'chat-unread') return;
    lastCount = Number(event.data.count || 0);
    updateBadge(lastCount);
  });

  poll();
  setInterval(() => { if (!document.hidden) poll(); }, 15000);
})();

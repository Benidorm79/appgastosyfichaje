document.addEventListener('DOMContentLoaded', function () {
  const button = document.getElementById('exportRegistroJornada') || document.getElementById('descargarRegistroJornada');
  const form = document.getElementById('automation-form');

  function showOverlay(message) {
    if (window.AppProcessing && typeof window.AppProcessing.show === 'function') {
      window.AppProcessing.show(message || 'Estamos preparando el registro de jornada. Espera unos segundos, por favor.');
    }
  }

  function hideOverlay() {
    if (window.AppProcessing && typeof window.AppProcessing.forceHide === 'function') {
      window.AppProcessing.forceHide();
      return;
    }
    if (window.AppProcessing && typeof window.AppProcessing.hide === 'function') {
      window.AppProcessing.hide();
    }
  }

  function showMessage(type, text, linkUrl) {
    hideOverlay();
    const box = document.getElementById('form-message');
    if (!box) {
      alert(text);
      return;
    }
    box.className = type === 'success' ? 'success' : 'error';
    box.innerHTML = '';
    const div = document.createElement('div');
    div.textContent = text;
    box.appendChild(div);
    if (linkUrl) {
      const a = document.createElement('a');
      a.className = 'message-file-link';
      a.href = linkUrl;
      a.target = '_blank';
      a.rel = 'noopener';
      a.textContent = 'Abrir archivo';
      box.appendChild(a);
    }
    box.style.display = 'block';
  }

  function buildPayload() {
    if (window.FICHAJE_EXPORT) return window.FICHAJE_EXPORT;

    const mes = document.getElementById('mes');
    const anio = document.getElementById('año') || document.getElementById('anio');
    return {
      mes: mes ? parseInt(mes.value, 10) : 0,
      anio: anio ? parseInt(anio.value, 10) : 0,
      user_id: 0
    };
  }

  function procesar(buttonRef) {
    const payload = buildPayload();

    if (!payload.mes || !payload.anio) {
      showMessage('error', 'Debes seleccionar mes y año.');
      return;
    }

    const original = buttonRef ? buttonRef.textContent : '';
    if (buttonRef) {
      buttonRef.disabled = true;
      buttonRef.textContent = 'Procesando...';
    }

    showOverlay('Estamos preparando el registro de jornada. Espera unos segundos, por favor.');

    fetch('procesar_descargar_fichaje.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (response) {
        return response.text().then(function (text) {
          let json = null;
          try { json = text ? JSON.parse(text) : null; } catch (e) { json = null; }
          return { ok: response.ok, status: response.status, text: text, json: json };
        });
      })
      .then(function (result) {
        if (!result.ok || !result.json || result.json.ok === false) {
          throw new Error((result.json && result.json.message) ? result.json.message : 'No se pudo generar el registro de jornada.');
        }
        showMessage('success', result.json.message || 'Registro de jornada generado correctamente.', result.json.file_url || null);
      })
      .catch(function (error) {
        showMessage('error', error.message || 'No se pudo generar el registro de jornada.');
      })
      .finally(function () {
        hideOverlay();
        if (buttonRef) {
          buttonRef.disabled = false;
          buttonRef.textContent = original;
        }
      });
  }

  if (button) {
    button.addEventListener('click', function () {
      procesar(button);
    });
  }

  if (form) {
    form.addEventListener('click', function (event) {
      const target = event.target;
      if (target && target.name === 'accion' && target.value === 'jornada') {
        event.preventDefault();
        procesar(target);
      }
    });
  }
});

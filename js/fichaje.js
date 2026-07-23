document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('fichaje-form');
  const accionInput = document.getElementById('accion');
  const submitButton = document.getElementById('fichajeSubmit');
  const motivo = document.getElementById('motivo');
  const nota = document.getElementById('nota');
  const notaBox = document.getElementById('notaSalidaBox');

  function showOverlay(message) {
    if (window.AppProcessing && typeof window.AppProcessing.show === 'function') {
      window.AppProcessing.show(message || 'Estamos registrando la información. Espera unos segundos, por favor.');
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

  function showMessage(type, text) {
    hideOverlay();
    const box = document.getElementById('form-message');
    if (!box) {
      alert(text);
      return;
    }
    box.className = type === 'success' ? 'success' : 'error';
    box.textContent = text;
    box.style.display = 'block';
  }

  function refreshPageSoon() {
    window.setTimeout(function () {
      window.location.reload();
    }, 900);
  }

  if (motivo) {
    motivo.addEventListener('change', function () {
      if (notaBox) {
        notaBox.classList.toggle('fichaje-hidden', motivo.value !== 'otro');
      }
    });
  }

  if (!form) return;

  form.addEventListener('submit', function (event) {
    event.preventDefault();

    const accion = accionInput ? accionInput.value : '';
    const originalText = submitButton ? submitButton.textContent : '';

    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = 'Procesando...';
    }

    showOverlay('Estamos registrando la información. Espera unos segundos, por favor.');

    fetch('procesar_fichaje.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        accion: accion,
        motivo: motivo ? motivo.value : '',
        nota: nota ? nota.value : ''
      })
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
          throw new Error((result.json && result.json.message) ? result.json.message : 'No se pudo registrar el fichaje.');
        }
        showMessage('success', result.json.message || 'Fichaje registrado correctamente.');
        refreshPageSoon();
      })
      .catch(function (error) {
        showMessage('error', error.message || 'No se pudo registrar el fichaje.');
      })
      .finally(function () {
        hideOverlay();
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = originalText;
        }
      });
  });
});

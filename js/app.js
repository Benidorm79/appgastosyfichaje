document.addEventListener('DOMContentLoaded', function () {
  const expenseForm = document.getElementById('expense-form');
  const automationForm = document.getElementById('automation-form');

  function configValue(key, fallback) {
    if (window.APP_CONFIG && window.APP_CONFIG[key] !== undefined && window.APP_CONFIG[key] !== null && window.APP_CONFIG[key] !== '') {
      return window.APP_CONFIG[key];
    }
    return fallback;
  }

  function userId() {
    const input = document.getElementById('user_id');
    return parseInt(input && input.value ? input.value : configValue('USER_ID', 0), 10) || 0;
  }

  function showProcessing(message) {
    if (window.AppProcessing && typeof window.AppProcessing.show === 'function') {
      window.AppProcessing.show(message || 'Estamos preparando la operación. Espera unos segundos, por favor.');
    }
  }

  function hideProcessing() {
    if (!window.AppProcessing) return;
    if (typeof window.AppProcessing.forceHide === 'function') window.AppProcessing.forceHide();
    else if (typeof window.AppProcessing.hide === 'function') window.AppProcessing.hide();
  }

  function showMessage(type, message, url) {
    hideProcessing();
    const box = document.getElementById('form-message');
    const safeMessage = message || (type === 'success' ? 'Operación completada.' : 'No se ha podido completar la operación. Inténtalo de nuevo.');

    if (!box) {
      window.alert(safeMessage);
      return;
    }

    box.className = type === 'success' ? 'success' : 'error';
    box.replaceChildren();

    const text = document.createElement('div');
    text.textContent = safeMessage;
    box.appendChild(text);

    if (url) {
      const link = document.createElement('a');
      link.className = 'message-file-link';
      link.href = url;
      link.target = '_blank';
      link.rel = 'noopener';
      link.textContent = 'Abrir archivo';
      box.appendChild(link);
    }

    box.style.display = 'block';
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function clearMessage() {
    const box = document.getElementById('form-message');
    if (!box) return;
    box.className = '';
    box.replaceChildren();
    box.style.display = 'none';
  }

  async function jsonRequest(url, payload) {
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    let data = null;
    try {
      data = await response.json();
    } catch (error) {
      data = null;
    }

    if (!response.ok || !data || data.ok !== true) {
      throw new Error(data && data.message ? data.message : 'No se ha podido completar la operación. Inténtalo de nuevo.');
    }

    return data;
  }

  function readFile(file) {
    return new Promise(function (resolve, reject) {
      const reader = new FileReader();
      reader.onload = function () { resolve(reader.result); };
      reader.onerror = function () { reject(new Error('No se ha podido leer la imagen seleccionada.')); };
      reader.readAsDataURL(file);
    });
  }

  if (expenseForm) {
    expenseForm.addEventListener('submit', async function (event) {
      event.preventDefault();
      clearMessage();

      const submit = expenseForm.querySelector('button[type="submit"]');
      const photoInput = document.getElementById('foto');
      const commercialInput = document.getElementById('comercial');
      const selectedUserId = userId();

      if (!selectedUserId) {
        showMessage('error', 'No se ha podido identificar al usuario. Vuelve a iniciar sesión.');
        return;
      }

      if (!photoInput || !photoInput.files || photoInput.files.length !== 1) {
        showMessage('error', 'Selecciona una única imagen del ticket.');
        return;
      }

      const file = photoInput.files[0];
      const originalLabel = submit ? submit.textContent : '';

      if (submit) {
        submit.disabled = true;
        submit.textContent = 'Procesando...';
      }
      showProcessing('Estamos registrando el gasto. Espera unos segundos, por favor.');

      try {
        const imageData = await readFile(file);
        const data = await jsonRequest('procesar_gasto.php', {
          csrf_token: configValue('CSRF_TOKEN', ''),
          viaje: document.getElementById('viaje').value,
          motivo: document.getElementById('motivo').value,
          comentarios: document.getElementById('comentarios').value,
          foto: {
            name: file.name || 'ticket.jpeg',
            type: file.type || 'image/jpeg',
            data: imageData
          }
        });

        const commercial = commercialInput ? commercialInput.value : '';
        expenseForm.reset();
        if (commercialInput) commercialInput.value = commercial;

        if (window.GastoConfirmacion) {
          window.GastoConfirmacion.show({
            id: data.registro_id,
            tipo: 'Gasto con ticket',
            motivo: data.motivo,
            fecha: data.fecha_ticket,
            importe: data.importe
          });
        } else {
          showMessage('success', 'Gasto registrado correctamente.');
        }
      } catch (error) {
        showMessage('error', error.message);
      } finally {
        hideProcessing();
        if (submit) {
          submit.disabled = false;
          submit.textContent = originalLabel || 'Enviar gasto';
        }
      }
    });
  }

  if (automationForm) {
    automationForm.addEventListener('submit', async function (event) {
      event.preventDefault();
      clearMessage();

      const submit = event.submitter;
      const action = submit ? submit.value : 'excel';
      const month = parseInt(document.getElementById('mes').value, 10);
      const year = parseInt(document.getElementById('año').value, 10);

      if (!month || !year) {
        showMessage('error', 'Selecciona el mes y el año.');
        return;
      }

      const originalLabel = submit ? submit.textContent : '';
      if (submit) {
        submit.disabled = true;
        submit.textContent = 'Procesando...';
      }
      showProcessing('Estamos preparando la descarga. Espera unos segundos, por favor.');

      try {
        const basePayload = {
          csrf_token: configValue('CSRF_TOKEN', ''),
          user_id: userId(),
          comercial: document.getElementById('comercial').value,
          mes: month,
          anio: year,
          año: year
        };

        let endpoint = 'procesar_descargar_nota.php';
        if (action === 'efectivo_kms') endpoint = 'procesar_descargar_efectivo_kms.php';
        if (action === 'tickets') endpoint = 'procesar_tickets_pdf.php';

        const data = await jsonRequest(endpoint, basePayload);
        const fileUrl = data.file_url || data.excel_file_url || data.pdf_file_url || null;
        showMessage('success', data.message || 'La descarga está preparada.', fileUrl);
      } catch (error) {
        showMessage('error', error.message);
      } finally {
        hideProcessing();
        if (submit) {
          submit.disabled = false;
          submit.textContent = originalLabel;
        }
      }
    });
  }
});

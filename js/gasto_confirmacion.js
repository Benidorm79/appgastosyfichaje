window.GastoConfirmacion = {
  show(data) {
    const config = data || {};
    let modal = document.getElementById('expense-confirm-modal');

    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'expense-confirm-modal';
      modal.className = 'expense-modal';
      modal.innerHTML = `
        <div class="expense-modal-card" role="dialog" aria-modal="true" aria-labelledby="expense-modal-title">
          <div class="expense-modal-icon">✓</div>
          <h2 id="expense-modal-title">Información enviada</h2>

          <div class="expense-summary">
            <div><span>🧾</span><strong id="ec-tipo"></strong></div>
            <div><span>📌</span><strong id="ec-motivo"></strong></div>
            <div><span>📅</span><strong id="ec-fecha"></strong></div>
            <div><span>💶</span><strong id="ec-importe"></strong></div>
          </div>

          <div class="expense-modal-actions">
            <button type="button" id="ec-ok">Todo correcto</button>
            <button type="button" id="ec-error" class="expense-modal-error">Corregir fecha o importe</button>
          </div>

          <form id="ec-form" class="expense-correction-form">
            <input type="hidden" id="ec-id">

            <div>
              <label for="ec-new-date">Fecha correcta</label>
              <input type="date" id="ec-new-date" required>
            </div>

            <div>
              <label for="ec-new-amount">Importe correcto</label>
              <input type="number" step="0.01" min="0.01" id="ec-new-amount" required>
            </div>

            <button type="submit">Guardar corrección</button>
          </form>
        </div>
      `;
      document.body.appendChild(modal);
    }

    const allowCorrection = config.allowCorrection !== false && Boolean(config.id);
    const amountNumber = Number(config.importe);
    const formattedAmount = Number.isFinite(amountNumber)
      ? amountNumber.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
      : (config.importe || '—');

    document.getElementById('expense-modal-title').textContent = config.title || 'Información enviada';
    document.getElementById('ec-tipo').textContent = config.tipo || 'Gasto';
    document.getElementById('ec-motivo').textContent = config.motivo || '—';
    document.getElementById('ec-fecha').textContent = config.fecha || '—';
    document.getElementById('ec-importe').textContent = formattedAmount + ' €';
    document.getElementById('ec-id').value = config.id || '';
    document.getElementById('ec-new-date').value = config.fecha || '';
    document.getElementById('ec-new-amount').value = Number.isFinite(amountNumber) ? amountNumber.toFixed(2) : '';
    document.getElementById('ec-form').classList.remove('is-visible');
    document.getElementById('ec-error').style.display = allowCorrection ? '' : 'none';

    const actions = modal.querySelector('.expense-modal-actions');
    actions.classList.toggle('single', !allowCorrection);

    modal.classList.add('open');

    document.getElementById('ec-ok').onclick = () => {
      modal.classList.remove('open');

      if (typeof config.onOk === 'function') {
        config.onOk();
        return;
      }

      if (config.reloadOnOk !== false) {
        window.location.reload();
      }
    };

    document.getElementById('ec-error').onclick = () => {
      document.getElementById('ec-form').classList.add('is-visible');
    };

    document.getElementById('ec-form').onsubmit = async (event) => {
      event.preventDefault();

      if (window.AppProcessing) {
        window.AppProcessing.show('Actualizando la información...');
      }

      try {
        const response = await fetch('corregir_gasto_rapido.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': (
              (window.APP_CONFIG && window.APP_CONFIG.CSRF_TOKEN)
              || (window.EK_CONFIG && window.EK_CONFIG.csrfToken)
              || (document.querySelector('meta[name="csrf-token"]') || {}).content
              || ''
            )
          },
          body: JSON.stringify({
            id: document.getElementById('ec-id').value,
            fecha_ticket: document.getElementById('ec-new-date').value,
            importe_detectado: document.getElementById('ec-new-amount').value,
            csrf_token: (
              (window.APP_CONFIG && window.APP_CONFIG.CSRF_TOKEN)
              || (window.EK_CONFIG && window.EK_CONFIG.csrfToken)
              || (document.querySelector('meta[name="csrf-token"]') || {}).content
              || ''
            )
          })
        });

        const result = await response.json();

        if (!result.ok) {
          throw new Error(result.message || 'No se pudo corregir.');
        }

        modal.classList.remove('open');
        window.location.reload();
      } catch (error) {
        window.alert(error.message);
      } finally {
        if (window.AppProcessing) {
          window.AppProcessing.forceHide();
        }
      }
    };
  }
};

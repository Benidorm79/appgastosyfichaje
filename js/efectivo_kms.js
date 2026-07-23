document.addEventListener('DOMContentLoaded', function () {
  const messageBox = document.getElementById('form-message');

  function hideProcessing() {
    if (window.AppProcessing && typeof window.AppProcessing.forceHide === 'function') window.AppProcessing.forceHide();
  }

  function show(type, text) {
    hideProcessing();
    if (!messageBox) return;
    messageBox.className = type === 'success' ? 'success' : 'error';
    messageBox.textContent = text || 'No se ha podido completar la operación. Inténtalo de nuevo.';
    messageBox.style.display = 'block';
    messageBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  async function request(url, payload) {
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.EK_CONFIG.csrfToken },
      body: JSON.stringify(payload)
    });
    let data = null;
    try { data = await response.json(); } catch (error) { data = null; }
    if (!response.ok || !data || data.ok !== true) {
      throw new Error(data && data.message ? data.message : 'No se ha podido completar la operación. Inténtalo de nuevo.');
    }
    return data;
  }

  function toDataUrl(file) {
    return new Promise(function (resolve, reject) {
      const reader = new FileReader();
      reader.onload = function () { resolve(reader.result); };
      reader.onerror = function () { reject(new Error('No se ha podido leer la imagen.')); };
      reader.readAsDataURL(file);
    });
  }

  const cashForm = document.getElementById('cash-form');
  if (cashForm) {
    cashForm.addEventListener('submit', async function (event) {
      event.preventDefault();
      const formData = new FormData(cashForm);
      const file = formData.get('imagen');
      if (!file || !file.size) {
        show('error', 'La imagen del ticket es obligatoria.');
        return;
      }
      if (file.size > 8388608) {
        show('error', 'La imagen supera el máximo de 8 MB.');
        return;
      }
      try {
        if (window.AppProcessing) window.AppProcessing.show('Guardando el gasto en efectivo...');
        const result = await request('procesar_efectivo.php', {
          csrf_token: window.EK_CONFIG.csrfToken,
          fecha: formData.get('fecha'),
          motivo: formData.get('motivo'),
          importe: formData.get('importe'),
          imagen: { name: file.name, type: file.type, data: await toDataUrl(file) }
        });
        hideProcessing();
        if (messageBox) messageBox.style.display = 'none';
        if (window.GastoConfirmacion) {
          window.GastoConfirmacion.show({ title: 'Gasto en efectivo enviado', tipo: 'Efectivo', motivo: formData.get('motivo'), fecha: formData.get('fecha'), importe: formData.get('importe'), id: result.id || '', allowCorrection: false });
        } else show('success', 'Gasto en efectivo registrado correctamente.');
        cashForm.reset();
      } catch (error) { show('error', error.message); }
    });
  }

  const kmForm = document.getElementById('km-form');
  if (!kmForm) return;

  const kmInput = kmForm.querySelector('[name="kilometros"]');
  const total = document.getElementById('km-total');
  const routeStops = document.getElementById('route-stops');
  const alternatives = document.getElementById('route-alternatives');
  const hiddenStops = kmForm.querySelector('[name="paradas_json"]');
  const hiddenDuration = kmForm.querySelector('[name="duracion_minutos"]');
  const hiddenPolyline = kmForm.querySelector('[name="ruta_polyline"]');
  const originInput = document.getElementById('route-origin');
  const destinationInput = document.getElementById('route-destination');
  let map = null;
  let routeLayer = null;

  function updateTotal() {
    const value = parseFloat(kmInput.value || 0) * Number(window.EK_CONFIG.priceKm || 0);
    total.textContent = value.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
  }
  kmInput.addEventListener('input', updateTotal);

  function stopInputs() { return Array.from(routeStops.querySelectorAll('input')); }
  function stopValues() { return stopInputs().map(function (input) { return input.value.trim(); }).filter(Boolean); }
  function syncStops() { hiddenStops.value = JSON.stringify(stopValues()); }
  function renumberStops() {
    Array.from(routeStops.children).forEach(function (row, index) {
      row.querySelector('.ek-route-stop-index').textContent = String(index + 1);
    });
  }

  function addStop() {
    if (routeStops.children.length >= 8) {
      show('error', 'Se permiten hasta 8 paradas intermedias.');
      return;
    }
    const row = document.createElement('div');
    row.className = 'ek-route-stop';
    const index = document.createElement('span');
    index.className = 'ek-route-stop-index';
    const input = document.createElement('input');
    input.type = 'text'; input.maxLength = 255; input.placeholder = 'Parada intermedia';
    const remove = document.createElement('button');
    remove.type = 'button'; remove.setAttribute('aria-label', 'Eliminar parada'); remove.textContent = '×';
    input.addEventListener('input', syncStops);
    remove.addEventListener('click', function () { row.remove(); renumberStops(); syncStops(); });
    row.append(index, input, remove);
    routeStops.appendChild(row);
    renumberStops();
  }
  document.getElementById('add-stop').addEventListener('click', addStop);

  function decodePolyline(encoded) {
    const points = [];
    let index = 0, lat = 0, lng = 0;
    while (index < encoded.length) {
      let result = 0, shift = 0, byte;
      do { byte = encoded.charCodeAt(index++) - 63; result |= (byte & 31) << shift; shift += 5; } while (byte >= 32);
      lat += (result & 1) ? ~(result >> 1) : (result >> 1);
      result = 0; shift = 0;
      do { byte = encoded.charCodeAt(index++) - 63; result |= (byte & 31) << shift; shift += 5; } while (byte >= 32);
      lng += (result & 1) ? ~(result >> 1) : (result >> 1);
      points.push([lat / 1e5, lng / 1e5]);
    }
    return points;
  }

  function drawRoute(encoded) {
    if (!encoded || !window.L) return;
    const points = decodePolyline(encoded);
    if (!points.length) return;
    const element = document.getElementById('route-map');
    element.classList.add('is-ready');
    if (!map) {
      map = L.map(element, { scrollWheelZoom: false });
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
      }).addTo(map);
    }
    if (routeLayer) map.removeLayer(routeLayer);
    routeLayer = L.polyline(points, { color: '#0f4c81', weight: 6, opacity: .88 }).addTo(map);
    map.fitBounds(routeLayer.getBounds(), { padding: [18, 18] });
    setTimeout(function () { map.invalidateSize(); }, 50);
  }

  function selectAlternative(route) {
    kmInput.value = route.kilometros;
    hiddenDuration.value = route.duracion_minutos || '';
    hiddenPolyline.value = route.polyline || '';
    updateTotal();
    drawRoute(route.polyline || '');
    alternatives.querySelectorAll('button').forEach(function (button) {
      button.classList.toggle('active', Number(button.dataset.index) === Number(route.index));
    });
  }

  function renderAlternatives(routes) {
    alternatives.replaceChildren();
    (routes || []).forEach(function (route, routeIndex) {
      const button = document.createElement('button');
      button.type = 'button'; button.dataset.index = route.index;
      const title = document.createElement('strong'); title.textContent = 'Ruta ' + (routeIndex + 1);
      const detail = document.createElement('span');
      detail.textContent = Number(route.kilometros).toLocaleString('es-ES', { maximumFractionDigits: 2 }) + ' km · ' + (route.duracion_minutos || 0) + ' min';
      button.append(title, detail);
      button.addEventListener('click', function () { selectAlternative(route); });
      alternatives.appendChild(button);
    });
  }

  document.getElementById('calc-route').addEventListener('click', async function () {
    const origin = originInput.value.trim();
    const destination = destinationInput.value.trim();
    if (!origin || !destination) {
      show('error', 'Indica origen y destino.');
      return;
    }
    try {
      if (window.AppProcessing) window.AppProcessing.show('Calculando la ruta...');
      const stops = stopValues();
      const result = await request('calcular_ruta.php', {
        csrf_token: window.EK_CONFIG.csrfToken,
        origen: { address: origin },
        destino: { address: destination },
        paradas: stops.map(function (address) { return { address: address }; })
      });
      const routeUrl = 'https://www.google.com/maps/dir/?api=1&origin=' + encodeURIComponent(origin)
        + '&destination=' + encodeURIComponent(destination)
        + (stops.length ? '&waypoints=' + encodeURIComponent(stops.join('|')) : '')
        + '&travelmode=driving';
      kmForm.querySelector('[name="ruta_url"]').value = routeUrl;
      renderAlternatives(result.alternativas || []);
      selectAlternative((result.alternativas && result.alternativas[0]) || result);
      syncStops();
      hideProcessing();
      if (messageBox) messageBox.style.display = 'none';
    } catch (error) { show('error', error.message); }
  });

  kmForm.addEventListener('submit', async function (event) {
    event.preventDefault();
    syncStops();
    const formData = Object.fromEntries(new FormData(kmForm));
    try {
      if (window.AppProcessing) window.AppProcessing.show('Guardando el kilometraje...');
      const result = await request('procesar_kilometraje.php', formData);
      hideProcessing();
      if (messageBox) messageBox.style.display = 'none';
      const amount = result.importe || (Number(formData.kilometros || 0) * Number(window.EK_CONFIG.priceKm || 0));
      if (window.GastoConfirmacion) {
        window.GastoConfirmacion.show({ title: 'Kilometraje enviado', tipo: 'Kilometraje', motivo: formData.motivo, fecha: formData.fecha, importe: amount, id: result.id || '', allowCorrection: false });
      } else show('success', 'Kilometraje registrado correctamente.');
      kmForm.reset(); updateTotal();
    } catch (error) { show('error', error.message); }
  });
});

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-assistant-batch]').forEach(function (form) {
    const fileInputs = Array.from(form.querySelectorAll('[data-batch-files], [data-batch-folder]'));
    const regularInput = form.querySelector('[data-batch-files]');
    const dropZone = form.querySelector('[data-batch-drop]');
    const selection = form.querySelector('[data-batch-selection]');
    const submitButton = form.querySelector('button[type="submit"]');
    const clearButton = form.querySelector('[data-batch-clear]');
    const refreshButton = form.querySelector('[data-batch-refresh]');
    const panel = form.querySelector('.assistant-batch-progress');
    const progress = panel.querySelector('progress');
    const summary = panel.querySelector('[data-batch-summary]');
    const results = panel.querySelector('[data-batch-results]');
    const maximum = Number(form.dataset.maxBytes || 0);
    const concurrency = Math.max(1, Math.min(2, Number(form.dataset.batchConcurrency || 2)));
    const initialPendingIds = String(form.dataset.pendingDocuments || '')
      .split(',')
      .map(function (value) { return Number(value); })
      .filter(function (value) { return Number.isInteger(value) && value > 0; });

    let queuedFiles = [];
    let retryMetadata = new Map();
    let running = false;
    let ignoredOnSelection = 0;

    function fileKey(file) {
      return [file.webkitRelativePath || file.name, file.size, file.lastModified].join('|');
    }

    function displayName(file) {
      return file.webkitRelativePath || file.name;
    }

    function isPdf(file) {
      return file.name.toLowerCase().endsWith('.pdf') || file.type === 'application/pdf';
    }

    function setControlsDisabled(disabled) {
      running = disabled;
      fileInputs.forEach(function (input) { input.disabled = disabled; });
      submitButton.disabled = disabled || queuedFiles.length === 0;
      clearButton.disabled = disabled || queuedFiles.length === 0;
    }

    function updateSelection() {
      const totalBytes = queuedFiles.reduce(function (total, file) {
        return total + Number(file.size || 0);
      }, 0);
      const megabytes = totalBytes / 1048576;
      const ocrRetries = queuedFiles.reduce(function (total, file) {
        const retry = retryMetadata.get(fileKey(file));
        return total + (retry && retry.forceOcr ? 1 : 0);
      }, 0);

      if (!queuedFiles.length) {
        selection.textContent = ignoredOnSelection > 0
          ? 'No hay PDF seleccionados. Se han omitido ' + ignoredOnSelection + ' archivos no compatibles.'
          : 'No hay archivos seleccionados.';
      } else {
        selection.textContent = queuedFiles.length
          + (queuedFiles.length === 1 ? ' PDF seleccionado' : ' PDF seleccionados')
          + ' · ' + megabytes.toLocaleString('es-ES', {maximumFractionDigits: 1}) + ' MB'
          + (ocrRetries > 0 ? ' · ' + ocrRetries + ' para reconocimiento de texto' : '')
          + (ignoredOnSelection > 0 ? ' · ' + ignoredOnSelection + ' no compatibles omitidos' : '');
      }

      const onlyOcrRetries = queuedFiles.length > 0 && queuedFiles.every(function (file) {
        const retry = retryMetadata.get(fileKey(file));
        return Boolean(retry && retry.forceOcr);
      });
      submitButton.textContent = onlyOcrRetries ? 'Reconocer texto' : 'Publicar lote';
      submitButton.disabled = running || queuedFiles.length === 0;
      clearButton.disabled = running || queuedFiles.length === 0;
    }

    function addFiles(fileList) {
      if (running) return;
      const existing = new Set(queuedFiles.map(fileKey));
      Array.from(fileList || []).forEach(function (file) {
        if (!isPdf(file)) {
          ignoredOnSelection += 1;
          return;
        }
        const key = fileKey(file);
        if (!existing.has(key)) {
          existing.add(key);
          queuedFiles.push(file);
        }
      });
      updateSelection();
    }

    function clearSelection() {
      if (running) return;
      queuedFiles = [];
      retryMetadata.clear();
      ignoredOnSelection = 0;
      fileInputs.forEach(function (input) { input.value = ''; });
      results.textContent = '';
      panel.hidden = true;
      updateSelection();
    }

    function createResultItem(name) {
      const item = document.createElement('li');
      item.className = 'pending';
      item.textContent = name + ' — Pendiente.';
      results.appendChild(item);
      return item;
    }

    function setTaskStatus(task, status, message) {
      task.status = status;
      task.message = message;
      const visualStatus = status === 'published'
        ? 'ok'
        : (status === 'not_sent' ? 'warning' : status);
      task.item.className = visualStatus;
      task.item.textContent = task.name + ' — ' + message;
    }

    function updateDocumentRow(documentId, status, message) {
      const row = document.querySelector('[data-document-row="' + documentId + '"]');
      if (!row) return;
      const cell = row.querySelector('[data-document-status]');
      if (!cell) return;
      cell.textContent = '';
      const pill = document.createElement('span');
      const statusClasses = {
        published: 'active',
        uploading: 'processing',
        needs_ocr: 'needs-ocr',
        error: 'error'
      };
      const statusLabels = {
        published: 'Publicado',
        uploading: 'Cargando',
        needs_ocr: 'Necesita reconocimiento de texto',
        error: 'Error'
      };
      pill.className = 'pill ' + (statusClasses[status] || 'inactive');
      pill.textContent = statusLabels[status] || 'Inactivo';
      cell.appendChild(pill);
      if (message) {
        const note = document.createElement('small');
        note.textContent = message;
        cell.appendChild(note);
      }
    }

    async function postForm(data) {
      const response = await fetch('asistente_accion.php', {
        method: 'POST',
        body: data,
        credentials: 'same-origin'
      });
      const raw = await response.text();
      let payload = null;
      try {
        payload = raw ? JSON.parse(raw) : null;
      } catch (_) {
        payload = null;
      }
      return {response: response, payload: payload, validJson: payload !== null};
    }

    async function prepareBatch() {
      const data = new FormData();
      data.append('csrf_token', form.elements.csrf_token.value);
      data.append('action', 'prepare_batch');
      data.append('batch_mode', '1');
      data.append('brand_id', form.elements.brand_id.value);
      try {
        const result = await postForm(data);
        if (!result.validJson) {
          return {
            ok: false,
            message: 'ELÍAS no ha podido iniciar la carga. No se ha enviado ningún PDF.',
            systemic: true
          };
        }
        if (!result.response.ok || result.payload.ok !== true) {
          return {
            ok: false,
            message: result.payload.message || 'ELÍAS no está disponible para recibir documentos.',
            systemic: true
          };
        }
        return {ok: true};
      } catch (_) {
        return {
          ok: false,
          message: 'No se ha podido iniciar la carga. No se ha enviado ningún PDF.',
          systemic: true
        };
      }
    }

    function sleep(ms) {
      return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    async function fetchBatchStatusItems() {
      const data = new FormData();
      data.append('csrf_token', form.elements.csrf_token.value);
      data.append('action', 'batch_status');
      data.append('batch_mode', '1');
      data.append('brand_id', form.elements.brand_id.value);
      try {
        const result = await postForm(data);
        if (!result.validJson || !result.response.ok || result.payload.ok !== true) return null;
        return result.payload.items || [];
      } catch (_) {
        return null;
      }
    }

    async function waitForDocumentPublication(task) {
      const maxAttempts = 40;
      const intervalMs = 6000;
      for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
        await sleep(intervalMs);
        const items = await fetchBatchStatusItems();
        if (!items) continue;
        const match = items.find(function (item) {
          return Number(item.document_id) === task.documentId;
        });
        if (!match) continue;
        if (match.status === 'published') {
          task.retryDocumentId = 0;
          task.forceOcr = false;
          task.retryable = false;
          task.systemic = false;
          setTaskStatus(task, 'published', match.message || 'Documento publicado y disponible para consultas.');
          return;
        }
        if (match.status === 'error') {
          task.retryDocumentId = task.documentId;
          task.retryable = Boolean(match.retryable);
          task.systemic = false;
          setTaskStatus(task, 'error', match.message || 'No se ha podido completar la publicación.');
          return;
        }
        setTaskStatus(task, 'uploading', 'Terminando de indexar el documento…');
      }
      task.retryDocumentId = task.documentId;
      task.retryable = true;
      task.systemic = false;
      setTaskStatus(
        task,
        'error',
        'La publicación está tardando más de lo esperado. Usa "Actualizar estado" para comprobarla más tarde.'
      );
    }

    async function uploadTask(task) {
      if (maximum > 0 && task.file.size > maximum) {
        task.retryable = false;
        task.systemic = false;
        setTaskStatus(task, 'error', 'Supera el tamaño permitido por documento.');
        return;
      }
      if (!isPdf(task.file)) {
        task.retryable = false;
        task.systemic = false;
        setTaskStatus(task, 'error', 'No es un archivo PDF válido.');
        return;
      }

      setTaskStatus(task, 'uploading', 'Enviando, leyendo y publicando…');
      const data = new FormData();
      data.append('csrf_token', form.elements.csrf_token.value);
      data.append('action', 'upload_document');
      data.append('batch_mode', '1');
      data.append('brand_id', form.elements.brand_id.value);
      data.append(
        'title',
        task.name
          .replace(/\.pdf$/i, '')
          .replace(/[\\/]+/g, ' · ')
          .replace(/[_-]+/g, ' ')
          .trim()
          .slice(0, 240)
      );
      data.append('document_type', form.elements.document_type.value || 'Manual');
      data.append('version_label', form.elements.version_label.value || '');
      data.append('effective_date', form.elements.effective_date.value || '');
      if (task.retryDocumentId > 0) data.append('retry_document_id', String(task.retryDocumentId));
      if (task.forceOcr) data.append('force_ocr', '1');
      data.append('document', task.file, task.file.name);

      try {
        const result = await postForm(data);
        if (!result.validJson) {
          task.retryable = true;
          task.systemic = false;
          setTaskStatus(
            task,
            'error',
            'No se ha recibido confirmación para este archivo (puede que tardara demasiado en procesarse). Se podrá reintentar.'
          );
          return;
        }

        const payload = result.payload;
        const message = payload.message || 'No se ha podido publicar el documento.';
        task.documentId = Number(payload.document_id || task.documentId || 0);

        if (result.response.ok && payload.ok === true && payload.status === 'published') {
          task.retryDocumentId = 0;
          task.forceOcr = false;
          task.retryable = false;
          task.systemic = false;
          setTaskStatus(task, 'published', message);
          return;
        }

        if (result.response.ok && payload.ok === true && payload.status === 'processing') {
          setTaskStatus(task, 'uploading', message || 'Documento recibido. Terminando de prepararlo para consultas…');
          await waitForDocumentPublication(task);
          return;
        }

        if (payload.code === 'duplicate' || /ya se hab[ií]a a[nñ]adido|duplicad/i.test(message)) {
          task.retryable = false;
          task.systemic = false;
          setTaskStatus(task, 'warning', message);
          return;
        }

        task.retryDocumentId = task.documentId;
        task.forceOcr = payload.code === 'needs_ocr';
        task.retryable = Boolean(payload.retryable);
        task.systemic = Boolean(payload.systemic);
        setTaskStatus(task, 'error', message);
      } catch (_) {
        task.retryable = true;
        task.systemic = false;
        setTaskStatus(
          task,
          'error',
          'La comunicación se ha interrumpido para este archivo. Se podrá reintentar.'
        );
      }
    }

    function taskCounts(tasks) {
      return tasks.reduce(function (counts, task) {
        if (task.status === 'published') counts.published += 1;
        else if (task.status === 'warning') counts.omitted += 1;
        else if (task.status === 'not_sent') counts.notSent += 1;
        else if (task.status === 'error') counts.failed += 1;
        else if (task.status === 'uploading') counts.uploading += 1;
        else counts.pending += 1;
        return counts;
      }, {published: 0, omitted: 0, notSent: 0, failed: 0, uploading: 0, pending: 0});
    }

    function updateBatchSummary(tasks, completed, total) {
      const counts = taskCounts(tasks);
      if (completed < total && counts.notSent === 0) {
        summary.textContent = 'Completados ' + completed + ' de ' + total
          + ' · ' + counts.published + ' publicados'
          + ' · ' + counts.failed + ' con incidencia.';
        return;
      }
      summary.textContent = 'Lote terminado: ' + counts.published + ' publicados, '
        + counts.omitted + ' duplicados, ' + counts.failed + ' con incidencia'
        + (counts.notSent > 0 ? ' y ' + counts.notSent + ' no enviados' : '') + '.';
    }

    async function runPool(tasks, maximumWorkers, controller, onCompleted) {
      let nextIndex = 0;
      async function worker() {
        while (!controller.stop && nextIndex < tasks.length) {
          const task = tasks[nextIndex];
          nextIndex += 1;
          await uploadTask(task);
          onCompleted();
          if (task.systemic) controller.stop = true;
        }
      }
      const workers = [];
      const workerCount = Math.min(maximumWorkers, tasks.length);
      for (let index = 0; index < workerCount; index += 1) workers.push(worker());
      await Promise.all(workers);
    }

    async function recoverLegacyDocuments() {
      if (!initialPendingIds.length || running) return;
      const data = new FormData();
      data.append('csrf_token', form.elements.csrf_token.value);
      data.append('action', 'batch_status');
      data.append('batch_mode', '1');
      data.append('brand_id', form.elements.brand_id.value);
      try {
        const result = await postForm(data);
        if (!result.validJson || !result.response.ok || result.payload.ok !== true) return;
        (result.payload.items || []).forEach(function (item) {
          updateDocumentRow(
            Number(item.document_id || 0),
            String(item.status || 'error'),
            String(item.message || '')
          );
        });
        if ((result.payload.items || []).length > 0) refreshButton.hidden = false;
      } catch (_) {
        return;
      }
    }

    fileInputs.forEach(function (input) {
      input.addEventListener('change', function () {
        addFiles(input.files);
        input.value = '';
      });
    });

    ['dragenter', 'dragover'].forEach(function (eventName) {
      dropZone.addEventListener(eventName, function (event) {
        event.preventDefault();
        if (!running) dropZone.classList.add('drag-over');
      });
    });

    ['dragleave', 'drop'].forEach(function (eventName) {
      dropZone.addEventListener(eventName, function (event) {
        event.preventDefault();
        dropZone.classList.remove('drag-over');
      });
    });

    dropZone.addEventListener('drop', function (event) {
      addFiles(event.dataTransfer ? event.dataTransfer.files : []);
    });

    dropZone.addEventListener('keydown', function (event) {
      if ((event.key === 'Enter' || event.key === ' ') && !running) {
        event.preventDefault();
        regularInput.click();
      }
    });

    clearButton.addEventListener('click', clearSelection);
    refreshButton.addEventListener('click', function () { window.location.reload(); });

    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      if (running || !queuedFiles.length) return;

      setControlsDisabled(true);
      panel.hidden = false;
      refreshButton.hidden = true;
      results.textContent = '';
      progress.max = queuedFiles.length;
      progress.value = 0;
      summary.textContent = 'Comprobando que ELÍAS puede recibir el lote…';

      const prepared = await prepareBatch();
      if (!prepared.ok) {
        summary.textContent = prepared.message;
        setControlsDisabled(false);
        updateSelection();
        return;
      }

      const files = queuedFiles.slice();
      const tasks = files.map(function (file) {
        const retry = retryMetadata.get(fileKey(file)) || {};
        return {
          file: file,
          name: displayName(file),
          status: 'pending',
          message: '',
          retryable: false,
          systemic: false,
          documentId: 0,
          retryDocumentId: Number(retry.documentId || 0),
          forceOcr: Boolean(retry.forceOcr),
          item: createResultItem(displayName(file))
        };
      });

      let completed = 0;
      const controller = {stop: false};
      await uploadTask(tasks[0]);
      completed += 1;
      progress.value = completed;
      updateBatchSummary(tasks, completed, tasks.length);
      if (tasks[0].systemic) controller.stop = true;

      if (!controller.stop && tasks.length > 1) {
        const remaining = tasks.slice(1);
        const workers = remaining.some(function (task) { return task.forceOcr; }) ? 1 : concurrency;
        await runPool(remaining, workers, controller, function () {
          completed += 1;
          progress.value = completed;
          updateBatchSummary(tasks, completed, tasks.length);
        });
      }

      tasks.forEach(function (task) {
        if (task.status === 'pending') {
          setTaskStatus(task, 'not_sent', 'No enviado para evitar repetir el mismo fallo.');
        }
      });

      const pendingForRetry = tasks.filter(function (task) {
        return (task.status === 'error' && task.retryable) || task.status === 'not_sent';
      });
      queuedFiles = pendingForRetry.map(function (task) { return task.file; });
      retryMetadata = new Map();
      pendingForRetry.forEach(function (task) {
        retryMetadata.set(fileKey(task.file), {
          documentId: Number(task.retryDocumentId || task.documentId || 0),
          forceOcr: Boolean(task.forceOcr)
        });
      });

      ignoredOnSelection = 0;
      progress.value = completed;
      updateBatchSummary(tasks, tasks.length, tasks.length);
      refreshButton.hidden = false;
      setControlsDisabled(false);
      updateSelection();
    });

    updateSelection();
    recoverLegacyDocuments();
  });
});

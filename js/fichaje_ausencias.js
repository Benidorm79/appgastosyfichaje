(function () {
  'use strict';

  var scrollStorageKey = 'fichaje_ausencias_calendar_scroll:' + window.location.pathname;

  function rememberCalendarScroll() {
    try {
      window.sessionStorage.setItem(scrollStorageKey, JSON.stringify({
        y: Math.max(0, window.scrollY || window.pageYOffset || 0),
        expires: Date.now() + 15000
      }));
    } catch (error) {
      // El navegador puede bloquear sessionStorage. En ese caso se mantiene la navegación normal.
    }
  }

  function restoreCalendarScroll() {
    var stored = null;

    try {
      stored = window.sessionStorage.getItem(scrollStorageKey);
      window.sessionStorage.removeItem(scrollStorageKey);
    } catch (error) {
      stored = null;
    }

    if (!stored) return;

    try {
      var state = JSON.parse(stored);
      if (!state || !Number.isFinite(Number(state.y)) || Number(state.expires || 0) < Date.now()) return;

      var targetY = Math.max(0, Number(state.y));
      var restore = function () {
        window.scrollTo({ top: targetY, left: 0, behavior: 'auto' });
      };

      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(restore);
      });

      window.setTimeout(restore, 120);
    } catch (error) {
      // Si el valor guardado no es válido, no se altera la posición de la página.
    }
  }

  restoreCalendarScroll();

  var calendar = document.getElementById('vacacionesCalendar');
  var startInput = document.getElementById('fecha_inicio');
  var endInput = document.getElementById('fecha_fin');
  var startDisplayInput = document.getElementById('fecha_inicio_visual');
  var endDisplayInput = document.getElementById('fecha_fin_visual');
  var selectedInput = document.getElementById('fechas_seleccionadas');
  var selectionToggle = document.getElementById('vacacionesSelectionToggle');
  var selectionClear = document.getElementById('vacacionesSelectionClear');
  var selectionSummary = document.getElementById('vacacionesSelectionSummary');
  var selectionMode = false;
  var selectionSource = 'manual';
  var rangeAnchor = '';
  var selectedDates = new Set();

  function normalizeRange(a, b) {
    return a <= b ? [a, b] : [b, a];
  }

  function parseLocalDate(value) {
    var parts = String(value || '').split('-');
    if (parts.length !== 3) return null;

    var year = Number(parts[0]);
    var month = Number(parts[1]);
    var day = Number(parts[2]);
    var date = new Date(year, month - 1, day, 12, 0, 0, 0);

    if (
      isNaN(date.getTime())
      || date.getFullYear() !== year
      || date.getMonth() !== month - 1
      || date.getDate() !== day
    ) {
      return null;
    }

    return date;
  }

  function formatLocalDate(date) {
    var year = date.getFullYear();
    var month = String(date.getMonth() + 1).padStart(2, '0');
    var day = String(date.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
  }

  function formatDisplayDate(value) {
    var parts = String(value || '').split('-');
    return parts.length === 3 ? parts.reverse().join('/') : value;
  }

  function parseDisplayDate(value) {
    var clean = String(value || '').trim().replace(/[.\-]/g, '/');
    var match = clean.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!match) return '';

    var day = Number(match[1]);
    var month = Number(match[2]);
    var year = Number(match[3]);
    var date = new Date(year, month - 1, day, 12, 0, 0, 0);

    if (
      isNaN(date.getTime())
      || date.getFullYear() !== year
      || date.getMonth() !== month - 1
      || date.getDate() !== day
      || year < 2020
      || year > 2100
    ) {
      return '';
    }

    return formatLocalDate(date);
  }

  function formatDateTyping(value) {
    var digits = String(value || '').replace(/\D/g, '').slice(0, 8);
    if (digits.length <= 2) return digits;
    if (digits.length <= 4) return digits.slice(0, 2) + '/' + digits.slice(2);
    return digits.slice(0, 2) + '/' + digits.slice(2, 4) + '/' + digits.slice(4);
  }

  function syncHiddenDatesFromDisplay(showError) {
    if (!startDisplayInput || !endDisplayInput || !startInput || !endInput) return true;

    var startIso = parseDisplayDate(startDisplayInput.value);
    var endIso = parseDisplayDate(endDisplayInput.value);

    startInput.value = startIso;
    endInput.value = endIso;

    startDisplayInput.setCustomValidity(startDisplayInput.value && !startIso ? 'Introduce una fecha válida con formato dd/mm/aaaa.' : '');
    endDisplayInput.setCustomValidity(endDisplayInput.value && !endIso ? 'Introduce una fecha válida con formato dd/mm/aaaa.' : '');

    if (showError && (!startIso || !endIso)) {
      if (!startIso) startDisplayInput.reportValidity();
      else endDisplayInput.reportValidity();
      return false;
    }

    return !!(startIso && endIso);
  }

  function syncDisplayDatesFromHidden() {
    if (startDisplayInput && startInput) startDisplayInput.value = startInput.value ? formatDisplayDate(startInput.value) : '';
    if (endDisplayInput && endInput) endDisplayInput.value = endInput.value ? formatDisplayDate(endInput.value) : '';
  }

  function datesBetween(a, b) {
    var range = normalizeRange(a, b);
    var current = parseLocalDate(range[0]);
    var end = parseLocalDate(range[1]);
    var result = [];
    if (!current || !end) return result;

    while (current <= end && result.length <= 370) {
      result.push(formatLocalDate(current));
      current.setDate(current.getDate() + 1);
    }
    return result;
  }

  function selectedArray() {
    return Array.from(selectedDates).sort();
  }

  function setSelectionMode(active) {
    selectionMode = !!active;
    if (selectionToggle) {
      selectionToggle.setAttribute('aria-pressed', selectionMode ? 'true' : 'false');
      selectionToggle.textContent = selectionMode ? 'Finalizar selección' : 'Seleccionar vacaciones o días libres';
      selectionToggle.classList.toggle('is-active', selectionMode);
    }
    if (calendar) calendar.classList.toggle('is-selection-mode', selectionMode);
  }

  function updateSelectionSummary(dates) {
    if (!selectionSummary) return;

    if (!dates.length) {
      selectionSummary.textContent = selectionSource === 'manual'
        ? 'Puedes indicar las fechas manualmente en el formulario inferior.'
        : 'Ningún día seleccionado';
      return;
    }

    if (dates.length === 1) {
      selectionSummary.textContent = (selectionSource === 'manual' ? 'Fecha indicada manualmente: ' : '1 día seleccionado: ') + formatDisplayDate(dates[0]);
      return;
    }

    selectionSummary.textContent = (selectionSource === 'manual' ? 'Rango indicado manualmente · ' : dates.length + ' días seleccionados · ')
      + formatDisplayDate(dates[0]) + ' → ' + formatDisplayDate(dates[dates.length - 1]);
  }

  function refreshSelection() {
    if (!calendar) return;
    var dates = selectedArray();
    var first = dates.length ? dates[0] : '';
    var last = dates.length ? dates[dates.length - 1] : '';

    calendar.querySelectorAll('.vacaciones-calendar-day[data-date]').forEach(function (day) {
      var date = day.getAttribute('data-date');
      var selected = selectedDates.has(date);
      day.classList.toggle('is-range-selected', selected);
      day.classList.toggle('is-range-start', selected && first === date);
      day.classList.toggle('is-range-end', selected && last === date);
    });
  }

  function syncCalendarSelectionToForm() {
    var dates = selectedArray();
    selectionSource = 'calendar';

    if (selectedInput) selectedInput.value = dates.join(',');
    if (startInput && endInput) {
      startInput.value = dates.length ? dates[0] : '';
      endInput.value = dates.length ? dates[dates.length - 1] : '';
      syncDisplayDatesFromHidden();
    }

    updateSelectionSummary(dates);
    refreshSelection();
  }

  function syncManualInputsToCalendar() {
    selectionSource = 'manual';
    rangeAnchor = '';
    selectedDates.clear();

    if (selectedInput) selectedInput.value = '';

    var start = startInput ? startInput.value : '';
    var end = endInput ? endInput.value : '';

    if (start && !end && endInput) {
      endInput.value = start;
      end = start;
    }

    if (start && end && start > end) {
      var normalized = normalizeRange(start, end);
      startInput.value = normalized[0];
      endInput.value = normalized[1];
      start = normalized[0];
      end = normalized[1];
    }

    if (parseLocalDate(start) && parseLocalDate(end || start)) {
      datesBetween(start, end || start).forEach(function (date) {
        selectedDates.add(date);
      });
    }

    var dates = selectedArray();
    updateSelectionSummary(dates);
    refreshSelection();
  }

  function clearSelection() {
    selectedDates.clear();
    rangeAnchor = '';
    selectionSource = 'calendar';
    if (selectedInput) selectedInput.value = '';
    if (startInput) startInput.value = '';
    if (endInput) endInput.value = '';
    syncDisplayDatesFromHidden();
    updateSelectionSummary([]);
    refreshSelection();
  }

  if (selectionToggle) {
    selectionToggle.addEventListener('click', function () {
      setSelectionMode(!selectionMode);
    });
  }

  if (selectionClear) {
    selectionClear.addEventListener('click', function () {
      clearSelection();
      setSelectionMode(true);
    });
  }

  if (calendar) {
    calendar.addEventListener('click', function (event) {
      var day = event.target.closest('.vacaciones-calendar-day[data-date]');
      if (!day) return;

      if (!selectionMode) {
        event.preventDefault();
        var agendaUrl = day.getAttribute('data-agenda-url');
        if (agendaUrl) {
          rememberCalendarScroll();
          window.location.assign(agendaUrl);
        }
        return;
      }

      event.preventDefault();
      selectionSource = 'calendar';
      var date = day.getAttribute('data-date');
      var multiKey = event.ctrlKey || event.metaKey;

      if (multiKey) {
        if (selectedDates.has(date)) selectedDates.delete(date);
        else selectedDates.add(date);
        rangeAnchor = '';
        syncCalendarSelectionToForm();
        return;
      }

      if (!rangeAnchor) {
        selectedDates.clear();
        selectedDates.add(date);
        rangeAnchor = date;
      } else {
        selectedDates.clear();
        datesBetween(rangeAnchor, date).forEach(function (rangeDate) {
          selectedDates.add(rangeDate);
        });
        rangeAnchor = '';
      }

      syncCalendarSelectionToForm();
    });
  }

  if (startInput && endInput && startDisplayInput && endDisplayInput) {
    [startDisplayInput, endDisplayInput].forEach(function (input) {
      input.addEventListener('input', function () {
        var cursorAtEnd = input.selectionStart === input.value.length;
        input.value = formatDateTyping(input.value);
        if (cursorAtEnd && input.setSelectionRange) {
          input.setSelectionRange(input.value.length, input.value.length);
        }
        syncHiddenDatesFromDisplay(false);
        syncManualInputsToCalendar();
      });

      input.addEventListener('blur', function () {
        syncHiddenDatesFromDisplay(false);
        syncManualInputsToCalendar();
      });
    });

    var absenceForm = startInput.closest('form');
    if (absenceForm) {
      absenceForm.addEventListener('submit', function (event) {
        if (!syncHiddenDatesFromDisplay(true)) {
          event.preventDefault();
          event.stopImmediatePropagation();
        }
      }, true);
    }

    syncDisplayDatesFromHidden();
    if (startInput.value || endInput.value) {
      syncManualInputsToCalendar();
    } else {
      updateSelectionSummary([]);
      refreshSelection();
    }
  }

  var allDay = document.querySelector('input[name="todo_el_dia"]');
  var startTime = document.getElementById('agenda_inicio');
  var endTime = document.getElementById('agenda_fin');

  function syncAllDay() {
    if (!allDay || !startTime || !endTime) return;
    startTime.disabled = allDay.checked;
    endTime.disabled = allDay.checked;
  }

  if (allDay) {
    allDay.addEventListener('change', syncAllDay);
    syncAllDay();
  }
})();

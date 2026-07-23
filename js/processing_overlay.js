(function () {
  var hideTimer = null;

  function ensureOverlay() {
    var overlay = document.getElementById('app-processing-overlay');

    if (overlay) {
      return overlay;
    }

    overlay = document.createElement('div');
    overlay.id = 'app-processing-overlay';
    overlay.className = 'app-processing-overlay';
    overlay.setAttribute('aria-live', 'polite');
    overlay.setAttribute('aria-hidden', 'true');
    overlay.style.display = 'none';
    overlay.innerHTML = '' +
      '<div class="app-processing-box" role="status">' +
        '<div class="app-processing-spinner" aria-hidden="true"></div>' +
        '<p class="app-processing-title">Procesando</p>' +
        '<p class="app-processing-text">Estamos preparando la operación. Espera unos segundos, por favor.</p>' +
      '</div>';

    document.body.appendChild(overlay);
    return overlay;
  }

  function dimPage(enable) {
    var children = Array.prototype.slice.call(document.body.children);

    children.forEach(function (child) {
      if (child.id === 'app-processing-overlay') {
        return;
      }

      if (enable) {
        child.classList.add('app-processing-dimmed');
      } else {
        child.classList.remove('app-processing-dimmed');
      }
    });
  }

  function show(message) {
    var overlay = ensureOverlay();
    var text = overlay.querySelector('.app-processing-text');

    if (hideTimer) {
      window.clearTimeout(hideTimer);
      hideTimer = null;
    }

    if (text && message) {
      text.textContent = message;
    }

    overlay.style.display = 'flex';
    dimPage(true);

    window.requestAnimationFrame(function () {
      overlay.classList.add('is-visible');
      overlay.setAttribute('aria-hidden', 'false');
    });

    return true;
  }

  function hide() {
    var overlay = document.getElementById('app-processing-overlay');

    if (!overlay) {
      dimPage(false);
      return;
    }

    overlay.classList.remove('is-visible');
    overlay.setAttribute('aria-hidden', 'true');
    dimPage(false);

    if (hideTimer) {
      window.clearTimeout(hideTimer);
    }

    hideTimer = window.setTimeout(function () {
      overlay.style.display = 'none';
      dimPage(false);
      hideTimer = null;
    }, 180);
  }

  function forceHide() {
    var overlay = document.getElementById('app-processing-overlay');

    if (hideTimer) {
      window.clearTimeout(hideTimer);
      hideTimer = null;
    }

    if (overlay) {
      overlay.classList.remove('is-visible');
      overlay.setAttribute('aria-hidden', 'true');
      overlay.style.display = 'none';
    }

    dimPage(false);
  }

  window.AppProcessing = {
    show: show,
    hide: hide,
    forceHide: forceHide
  };

  function bindProcessingForms() {
    document.querySelectorAll('form[data-processing-overlay], form[data-processing-form]').forEach(function (form) {
      if (form.dataset.processingBound === '1') return;
      form.dataset.processingBound = '1';
      form.addEventListener('submit', function (event) {
        if (event.defaultPrevented) return;
        var submitter = event.submitter || document.activeElement;
        if (submitter && submitter.disabled) return;
        var message = form.getAttribute('data-processing-message') || 'Estamos procesando la información. Espera unos segundos, por favor.';
        show(message);
      });
    });
  }

  document.addEventListener('DOMContentLoaded', bindProcessingForms);
  document.addEventListener('pageshow', function () {
    forceHide();
    bindProcessingForms();
  });
})();

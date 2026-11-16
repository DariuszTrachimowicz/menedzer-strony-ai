(function () {
  'use strict';

  var dialog = document.getElementById('asm-popup');
  var config = window.asmPopupConfig || {};
  if (!dialog) return;

  var key = 'asm_popup_dismissed_' + String(config.revision || 'default');
  var days = Math.max(0, Number(config.dismissDays || 0));
  var previousFocus = null;

  function dismissed() {
    if (config.preview) return false;
    try {
      var until = Number(window.localStorage.getItem(key) || 0);
      return days > 0 && until > Date.now();
    } catch (error) {
      return false;
    }
  }

  function cookieDialogActive() {
    return Boolean(document.querySelector('.cmplz-cookiebanner:not(.cmplz-dismissed), .cmplz-cookiebanner[aria-hidden="false"]'));
  }

  function focusables() {
    return Array.prototype.slice.call(dialog.querySelectorAll('button, a[href], input, select, textarea, [tabindex]:not([tabindex="-1"])'))
      .filter(function (node) { return !node.disabled && node.offsetParent !== null; });
  }

  function solid(color) {
    return Boolean(color) && color !== 'transparent' && !/,\s*0(\.0+)?\s*\)$/.test(color);
  }

  // Pierwszy widoczny element strony (poza popupem i paskiem administratora) pasujący do selektora.
  function sample(selector, needsBackground) {
    var nodes = document.querySelectorAll(selector);
    for (var i = 0; i < nodes.length; i += 1) {
      var node = nodes[i];
      if (dialog.contains(node) || node.closest('#wpadminbar') || node.offsetParent === null) continue;
      var style = window.getComputedStyle(node);
      if (!needsBackground || solid(style.backgroundColor)) return style;
    }
    return null;
  }

  // Styl „Jak strona”: czcionki, kolory i wygląd przycisku pochodzą z elementów tej strony.
  function applySiteStyle() {
    var set = function (name, value) { if (value) dialog.style.setProperty(name, value); };
    var body = window.getComputedStyle(document.body);
    var background = [body.backgroundColor, window.getComputedStyle(document.documentElement).backgroundColor].filter(solid)[0];
    set('--asm-popup-font', body.fontFamily);
    // Bez odczytanego tła zostaje domyślne jasne tło z ciemnym tekstem, żeby nie stracić kontrastu.
    if (background) { set('--asm-popup-bg', background); set('--asm-popup-text', body.color); }
    var heading = sample('h1, h2, h3', false);
    if (heading) { set('--asm-popup-heading-font', heading.fontFamily); set('--asm-popup-heading-weight', heading.fontWeight); }
    var button = sample('.elementor-button, .wp-block-button__link, .wp-element-button, button[type="submit"], input[type="submit"], a.button, .button, .btn', true);
    if (button) {
      set('--asm-popup-accent', button.backgroundColor);
      set('--asm-popup-accent-text', button.color);
      set('--asm-popup-btn-radius', button.borderTopLeftRadius);
      set('--asm-popup-radius', Math.min(parseFloat(button.borderTopLeftRadius) || 0, 20) + 'px');
      set('--asm-popup-btn-font', button.fontFamily);
      set('--asm-popup-btn-weight', button.fontWeight);
      set('--asm-popup-btn-spacing', button.letterSpacing);
      set('--asm-popup-btn-transform', button.textTransform);
      return;
    }
    var link = sample('main a, article a, .entry-content a, a', false);
    if (link) set('--asm-popup-accent', link.color);
  }

  function close() {
    dialog.hidden = true;
    document.documentElement.classList.remove('asm-popup-open');
    try {
      if (days > 0 && !config.preview) window.localStorage.setItem(key, String(Date.now() + days * 86400000));
    } catch (error) {}
    if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
  }

  function onKeydown(event) {
    if (dialog.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
      return;
    }
    if (event.key !== 'Tab') return;
    var nodes = focusables();
    if (!nodes.length) {
      event.preventDefault();
      dialog.focus();
      return;
    }
    var first = nodes[0];
    var last = nodes[nodes.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function openWhenReady(attempt) {
    if (dismissed()) return;
    if (cookieDialogActive() && attempt < 120) {
      window.setTimeout(function () { openWhenReady(attempt + 1); }, 500);
      return;
    }
    if (config.style === 'auto') applySiteStyle();
    previousFocus = document.activeElement;
    dialog.hidden = false;
    document.documentElement.classList.add('asm-popup-open');
    var nodes = focusables();
    (nodes[0] || dialog).focus();
  }

  dialog.addEventListener('click', function (event) {
    if (event.target.closest('[data-asm-close]')) close();
  });
  document.addEventListener('keydown', onKeydown);
  openWhenReady(0);
}());

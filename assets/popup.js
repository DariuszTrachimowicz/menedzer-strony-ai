(function () {
  'use strict';

  var dialog = document.getElementById('asm-popup');
  if (!dialog) return;
  var config = {
    revision: dialog.getAttribute('data-revision'),
    dismissDays: dialog.getAttribute('data-dismiss-days'),
    preview: dialog.getAttribute('data-preview') === '1',
    style: dialog.classList.contains('asm-popup--auto') ? 'auto' : ''
  };

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

  function focusables() {
    return Array.prototype.slice.call(dialog.querySelectorAll('button, a[href], input, select, textarea, [tabindex]:not([tabindex="-1"])'))
      .filter(function (node) { return !node.disabled && node.offsetParent !== null; });
  }

  // Widoczny kolor w znanym formacie. Czarne rgb(0, 0, 0) też jest widoczne; liczy się kanał alfa.
  function solid(color) {
    var parsed = parseColor(color);
    return Boolean(parsed) && parsed.alpha > 0;
  }

  // Kolor przyjmujemy tylko wtedy, gdy cały zapis pasuje do rgb(), rgba() albo color(srgb ...) z liczbami.
  // Przeglądarki podają tak kolory obliczone. Każdy inny zapis (display-p3, lab, none, nazwy) daje null
  // i nie bierze udziału w ocenie bieli, przezroczystości ani kontrastu.
  function parseColor(color) {
    var text = String(color).trim();
    var number = '(\\d+(?:\\.\\d+)?|\\.\\d+)';
    var match = new RegExp('^rgba?\\(\\s*' + number + '\\s*[,\\s]\\s*' + number + '\\s*[,\\s]\\s*' + number + '(?:\\s*[,/]\\s*' + number + ')?\\s*\\)$').exec(text);
    var scale = 1;
    if (!match) {
      match = new RegExp('^color\\(srgb\\s+' + number + '\\s+' + number + '\\s+' + number + '(?:\\s*/\\s*' + number + ')?\\s*\\)$').exec(text);
      scale = 255;
    }
    if (!match) return null;
    return { rgb: [Number(match[1]) * scale, Number(match[2]) * scale, Number(match[3]) * scale], alpha: match[4] === undefined ? 1 : Number(match[4]) };
  }

  function rgbOf(color) {
    var parsed = parseColor(color);
    return parsed ? parsed.rgb : null;
  }

  // Kolor półprzezroczysty wygląda inaczej, niż mówią jego składowe, więc nie nadaje się do oceny kontrastu.
  function opaque(color) {
    var parsed = parseColor(color);
    return Boolean(parsed) && parsed.alpha >= 1;
  }

  function nearWhite(color) {
    var rgb = rgbOf(color);
    return Boolean(rgb) && rgb[0] > 235 && rgb[1] > 235 && rgb[2] > 235;
  }

  function luminance(color) {
    var rgb = rgbOf(color);
    if (!rgb) return null;
    var linear = rgb.map(function (value) { value /= 255; return value <= 0.03928 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4); });
    return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2];
  }

  // Kontrast dwóch kolorów według WCAG; null, gdy któregoś nie da się odczytać.
  function contrast(first, second) {
    var a = luminance(first);
    var b = luminance(second);
    if (a === null || b === null) return null;
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  }

  function outside(node) {
    return !dialog.contains(node) && !node.closest('#wpadminbar, .cmplz-cookiebanner, #cmplz-cookiebanner-container, [role="dialog"]') && node.offsetParent !== null;
  }

  // Styl pierwszego widocznego elementu strony pasującego do selektora.
  function sample(selector) {
    var nodes = document.querySelectorAll(selector);
    for (var i = 0; i < nodes.length; i += 1) {
      if (outside(nodes[i])) return window.getComputedStyle(nodes[i]);
    }
    return null;
  }

  // Przycisk strony: kolor tła, który powtarza się najczęściej. Pierwszy z brzegu bywa przyciskiem cudzego widżetu.
  function siteButton() {
    var nodes = document.querySelectorAll('.elementor-button, .wp-block-button__link, .wp-element-button, a[class*="btn"], a[class*="button"], button[class*="btn"], button[class*="button"], button[type="submit"], input[type="submit"]');
    var groups = {};
    var best = null;
    for (var i = 0; i < nodes.length; i += 1) {
      if (!outside(nodes[i])) continue;
      var style = window.getComputedStyle(nodes[i]);
      var background = style.backgroundColor;
      // Tylko nieprzezroczyste tło w znanym formacie i z czytelnym napisem; biały przycisk nie jest kolorem marki.
      if (!opaque(background) || nearWhite(background) || !opaque(style.color) || (contrast(style.color, background) || 0) < 3) continue;
      var group = groups[background] || (groups[background] = { count: 0, style: style });
      group.count += 1;
      if (!best || group.count > best.count) best = group;
    }
    return best ? best.style : null;
  }

  // Styl „Jak strona”: czcionki, kolory i wygląd przycisku pochodzą z elementów tej strony.
  function applySiteStyle() {
    var set = function (name, value) { if (value) dialog.style.setProperty(name, value); };
    var body = window.getComputedStyle(document.body);
    var background = [body.backgroundColor, window.getComputedStyle(document.documentElement).backgroundColor].filter(function (color) { return solid(color) && opaque(color); })[0];
    set('--asm-popup-font', body.fontFamily);
    // Bez odczytanego nieprzezroczystego tła zostaje domyślne jasne tło z ciemnym tekstem, żeby nie stracić kontrastu.
    // Tło i tekst kopiujemy tylko jako czytelną parę; inaczej zostają oba kolory domyślne.
    var readable = Boolean(background) && opaque(body.color) && (contrast(body.color, background) || 0) >= 4.5;
    if (readable) { set('--asm-popup-bg', background); set('--asm-popup-text', body.color); }
    var heading = sample('h1, h2, h3');
    if (heading) {
      set('--asm-popup-heading-font', heading.fontFamily);
      set('--asm-popup-heading-weight', heading.fontWeight);
      // Nagłówek strony bywa jasny na ciemnej sekcji. Jego kolor bierzemy tylko, gdy jest czytelny na tle okna.
      if (readable && opaque(heading.color) && (contrast(heading.color, background) || 0) >= 4.5) set('--asm-popup-heading-color', heading.color);
    }
    var button = siteButton();
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
    var link = sample('main a, article a, .entry-content a, a');
    // Kolor linku staje się tłem przycisku z białym napisem, więc musi dawać z nim kontrast.
    if (link && opaque(link.color) && (contrast('rgb(255, 255, 255)', link.color) || 0) >= 3) set('--asm-popup-accent', link.color);
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

  // Popup pokazuje się od razu, także gdy na stronie jest jeszcze baner cookies.
  function open() {
    if (dismissed()) return;
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
  open();
}());

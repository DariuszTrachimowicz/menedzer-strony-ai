(() => {
  'use strict';
  if (window.jQuery && window.jQuery.fn.wpColorPicker) window.jQuery('.asm-color').wpColorPicker();
  const live = document.getElementById('asm-live');
  const say = text => { if (live) live.textContent = text; };
  const secret = document.querySelector('[data-secret]');
  const wipe = () => { if (secret) { secret.value = ''; secret.defaultValue = ''; secret.removeAttribute('value'); secret.type = 'password'; } };
  window.addEventListener('pagehide', wipe);
  window.addEventListener('pageshow', event => { if (event.persisted) wipe(); });
  document.getElementById('asm-show')?.addEventListener('click', event => {
    secret.type = secret.type === 'password' ? 'text' : 'password';
    event.currentTarget.setAttribute('aria-pressed',String(secret.type === 'text'));
    event.currentTarget.textContent = secret.type === 'text' ? 'Ukryj poświadczenie' : 'Pokaż poświadczenie';
  });
  document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
    const field = document.getElementById(button.dataset.copy);
    say('Kopiowanie wybranego elementu.');
    try { await navigator.clipboard.writeText(field.value); say('Skopiowano wybrany element.'); }
    catch { field.focus(); field.select(); say('Schowek jest niedostępny. Zaznaczono pole. Skopiuj je ręcznie skrótem klawiaturowym.'); }
  }));
  const toggle = document.querySelector('[data-popup-toggle]');
  if (toggle) {
    const status = document.getElementById('asm-popup-toggle-status');
    toggle.addEventListener('change', async () => {
      const wanted = toggle.checked;
      toggle.disabled = true;
      status.textContent = 'Zapisywanie.';
      try {
        const body = new URLSearchParams({ action: 'asm2_popup_toggle', nonce: toggle.dataset.nonce, enabled: wanted ? '1' : '0' });
        const response = await fetch(toggle.dataset.url, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.data && result.data.message ? result.data.message : 'Nie udało się zapisać. Spróbuj ponownie.');
        status.textContent = result.data.message;
      } catch (error) {
        // Stan pola wraca do zapisanego, żeby ekran nie pokazywał czegoś innego niż strona.
        toggle.checked = !wanted;
        status.textContent = error.message || 'Nie udało się zapisać. Spróbuj ponownie.';
      }
      toggle.disabled = false;
      toggle.focus();
    });
  }
  document.querySelectorAll('[data-needs-confirm]').forEach(button => { button.disabled = false; });
  document.querySelectorAll('[data-confirm]').forEach(form => form.addEventListener('submit', event => { if (!window.confirm(form.dataset.confirm)) { event.preventDefault(); form.querySelector('button[type=submit]')?.focus(); } }));
  document.querySelectorAll('[data-expiry-mode]').forEach(mode => {
    const date = mode.form.querySelector('[data-expiry-date]');
    const update = () => { date.disabled = mode.value === 'never'; date.required = mode.value === 'date'; };
    mode.addEventListener('change', update); update();
  });
  const form = document.getElementById('asm-wizard');
  if (form) {
    const one = document.getElementById('asm-step-one'), two = document.getElementById('asm-step-two');
    const next = document.getElementById('asm-next'), back = document.getElementById('asm-back'), create = document.getElementById('asm-create');
    two.hidden = true; create.hidden = true; next.hidden = false;
    two.disabled = true;
    next.addEventListener('click', () => {
      if (!document.getElementById('asm-name').reportValidity()) return;
      one.hidden = true; two.hidden = false; two.disabled = false; create.hidden = false; next.hidden = true; back.hidden = false;
      document.getElementById('asm-scope').focus();
    });
    back.addEventListener('click', () => { one.hidden = false; two.hidden = true; two.disabled = true; create.hidden = true; next.hidden = false; back.hidden = true; document.getElementById('asm-client').focus(); });
    form.addEventListener('keydown', event => { if (event.key === 'Enter' && !one.hidden) { event.preventDefault(); next.click(); } });
    form.addEventListener('submit', event => { if (!one.hidden) { event.preventDefault(); next.click(); } else { create.disabled = true; } });
  }
  document.querySelector('#asm-created h2')?.focus();
  document.getElementById('asm-error')?.focus();
  const poll = document.getElementById('asm-poll');
  if (poll) {
    let stopped = false;
    let timer;
    window.addEventListener('pagehide', () => { stopped = true; });
    window.addEventListener('pageshow', event => { if (event.persisted) { clearTimeout(timer); stopped = false; check(); } });
    const check = async () => {
      if (stopped) return;
      try {
        const body = new URLSearchParams({action:'asm_mcp_status',id:poll.dataset.id,nonce:poll.dataset.nonce});
        const response = await fetch(poll.dataset.url,{method:'POST',body,credentials:'same-origin',cache:'no-store'});
        const result = await response.json();
        if (!response.ok || !result.success) { poll.textContent = 'Utracono dostęp do sprawdzania stanu. Odśwież stronę i sprawdź listę połączeń.'; stopped = true; }
        else if (result.data.expired) { poll.textContent = 'Połączenie wygasło. Utwórz nowe na liście połączeń.'; stopped = true; }
        else if (result.data.deleted) { poll.textContent = 'Połączenie zostało usunięte.'; stopped = true; }
        else if (result.data.revoked) { poll.textContent = 'Dostęp został cofnięty.'; stopped = true; }
        else if (result.success && result.data.confirmed) { poll.textContent = `Potwierdzono rzeczywisty odczyt statusu: ${result.data.time}.`; stopped = true; }
      } catch { poll.textContent = 'Nie udało się odświeżyć stanu. Odśwież stronę i sprawdź listę połączeń.'; stopped = true; }
      if (!stopped) timer = setTimeout(check,5000);
    };
    timer = setTimeout(check,2000);
  }
})();

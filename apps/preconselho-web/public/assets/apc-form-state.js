(() => {
  const selector = '[data-apc-plan-form], [data-apc-card-form]';
  const states = new Map();
  let leaving = false;
  let sessionNeedsRefresh = false;

  const snapshot = form => JSON.stringify([...new FormData(form)].map(([name, value]) => [name,
    value instanceof File ? (value.name ? [value.name, value.size, value.lastModified] : null) : value]));
  const track = root => root.querySelectorAll(selector).forEach(form => {
    if (!states.has(form)) states.set(form, { saved: snapshot(form), busy: false });
  });
  const dirty = form => {
    const state = states.get(form);
    return !!state && (state.busy || snapshot(form) !== state.saved);
  };
  const status = (form, message, error = false) => {
    let output = form.querySelector('[data-apc-form-status]');
    if (!output) {
      output = document.createElement('p');
      output.className = 'helper';
      output.dataset.apcFormStatus = '';
      output.setAttribute('aria-live', 'polite');
      form.append(output);
    }
    output.setAttribute('role', error ? 'alert' : 'status');
    output.textContent = message;
  };
  const refresh = form => {
    if (states.has(form) && !states.get(form).busy) status(form,
      dirty(form) ? 'Alterações pendentes. Salve para não perder a edição.' : 'Nenhuma alteração pendente.');
  };
  track(document);
  if (!states.size) return;

  ['input', 'change'].forEach(type => document.addEventListener(type, event => {
    const form = event.target.closest(selector);
    if (form) queueMicrotask(() => refresh(form));
  }));
  // Curriculum buttons add/remove hidden fields after the click handler runs.
  document.addEventListener('click', event => {
    const form = event.target.closest('[data-apc-plan-form]');
    if (form && event.target.closest('button[type="button"]')) queueMicrotask(() => refresh(form));
  });
  window.addEventListener('beforeunload', event => {
    if (!leaving && [...states.keys()].some(form => form.isConnected && dirty(form))) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  const readPage = async response => {
    if (response.status === 419) {
      sessionNeedsRefresh = true;
      throw new Error('A sessão mudou ou expirou. A edição foi mantida; entre novamente em outra aba e tente salvar aqui.');
    }
    const text = await response.text();
    const type = response.headers.get('Content-Type') || '';
    if (type.includes('application/json')) {
      let data;
      try { data = JSON.parse(text); } catch { throw new Error('Resposta inválida. A edição foi mantida; tente salvar novamente.'); }
      throw new Error(typeof data.message === 'string' ? data.message : 'Não foi possível salvar. A edição foi mantida.');
    }
    const page = new DOMParser().parseFromString(text, 'text/html');
    if (!response.ok) throw new Error(page.querySelector('[role="alert"]')?.textContent.trim() ||
      'Não foi possível salvar. A edição foi mantida; tente novamente.');
    if (!response.redirected) throw new Error('O salvamento não foi confirmado. A edição foi mantida; confira a conexão e tente novamente.');
    return page;
  };

  const refreshSession = async form => {
    const response = await fetch(location.href, { credentials: 'same-origin', cache: 'no-store' });
    const target = new URL(response.url);
    const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
    const sameUser = document.body.dataset.sessionUser && fresh.body.dataset.sessionUser === document.body.dataset.sessionUser;
    const counterpart = [...fresh.querySelectorAll(selector)].find(candidate => candidate.action === form.action);
    const token = counterpart?.querySelector('input[name="_csrf"]')?.value;
    if (!response.ok || !sameUser || target.origin !== location.origin || target.pathname !== location.pathname || !token) {
      throw new Error('Não foi possível retomar esta edição. Entre novamente em outra aba; se a situação do plano mudou, copie os textos pendentes antes de atualizar esta página.');
    }
    document.querySelectorAll('input[name="_csrf"]').forEach(input => { input.value = token; });
    // A renewed security token is not a user edit.
    states.forEach((state, tracked) => {
      const saved = JSON.parse(state.saved);
      saved.forEach(entry => { if (entry[0] === '_csrf') entry[1] = token; });
      state.saved = JSON.stringify(saved);
    });
    sessionNeedsRefresh = false;
  };

  const replaceCard = (card, fresh, submitted) => {
    // Preserve every other unsaved form, including a file already chosen for upload.
    const pending = [...card.querySelectorAll('[data-apc-card-form]')].filter(form => form !== submitted && dirty(form));
    const replacements = pending.map(form => {
      const counterpart = [...fresh.querySelectorAll('[data-apc-card-form]')].find(candidate => candidate.action === form.action);
      if (!counterpart) throw new Error('O registro foi salvo, mas a situação do plano mudou. As edições pendentes foram mantidas nesta tela; copie-as antes de atualizar a página.');
      return [counterpart, form];
    });
    replacements.forEach(([counterpart, form]) => counterpart.replaceWith(form));
    card.replaceWith(fresh);
    for (const form of states.keys()) if (!form.isConnected) states.delete(form);
    track(fresh);
    // app.js binds confirmations on initial load; these are new controls from the server.
    fresh.querySelectorAll('[data-confirm]').forEach(button => button.addEventListener('click', event => {
      if (!window.confirm(button.dataset.confirm)) event.preventDefault();
    }));
  };

  document.addEventListener('submit', async event => {
    const form = event.target.closest(selector);
    if (!form || event.defaultPrevented) return;
    event.preventDefault();
    const state = states.get(form);
    if (!state || state.busy) return;
    const activeCard = form.closest('.apc-student-card');
    if (activeCard && [...states].some(([candidate, other]) => candidate !== form && activeCard.contains(candidate) && other.busy)) {
      status(form, 'Aguarde o salvamento desta ficha antes de continuar.');
      return;
    }
    const submitter = event.submitter;
    const url = submitter?.getAttribute('formaction') || form.action;
    const controls = [...form.querySelectorAll('input, select, textarea, button')].map(control => [control, control.disabled]);
    state.busy = true;
    controls.forEach(([control]) => { control.disabled = true; });
    form.setAttribute('aria-busy', 'true');
    status(form, 'Salvando…');
    try {
      if (sessionNeedsRefresh) await refreshSession(form);
      // Read the enabled controls after a possible token renewal.
      controls.forEach(([control, disabled]) => { control.disabled = disabled; });
      const body = new FormData(form);
      if (submitter?.name) body.append(submitter.name, submitter.value);
      controls.forEach(([control]) => { control.disabled = true; });
      const response = await fetch(url, {
        method: 'POST', body, credentials: 'same-origin', cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const page = await readPage(response);
      const target = new URL(response.url);
      if (target.origin !== location.origin) throw new Error('Destino inesperado. A edição foi mantida.');
      if (form.matches('[data-apc-plan-form]')) {
        const expected = /^\/apc\/planos\/\d+$/.test(target.pathname);
        if (!expected || !page.querySelector('[data-curriculum-plan]')) {
          sessionNeedsRefresh = true;
          throw new Error('A sessão pode ter expirado. A edição foi mantida; entre novamente em outra aba antes de tentar salvar.');
        }
        leaving = true;
        location.assign(response.url);
      } else {
        const card = form.closest('.apc-student-card');
        const fresh = page.getElementById(card.id);
        if (!fresh || target.pathname !== location.pathname) {
          sessionNeedsRefresh = true;
          throw new Error('A sessão pode ter expirado. A edição foi mantida; entre novamente em outra aba antes de tentar salvar.');
        }
        const hadFocus = card.contains(document.activeElement);
        const savedAction = form.action;
        replaceCard(card, fresh, form);
        const savedForm = [...fresh.querySelectorAll('[data-apc-card-form]')].find(candidate => candidate.action === savedAction);
        if (savedForm) status(savedForm, form.matches('[data-apc-delivery-form]') ? 'Entrega salva. As demais fichas foram mantidas.' : 'Anexos atualizados. As demais edições foram mantidas.');
        else status(fresh.querySelector('[data-apc-delivery-form]') || fresh, 'Anexos atualizados. As demais edições foram mantidas.');
        if (hadFocus) (savedForm || fresh.querySelector('form'))?.querySelector('button')?.focus({ preventScroll: true });
      }
    } catch (error) {
      status(form, error instanceof TypeError
        ? 'Não foi possível confirmar o salvamento. A edição foi mantida; confira a conexão e tente novamente.'
        : error instanceof Error ? error.message : 'Não foi possível salvar. A edição foi mantida.', true);
    } finally {
      state.busy = false;
      controls.forEach(([control, disabled]) => { control.disabled = disabled; });
      form.removeAttribute('aria-busy');
    }
  });
})();

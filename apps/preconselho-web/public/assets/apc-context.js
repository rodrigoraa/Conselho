(() => {
  const filterForms = document.querySelectorAll('[data-apc-server-filters]');
  filterForms.forEach(form => form.addEventListener('submit', () => {
    // A changed filter always starts on page one; the GET form has no page input.
    const anchor = form.elements.aba?.value === 'tracking' ? '#acompanhamento' : (form.elements.aba?.value === 'files' ? '#arquivos' : '#minhas-apcs');
    form.action = '/apc' + anchor;
  }));
  const legacyViews = {'#arquivos':'files','#acompanhamento':'tracking','#minhas-apcs':'mine','#apc-upload':'mine'};
  if (filterForms.length && !new URLSearchParams(location.search).has('aba')) {
    const view = legacyViews[location.hash];
    if (view && document.querySelector(`[data-apc-view-panel="${view}"][hidden]`)) {
      const url = new URL(location.href); url.searchParams.set('aba', view); location.replace(url.pathname + url.search + url.hash);
    }
  }
  document.querySelectorAll('[data-choose-apc-event]').forEach(link => link.addEventListener('click', () => {
    const url = new URL(location.href); url.searchParams.set('evento', link.dataset.chooseApcEvent); url.searchParams.set('aba', 'mine');
    url.hash = 'apc-upload'; history.replaceState(null, '', url.pathname + url.search + url.hash);
  }));
  const groups = [...document.querySelectorAll('[data-apc-file-group][data-professor-id]')];
  const key = 'apc-open-professors:' + (document.body.dataset.sessionUser || '');
  try {
    const open = new Set(JSON.parse(sessionStorage.getItem(key) || '[]'));
    groups.forEach(group => {
      if (open.has(group.dataset.professorId)) group.open = true;
      group.addEventListener('toggle', () => {
        if (group.open) open.add(group.dataset.professorId); else open.delete(group.dataset.professorId);
        sessionStorage.setItem(key, JSON.stringify([...open]));
      });
    });
  } catch (_) { /* The server links and open filters continue working without storage. */ }
})();

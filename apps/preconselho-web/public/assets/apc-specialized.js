(() => {
  'use strict';

  const search = document.querySelector('[data-apc-student-search]');
  const list = document.querySelector('[data-apc-student-list]');
  if (!search || !list) return;

  const status = document.querySelector('[data-apc-student-status]');
  const empty = document.querySelector('[data-apc-student-empty]');
  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR');

  const refresh = () => {
    const students = [...list.querySelectorAll('[data-apc-student-item]')];
    const query = normalize(search.value.trim());
    let visible = 0;
    students.forEach(student => {
      const name = student.dataset.studentName || '';
      const id = student.dataset.studentId || '';
      student.hidden = !normalize(`${name} ${id}`).includes(query);
      if (!student.hidden) visible += 1;
    });
    if (status) status.textContent = `Exibindo ${visible} de ${students.length} alunos. A busca não altera os registros.`;
    if (empty) empty.hidden = visible !== 0 || students.length === 0;
    const delivered = students.filter(student => student.dataset.studentDelivered === '1').length;
    const historical = students.filter(student => student.dataset.studentHistorical === '1').length;
    const totals = { total: students.length, delivered, pending: students.length - delivered, historical };
    Object.entries(totals).forEach(([key, value]) => {
      const output = document.querySelector(`[data-apc-student-${key}]`);
      if (output) output.textContent = value;
    });
  };
  search.addEventListener('input', refresh);
  // The form recovery flow replaces only the saved card; keep search and totals current.
  new MutationObserver(refresh).observe(list, { childList: true });
})();

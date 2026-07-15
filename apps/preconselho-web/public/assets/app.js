document.querySelectorAll('[data-confirm]').forEach(el=>el.addEventListener('click',e=>{if(!confirm(el.dataset.confirm))e.preventDefault()}));

const menuButton=document.querySelector('.menu-toggle');
const mainNav=document.querySelector('#main-nav');
menuButton?.addEventListener('click',()=>{const open=menuButton.getAttribute('aria-expanded')==='true';menuButton.setAttribute('aria-expanded',String(!open));mainNav?.classList.toggle('open',!open)});

document.querySelectorAll('.student-list details').forEach(block=>{const box=block.querySelector('input[type="checkbox"]');const sync=()=>{block.classList.toggle('selected',box.checked);block.querySelectorAll('input:not([type="checkbox"]), textarea').forEach(field=>field.disabled=!box.checked)};box?.addEventListener('change',sync);sync()});

const rows=[...document.querySelectorAll('#reports-table tbody tr')];
const search=document.querySelector('#report-search');
const count=document.querySelector('#report-count');
const filterEmpty=document.querySelector('#filter-empty');
let statusFilter='';
const filterReports=()=>{const term=(search?.value||'').toLocaleLowerCase('pt-BR').trim();let visible=0;rows.forEach(row=>{const show=(!statusFilter||row.dataset.status===statusFilter)&&(!term||row.textContent.toLocaleLowerCase('pt-BR').includes(term));row.hidden=!show;if(show)visible++});if(count)count.textContent=String(visible);if(filterEmpty)filterEmpty.hidden=visible!==0};
search?.addEventListener('input',filterReports);
document.querySelectorAll('[data-status-filter]').forEach(button=>button.addEventListener('click',()=>{const selected=button.getAttribute('aria-pressed')==='true';document.querySelectorAll('[data-status-filter]').forEach(item=>item.setAttribute('aria-pressed','false'));statusFilter=selected?'':button.dataset.statusFilter||'';button.setAttribute('aria-pressed',String(!selected));filterReports();document.querySelector('.work-list')?.scrollIntoView({behavior:'smooth',block:'start'})}));

const studentSearch=document.querySelector('#student-search');
studentSearch?.addEventListener('input',()=>{const term=studentSearch.value.toLocaleLowerCase('pt-BR').trim();document.querySelectorAll('.student-list details').forEach(item=>item.hidden=!!term&&!item.querySelector('summary').textContent.toLocaleLowerCase('pt-BR').includes(term))});

document.querySelectorAll('[data-table-search]').forEach(input=>input.addEventListener('input',()=>{const term=input.value.toLocaleLowerCase('pt-BR').trim();document.querySelectorAll(`${input.dataset.tableSearch} tbody tr`).forEach(row=>row.hidden=!!term&&!row.textContent.toLocaleLowerCase('pt-BR').includes(term))}));

const professorSearch=document.querySelector('#professor-search');
professorSearch?.addEventListener('input',()=>{const term=professorSearch.value.toLocaleLowerCase('pt-BR').trim();let visible=0;document.querySelectorAll('.professor-card').forEach(card=>{const show=!term||card.textContent.toLocaleLowerCase('pt-BR').includes(term);card.hidden=!show;if(show){visible++;if(term)card.open=true}});const empty=document.querySelector('#professor-empty');if(empty)empty.hidden=visible!==0});

document.querySelectorAll('[data-choice-filter]').forEach(input=>input.addEventListener('input',()=>{const term=input.value.toLocaleLowerCase('pt-BR').trim();document.querySelectorAll(`${input.dataset.choiceFilter} .choice-card`).forEach(choice=>choice.hidden=!!term&&!choice.textContent.toLocaleLowerCase('pt-BR').includes(term))}));
const syncSelectionCounts=()=>document.querySelectorAll('[data-selection-count]').forEach(output=>{output.textContent=String(document.querySelectorAll(`${output.dataset.selectionCount} input:checked`).length)});
document.querySelectorAll('.choice-grid input').forEach(input=>input.addEventListener('change',syncSelectionCounts));syncSelectionCounts();
document.querySelector('#binding-form')?.addEventListener('submit',event=>{const classes=document.querySelectorAll('#class-choices input:checked').length;const disciplines=document.querySelectorAll('#discipline-choices input:checked').length;if(!classes||!disciplines){event.preventDefault();alert('Selecione ao menos uma turma e uma disciplina.')}});

document.querySelectorAll('[data-card-search]').forEach(input=>input.addEventListener('input',()=>{const term=input.value.toLocaleLowerCase('pt-BR').trim();let visible=0;document.querySelectorAll(`${input.dataset.cardSearch}>article`).forEach(card=>{const show=!term||card.textContent.toLocaleLowerCase('pt-BR').includes(term);card.hidden=!show;if(show)visible++});const empty=document.querySelector('#users-empty');if(empty)empty.hidden=visible!==0}));
document.querySelectorAll('[data-close-details]').forEach(button=>button.addEventListener('click',()=>{const details=button.closest('details');if(details)details.open=false}));

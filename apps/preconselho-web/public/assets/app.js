document.querySelectorAll('[data-confirm]').forEach(el=>el.addEventListener('click',e=>{if(!confirm(el.dataset.confirm))e.preventDefault()}));
document.querySelectorAll('[data-print-page]').forEach(button=>button.addEventListener('click',()=>window.print()));

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
studentSearch?.addEventListener('input',()=>{const term=studentSearch.value.toLocaleLowerCase('pt-BR').trim();document.querySelectorAll('[data-student-row]').forEach(item=>item.hidden=!!term&&!item.textContent.toLocaleLowerCase('pt-BR').includes(term))});

const syncOtherChoiceFields=()=>document.querySelectorAll('.other-toggle').forEach(toggle=>{const target=document.getElementById(toggle.dataset.otherTarget||'');if(!target)return;const label=target.closest('label');if(label)label.hidden=!toggle.checked;if(!target.readOnly)target.disabled=toggle.disabled||!toggle.checked});
const syncStudentSheet=()=>{let selected=0;document.querySelectorAll('[data-student-row]').forEach(row=>{const checkbox=row.querySelector('input[type="checkbox"]');if(!checkbox)return;row.classList.toggle('selected',checkbox.checked);if(!checkbox.checked)row.classList.remove('incomplete');if(!checkbox.disabled)row.querySelectorAll('.student-field').forEach(field=>{if(!field.readOnly)field.disabled=!checkbox.checked});if(checkbox.checked)selected++});syncOtherChoiceFields();const output=document.querySelector('#selected-student-count');if(output)output.textContent=String(selected)};
const syncClassChoices=()=>{const enabled=document.querySelector('input[name="possui_alunos_rav"]:checked')?.value==='1';document.querySelectorAll('[data-class-choices]').forEach(section=>{const editable=section.dataset.editable==='1';section.classList.toggle('disabled-section',!enabled);section.querySelectorAll('.class-choice-field').forEach(field=>{if(!field.readOnly)field.disabled=!editable||!enabled})});syncOtherChoiceFields()};
document.querySelectorAll('[data-student-row] input[type="checkbox"]').forEach(input=>input.addEventListener('change',syncStudentSheet));syncStudentSheet();
document.querySelectorAll('.other-toggle').forEach(input=>input.addEventListener('change',syncOtherChoiceFields));
document.querySelectorAll('input[name="possui_alunos_rav"]').forEach(radio=>radio.addEventListener('change',()=>{if(radio.checked&&radio.value==='0'){document.querySelectorAll('[data-student-row] input[type="checkbox"]:checked').forEach(input=>{input.checked=false});syncStudentSheet()}syncClassChoices()}));syncClassChoices();

document.querySelectorAll('[data-table-search]').forEach(input=>input.addEventListener('input',()=>{const term=input.value.toLocaleLowerCase('pt-BR').trim();document.querySelectorAll(`${input.dataset.tableSearch} tbody tr`).forEach(row=>row.hidden=!!term&&!row.textContent.toLocaleLowerCase('pt-BR').includes(term))}));

const professorSearch=document.querySelector('#professor-search');
const coordinationFilters=document.querySelector('[data-coordination-filters]');
if(coordinationFilters){
  const period=document.querySelector('#coord-period'),schoolClass=document.querySelector('#coord-class'),status=document.querySelector('#coord-status'),overdue=document.querySelector('#coord-overdue'),visibleCount=document.querySelector('#coord-visible-count');
  const filterCoordination=()=>{const term=(professorSearch?.value||'').toLocaleLowerCase('pt-BR').trim();let cards=0,reports=0;document.querySelectorAll('.professor-card').forEach(card=>{let cardRows=0;card.querySelectorAll('[data-coord-report]').forEach(row=>{const show=(!term||row.textContent.toLocaleLowerCase('pt-BR').includes(term)||card.dataset.professor.includes(term))&&(!period.value||row.dataset.period===period.value)&&(!schoolClass.value||row.dataset.class===schoolClass.value)&&(!status.value||row.dataset.status===status.value)&&(!overdue.checked||row.dataset.overdue==='1');row.hidden=!show;if(show)cardRows++});card.hidden=cardRows===0;if(cardRows){cards++;reports+=cardRows;if(term||period.value||schoolClass.value||status.value||overdue.checked)card.open=true}});const empty=document.querySelector('#professor-empty');if(empty)empty.hidden=cards!==0;if(visibleCount)visibleCount.textContent=String(reports)};
  [professorSearch,period,schoolClass,status].forEach(field=>field?.addEventListener(field===professorSearch?'input':'change',filterCoordination));overdue?.addEventListener('change',filterCoordination);document.querySelector('#coord-clear-filters')?.addEventListener('click',()=>{professorSearch.value='';period.value='';schoolClass.value='';status.value='';overdue.checked=false;filterCoordination()});filterCoordination();
}

document.querySelectorAll('[data-choice-filter]').forEach(input=>input.addEventListener('input',()=>{const term=input.value.toLocaleLowerCase('pt-BR').trim();document.querySelectorAll(`${input.dataset.choiceFilter} .choice-card`).forEach(choice=>choice.hidden=!!term&&!choice.textContent.toLocaleLowerCase('pt-BR').includes(term))}));
const syncSelectionCounts=()=>document.querySelectorAll('[data-selection-count]').forEach(output=>{output.textContent=String(document.querySelectorAll(`${output.dataset.selectionCount} input:checked`).length)});
document.querySelectorAll('.choice-grid input').forEach(input=>input.addEventListener('change',syncSelectionCounts));syncSelectionCounts();
document.querySelector('#binding-form')?.addEventListener('submit',event=>{const classes=document.querySelectorAll('#class-choices input:checked').length;const disciplines=document.querySelectorAll('#discipline-choices input:checked').length;if(!classes||!disciplines){event.preventDefault();alert('Selecione ao menos uma turma e uma disciplina.')}});

document.querySelectorAll('[data-card-search]').forEach(input=>input.addEventListener('input',()=>{const term=input.value.toLocaleLowerCase('pt-BR').trim();let visible=0;document.querySelectorAll(`${input.dataset.cardSearch}>article`).forEach(card=>{const show=!term||card.textContent.toLocaleLowerCase('pt-BR').includes(term);card.hidden=!show;if(show)visible++});const empty=document.querySelector('#users-empty');if(empty)empty.hidden=visible!==0}));
document.querySelectorAll('[data-close-details]').forEach(button=>button.addEventListener('click',()=>{const details=button.closest('details');if(details)details.open=false}));

const reportForm=document.querySelector('[data-report-form]');
if(reportForm){
  const progress=reportForm.querySelector('[data-report-progress]');
  const progressLabel=reportForm.querySelector('[data-progress-label]');
  const missingOutput=reportForm.querySelector('[data-missing-fields]');
  const autosaveStatus=reportForm.querySelector('[data-autosave-status]');
  let saveTimer=null,saveInFlight=null,dirty=false,submitting=false;
  const reportState=()=>{
    const answer=reportForm.querySelector('input[name="possui_alunos_rav"]:checked')?.value;
    const selected=[...reportForm.querySelectorAll('[data-student-row]')].filter(row=>row.querySelector('input[type="checkbox"]')?.checked);
    const missing=[];let total=1,done=answer!==undefined?1:0;
    if(answer===undefined)missing.push('Informe se existem alunos que realizarão o RAV.');
    if(answer==='1'){
      total+=selected.length+2;
      if(!selected.length)missing.push('Adicione ao menos um aluno que realizará o RAV.');
      selected.forEach(row=>{const name=row.querySelector('td:nth-child(2) strong')?.textContent||'Aluno';const grade=row.querySelector('.student-grade')?.value;if(grade!=='')done++;else missing.push(`Informe a nota parcial de ${name}.`);row.classList.toggle('incomplete',grade==='')});
      const difficulties=[...reportForm.querySelectorAll('input[name="dificuldades_turma[]"]:checked')];
      const measures=[...reportForm.querySelectorAll('input[name="medidas_adotadas[]"]:checked')];
      const otherDifficulty=difficulties.find(input=>input.value==='OUTROS');
      const otherMeasure=measures.find(input=>input.value==='OUTROS');
      const difficultyComplete=difficulties.length&&(!otherDifficulty||document.getElementById(otherDifficulty.dataset.otherTarget||'')?.value.trim());
      const measuresComplete=measures.length&&(!otherMeasure||document.getElementById(otherMeasure.dataset.otherTarget||'')?.value.trim());
      if(difficultyComplete)done++;else missing.push(difficulties.length?'Especifique as outras dificuldades da turma.':'Marque as dificuldades observadas na turma.');
      if(measuresComplete)done++;else missing.push(measures.length?'Especifique as outras medidas adotadas.':'Marque as medidas adotadas para a turma.');
      const sections=[...reportForm.querySelectorAll('.class-pedagogical-fields')];
      sections[0]?.classList.toggle('incomplete',!difficultyComplete);
      sections[1]?.classList.toggle('incomplete',!measuresComplete);
    }else reportForm.querySelectorAll('[data-student-row],.class-pedagogical-fields').forEach(item=>item.classList.remove('incomplete'));
    const value=answer==='0'?100:Math.round(done/Math.max(total,1)*100);
    return{value,missing,answer};
  };
  const renderProgress=()=>{const state=reportState();if(progress){progress.value=state.value;progress.textContent=`${state.value}%`;}if(progressLabel)progressLabel.textContent=`${state.value}% preenchido`;if(missingOutput)missingOutput.textContent=state.missing.length?state.missing.slice(0,3).join(' • '):'Todos os campos obrigatórios estão preenchidos.';return state};
  const autosave=()=>{if(!dirty||submitting)return saveInFlight||Promise.resolve();const state=reportState();if(state.answer===undefined)return Promise.resolve();dirty=false;if(autosaveStatus)autosaveStatus.textContent='Salvando alterações…';saveInFlight=fetch(reportForm.dataset.autosaveUrl,{method:'POST',body:new FormData(reportForm),headers:{'X-Requested-With':'XMLHttpRequest'}}).then(response=>{if(!response.ok)throw new Error('save');return response.json()}).then(data=>{const version=reportForm.querySelector('[data-report-version]');if(version)version.value=String(data.version);if(autosaveStatus)autosaveStatus.textContent=`✓ Alterações salvas às ${data.saved_at}`}).catch(()=>{dirty=true;if(autosaveStatus)autosaveStatus.textContent='Não foi possível salvar automaticamente. Use “Salvar para continuar depois”.'}).finally(()=>{saveInFlight=null});return saveInFlight};
  const scheduleSave=()=>{dirty=true;renderProgress();if(saveTimer)clearTimeout(saveTimer);saveTimer=setTimeout(autosave,1500)};
  reportForm.addEventListener('input',scheduleSave);reportForm.addEventListener('change',scheduleSave);
  reportForm.addEventListener('submit',event=>{const state=renderProgress();const action=event.submitter?.value||'rascunho';if(action==='enviar'&&state.missing.length){event.preventDefault();alert('Antes de enviar, corrija:\n\n'+state.missing.slice(0,8).join('\n'));reportForm.querySelector('.incomplete')?.scrollIntoView({behavior:'smooth',block:'center'});return}if(saveTimer||saveInFlight){event.preventDefault();if(saveTimer){clearTimeout(saveTimer);saveTimer=null;}const finish=saveInFlight||autosave();finish.finally(()=>{submitting=true;const field=document.createElement('input');field.type='hidden';field.name='acao';field.value=action;reportForm.appendChild(field);HTMLFormElement.prototype.submit.call(reportForm)})}});
  window.addEventListener('beforeunload',event=>{if(dirty&&!submitting){event.preventDefault();event.returnValue=''}});renderProgress();
}

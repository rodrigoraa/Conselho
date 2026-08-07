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
document.querySelector('#binding-form')?.addEventListener('submit',event=>{const classes=document.querySelectorAll('#class-choices input:checked').length;if(!classes){event.preventDefault();alert('Selecione ao menos uma turma.')}});

document.querySelectorAll('[data-card-search]').forEach(input=>input.addEventListener('input',()=>{const term=input.value.toLocaleLowerCase('pt-BR').trim();let visible=0;document.querySelectorAll(`${input.dataset.cardSearch}>article`).forEach(card=>{const show=!term||card.textContent.toLocaleLowerCase('pt-BR').includes(term);card.hidden=!show;if(show)visible++});const empty=document.querySelector(input.dataset.emptyTarget||'#users-empty');if(empty)empty.hidden=visible!==0}));
const adminModules=[...document.querySelectorAll('.admin-module')];
adminModules.forEach(module=>module.addEventListener('toggle',()=>{if(module.open)adminModules.forEach(other=>{if(other!==module)other.open=false})}));
const openAdminModuleFromHash=()=>{const id=window.location.hash.slice(1);if(!id)return;const target=document.getElementById(id);const module=target?.matches('.admin-module')?target:(target?.querySelector('.admin-module')||target?.closest('.admin-module'));if(module){module.open=true;requestAnimationFrame(()=>target.scrollIntoView({block:'start'}))}};
document.querySelectorAll('.admin-nav a[href^="#"]').forEach(link=>link.addEventListener('click',()=>{const module=document.querySelector(link.getAttribute('href'))?.querySelector('.admin-module');if(module)module.open=true}));
window.addEventListener('hashchange',openAdminModuleFromHash);openAdminModuleFromHash();
document.querySelectorAll('[data-close-details]').forEach(button=>button.addEventListener('click',()=>{const details=button.closest('details');if(details)details.open=false}));

const formatCpf=value=>value.replace(/\D/g,'').slice(0,11).replace(/^(\d{3})(\d)/,'$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/,'$1.$2.$3').replace(/(\d{3})(\d{1,2})$/,'$1-$2');
document.querySelectorAll('[data-cpf-input]').forEach(input=>{input.value=formatCpf(input.value);input.addEventListener('input',()=>{input.value=formatCpf(input.value)})});

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

const councilDocumentForm=document.querySelector('[data-document-form]');
if(councilDocumentForm){
  const sections=[...councilDocumentForm.querySelectorAll('[data-document-section]')];
  const filledOutput=document.querySelector('[data-document-filled]');
  const progress=document.querySelector('[data-document-progress]');
  const autosaveOutput=document.querySelector('[data-document-autosave]');
  let timer=null,inFlight=null,dirty=false,submitting=false;
  const refreshDocument=()=>{
    let filled=0;
    sections.forEach(section=>{const textarea=section.querySelector('textarea');if(!textarea)return;const complete=textarea.value.trim()!=='';if(complete)filled++;section.classList.toggle('is-empty',!complete);section.classList.remove('incomplete');const state=section.querySelector('.section-state');if(state)state.textContent=complete?'Relato preenchido':'Aguardando relato';const print=section.querySelector('[data-print-narrative]');if(print)print.textContent=complete?textarea.value:'Sem relato registrado.'});
    if(filledOutput)filledOutput.textContent=String(filled);if(progress)progress.value=filled;return{filled,missing:sections.length-filled};
  };
  const saveDocument=()=>{if(!dirty||submitting)return inFlight||Promise.resolve();dirty=false;if(autosaveOutput)autosaveOutput.textContent='Salvando alterações…';inFlight=fetch(councilDocumentForm.dataset.autosaveUrl,{method:'POST',body:new FormData(councilDocumentForm),headers:{'X-Requested-With':'XMLHttpRequest'}}).then(response=>{if(!response.ok)throw new Error('save');return response.json()}).then(data=>{Object.entries(data.versions||{}).forEach(([id,version])=>{const field=councilDocumentForm.querySelector(`[data-document-version="${id}"]`);if(field)field.value=String(version)});if(autosaveOutput)autosaveOutput.textContent=`✓ Alterações salvas às ${data.saved_at}`}).catch(()=>{dirty=true;if(autosaveOutput)autosaveOutput.textContent='Não foi possível salvar automaticamente. Use “Salvar e continuar depois”.'}).finally(()=>{inFlight=null});return inFlight};
  const scheduleDocumentSave=()=>{dirty=true;refreshDocument();if(timer)clearTimeout(timer);timer=setTimeout(saveDocument,1500)};
  councilDocumentForm.addEventListener('input',scheduleDocumentSave);
  councilDocumentForm.addEventListener('submit',event=>{const action=event.submitter?.value||'rascunho';const state=refreshDocument();if(action==='enviar'&&state.missing){event.preventDefault();sections.filter(section=>!section.querySelector('textarea')?.value.trim()).forEach(section=>section.classList.add('incomplete'));alert(`Preencha todas as turmas antes de enviar. Ainda faltam ${state.missing} seção(ões).`);councilDocumentForm.querySelector('.incomplete')?.scrollIntoView({behavior:'smooth',block:'center'});return}if(timer||inFlight){event.preventDefault();if(timer){clearTimeout(timer);timer=null;}const finish=inFlight||saveDocument();finish.finally(()=>{submitting=true;const actionField=document.createElement('input');actionField.type='hidden';actionField.name='acao';actionField.value=action;councilDocumentForm.appendChild(actionField);HTMLFormElement.prototype.submit.call(councilDocumentForm)})}else submitting=true});
  window.addEventListener('beforeunload',event=>{if(dirty&&!submitting){event.preventDefault();event.returnValue=''}});
  refreshDocument();
}

const collectiveDocument=document.querySelector('[data-collective-document]');
if(collectiveDocument){
  const classSections=[...collectiveDocument.querySelectorAll('[data-collective-class]')];
  let hasUnsavedChanges=false;
  const refreshUnsavedState=()=>{hasUnsavedChanges=document.querySelector('[data-opening-content][data-dirty="1"], [data-shared-content][data-dirty="1"]')!==null};
  const finalOutput=collectiveDocument.querySelector('[data-final-narrative]');
  const contributionText=element=>(element instanceof HTMLTextAreaElement?element.value:element.textContent||'').trim();
  const rebuildFinalNarrative=()=>{const openingField=document.querySelector('[data-opening-content]');const openingReadonly=document.querySelector('[data-opening-readonly]');const opening=openingField?.value.trim()||(openingReadonly?.dataset.openingEmpty==='1'?'':openingReadonly?.textContent.trim())||'';const parts=opening?[opening]:[];classSections.forEach(section=>{const editable=section.querySelector('[data-shared-content]');const readonly=section.querySelector('[data-class-readonly]');const classText=contributionText(editable||readonly);if(classText&&!(!editable&&classText==='Nenhum professor escreveu nesta turma até o momento.'))parts.push(`${section.dataset.className}: ${classText}`)});if(finalOutput)finalOutput.textContent=parts.join(' ')||'O documento ainda não possui texto.'};
  document.querySelectorAll('[data-document-view]').forEach(button=>button.addEventListener('click',()=>{const final=button.dataset.documentView==='final';collectiveDocument.querySelector('[data-document-editor]').hidden=final;collectiveDocument.querySelector('[data-document-final]').hidden=!final;document.querySelectorAll('[data-document-view]').forEach(item=>{item.classList.toggle('primary',item===button);item.setAttribute('aria-pressed',String(item===button))});if(final)rebuildFinalNarrative()}));
  const openingField=document.querySelector('[data-opening-content]');
  if(openingField){const openingStatus=document.querySelector('[data-opening-save-status]');const openingCsrf=document.querySelector('[data-opening-csrf]')?.value||'';let openingTimer=null,openingInFlight=null,openingDirty=false;const saveOpening=()=>{if(openingInFlight)return openingInFlight.then(saved=>openingDirty?saveOpening():saved);if(!openingDirty)return Promise.resolve(true);openingDirty=false;if(openingStatus)openingStatus.textContent='Salvando abertura…';const body=new FormData();body.append('_csrf',openingCsrf);body.append('texto',openingField.value);body.append('versao',openingField.dataset.version||'0');openingInFlight=fetch(openingField.dataset.autosaveUrl,{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(async response=>{if(!response.ok){if(response.status===409)throw new Error('conflict');throw new Error('save')}return response.json()}).then(data=>{openingField.dataset.version=String(data.version);if(!openingDirty)openingField.dataset.dirty='0';refreshUnsavedState();if(openingStatus)openingStatus.textContent=`✓ Abertura salva às ${data.saved_at}.`;return true}).catch(error=>{openingDirty=true;openingField.dataset.dirty='1';refreshUnsavedState();if(openingStatus)openingStatus.textContent=error.message==='conflict'?'A abertura foi alterada em outra sessão. Recarregue a página.':'Não foi possível salvar a abertura.';return false}).finally(()=>{openingInFlight=null});return openingInFlight};openingField.addEventListener('input',()=>{openingDirty=true;openingField.dataset.dirty='1';refreshUnsavedState();rebuildFinalNarrative();if(openingStatus)openingStatus.textContent='Alterações aguardando salvamento…';if(openingTimer)clearTimeout(openingTimer);openingTimer=setTimeout(saveOpening,1200)})}
  const applyClassFilter=mode=>{classSections.forEach(section=>section.hidden=mode==='mine'&&section.dataset.mine!=='1');document.querySelectorAll('[data-class-filter]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.classFilter===mode)))};
  document.querySelectorAll('[data-class-filter]').forEach(button=>button.addEventListener('click',()=>applyClassFilter(button.dataset.classFilter||'all')));
  if(document.querySelector('[data-class-filter="mine"]'))applyClassFilter('mine');
  document.querySelector('[data-class-jump]')?.addEventListener('change',event=>{const target=document.getElementById(event.target.value);if(!target)return;if(target.hidden)applyClassFilter('all');target.open=true;target.scrollIntoView({behavior:'smooth',block:'start'});history.replaceState(null,'','#'+target.id)});
  if(location.hash){const target=document.getElementById(location.hash.slice(1));if(target?.matches('[data-collective-class]'))target.open=true}

  classSections.forEach(section=>{
    const textarea=section.querySelector('[data-shared-content]');
    const csrf=section.querySelector('[data-class-csrf]')?.value||section.querySelector('input[name="_csrf"]')?.value||'';
    const render=()=>{const readonly=section.querySelector('[data-class-readonly]');const text=contributionText(textarea||readonly);section.classList.toggle('is-empty',text===''||text==='Nenhum professor escreveu nesta turma até o momento.');rebuildFinalNarrative()};
    let timer=null,inFlight=null,dirty=false,lastValue=textarea?.value||'',operations=[],lockToken='',lockInFlight=null,lastActivity=0;
    const status=section.querySelector('[data-shared-save-status]');
    const badge=section.querySelector('[data-lock-badge]');
    const requestLock=(renew=false)=>{if(!textarea)return Promise.resolve(false);if(lockToken&&!renew){lastActivity=Date.now();return Promise.resolve(true)}if(lockInFlight)return lockInFlight;const body=new FormData();body.append('_csrf',csrf);body.append('lock_token',lockToken);if(status&&!renew)status.textContent='Verificando disponibilidade da turma…';lockInFlight=fetch(textarea.dataset.lockUrl,{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(response=>{if(!response.ok)throw new Error('lock');return response.json()}).then(data=>{if(!data.acquired){textarea.readOnly=true;if(badge){badge.textContent='Em edição';badge.classList.remove('status-rascunho');badge.classList.add('status-pendente')}if(status)status.textContent=`${data.locked_by} está editando esta turma. Tente novamente em cerca de um minuto.`;return false}lockToken=data.token;textarea.readOnly=false;if(!renew){lastActivity=Date.now();textarea.focus()}if(badge){badge.textContent='Reservada para você';badge.classList.remove('status-pendente');badge.classList.add('status-rascunho')}if(status&&!renew)status.textContent='Edição liberada. O salvamento automático está ativo.';return true}).catch(()=>{textarea.readOnly=true;if(status)status.textContent='Não foi possível reservar esta turma para edição. Tente novamente.';return false}).finally(()=>{lockInFlight=null});return lockInFlight};
    const releaseLock=beacon=>{if(!textarea||!lockToken)return;const body=new FormData();body.append('_csrf',csrf);body.append('lock_token',lockToken);const url=textarea.dataset.releaseLockUrl;lockToken='';if(beacon&&navigator.sendBeacon){navigator.sendBeacon(url,body);return}fetch(url,{method:'POST',body,keepalive:true,headers:{'X-Requested-With':'XMLHttpRequest'}}).catch(()=>{});textarea.readOnly=true;textarea.blur();if(badge){badge.textContent='Disponível';badge.classList.remove('status-pendente');badge.classList.add('status-rascunho')}if(status)status.textContent='Bloqueio liberado por inatividade. Clique no texto para editar novamente.'};
    const save=()=>{if(!textarea)return Promise.resolve(true);if(inFlight)return inFlight.then(saved=>dirty?save():saved);if(!dirty)return Promise.resolve(true);if(!lockToken){if(status)status.textContent='Clique no texto para obter o bloqueio antes de salvar.';return Promise.resolve(false)}dirty=false;const sentOperations=operations;operations=[];const sentContent=textarea.value;if(status)status.textContent='Salvando texto coletivo…';const body=new FormData();body.append('_csrf',csrf);body.append('lock_token',lockToken);body.append('conteudo',sentContent);body.append('versao',textarea.dataset.version||'0');body.append('operacoes',JSON.stringify(sentOperations));inFlight=fetch(textarea.dataset.autosaveUrl,{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(async response=>{if(!response.ok){if(response.status===409)throw new Error('conflict');if(response.status===423)throw new Error('lock');if(response.status===422)throw new Error('protected');throw new Error('save')}return response.json()}).then(data=>{textarea.dataset.version=String(data.version);if(!dirty)textarea.dataset.dirty='0';refreshUnsavedState();section.classList.remove('save-conflict');if(status)status.textContent=`✓ Texto salvo às ${data.saved_at}. Turma reservada para você.`;return true}).catch(error=>{operations=[...sentOperations,...operations];dirty=true;textarea.dataset.dirty='1';refreshUnsavedState();section.classList.add('save-conflict');if(error.message==='lock'){lockToken='';textarea.readOnly=true}if(status)status.textContent=error.message==='conflict'?'O documento mudou em outra sessão. Recarregue a página antes de continuar.':error.message==='lock'?'Seu bloqueio expirou. Clique novamente no texto para tentar reservar a turma.':error.message==='protected'?'Você só pode alterar ou apagar os trechos que escreveu. Use Ctrl+Z ou recarregue a página.':'Não foi possível salvar. O sistema tentará novamente quando você escrever.';return false}).finally(()=>{inFlight=null});return inFlight};
    textarea?.addEventListener('focus',()=>requestLock(false));textarea?.addEventListener('click',()=>{if(!lockToken)requestLock(false)});
    textarea?.addEventListener('input',()=>{if(!lockToken)return;lastActivity=Date.now();const before=Array.from(lastValue),after=Array.from(textarea.value);let start=0;while(start<before.length&&start<after.length&&before[start]===after[start])start++;let suffix=0;while(suffix<before.length-start&&suffix<after.length-start&&before[before.length-1-suffix]===after[after.length-1-suffix])suffix++;const deleteCount=before.length-start-suffix;const insert=after.slice(start,after.length-suffix).join('');if(deleteCount||insert!=='')operations.push({start,delete:deleteCount,insert});lastValue=textarea.value;dirty=true;textarea.dataset.dirty='1';refreshUnsavedState();section.classList.remove('save-conflict');render();if(status)status.textContent='Alterações aguardando salvamento…';if(timer)clearTimeout(timer);timer=setTimeout(save,1200)});
    if(textarea){setInterval(()=>{if(!lockToken)return;if(Date.now()-lastActivity>=60000){releaseLock(false);return}requestLock(true)},15000);window.addEventListener('pagehide',()=>releaseLock(true))}
    section.querySelector('[data-finalize-class]')?.addEventListener('submit',event=>{event.preventDefault();if(!textarea?.value.trim()){alert('Escreva no texto da turma antes de finalizar.');return}if(timer){clearTimeout(timer);timer=null}save().then(saved=>{if(saved)HTMLFormElement.prototype.submit.call(event.currentTarget)})});
    render();
  });
  rebuildFinalNarrative();
  window.addEventListener('beforeunload',event=>{if(hasUnsavedChanges){event.preventDefault();event.returnValue=''}});
}

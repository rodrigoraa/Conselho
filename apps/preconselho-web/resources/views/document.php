<?php
use PreConselho\Support\Csrf;
use Shared\Env;

$periodData=$document['period'];$classes=$document['classes'];$conclusions=$document['conclusoes'];$opening=$document['opening'];
$currentRole=(string)($_SESSION['user']['perfil']??'');
$isTeacher=$currentRole==='PROFESSOR';
$canWrite=$periodData['status']==='ABERTO';
$isPrivilegedEditor=in_array($currentRole,['ADMIN','COORDENADOR'],true);
$canEditOpening=in_array($currentRole,['ADMIN','COORDENADOR'],true)&&$periodData['status']==='ABERTO';
$canReleaseEditing=in_array($currentRole,['ADMIN','COORDENADOR'],true)&&$periodData['status']==='ABERTO';
$myClasses=count(array_filter($classes,static fn(array$c):bool=>(bool)$c['minha_turma']));
$myFinished=count(array_filter($classes,static fn(array$c):bool=>(bool)$c['minha_turma']&&(bool)$c['meu_finalizado']));
$allContributions=[];foreach($conclusions as$items)$allContributions=array_merge($allContributions,$items);
$totalFinished=count(array_filter($allContributions,static fn(array$c):bool=>(bool)$c['finalizado']));
$classTracking=[];$pendingTeachers=[];$completedClasses=0;$classesWithAssignments=0;$unassignedClasses=[];
foreach($classes as$class){
    $classId=(int)$class['id'];$items=$conclusions[$classId]??[];
    $finishedItems=array_values(array_filter($items,static fn(array$item):bool=>(bool)$item['finalizado']));
    $pendingItems=array_values(array_filter($items,static fn(array$item):bool=>!(bool)$item['finalizado']));
    $total=count($items);$finishedCount=count($finishedItems);$complete=$total>0&&$finishedCount===$total;
    if($total>0)$classesWithAssignments++;else$unassignedClasses[]=$class;
    if($complete)$completedClasses++;
    foreach($pendingItems as$item){
        $teacherId=(int)$item['professor_usuario_id'];
        if(!isset($pendingTeachers[$teacherId]))$pendingTeachers[$teacherId]=['nome'=>(string)$item['professor_nome'],'turmas'=>[]];
        $pendingTeachers[$teacherId]['turmas'][]=['id'=>$classId,'nome'=>(string)$class['nome_conselho']];
    }
    $classTracking[$classId]=['total'=>$total,'finalizados'=>$finishedCount,'pendentes'=>$pendingItems,'completa'=>$complete];
}
uasort($pendingTeachers,static fn(array$a,array$b):int=>strnatcasecmp($a['nome'],$b['nome']));
$pendingFinalizations=count($allContributions)-$totalFinished;
$schoolAuthority=Env::get('SCHOOL_AUTHORITY','Secretaria de Estado de Educação')??'Secretaria de Estado de Educação';
$schoolName=Env::get('SCHOOL_NAME','Escola Estadual São José')??'Escola Estadual São José';
$shiftLabel=$periodData['turno']==='VESPERTINO'?'Turno Vespertino':'Turno Matutino';
$finalSegments=[];$openingText=trim((string)$opening['texto']);if($openingText!=='')$finalSegments[]=rtrim($openingText);
foreach($classes as$class){$classText=trim((string)$class['conteudo']);if($classText!=='')$finalSegments[]=$classText;}
$finalText=implode("\n\n",$finalSegments);
ob_start();
?>
<section class="page-heading council-heading"><div><p class="eyebrow">Documento coletivo do conselho</p><h1><?=e($periodData['nome'])?> · <?=e($periodData['ano_letivo'])?></h1><p><?=e($shiftLabel)?> · <?=count($classes)?> turma(s) · um texto livre e compartilhado para cada turma</p></div><div class="heading-actions"><span class="badge status-<?=e(strtolower($periodData['status']))?>"><?=e($periodData['status']==='ABERTO'?'Em preenchimento':($periodData['status']==='ENCERRADO'?'Encerrado':'Em preparação'))?></span><button type="button" data-print-page>Imprimir documento</button></div></section>

<?php if($isTeacher):?><aside class="document-instructions"><div><strong>Como funciona</strong><p>Abra uma de suas turmas e clique no texto para começar. Enquanto você estiver digitando, a turma ficará temporariamente bloqueada para os demais. Você pode alterar somente os próprios trechos e, depois de finalizar, dependerá de uma nova liberação.</p></div><div class="document-completion"><span><strong data-my-finished><?=e($myFinished)?></strong> de <?=e($myClasses)?> turmas finalizadas</span><progress max="<?=max(1,$myClasses)?>" value="<?=e($myFinished)?>"><?=e($myFinished)?></progress><small>Seu acompanhamento individual</small></div></aside><?php else:?><aside class="document-instructions"><div><strong>Preenchimento e acompanhamento</strong><p>Coordenação e administração podem escrever e alterar qualquer trecho de qualquer turma. Ao iniciar uma edição, a turma fica temporariamente reservada para esse usuário, evitando alterações simultâneas.</p></div><div class="document-completion"><span><strong><?=e($totalFinished)?></strong> de <?=count($allContributions)?> finalizações</span><progress max="<?=max(1,count($allContributions))?>" value="<?=e($totalFinished)?>"><?=e($totalFinished)?></progress><small>Coordenação e administração</small></div></aside><?php endif;?>

<?php if($isPrivilegedEditor):?>
<section class="card coordination-tracking" aria-labelledby="coordination-tracking-title">
    <div class="section-heading"><div><p class="eyebrow">Visão da coordenação</p><h2 id="coordination-tracking-title">Acompanhamento das finalizações</h2><p>Veja rapidamente quem ainda precisa concluir sua participação e em quais turmas.</p></div></div>
    <div class="tracking-metrics">
        <div class="tracking-metric complete"><strong><?=e($completedClasses)?></strong><span>de <?=e($classesWithAssignments)?> turmas concluídas</span></div>
        <div class="tracking-metric pending"><strong><?=e(count($pendingTeachers))?></strong><span>professor(es) pendentes</span></div>
        <div class="tracking-metric remaining"><strong><?=e($pendingFinalizations)?></strong><span>finalização(ões) pendentes</span></div>
    </div>
    <?php if($pendingTeachers):?>
        <div class="pending-teachers"><h3>Quem ainda precisa finalizar</h3><?php foreach($pendingTeachers as$teacher):?><article class="pending-teacher"><div><strong><?=e($teacher['nome'])?></strong><small>Pendente em <?=count($teacher['turmas'])?> turma(s)</small></div><div class="pending-class-links"><?php foreach($teacher['turmas'] as$pendingClass):?><a href="#turma-<?=e($pendingClass['id'])?>"><?=e($pendingClass['nome'])?></a><?php endforeach;?></div></article><?php endforeach;?></div>
    <?php elseif(!$unassignedClasses):?><p class="tracking-complete-message"><strong>✓ Todos os professores finalizaram.</strong> O documento não possui participações pendentes.</p><?php endif;?>
    <?php if($unassignedClasses):?><div class="unassigned-warning"><strong>Atenção: <?=count($unassignedClasses)?> turma(s) sem professores vinculados.</strong><div><?php foreach($unassignedClasses as$unassigned):?><a href="#turma-<?=e($unassigned['id'])?>"><?=e($unassigned['nome_conselho'])?></a><?php endforeach;?></div></div><?php endif;?>
</section>
<?php endif;?>

<section class="card council-opening-section"><div class="section-heading"><div><p class="eyebrow">Abertura da ata</p><h2>Introdução do documento final</h2><p>Este trecho aparece antes dos relatos das turmas e só pode ser alterado pela coordenação ou administração.</p></div><span class="badge <?=$canEditOpening?'status-enviado':'status-pendente'?>"><?=$canEditOpening?'Edição autorizada':'Somente leitura'?></span></div>
<?php if($canEditOpening):?><label><span class="sr-only">Texto de abertura da ata</span><textarea rows="8" maxlength="12000" data-opening-content data-version="<?=e($opening['versao'])?>" data-autosave-url="/documentos/<?=e($period)?>/abertura/autosave"><?=e($openingText)?></textarea></label><input type="hidden" value="<?=e(Csrf::token())?>" data-opening-csrf><small class="autosave-status" data-opening-save-status aria-live="polite">Salvamento automático ativado para a abertura.</small><?php else:?><div class="opening-readonly" data-opening-readonly data-opening-empty="<?=$openingText===''?'1':'0'?>"><?=nl2br(e($openingText?:'A abertura ainda não foi preenchida pela coordenação.'))?></div><?php endif;?>
<?php if($opening['atualizado_por_nome']):?><small class="shared-last-update">Última atualização: <?=e($opening['atualizado_por_nome'])?> em <?=e(date('d/m/Y H:i',strtotime($opening['atualizado_em'])))?></small><?php endif;?></section>

<section class="collective-toolbar card" aria-label="Navegação das turmas">
    <?php if($isTeacher):?><div class="class-filters"><button class="primary" type="button" data-class-filter="mine" aria-pressed="true">Minhas turmas (<?=e($myClasses)?>)</button><button type="button" data-class-filter="all" aria-pressed="false">Todas as turmas (<?=count($classes)?>)</button></div><?php endif;?>
    <label>Ir diretamente para uma turma<select data-class-jump><option value="">Selecione…</option><?php foreach($classes as$class):?><option value="turma-<?=e($class['id'])?>"><?=e($class['nome_conselho'])?><?=$class['minha_turma']?' — minha turma':''?></option><?php endforeach;?></select></label>
</section>

<div class="document-view-switch" role="group" aria-label="Modo de visualização"><button class="primary" type="button" data-document-view="edit" aria-pressed="true">Preenchimento por turma</button><button type="button" data-document-view="final" aria-pressed="false">Texto final contínuo</button></div>

<div class="council-document-form" data-collective-document data-period="<?=e($period)?>">
<article class="paper-document collective-paper collective-editor-paper" data-document-editor>
    <header class="paper-header"><p><?=e($schoolAuthority)?></p><p><?=e($schoolName)?></p><p><?=e($shiftLabel)?></p><h2>Conselho de Classe · <?=e($periodData['nome'])?> de <?=e($periodData['ano_letivo'])?></h2><p class="paper-professor"><strong>Documento coletivo do corpo docente</strong></p></header>
    <p class="paper-introduction">As turmas seguem a ordem escolar do 1º Ano do Ensino Fundamental ao 3º Ano do Ensino Médio. Professores alteram os próprios trechos; coordenação e administração podem corrigir todo o texto.</p>
    <div class="paper-sections">
    <?php foreach($classes as$index=>$class):$content=(string)$class['conteudo'];$classLabel=(string)$class['nome_conselho'];$mine=(bool)$class['minha_turma'];$finished=(bool)$class['meu_finalizado'];$editable=$canWrite&&($isPrivilegedEditor||($mine&&!$finished));$classConclusions=$conclusions[(int)$class['id']]??[];$progress=$classTracking[(int)$class['id']];$classComplete=(bool)$progress['completa'];$classHasPending=(int)$progress['total']>0&&!$classComplete;$classPendingNames=array_map(static fn(array$item):string=>(string)$item['professor_nome'],$progress['pendentes']);$classSegments=$class['segmentos']??[];$segmentCount=count($classSegments);$classLock=$class['bloqueio']??null;$lockedByOther=$classLock&&(int)$classLock['usuario_id']!==(int)($_SESSION['user']['id']??0);?>
        <details id="turma-<?=e($class['id'])?>" class="paper-class-section shared-class-section <?=trim($content)===''?'is-empty':''?> <?=$mine?'is-mine':'is-readonly'?> <?=$finished?'is-finalized':''?> <?=$classComplete?'is-complete':($classHasPending?'has-pending':'has-no-assignees')?>" data-collective-class data-mine="<?=$mine?'1':'0'?>" data-class-id="<?=e($class['id'])?>" data-class-name="<?=e($classLabel)?>">
            <summary class="paper-section-heading"><span><?=e($index+1)?></span><div><h3><?=e($classLabel)?></h3><?php if($isPrivilegedEditor):?><p class="class-status-detail"><?php if($classComplete):?>Todos os <?=e($progress['total'])?> professores finalizaram<?php elseif($classHasPending):?><?=e($progress['finalizados'])?> de <?=e($progress['total'])?> finalizaram · <strong>Faltam: <?=e(implode(', ',$classPendingNames))?></strong><?php else:?>Nenhum professor vinculado<?php endif;?> · <span data-contribution-count><?=e($segmentCount)?></span> trecho(s) com autoria</p><?php else:?><p><?=$mine?'Você está vinculado':'Consulta'?> · <span data-contribution-count><?=e($segmentCount)?></span> trecho(s) com autoria</p><?php endif;?></div><?php if($isPrivilegedEditor):?><span class="class-responsibility badge status-<?=$classComplete?'aprovado':($classHasPending?'rascunho':'devolvido')?>"><?=$classComplete?'Turma concluída':($classHasPending?count($classPendingNames).' pendente(s)':'Sem professores')?></span><?php else:?><span class="class-responsibility badge status-<?=$finished?'aprovado':($editable?'rascunho':'pendente')?>"><?=$finished?'Você finalizou':($editable?'Edição disponível':'Somente leitura')?></span><?php endif;?><span class="class-expand-label" aria-hidden="true">Abrir</span></summary>
            <div class="shared-class-body">
                <?php if($editable):?>
                    <article class="shared-text-editor"><header><div><strong>Texto coletivo da turma</strong><small><?=$isPrivilegedEditor?'Você pode escrever, corrigir ou apagar qualquer trecho desta turma.':'Você pode escrever em qualquer ponto e alterar somente os trechos de sua autoria. O texto dos demais usuários fica protegido.'?></small></div><span class="badge <?=$lockedByOther?'status-pendente':'status-rascunho'?>" data-lock-badge><?=$lockedByOther?'Em edição':'Disponível'?></span></header><label><span class="sr-only">Texto coletivo da turma <?=e($classLabel)?></span><textarea maxlength="60000" rows="14" readonly data-shared-content data-version="<?=e($class['versao'])?>" data-lock-url="/documentos/<?=e($period)?>/turmas/<?=e($class['id'])?>/bloqueio" data-release-lock-url="/documentos/<?=e($period)?>/turmas/<?=e($class['id'])?>/bloqueio/liberar" data-autosave-url="/documentos/<?=e($period)?>/turmas/<?=e($class['id'])?>/autosave" placeholder="Comece o texto coletivo desta turma."><?=e($content)?></textarea></label><input type="hidden" value="<?=e(Csrf::token())?>" data-class-csrf><small class="shared-editor-status" data-shared-save-status aria-live="polite"><?=$lockedByOther?e($classLock['usuario_nome_snapshot']).' está editando esta turma.':'Clique no texto para iniciar a edição.'?></small></article>
                <?php else:?><div class="narrative-readonly shared-class-readonly" data-class-readonly><?=trim($content)!==''?nl2br(e($content)):'Nenhum professor escreveu nesta turma até o momento.'?></div><?php endif;?>

                <?php if($editable):?><div class="shared-save-row"><small><?=$isTeacher?'Seus trechos continuam editáveis até você finalizar sua participação.':'Você pode escrever nesta turma sem precisar de vínculo.'?></small><?php if($isTeacher):?><form method="post" action="/documentos/<?=e($period)?>/turmas/<?=e($class['id'])?>/finalizar" data-finalize-class><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button class="primary" name="acao" value="finalizar" data-confirm="Finalizar sua participação nesta turma? Depois disso, somente a coordenação ou administração poderá liberar uma nova edição.">Finalizar turma</button></form><?php endif;?></div><?php endif;?>
                <?php if($finished&&$canWrite):?><p class="editing-locked-note">Sua participação está finalizada. Solicite à coordenação ou administração a liberação para editar novamente.</p><?php endif;?>
                <?php if($class['atualizado_por_nome']):?><small class="shared-last-update">Último acréscimo: <?=e($class['atualizado_por_nome'])?> em <?=e(date('d/m/Y H:i',strtotime($class['atualizado_em'])))?></small><?php endif;?>

                <details class="edit-history"><summary><strong>Autoria dos trechos atuais</strong> <span><?=e($segmentCount)?> trecho(s)</span></summary><?php if($classSegments):?><div class="edit-history-list"><?php foreach($classSegments as$segment):$author=$segment['autor_nome_atual']?:$segment['autor_nome_snapshot'];?><article><header><strong><?=e($author)?></strong></header><div class="edit-inserted-text"><?=nl2br(e($segment['conteudo']))?></div></article><?php endforeach;?></div><?php else:?><p class="empty-contributions"><em>Ainda não há texto nesta turma.</em></p><?php endif;?></details>
                <details class="class-completion" <?=$isPrivilegedEditor&&$classHasPending?'open':''?>><summary><strong>Conclusão dos professores</strong> <span><?=e($progress['finalizados'])?>/<?=e($progress['total'])?></span></summary><?php if($classConclusions):?><div class="completion-chips"><?php foreach($classConclusions as$item):?><div class="completion-chip <?=$item['finalizado']?'done':'pending'?>"><strong><?=e($item['professor_nome'])?></strong><small>Professor vinculado · <?=$item['finalizado']?'Finalizado'.($item['finalizado_em']?' em '.date('d/m H:i',strtotime($item['finalizado_em'])):''):'Pendente'?></small><?php if($canReleaseEditing&&$item['finalizado']):?><form method="post" action="/documentos/<?=e($period)?>/turmas/<?=e($class['id'])?>/professores/<?=e($item['professor_usuario_id'])?>/reabrir"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button type="submit" data-confirm="Liberar novamente a edição desta turma para <?=e($item['professor_nome'])?>?">Permitir nova edição</button></form><?php endif;?></div><?php endforeach;?></div><?php else:?><p class="empty-contributions"><em>Esta turma ainda não possui professores vinculados.</em></p><?php endif;?></details>
            </div>
        </details>
    <?php endforeach;?>
    </div>
    <footer class="paper-signatures"><p><span></span>Coordenação pedagógica</p><p><span></span>Gestão escolar</p></footer>
</article>
<article class="paper-document final-document-preview" data-document-final hidden>
    <header class="paper-header"><p><?=e($schoolAuthority)?></p><p><?=e($schoolName)?></p><p><?=e($shiftLabel)?></p><h2>Ata de Reunião do Conselho de Classe · <?=e($periodData['nome'])?> de <?=e($periodData['ano_letivo'])?></h2></header>
    <p class="final-council-text" data-final-narrative><?=$finalText!==''?e($finalText):'O documento ainda não possui texto.'?></p>
    <footer class="paper-signatures"><p><span></span>Coordenação pedagógica</p><p><span></span>Gestão escolar</p></footer>
</article>
</div>
<?php $content=ob_get_clean();require __DIR__.'/layout.php';

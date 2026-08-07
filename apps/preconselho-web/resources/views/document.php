<?php
use PreConselho\Support\Csrf;
use Shared\Env;

$periodData=$document['period'];$classes=$document['classes'];$conclusions=$document['conclusoes'];$opening=$document['opening'];
$currentRole=(string)($_SESSION['user']['perfil']??'');
$isTeacher=$currentRole==='PROFESSOR';
$canWrite=$isTeacher&&$periodData['status']==='ABERTO';
$canEditOpening=in_array($currentRole,['ADMIN','COORDENADOR'],true)&&$periodData['status']==='ABERTO';
$myClasses=count(array_filter($classes,static fn(array$c):bool=>(bool)$c['minha_turma']));
$myFinished=count(array_filter($classes,static fn(array$c):bool=>(bool)$c['minha_turma']&&(bool)$c['meu_finalizado']));
$allContributions=[];foreach($conclusions as$items)$allContributions=array_merge($allContributions,$items);
$totalFinished=count(array_filter($allContributions,static fn(array$c):bool=>(bool)$c['finalizado']));
$schoolAuthority=Env::get('SCHOOL_AUTHORITY','Secretaria de Estado de Educação')??'Secretaria de Estado de Educação';
$schoolName=Env::get('SCHOOL_NAME','Escola Estadual São José')??'Escola Estadual São José';
$shiftLabel=$periodData['turno']==='VESPERTINO'?'Turno Vespertino':'Turno Matutino';
$finalSegments=[];$openingText=trim((string)$opening['texto']);if($openingText!=='')$finalSegments[]=rtrim($openingText);
foreach($classes as$class){$classText=trim((string)$class['conteudo']);if($classText!=='')$finalSegments[]='Na turma '.$class['turma_nome_snapshot'].', '.$classText;}
$finalText=implode(' ',$finalSegments);
ob_start();
?>
<section class="page-heading council-heading"><div><p class="eyebrow">Documento coletivo do conselho</p><h1><?=e($periodData['nome'])?> · <?=e($periodData['ano_letivo'])?></h1><p><?=e($shiftLabel)?> · <?=count($classes)?> turma(s) · um texto livre e compartilhado para cada turma</p></div><div class="heading-actions"><span class="badge status-<?=e(strtolower($periodData['status']))?>"><?=e($periodData['status']==='ABERTO'?'Em preenchimento':($periodData['status']==='ENCERRADO'?'Encerrado':'Em preparação'))?></span><button type="button" data-print-page>Imprimir documento</button></div></section>

<?php if($isTeacher):?><aside class="document-instructions"><div><strong>Como funciona</strong><p>Abra uma de suas turmas e escreva diretamente no texto coletivo. Você pode posicionar o cursor no meio do texto, inclusive logo depois de um aluno já mencionado, e acrescentar sua observação. Tudo que já foi salvo permanece protegido contra exclusão ou substituição.</p></div><div class="document-completion"><span><strong data-my-finished><?=e($myFinished)?></strong> de <?=e($myClasses)?> turmas finalizadas</span><progress max="<?=max(1,$myClasses)?>" value="<?=e($myFinished)?>"><?=e($myFinished)?></progress><small>Seu acompanhamento individual</small></div></aside><?php else:?><aside class="document-instructions"><div><strong>Acompanhamento da equipe</strong><p>Abra cada turma para consultar o texto coletivo completo. O histórico logo abaixo mostra qual professor inseriu cada trecho e em que horário.</p></div><div class="document-completion"><span><strong><?=e($totalFinished)?></strong> de <?=count($allContributions)?> finalizações</span><progress max="<?=max(1,count($allContributions))?>" value="<?=e($totalFinished)?>"><?=e($totalFinished)?></progress><small>Coordenação e administração</small></div></aside><?php endif;?>

<section class="card council-opening-section"><div class="section-heading"><div><p class="eyebrow">Abertura da ata</p><h2>Introdução do documento final</h2><p>Este trecho aparece antes dos relatos das turmas e só pode ser alterado pela coordenação ou administração.</p></div><span class="badge <?=$canEditOpening?'status-enviado':'status-pendente'?>"><?=$canEditOpening?'Edição autorizada':'Somente leitura'?></span></div>
<?php if($canEditOpening):?><label><span class="sr-only">Texto de abertura da ata</span><textarea rows="8" maxlength="12000" data-opening-content data-version="<?=e($opening['versao'])?>" data-autosave-url="/documentos/<?=e($period)?>/abertura/autosave"><?=e($openingText)?></textarea></label><input type="hidden" value="<?=e(Csrf::token())?>" data-opening-csrf><small class="autosave-status" data-opening-save-status aria-live="polite">Salvamento automático ativado para a abertura.</small><?php else:?><div class="opening-readonly" data-opening-readonly data-opening-empty="<?=$openingText===''?'1':'0'?>"><?=nl2br(e($openingText?:'A abertura ainda não foi preenchida pela coordenação.'))?></div><?php endif;?>
<?php if($opening['atualizado_por_nome']):?><small class="shared-last-update">Última atualização: <?=e($opening['atualizado_por_nome'])?> em <?=e(date('d/m/Y H:i',strtotime($opening['atualizado_em'])))?></small><?php endif;?></section>

<section class="collective-toolbar card" aria-label="Navegação das turmas">
    <?php if($isTeacher):?><div class="class-filters"><button class="primary" type="button" data-class-filter="mine" aria-pressed="true">Minhas turmas (<?=e($myClasses)?>)</button><button type="button" data-class-filter="all" aria-pressed="false">Todas as turmas (<?=count($classes)?>)</button></div><?php endif;?>
    <label>Ir diretamente para uma turma<select data-class-jump><option value="">Selecione…</option><?php foreach($classes as$class):?><option value="turma-<?=e($class['id'])?>"><?=e($class['turma_nome_snapshot'])?><?=$class['minha_turma']?' — minha turma':''?></option><?php endforeach;?></select></label>
</section>

<div class="document-view-switch" role="group" aria-label="Modo de visualização"><button class="primary" type="button" data-document-view="edit" aria-pressed="true">Preenchimento por turma</button><button type="button" data-document-view="final" aria-pressed="false">Texto final contínuo</button></div>

<div class="council-document-form" data-collective-document data-period="<?=e($period)?>">
<article class="paper-document collective-paper collective-editor-paper" data-document-editor>
    <header class="paper-header"><p><?=e($schoolAuthority)?></p><p><?=e($schoolName)?></p><p><?=e($shiftLabel)?></p><h2>Conselho de Classe · <?=e($periodData['nome'])?> de <?=e($periodData['ano_letivo'])?></h2><p class="paper-professor"><strong>Documento coletivo do corpo docente</strong></p></header>
    <p class="paper-introduction">Cada turma possui um único texto livre. Os professores vinculados podem acrescentar observações em qualquer ponto, sem apagar o conteúdo já salvo.</p>
    <div class="paper-sections">
    <?php foreach($classes as$index=>$class):$content=(string)$class['conteudo'];$mine=(bool)$class['minha_turma'];$finished=(bool)$class['meu_finalizado'];$editable=$canWrite&&$mine&&!$finished;$classConclusions=$conclusions[(int)$class['id']]??[];$classEdits=$class['edicoes']??[];$editCount=count($classEdits);?>
        <details id="turma-<?=e($class['id'])?>" class="paper-class-section shared-class-section <?=trim($content)===''?'is-empty':''?> <?=$mine?'is-mine':'is-readonly'?> <?=$finished?'is-finalized':''?>" data-collective-class data-mine="<?=$mine?'1':'0'?>" data-class-id="<?=e($class['id'])?>" data-class-name="<?=e($class['turma_nome_snapshot'])?>">
            <summary class="paper-section-heading"><span><?=e($index+1)?></span><div><h3>Turma <?=e($class['turma_nome_snapshot'])?></h3><p><?=$mine?'Você está vinculado':'Consulta'?> · <span data-contribution-count><?=e($editCount)?></span> acréscimo(s) no histórico</p></div><span class="class-responsibility badge status-<?=$finished?'aprovado':($mine?'rascunho':'pendente')?>"><?=$finished?'Você finalizou':($mine?'Sua participação está pendente':'Somente leitura')?></span><span class="class-expand-label" aria-hidden="true">Abrir</span></summary>
            <div class="shared-class-body">
                <?php if($editable):?>
                    <article class="shared-text-editor"><header><div><strong>Texto coletivo da turma</strong><small>Clique em qualquer ponto do texto e acrescente sua observação. O conteúdo já salvo não pode ser apagado ou substituído.</small></div><span class="badge status-rascunho">Editável</span></header><label><span class="sr-only">Texto coletivo da turma <?=e($class['turma_nome_snapshot'])?></span><textarea maxlength="60000" rows="14" data-shared-content data-version="<?=e($class['versao'])?>" data-autosave-url="/documentos/<?=e($period)?>/turmas/<?=e($class['id'])?>/autosave" placeholder="Comece o texto coletivo desta turma."><?=e($content)?></textarea></label><small class="shared-editor-status" data-shared-save-status aria-live="polite">Salvamento automático ativado.</small></article>
                <?php else:?><div class="narrative-readonly shared-class-readonly" data-class-readonly><?=trim($content)!==''?nl2br(e($content)):'Nenhum professor escreveu nesta turma até o momento.'?></div><?php endif;?>

                <?php if($editable):?><div class="shared-save-row"><small>Você pode inserir antes, no meio ou no final. Trechos já salvos ficam protegidos.</small><form method="post" action="/documentos/<?=e($period)?>/turmas/<?=e($class['id'])?>/finalizar" data-finalize-class><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button class="primary" name="acao" value="finalizar" data-confirm="Finalizar sua participação nesta turma? A coordenação verá que você concluiu.">Finalizar turma</button></form></div><?php endif;?>
                <?php if($finished&&$canWrite):?><form class="reopen-class-form" method="post" action="/documentos/<?=e($period)?>/turmas/<?=e($class['id'])?>/finalizar"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button name="acao" value="reabrir">Reabrir minha participação</button></form><?php endif;?>
                <?php if($class['atualizado_por_nome']):?><small class="shared-last-update">Último acréscimo: <?=e($class['atualizado_por_nome'])?> em <?=e(date('d/m/Y H:i',strtotime($class['atualizado_em'])))?></small><?php endif;?>

                <details class="edit-history"><summary><strong>Histórico de autoria</strong> <span><?=e($editCount)?> registro(s)</span></summary><?php if($classEdits):?><div class="edit-history-list"><?php foreach($classEdits as$edit):$author=$edit['autor_nome_atual']?:$edit['autor_nome_snapshot'];?><article><header><strong><?=e($author)?></strong><small><?=e(date('d/m/Y H:i',strtotime($edit['criado_em'])))?></small></header><div class="edit-inserted-text"><?=nl2br(e($edit['texto_inserido']))?></div></article><?php endforeach;?></div><?php else:?><p class="empty-contributions"><em>Ainda não há acréscimos registrados nesta turma.</em></p><?php endif;?></details>
                <details class="class-completion"><summary><strong>Conclusão dos professores</strong> <span><?=count(array_filter($classConclusions,static fn(array$c):bool=>(bool)$c['finalizado']))?>/<?=count($classConclusions)?></span></summary><div class="completion-chips"><?php foreach($classConclusions as$item):?><span class="completion-chip <?=$item['finalizado']?'done':'pending'?>"><strong><?=e($item['professor_nome'])?></strong><small>Professor vinculado · <?=$item['finalizado']?'Finalizado'.($item['finalizado_em']?' em '.date('d/m H:i',strtotime($item['finalizado_em'])):''):'Pendente'?></small></span><?php endforeach;?></div></details>
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

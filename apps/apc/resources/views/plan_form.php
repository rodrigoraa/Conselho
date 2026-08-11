<?php
use PreConselho\Support\Csrf;
$user=$_SESSION['user'];$editing=$plan!==null;$editable=!$editing||$plan['status']==='RASCUNHO';$isTeacher=$user['perfil']==='PROFESSOR';$canEdit=$editable&&$isTeacher;
$event=$editing?$plan:$selectedEvent;
ob_start();
?>
<nav class="breadcrumbs" aria-label="Navegação estrutural"><a href="/apc">APCs</a> <span aria-hidden="true">›</span> <?=$editing?'Plano de Ação':'Novo plano'?></nav>
<section class="page-heading"><div><p class="eyebrow">Plano de Ação</p><h1><?=$editing?'APC — '.e(date('d/m/Y',strtotime($plan['evento_data']))):'Novo Plano APC'?></h1><?php if($editing):?><p>Turma: <strong><?=e($plan['turma_nome_snapshot'])?></strong> · Professor: <strong><?=e($plan['professor_nome_snapshot'])?></strong></p><?php else:?><p>Associe o planejamento a um evento e a uma turma em que você leciona.</p><?php endif;?></div><?php if($editing):?><span class="badge status-<?=e(strtolower($plan['status']))?>"><?=e($plan['status']==='FINALIZADO'?'Finalizado':'Rascunho')?></span><?php endif;?></section>

<?php if($editing):?><nav class="apc-tabs" aria-label="Seções do plano"><a class="active" href="/apc/planos/<?=e($plan['id'])?>">Plano de Ação</a><a href="/apc/planos/<?=e($plan['id'])?>/entregas">Entregas dos alunos</a></nav><?php endif;?>

<section class="card">
<form method="post" action="<?=$editing?'/apc/planos/'.e($plan['id']):'/apc/planos'?>">
    <input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>">
    <?php if(!$editing):?>
    <div class="grid"><label>Evento APC <select name="evento_id" required><option value="">Selecione</option><?php foreach($events as$item):?><option value="<?=e($item['id'])?>" <?=($selectedEvent&&$selectedEvent['id']===$item['id'])?'selected':''?>><?=e(date('d/m/Y',strtotime($item['data'])).' · '.$item['titulo'])?></option><?php endforeach;?></select></label><label>Turma <select name="turma_id_externo" required><option value="">Selecione</option><?php foreach($classes as$class):?><option value="<?=e($class['id'])?>"><?=e($class['nome'])?></option><?php endforeach;?></select></label></div>
    <?php else:?><dl class="identity"><div><dt>Evento</dt><dd><?=e($plan['evento_titulo'])?></dd></div><div><dt>Data da APC</dt><dd><?=e(date('d/m/Y',strtotime($plan['evento_data'])))?></dd></div><div><dt>Origem</dt><dd><?=e($plan['evento_origem'])?></dd></div></dl><?php endif;?>
    <label>Componente curricular <input name="componente_curricular" maxlength="160" required value="<?=e($editing?$plan['componente_curricular']:'')?>" <?=$canEdit?'':'readonly'?>></label>
    <label>Competências / habilidades <textarea name="competencias_habilidades" maxlength="12000" aria-required="true" <?=$canEdit?'':'readonly'?>><?=e($editing?$plan['competencias_habilidades']:'')?></textarea><small class="helper">Obrigatório para finalizar o plano.</small></label>
    <label>Conteúdos / objetos abordados <textarea name="conteudos" maxlength="12000" aria-required="true" <?=$canEdit?'':'readonly'?>><?=e($editing?$plan['conteudos']:'')?></textarea><small class="helper">Obrigatório para finalizar o plano.</small></label>
    <label>Descrição da atividade planejada <textarea name="descricao_atividade" maxlength="20000" aria-required="true" <?=$canEdit?'':'readonly'?>><?=e($editing?$plan['descricao_atividade']:'')?></textarea><small class="helper">A atividade é elaborada e aplicada fora deste sistema.</small></label>
    <label>Estratégia de devolução dos estudantes <textarea name="estrategia_devolucao" maxlength="12000" aria-required="true" <?=$canEdit?'':'readonly'?>><?=e($editing?$plan['estrategia_devolucao']:'')?></textarea></label>
    <?php if($canEdit):?><div class="actions"><a class="button" href="/apc">Cancelar</a><button class="primary" formnovalidate>Salvar rascunho</button></div><?php endif;?>
</form>
<?php if($editing&&$editable&&$isTeacher):?><form method="post" action="/apc/planos/<?=e($plan['id'])?>/finalizar"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><div class="actions"><a class="button" href="/apc/planos/<?=e($plan['id'])?>/entregas">Registrar entregas</a><button class="primary" data-confirm="Finalizar este plano? As alterações ficarão bloqueadas.">Finalizar plano</button></div></form><?php endif;?>
<?php if($editing&&!$editable):?><p class="subtle-box">Este plano está finalizado e disponível somente para consulta. A coordenação ou administração pode reabri-lo com motivo registrado em auditoria.</p><?php endif;?>
</section>
<?php if($editing&&$plan['status']==='FINALIZADO'&&in_array($user['perfil'],['ADMIN','COORDENADOR'],true)):?><section class="card review-card"><h2>Reabrir plano</h2><form method="post" action="/apc/planos/<?=e($plan['id'])?>/reabrir"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><label>Motivo <textarea name="motivo" required maxlength="1000"></textarea></label><button class="danger" data-confirm="Confirma a reabertura deste plano?">Reabrir com auditoria</button></form></section><?php endif;?>
<?php $content=ob_get_clean();require dirname(__DIR__,3).'/preconselho-web/resources/views/layout.php';

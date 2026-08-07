<?php
$isTeacher=($_SESSION['user']['perfil']??'')==='PROFESSOR';
$labels=['PENDENTE'=>'Não iniciado','RASCUNHO'=>'Em preenchimento','ENVIADO'=>'Aguardando conferência','DEVOLVIDO'=>'Precisa de ajuste','APROVADO'=>'Aprovado'];
$counts=array_fill_keys(array_keys($labels),0);
foreach($documents as $document)$counts[$document['status']]++;
$needsAction=array_values(array_filter($documents,static fn(array$d):bool=>$d['periodo_status']==='ABERTO'&&in_array($d['status'],['PENDENTE','RASCUNHO','DEVOLVIDO'],true)));
$sent=array_values(array_filter($documents,static fn(array$d):bool=>in_array($d['status'],['ENVIADO','APROVADO'],true)));
$firstName=explode(' ',trim((string)$_SESSION['user']['nome']))[0];
$documentUrl=static fn(array$d):string=>$isTeacher?'/documentos/'.$d['periodo_id']:'/documentos/'.$d['periodo_id'].'/professores/'.$d['professor_usuario_id'];
$renderCards=static function(array$items)use($labels,$documentUrl,$isTeacher):void{foreach($items as$d):$editable=$isTeacher&&$d['periodo_status']==='ABERTO'&&in_array($d['status'],['PENDENTE','RASCUNHO','DEVOLVIDO'],true);$progress=$d['reports']?round($d['preenchidas']/count($d['reports'])*100):0;?>
<article class="document-card" data-status="<?=e($d['status'])?>">
    <div class="document-card-main">
        <span class="document-icon" aria-hidden="true">≣</span>
        <div><p class="eyebrow"><?=$isTeacher?'Documento do conselho':e($d['professor_nome'])?></p><h3><?=e($d['periodo'])?> · <?=e($d['ano_letivo'])?></h3><p><?=e($d['turmas'])?> turma(s) reunidas em um único documento</p></div>
    </div>
    <div class="document-card-status"><span class="badge status-<?=e(strtolower($d['status']))?>"><?=e($labels[$d['status']]??$d['status'])?></span><small>Prazo: <?=e(date('d/m/Y',strtotime($d['data_fim'])))?></small></div>
    <?php if(in_array($d['status'],['PENDENTE','RASCUNHO','DEVOLVIDO'],true)):?><div class="document-progress"><span><strong><?=e($d['preenchidas'])?> de <?=count($d['reports'])?></strong> seções preenchidas</span><progress max="100" value="<?=e($progress)?>"><?=e($progress)?>%</progress></div><?php endif;?>
    <a class="button <?=$editable||(!$isTeacher&&$d['status']==='ENVIADO')?'primary':''?>" href="<?=e($documentUrl($d))?>"><?=$editable?'Continuar documento':(!$isTeacher&&$d['status']==='ENVIADO'?'Conferir documento':'Visualizar documento')?> <span aria-hidden="true">→</span></a>
</article>
<?php endforeach;};
ob_start();
?>
<section class="page-heading document-dashboard-heading"><div><p class="eyebrow"><?=$isTeacher?'Área do professor':'Área da coordenação'?></p><h1>Olá, <?=e($firstName)?>!</h1><p><?=$isTeacher?'Agora cada bimestre é um único documento, com todas as suas turmas organizadas em sequência.':'Acompanhe e confira um documento completo por professor e por período.'?></p></div></section>

<?php if($isTeacher):?>
<div class="metrics teacher-summary" aria-label="Resumo dos documentos"><article class="metric static status-card-devolvido"><span class="metric-label">Precisam da sua ação</span><strong><?=count($needsAction)?></strong><small>Documentos pendentes ou devolvidos</small></article><article class="metric static status-card-enviado"><span class="metric-label">Já enviados</span><strong><?=count($sent)?></strong><small>Na coordenação ou aprovados</small></article><article class="metric static"><span class="metric-label">Períodos</span><strong><?=count($documents)?></strong><small>Um documento completo por período</small></article></div>
<section class="card work-list"><div class="section-heading"><div><p class="eyebrow">Seu trabalho</p><h2>Documentos que precisam de preenchimento</h2><p>Abra uma vez e percorra todas as suas turmas.</p></div></div><?php if(!$needsAction):?><div class="empty-state"><span aria-hidden="true">✓</span><h3>Tudo em dia</h3><p>Você não possui documentos aguardando preenchimento.</p></div><?php else:?><div class="document-grid"><?php $renderCards($needsAction);?></div><?php endif;?></section>
<?php if($sent):?><section class="card work-list"><div class="section-heading"><div><p class="eyebrow">Acompanhamento</p><h2>Documentos já enviados</h2></div></div><div class="document-grid"><?php $renderCards($sent);?></div></section><?php endif;?>
<?php else:?>
<div class="metrics coordination-summary" aria-label="Resumo da coordenação"><article class="metric static status-card-devolvido"><span class="metric-label">Com o professor</span><strong><?=e($counts['PENDENTE']+$counts['RASCUNHO']+$counts['DEVOLVIDO'])?></strong><small>Aguardando conclusão</small></article><article class="metric static status-card-enviado"><span class="metric-label">Prontos para conferir</span><strong><?=e($counts['ENVIADO'])?></strong><small>Documentos completos</small></article><article class="metric static status-card-aprovado"><span class="metric-label">Aprovados</span><strong><?=e($counts['APROVADO'])?></strong><small>Conferência concluída</small></article></div>
<section class="card work-list"><div class="section-heading"><div><p class="eyebrow">Documentos da equipe</p><h2>Um documento por professor</h2><p><?=count($documents)?> documento(s) no período aberto</p></div><?php if($documents):?><label class="search"><span class="sr-only">Buscar professor ou período</span><span aria-hidden="true">⌕</span><input type="search" placeholder="Buscar professor ou período" data-card-search="#coord-documents" data-empty-target="#documents-empty"></label><?php endif;?></div><?php if(!$documents):?><div class="empty-state"><h3>Nenhum documento gerado</h3><p>Os documentos serão criados quando um período for aberto.</p></div><?php else:?><div class="document-grid" id="coord-documents"><?php $renderCards($documents);?></div><div class="empty-state compact" id="documents-empty" hidden><p>Nenhum documento corresponde à busca.</p></div><?php endif;?></section>
<?php endif;?>
<?php $content=ob_get_clean();require __DIR__.'/layout.php';

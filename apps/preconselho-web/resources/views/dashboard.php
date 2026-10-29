<?php
$isTeacher=($_SESSION['user']['perfil']??'')==='PROFESSOR';
$firstName=explode(' ',trim((string)$_SESSION['user']['nome']))[0];
$open=array_values(array_filter($documents,static fn(array$d):bool=>$d['periodo_status']==='ABERTO'));
$preparing=array_values(array_filter($documents,static fn(array$d):bool=>$d['periodo_status']==='RASCUNHO'));
$historyFilters=$historyFilters??['ano'=>'','turno'=>''];$historyYears=$historyYears??[];
$closed=array_values(array_filter($documents,static fn(array$d):bool=>$d['periodo_status']==='ENCERRADO'&&($historyFilters['ano']===''||(int)$d['ano_letivo']===(int)$historyFilters['ano'])&&($historyFilters['turno']===''||$d['turno']===$historyFilters['turno'])));
if($isTeacher){
    $pending=array_sum(array_map(static fn(array$d):int=>max(0,(int)$d['minhas_turmas']-(int)$d['finalizadas']),$open));
    $finished=array_sum(array_map(static fn(array$d):int=>(int)$d['finalizadas'],$documents));
}else{
    $pending=array_sum(array_map(static fn(array$d):int=>max(0,(int)$d['contribuicoes']-(int)$d['finalizadas']),$open));
    $finished=array_sum(array_map(static fn(array$d):int=>(int)$d['finalizadas'],$documents));
}
$render=static function(array$items,bool$isTeacher):void{foreach($items as$d):
    $assigned=(int)($isTeacher?$d['minhas_turmas']:$d['contribuicoes']);
    $done=(int)$d['finalizadas'];$percent=$assigned?round($done/$assigned*100):0;
    $status=$d['periodo_status'];$canContinue=$isTeacher&&$status==='ABERTO'&&$done<$assigned;
    $target='/documentos/'.(int)$d['periodo_id'];if($canContinue&&!empty($d['first_pending_class']))$target.='#turma-'.(int)$d['first_pending_class'];
    $statusLabel=['RASCUNHO'=>'Em preparação','ABERTO'=>'Em preenchimento','ENCERRADO'=>'Encerrado'][$status]??$status;
?>
<article class="document-card">
    <div class="document-card-main"><span class="document-icon" aria-hidden="true">≣</span><div><p class="eyebrow"><?=e($d['turno']==='VESPERTINO'?'Turno vespertino':'Turno matutino')?></p><h3><?=e($d['periodo'])?> · <?=e($d['ano_letivo'])?></h3><p><?=e($d['total_turmas'])?> turma(s) no mesmo documento<?=$isTeacher?' · '.e($d['minhas_turmas']).' turma(s) sob sua responsabilidade':' · '.e($d['professores']).' professor(es)'?></p></div></div>
    <div class="document-card-status"><span class="badge status-<?=e(strtolower($status))?>"><?=e($statusLabel)?></span><small>Prazo informado: <?=e(date('d/m/Y',strtotime($d['data_fim'])))?></small><?php if($status==='ABERTO'&&$assigned>0&&$done===$assigned):?><small><?=$isTeacher?'Suas participações concluídas':'Contribuições da equipe concluídas'?></small><?php elseif($status==='RASCUNHO'):?><small>Preenchimento aguardando abertura pela administração.</small><?php elseif($status==='ENCERRADO'):?><small>Disponível para consulta e impressão.</small><?php endif;?></div>
    <div class="document-progress"><span><strong><?=e($done)?> de <?=e($assigned)?></strong> finalizações<?=$isTeacher?' suas':' da equipe'?></span><progress max="100" value="<?=e($percent)?>"><?=e($percent)?>%</progress></div>
    <a class="button <?=$status==='ABERTO'&&$done<$assigned?'primary':''?>" href="<?=e($target)?>"><?=$canContinue?'Continuar minha participação':($status==='ABERTO'?'Abrir documento coletivo':'Consultar documento')?> <span aria-hidden="true">→</span></a>
</article>
<?php endforeach;};
ob_start();
?>
<section class="page-heading document-dashboard-heading"><div><p class="eyebrow"><?=$isTeacher?'Área do professor':'Área da coordenação'?></p><h1>Olá, <?=e($firstName)?>!</h1><p><?=$isTeacher?'Cada conselho reúne as turmas de um turno. Você vê as contribuições da equipe e finaliza apenas as turmas às quais está vinculado.':'Acompanhe os documentos de cada turno e as finalizações de cada professor por turma.'?></p></div></section>

<div class="metrics teacher-summary" aria-label="Resumo"><article class="metric static status-card-devolvido"><span class="metric-label">Pendentes</span><strong><?=e($pending)?></strong><small>Finalizações aguardadas</small></article><article class="metric static status-card-aprovado"><span class="metric-label">Finalizadas</span><strong><?=e($finished)?></strong><small>Contribuições concluídas</small></article><article class="metric static"><span class="metric-label">Períodos</span><strong><?=count($documents)?></strong><small>Um documento coletivo por período</small></article></div>

<section class="card work-list"><div class="section-heading"><div><p class="eyebrow">Documentos por turno</p><h2>Conselhos em andamento</h2><p>Os professores colaboram nas seções das turmas vinculadas ao respectivo turno.</p></div></div><?php if(!$open):?><div class="empty-state"><span aria-hidden="true">✓</span><h3>Nenhum período aberto</h3><p>Quando um período for aberto, o documento coletivo do turno aparecerá aqui.</p></div><?php else:?><div class="document-grid"><?php $render($open,$isTeacher);?></div><?php endif;?></section>
<?php if($preparing):?><section class="card work-list"><div class="section-heading"><div><p class="eyebrow">Organização</p><h2>Conselhos em preparação</h2><p>O preenchimento será liberado quando a administração abrir o período.</p></div></div><div class="document-grid"><?php $render($preparing,$isTeacher);?></div></section><?php endif;?>
<section class="card work-list" id="historico"><div class="section-heading"><div><p class="eyebrow">Histórico</p><h2>Períodos anteriores</h2><p>Estes filtros consultam somente períodos encerrados. Suas tarefas atuais continuam visíveis acima.</p></div></div>
<form method="get" action="/conselho#historico"><div class="grid"><label>Ano do histórico<select name="ano"><option value="">Todos os anos</option><?php foreach($historyYears as$year):?><option value="<?=e($year)?>" <?=(string)$year===$historyFilters['ano']?'selected':''?>><?=e($year)?></option><?php endforeach;?><?php if($historyFilters['ano']!==''&&!in_array((int)$historyFilters['ano'],array_map('intval',$historyYears),true)):?><option value="<?=e($historyFilters['ano'])?>" selected><?=e($historyFilters['ano'])?></option><?php endif;?></select></label><label>Turno do histórico<select name="turno"><option value="">Todos os turnos</option><option value="MATUTINO" <?=$historyFilters['turno']==='MATUTINO'?'selected':''?>>Matutino</option><option value="VESPERTINO" <?=$historyFilters['turno']==='VESPERTINO'?'selected':''?>>Vespertino</option></select></label></div><div class="actions"><button type="submit">Filtrar histórico</button><a class="button" href="/conselho#historico">Limpar filtros</a></div></form>
<?php if($closed):?><div class="document-grid"><?php $render($closed,$isTeacher);?></div><?php else:?><div class="empty-state compact"><p><?=$historyFilters['ano']!==''||$historyFilters['turno']!==''?'Nenhum período encerrado corresponde aos filtros.':'Ainda não há períodos encerrados para consultar.'?></p></div><?php endif;?></section>
<?php $content=ob_get_clean();require __DIR__.'/layout.php';

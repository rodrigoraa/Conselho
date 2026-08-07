<?php
$isTeacher=($_SESSION['user']['perfil']??'')==='PROFESSOR';
$firstName=explode(' ',trim((string)$_SESSION['user']['nome']))[0];
$open=array_values(array_filter($documents,static fn(array$d):bool=>$d['periodo_status']==='ABERTO'));
$closed=array_values(array_filter($documents,static fn(array$d):bool=>$d['periodo_status']!=='ABERTO'));
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
?>
<article class="document-card">
    <div class="document-card-main"><span class="document-icon" aria-hidden="true">≣</span><div><p class="eyebrow"><?=e($d['turno']==='VESPERTINO'?'Turno vespertino':'Turno matutino')?></p><h3><?=e($d['periodo'])?> · <?=e($d['ano_letivo'])?></h3><p><?=e($d['total_turmas'])?> turma(s) no mesmo documento<?=$isTeacher?' · '.e($d['minhas_turmas']).' turma(s) sob sua responsabilidade':' · '.e($d['professores']).' professor(es)'?></p></div></div>
    <div class="document-card-status"><span class="badge status-<?=e($done===$assigned&&$assigned>0?'aprovado':($d['periodo_status']==='ABERTO'?'rascunho':'pendente'))?>"><?=e($done===$assigned&&$assigned>0?'Contribuições concluídas':($d['periodo_status']==='ABERTO'?'Em preenchimento':'Encerrado'))?></span><small>Prazo: <?=e(date('d/m/Y',strtotime($d['data_fim'])))?></small></div>
    <div class="document-progress"><span><strong><?=e($done)?> de <?=e($assigned)?></strong> finalizações<?=$isTeacher?' suas':' da equipe'?></span><progress max="100" value="<?=e($percent)?>"><?=e($percent)?>%</progress></div>
    <a class="button <?=$d['periodo_status']==='ABERTO'&&$done<$assigned?'primary':''?>" href="/documentos/<?=e($d['periodo_id'])?>"><?=$isTeacher&&$done<$assigned?'Continuar preenchimento':'Abrir documento coletivo'?> <span aria-hidden="true">→</span></a>
</article>
<?php endforeach;};
ob_start();
?>
<section class="page-heading document-dashboard-heading"><div><p class="eyebrow"><?=$isTeacher?'Área do professor':'Área da coordenação'?></p><h1>Olá, <?=e($firstName)?>!</h1><p><?=$isTeacher?'Cada conselho reúne as turmas de um turno. Você vê as contribuições da equipe e finaliza apenas as turmas às quais está vinculado.':'Acompanhe os documentos de cada turno e as finalizações de cada professor por turma.'?></p></div></section>

<div class="metrics teacher-summary" aria-label="Resumo"><article class="metric static status-card-devolvido"><span class="metric-label">Pendentes</span><strong><?=e($pending)?></strong><small>Finalizações aguardadas</small></article><article class="metric static status-card-aprovado"><span class="metric-label">Finalizadas</span><strong><?=e($finished)?></strong><small>Contribuições concluídas</small></article><article class="metric static"><span class="metric-label">Períodos</span><strong><?=count($documents)?></strong><small>Um documento coletivo por período</small></article></div>

<section class="card work-list"><div class="section-heading"><div><p class="eyebrow">Documentos por turno</p><h2>Conselhos em andamento</h2><p>Os professores colaboram nas seções das turmas vinculadas ao respectivo turno.</p></div></div><?php if(!$open):?><div class="empty-state"><span aria-hidden="true">✓</span><h3>Nenhum período aberto</h3><p>Quando um período for aberto, o documento coletivo do turno aparecerá aqui.</p></div><?php else:?><div class="document-grid"><?php $render($open,$isTeacher);?></div><?php endif;?></section>
<?php if($closed):?><section class="card work-list"><div class="section-heading"><div><p class="eyebrow">Histórico</p><h2>Períodos anteriores</h2></div></div><div class="document-grid"><?php $render($closed,$isTeacher);?></div></section><?php endif;?>
<?php $content=ob_get_clean();require __DIR__.'/layout.php';

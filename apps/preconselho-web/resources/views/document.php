<?php
use PreConselho\Support\Csrf;
use Shared\Env;

$first=$reports[0];
$isTeacher=($_SESSION['user']['perfil']??'')==='PROFESSOR';
$editableStatuses=['PENDENTE','RASCUNHO','DEVOLVIDO'];
$canEdit=$isTeacher&&$first['periodo_status']==='ABERTO'&&count(array_filter($reports,static fn(array$r):bool=>in_array($r['status'],$editableStatuses,true)))===count($reports);
$canReview=!$isTeacher&&$first['periodo_status']==='ABERTO'&&count(array_filter($reports,static fn(array$r):bool=>$r['status']==='ENVIADO'))===count($reports);
$statusLabels=['PENDENTE'=>'Não iniciado','RASCUNHO'=>'Em preenchimento','ENVIADO'=>'Aguardando conferência','DEVOLVIDO'=>'Precisa de ajuste','APROVADO'=>'Aprovado'];
$schoolAuthority=Env::get('SCHOOL_AUTHORITY','Secretaria de Estado de Educação')??'Secretaria de Estado de Educação';
$schoolName=Env::get('SCHOOL_NAME','Escola Estadual São José')??'Escola Estadual São José';
$schoolShift=Env::get('SCHOOL_SHIFT','Turno Matutino')??'Turno Matutino';
$filled=count(array_filter($reports,static fn(array$r):bool=>trim((string)($r['observacoes_professor']??''))!==''));
$lastReturnReason='';
foreach(array_reverse($history)as$item)if($item['status_novo']==='DEVOLVIDO'&&trim((string)$item['justificativa'])!==''){$lastReturnReason=trim((string)$item['justificativa']);break;}
$uniqueHistory=[];
foreach($history as$item){$key=$item['criado_em'].'|'.$item['status_anterior'].'|'.$item['status_novo'].'|'.$item['usuario_id'].'|'.$item['justificativa'];$uniqueHistory[$key]=$item;}
ob_start();
?>
<section class="page-heading council-heading"><div><p class="eyebrow">Documento único do professor</p><h1><?=e($first['periodo'])?> · <?=e($first['ano_letivo'])?></h1><p><?=e($first['professor_nome'])?> · <?=count($reports)?> seção(ões) · <?=e(count(array_unique(array_column($reports,'turma_nome_snapshot'))))?> turma(s)</p></div><div class="heading-actions"><span class="badge status-<?=e(strtolower($status))?>"><?=e($statusLabels[$status]??$status)?></span><button type="button" data-print-page>Imprimir documento</button></div></section>

<?php if($status==='DEVOLVIDO'):?><p class="error" role="alert"><span aria-hidden="true">!</span><span><strong>Ajustes solicitados pela coordenação.</strong> <?=e($lastReturnReason?:'Revise os relatos indicados e envie novamente o documento completo.')?></span></p><?php endif;?>

<?php if($canEdit):?><aside class="document-instructions"><div><strong>Como funciona</strong><p>Este é o seu documento completo. Escreva o relato de cada turma, salve quando quiser e envie tudo de uma só vez.</p></div><div class="document-completion"><span><strong data-document-filled><?=e($filled)?></strong> de <?=count($reports)?> seções</span><progress data-document-progress max="<?=count($reports)?>" value="<?=e($filled)?>"><?=e($filled)?></progress><small data-document-autosave aria-live="polite">Salvamento automático ativado.</small></div></aside><?php endif;?>

<form method="post" class="council-document-form" data-document-form data-autosave-url="/documentos/<?=e($period)?>/autosave">
    <input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>">
    <article class="paper-document">
        <header class="paper-header"><p><?=e($schoolAuthority)?></p><p><?=e($schoolName)?></p><p><?=e($schoolShift)?></p><h2>Conselho de Classe · <?=e($first['periodo'])?> de <?=e($first['ano_letivo'])?></h2><p class="paper-professor">Professor(a): <strong><?=e($first['professor_nome'])?></strong></p></header>
        <p class="paper-introduction">Registro das contribuições do(a) professor(a) para o Conselho de Classe referente a <?=e($first['periodo'])?>. Os relatos abaixo estão organizados por turma e disciplina em um único documento.</p>
        <?php if($canEdit):?><div class="narrative-example"><strong>Exemplo de relato</strong><p>“O aluno [nome], na disciplina de [disciplina], apresenta... Foram realizadas as seguintes intervenções...” Caso outro professor já tenha citado o aluno, dê continuidade identificando sua disciplina.</p></div><?php endif;?>
        <div class="paper-sections">
        <?php foreach($reports as$index=>$report):$value=trim((string)($report['observacoes_professor']??''));?>
            <section class="paper-class-section <?=$value===''?'is-empty':''?>" data-document-section>
                <div class="paper-section-heading"><span><?=e($index+1)?></span><div><h3>Na turma <?=e($report['turma_nome_snapshot'])?></h3><p><?=e($report['disciplina'])?></p></div></div>
                <input type="hidden" name="relatorios[<?=e($report['id'])?>][versao]" value="<?=e($report['versao'])?>" data-document-version="<?=e($report['id'])?>">
                <?php if($canEdit):?><label><span class="sr-only">Relato da turma <?=e($report['turma_nome_snapshot'])?>, disciplina <?=e($report['disciplina'])?></span><textarea name="relatorios[<?=e($report['id'])?>][relato]" maxlength="8000" rows="7" placeholder="Registre aqui os alunos, situações de aprendizagem, dificuldades, avanços e intervenções desta turma."><?=e($value)?></textarea></label><div class="print-narrative" data-print-narrative><?=$value!==''?nl2br(e($value)):'<em>Sem relato registrado.</em>'?></div><small class="section-state"><?=$value===''?'Aguardando relato':'Relato preenchido'?></small><?php else:?><div class="narrative-readonly"><?=$value!==''?nl2br(e($value)):'<em>Sem relato registrado.</em>'?></div><?php endif;?>
            </section>
        <?php endforeach;?>
        </div>
        <footer class="paper-signatures"><p><span></span>Assinatura do(a) professor(a)</p><p><span></span>Assinatura da coordenação</p></footer>
    </article>
    <?php if($canEdit):?><div class="document-actions actions"><button name="acao" value="rascunho">Salvar e continuar depois</button><button class="primary" name="acao" value="enviar" data-document-submit>Enviar documento completo <span aria-hidden="true">→</span></button></div><?php endif;?>
</form>

<?php if($canReview):?><section class="card review-card"><p class="eyebrow">Conferência da coordenação</p><h2>Conferir o documento completo</h2><p class="helper">A ação escolhida será aplicada de uma só vez a todas as turmas deste professor.</p><form method="post" action="/documentos/<?=e($period)?>/professores/<?=e($professor)?>/revisar"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><label>Parecer da coordenação<textarea name="parecer" maxlength="4000" placeholder="Registre um parecer sobre o conjunto dos relatos"></textarea></label><fieldset><legend>Motivos comuns para devolução</legend><div class="review-reasons"><label><input type="checkbox" name="motivos[]" value="Há turma sem informações suficientes"> Há turma sem informações suficientes</label><label><input type="checkbox" name="motivos[]" value="Falta identificar aluno ou disciplina"> Falta identificar aluno ou disciplina</label><label><input type="checkbox" name="motivos[]" value="Intervenções não registradas"> Intervenções não registradas</label><label><input type="checkbox" name="motivos[]" value="Relato precisa de maior clareza"> Relato precisa de maior clareza</label></div></fieldset><label>Orientação ao professor<textarea name="justificativa" maxlength="2000" placeholder="Obrigatória ao devolver: explique o que deve ser corrigido"></textarea></label><div class="actions"><button class="danger" name="acao" value="devolver">Devolver documento</button><button class="primary" name="acao" value="aprovar" data-confirm="Confirma a aprovação de todo o documento?">Aprovar documento completo</button></div></form></section><?php endif;?>

<?php if($uniqueHistory):?><details class="card report-history"><summary><strong>Ver histórico do documento (<?=count($uniqueHistory)?>)</strong></summary><ol class="history"><?php foreach($uniqueHistory as$item):?><li><strong><?=e($statusLabels[$item['status_anterior']]??$item['status_anterior'])?> → <?=e($statusLabels[$item['status_novo']]??$item['status_novo'])?></strong><br><small><?=e(date('d/m/Y H:i',strtotime($item['criado_em'])))?> por <?=e($item['usuario_nome'])?></small><?php if(trim((string)$item['justificativa'])!==''):?><p><?=e($item['justificativa'])?></p><?php endif;?></li><?php endforeach;?></ol></details><?php endif;?>
<?php $content=ob_get_clean();require __DIR__.'/layout.php';

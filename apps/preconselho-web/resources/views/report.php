<?php
use PreConselho\Support\Csrf;

$byId=[];
foreach($selected as $item) $byId[(int)$item['aluno_externo_id']]=$item;

$canEdit=$_SESSION['user']['perfil']==='PROFESSOR'&&$report['periodo_status']==='ABERTO'&&in_array($report['status'],['PENDENTE','RASCUNHO','DEVOLVIDO'],true);
$canReview=$_SESSION['user']['perfil']!=='PROFESSOR'&&$report['status']==='ENVIADO';
$statusLabels=['PENDENTE'=>'A preencher','RASCUNHO'=>'Em rascunho','ENVIADO'=>'Aguardando conferência','DEVOLVIDO'=>'Precisa de ajuste','APROVADO'=>'Aprovado'];
$difficultyOptions=['DIFICULDADES_CONCEITUAIS'=>'Dificuldades conceituais e cognitivas','LEITURA_INTERPRETACAO'=>'Leitura e interpretação','RESOLUCAO_PROBLEMAS_MATEMATICOS'=>'Resolução de problemas matemáticos','ESCRITA_ORTOGRAFIA'=>'Escrita e ortografia','FALTA_HABITO_ESTUDO'=>'Falta de hábito de estudo','DESATENCAO_SALA'=>'Desatenção em sala','FALTA_COMPROMISSO_ATIVIDADES'=>'Falta de compromisso com as atividades','OUTROS'=>'Outros'];
$measureOptions=['ACOMPANHAMENTO_INDIVIDUALIZADO'=>'Acompanhamento individualizado','ATIVIDADES_REFORCO'=>'Aplicação de atividades de reforço','REUNIAO_RESPONSAVEIS'=>'Reunião com responsáveis','ENCAMINHAMENTO_COORDENACAO'=>'Encaminhamento à coordenação','AVALIACOES_DIFERENCIADAS'=>'Avaliações diferenciadas','OUTROS'=>'Outros'];
$decode=static function(mixed $value):array{$items=json_decode((string)$value,true);return is_array($items)?array_values(array_unique(array_map('strval',$items))):[];};

$difficultySelected=$decode($report['dificuldades_turma_json']??'[]');
$difficultyOther=trim((string)($report['dificuldades_turma_outros']??''));
$measureSelected=$decode($report['medidas_adotadas_json']??'[]');
$measureOther=trim((string)($report['medidas_turma_outros']??''));

// Compatibilidade: relatórios preenchidos na versão por aluno são reunidos por turma.
if($difficultySelected===[]||$measureSelected===[]){
    $legacyDifficulty=[];$legacyMeasures=[];$legacyDifficultyOther=[];$legacyMeasureOther=[];
    foreach($selected as $item){
        foreach($decode($item['dificuldades_json']??'[]') as $code)$legacyDifficulty[]=$code;
        foreach($decode($item['intervencoes_json']??'[]') as $code)$legacyMeasures[]=$code;
        if(trim((string)($item['dificuldades_outros']??''))!=='')$legacyDifficultyOther[]=trim((string)$item['dificuldades_outros']);
        if(trim((string)($item['intervencoes_outros']??''))!=='')$legacyMeasureOther[]=trim((string)$item['intervencoes_outros']);
    }
    if($difficultySelected===[]){$difficultySelected=array_values(array_unique($legacyDifficulty));$difficultyOther=implode('; ',array_unique($legacyDifficultyOther));}
    if($measureSelected===[]){$measureSelected=array_values(array_unique($legacyMeasures));$measureOther=implode('; ',array_unique($legacyMeasureOther));}
}

$reportDate=!empty($report['enviado_em'])?date('d/m/Y',strtotime($report['enviado_em'])):date('d/m/Y');
$schoolName=\Shared\Env::get('SCHOOL_NAME','Escola Estadual São José')??'Escola Estadual São José';
$printDifficultyLeft=array_slice($difficultyOptions,0,4,true);
$printDifficultyRight=array_slice($difficultyOptions,4,null,true);
$printMark=static fn(string $code,array $values):string=>in_array($code,$values,true)?'X':' ';
$printGrade=static function(mixed $value):string{if($value===null||$value==='')return'';$formatted=number_format((float)$value,2,',','.');return rtrim(rtrim($formatted,'0'),',');};
$lastReturnReason='';
foreach(array_reverse($history) as $historyItem)if($historyItem['status_novo']==='DEVOLVIDO'&&trim((string)$historyItem['justificativa'])!==''){$lastReturnReason=trim((string)$historyItem['justificativa']);break;}
$pageContext=(($_SESSION['user']['perfil']??'')==='PROFESSOR'?'':$report['professor_nome'].' · ').$report['disciplina'].' · '.$report['etapa'];

ob_start();
?>
<section class="page-heading">
    <div><h1><?=e($report['turma_nome_snapshot'])?></h1><p><?=e($pageContext)?></p></div>
    <div class="heading-actions"><?php if($report['status']!=='DEVOLVIDO'):?><span class="badge status-<?=e(strtolower($report['status']))?>"><?=e($statusLabels[$report['status']]??$report['status'])?></span><?php endif;?><?php if(($_SESSION['user']['perfil']??'')!=='PROFESSOR'):?><button type="button" data-print-page>Imprimir</button><?php endif;?><?php if(($_SESSION['user']['perfil']??'')==='ADMIN'):?><form class="inline-form" method="post" action="/admin/relatorios/<?=e($report['id'])?>/excluir"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button class="danger" data-confirm="Excluir este relatório e todo o preenchimento? Esta ação não pode ser desfeita.">Excluir</button></form><?php endif;?></div>
</section>

<?php if($report['status']==='DEVOLVIDO'):?><p class="error" role="alert"><span aria-hidden="true">!</span><span><strong>Ajustes solicitados pela coordenação.</strong><?=$lastReturnReason!==''?' '.e($lastReturnReason):' Consulte o histórico ao final da página.'?></span></p><?php endif;?>
<?php if($apiError):?><p class="error" role="alert"><span aria-hidden="true">!</span><span><?=e($apiError)?></span></p><?php endif;?>

<form method="post" class="report-sheet" data-report-form data-autosave-url="/relatorios/<?=e($report['id'])?>/autosave">
    <input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>">
    <input type="hidden" name="versao" value="<?=e($report['versao'])?>" data-report-version>

    <section class="card sheet-card">
        <div class="sheet-section-title"><span>1</span><div><h2>Alunos que realizarão o RAV</h2><p>Selecione os alunos e informe as notas parciais.</p></div></div>
        <details class="quick-help"><summary>Como preencher</summary><ol><li>Informe se existem alunos que realizarão o RAV.</li><li>Marque os alunos e coloque suas notas parciais.</li><li>Selecione as dificuldades observadas.</li><li>Selecione as medidas adotadas e envie.</li></ol></details>
        <fieldset class="inline-question"><legend>Existem alunos que realizarão o RAV?</legend><label><input type="radio" name="possui_alunos_rav" value="1" <?=(string)$report['possui_alunos_rav']==='1'?'checked':''?> <?=!$canEdit?'disabled':''?>> Sim</label><label><input type="radio" name="possui_alunos_rav" value="0" <?=(string)$report['possui_alunos_rav']==='0'?'checked':''?> <?=!$canEdit?'disabled':''?>> Não</label></fieldset>
        <?php if($canEdit):?><div class="report-progress"><div><strong>Progresso</strong><span data-progress-label>0% preenchido</span></div><progress data-report-progress max="100" value="0">0%</progress><p data-missing-fields class="helper"></p><p class="autosave-status" data-autosave-status aria-live="polite">Salvamento automático ativado.</p></div><?php endif;?>

        <?php if($students):?>
        <div class="form-section-header"><div><h3>Lista de alunos da turma</h3><p class="helper">Marque todos que realizarão o RAV. A observação individual é opcional.</p></div><label class="search"><span class="sr-only">Buscar aluno</span><span aria-hidden="true">⌕</span><input id="student-search" type="search" placeholder="Buscar aluno"></label></div>
        <div class="student-sheet-table rav-student-table table"><table><thead><tr><th class="select-column">Adicionar</th><th>Aluno</th><th class="grade-column">Nota parcial</th><th>Observação individual <small>(opcional)</small></th></tr></thead><tbody>
        <?php foreach($students as $student):$saved=$byId[(int)$student['id']]??[];$chosen=isset($byId[(int)$student['id']]);?>
            <tr data-student-row><td data-label="Adicionar"><input type="checkbox" aria-label="Adicionar <?=e($student['nome_completo'])?>" name="alunos[<?=e($student['id'])?>][selecionado]" value="1" <?=$chosen?'checked':''?> <?=!$canEdit?'disabled':''?>></td><td data-label="Aluno"><strong><?=e($student['nome_completo'])?></strong><input type="hidden" name="alunos[<?=e($student['id'])?>][motivo_rav]" value="<?=e($saved['motivo_rav']??'')?>"></td><td data-label="Nota parcial"><input class="student-field student-grade" aria-label="Nota parcial de <?=e($student['nome_completo'])?>" type="number" step="0.1" min="0" max="10" name="alunos[<?=e($student['id'])?>][nota]" value="<?=e($saved['nota']??'')?>" <?=$chosen?'':'disabled'?> <?=!$canEdit?'readonly':''?>></td><td data-label="Observação individual"><textarea class="student-field student-note" aria-label="Observação sobre <?=e($student['nome_completo'])?>" name="alunos[<?=e($student['id'])?>][observacao]" placeholder="Opcional" <?=$chosen?'':'disabled'?> <?=!$canEdit?'readonly':''?>><?=e($saved['observacao']??'')?></textarea></td></tr>
        <?php endforeach;?>
        </tbody></table></div>
        <?php elseif(!$apiError):?><div class="empty-state compact"><p><?=(($_SESSION['user']['perfil']??'')==='PROFESSOR')?'Nenhum aluno encontrado nesta turma.':'Nenhum aluno foi indicado para realizar o RAV.'?></p></div><?php endif;?>
    </section>

    <section class="card sheet-card class-pedagogical-fields" data-class-choices data-editable="<?=$canEdit?'1':'0'?>">
        <div class="sheet-section-title"><span>2</span><div><h2>Principais dificuldades observadas</h2><p>Marque uma única vez as opções que representam a turma.</p></div></div>
        <fieldset class="sheet-check-grid class-choice-list"><legend class="sr-only">Principais dificuldades observadas na turma</legend><?php foreach($difficultyOptions as $value=>$label):?><label><input class="class-choice-field <?=$value==='OUTROS'?'other-toggle':''?>" <?=$value==='OUTROS'?'data-other-target="class-difficulty-other"':''?> type="checkbox" name="dificuldades_turma[]" value="<?=e($value)?>" <?=in_array($value,$difficultySelected,true)?'checked':''?> <?=!$canEdit?'disabled':''?>> <span><?=e($label)?></span></label><?php endforeach;?></fieldset>
        <label id="class-difficulty-other-label">Outras dificuldades<input id="class-difficulty-other" class="class-choice-field other-detail" name="dificuldades_turma_outros" value="<?=e($difficultyOther)?>" placeholder="Especifique" <?=($canEdit&&in_array('OUTROS',$difficultySelected,true))?'':'disabled'?> <?=!$canEdit?'readonly':''?>></label>
    </section>

    <section class="card sheet-card class-pedagogical-fields" data-class-choices data-editable="<?=$canEdit?'1':'0'?>">
        <div class="sheet-section-title"><span>3</span><div><h2>Medidas adotadas pelo professor</h2><p>Marque as medidas aplicadas com a turma.</p></div></div>
        <fieldset class="sheet-check-grid class-choice-list"><legend class="sr-only">Medidas adotadas pelo professor na turma</legend><?php foreach($measureOptions as $value=>$label):?><label><input class="class-choice-field <?=$value==='OUTROS'?'other-toggle':''?>" <?=$value==='OUTROS'?'data-other-target="class-measure-other"':''?> type="checkbox" name="medidas_adotadas[]" value="<?=e($value)?>" <?=in_array($value,$measureSelected,true)?'checked':''?> <?=!$canEdit?'disabled':''?>> <span><?=e($label)?></span></label><?php endforeach;?></fieldset>
        <label id="class-measure-other-label">Outras medidas<input id="class-measure-other" class="class-choice-field other-detail" name="medidas_turma_outros" value="<?=e($measureOther)?>" placeholder="Especifique" <?=($canEdit&&in_array('OUTROS',$measureSelected,true))?'':'disabled'?> <?=!$canEdit?'readonly':''?>></label>
        <?php if($canEdit):?><div class="actions"><button name="acao" value="rascunho">Salvar e continuar depois</button><button name="acao" value="enviar" class="primary" data-confirm="As informações serão enviadas para a coordenação. Deseja continuar?">Enviar para a coordenação <span aria-hidden="true">→</span></button></div><?php endif;?>
    </section>
</form>

<section class="print-document" aria-label="Relatório para impressão">
    <div class="print-column print-column-left">
        <h1>RELATÓRIO DE PRÉ-CONSELHO ESCOLAR</h1>
        <p class="print-focus"><strong>FOCO: ALUNOS EM SITUAÇÃO DE RECUPERAÇÃO</strong></p>
        <p class="print-school"><strong><?=e($schoolName)?></strong></p>
        <dl class="print-identification">
            <div><dt>Turma:</dt><dd><?=e($report['turma_nome_snapshot'])?></dd></div>
            <div><dt>Professor(a):</dt><dd><?=e($report['professor_nome'])?></dd></div>
            <div><dt>Disciplina:</dt><dd><?=e($report['disciplina'])?></dd></div>
            <div><dt>Data:</dt><dd><?=e($reportDate)?></dd></div>
        </dl>
        <p class="print-student-title"><strong>Nomes dos alunos que realizarão o RAV:</strong></p>
        <table class="print-student-table">
            <thead><tr><th>Nome:</th><th>Notas<br>parciais</th></tr></thead>
            <tbody><?php $printRowCount=max(8,count($selected));for($index=0;$index<$printRowCount;$index++):$student=$selected[$index]??null;?><tr><td><?=$student?e($student['aluno_nome_snapshot']):''?></td><td><?=$student?e($printGrade($student['nota'])):''?></td></tr><?php endfor;?></tbody>
        </table>
        <section class="print-options print-difficulties-left">
            <h2>PRINCIPAIS DIFICULDADES OBSERVADAS</h2>
            <?php foreach($printDifficultyLeft as $code=>$label):?><p>( <?=$printMark($code,$difficultySelected)?> ) <?=e($label)?></p><?php endforeach;?>
        </section>
    </div>

    <div class="print-column print-column-right">
        <section class="print-options print-difficulties-right">
            <?php foreach($printDifficultyRight as $code=>$label):?><p>( <?=$printMark($code,$difficultySelected)?> ) <?=e($label)?><?=$code==='OUTROS'?': <span class="print-fill-line">'.e($difficultyOther).'</span>':''?></p><?php endforeach;?>
        </section>
        <section class="print-options print-measures">
            <h2>MEDIDAS ADOTADAS PELO PROFESSOR</h2>
            <?php foreach($measureOptions as $code=>$label):?><p>( <?=$printMark($code,$measureSelected)?> ) <?=e($label)?><?=$code==='OUTROS'?': <span class="print-fill-line">'.e($measureOther).'</span>':''?></p><?php endforeach;?>
        </section>
        <section class="print-guidance">
            <p>A coordenação orientou o(a) professor(a) a desenvolver algumas intervenções pedagógicas, como:</p>
            <ul><li>Reforçar conteúdos básicos de forma contínua durante as aulas.</li><li>Aplicar atividades de recuperação paralela com foco nos conteúdos essenciais.</li><li>Utilizar metodologias diversificadas e adaptadas ao nível de compreensão dos alunos.</li><li>Incentivar o uso de recursos lúdicos, jogos educativos e estratégias visuais.</li><li>Acompanhar individualmente os alunos com maior defasagem, promovendo momentos de escuta e apoio emocional.</li><li>Registrar os avanços e dificuldades dos alunos para possível replanejamento.</li></ul>
        </section>
        <div class="print-signatures"><p><strong>ASS. Professor:</strong><span></span></p><p><strong>ASS. Coordenador:</strong><span></span></p></div>
    </div>
</section>

<?php if($canReview):?><section class="card review-card"><p class="eyebrow">Conferência da coordenação</p><h2>Registrar parecer</h2><p class="helper">Aprove o relatório ou devolva ao professor com uma orientação clara.</p><form method="post" action="/coordenacao/relatorios/<?=e($report['id'])?>"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><label>Orientação específica para esta turma<textarea name="orientacao_coordenacao" placeholder="Acrescente orientações além do texto pedagógico padrão"><?=e($report['orientacao_coordenacao']??'')?></textarea></label><label>Parecer da coordenação<textarea name="parecer" placeholder="Registre o parecer sobre as informações apresentadas"><?=e($report['parecer_coordenacao']??'')?></textarea></label><fieldset><legend>Motivos comuns para devolução</legend><div class="review-reasons"><label><input type="checkbox" name="motivos[]" value="Nota não informada"> Nota não informada</label><label><input type="checkbox" name="motivos[]" value="Informações incompletas"> Informações incompletas</label><label><input type="checkbox" name="motivos[]" value="Medidas não registradas"> Medidas não registradas</label><label><input type="checkbox" name="motivos[]" value="Aluno indicado incorretamente"> Aluno indicado incorretamente</label></div></fieldset><label>Outra orientação para devolução<textarea name="justificativa" placeholder="Obrigatória ao devolver: explique o que deve ser corrigido"></textarea></label><div class="actions"><button class="danger" name="acao" value="devolver">Devolver para ajustes</button><button class="primary" name="acao" value="aprovar" data-confirm="Confirma a aprovação deste relatório?">Aprovar relatório</button></div></form></section><?php endif;?>

<?php if($history):?><details class="card report-history"><summary><strong>Ver histórico (<?=count($history)?>)</strong></summary><ol class="history"><?php foreach($history as $item):?><li><strong><?=e($statusLabels[$item['status_anterior']]??$item['status_anterior'])?> → <?=e($statusLabels[$item['status_novo']]??$item['status_novo'])?></strong><br><small><?=e(date('d/m/Y H:i',strtotime($item['criado_em'])))?> por <?=e($item['usuario_nome'])?></small><?php if(trim((string)$item['justificativa'])!==''):?><p><?=e($item['justificativa'])?></p><?php endif;?></li><?php endforeach;?></ol></details><?php endif;?>
<?php $content=ob_get_clean();require __DIR__.'/layout.php';

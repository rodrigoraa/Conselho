<?php
$actionNames=['CRIAR'=>'Criar','EDITAR'=>'Editar','ALTERAR'=>'Alterar','ATIVAR'=>'Ativar','DESATIVAR'=>'Desativar','EXCLUIR'=>'Excluir','EXCLUIR_TODOS'=>'Excluir vínculos do professor','ABRIR'=>'Abrir período','ENCERRAR'=>'Encerrar período','FINALIZAR_TURMA'=>'Finalizar turma','REABRIR_TURMA'=>'Reabrir turma','FINALIZAR_PARTICIPACAO_TURMA'=>'Finalizar participação na turma','LIBERAR_REEDICAO_TURMA'=>'Liberar nova edição na turma','LOGIN'=>'Entrar no sistema','ENVIAR'=>'Enviar relatório','SALVAR_RASCUNHO'=>'Salvar rascunho','APROVADO'=>'Aprovar relatório','DEVOLVIDO'=>'Devolver relatório','REABRIR'=>'Reabrir relatório'];
$entityNames=['usuarios'=>'Usuários','periodos_pre_conselho'=>'Períodos do conselho','documento_turmas'=>'Turmas do documento','documento_turma_professores'=>'Participações dos professores','vinculos_professor_turma'=>'Vínculos de professores','relatorios_pre_conselho'=>'Relatórios','disciplinas'=>'Disciplinas'];
$fieldNames=['id'=>'ID','nome'=>'Nome','cpf'=>'CPF','email'=>'E-mail','perfil'=>'Perfil','ativo'=>'Ativo','status'=>'Estado','turno'=>'Turno','ano_letivo'=>'Ano letivo','etapa'=>'Etapa','data_inicio'=>'Início','data_fim'=>'Fim','finalizado'=>'Finalizado','finalizado_em'=>'Finalizado em','finalizado_por_admin_id'=>'ID da administração que finalizou','professor_usuario_id'=>'ID do usuário professor','professor_id'=>'ID do professor','turma_externa_id'=>'ID da turma na Secretaria','turma_nome_snapshot'=>'Nome da turma registrado','turma_ano_letivo_snapshot'=>'Ano da turma registrado','documento_turma_id'=>'ID da turma no documento','periodo_id'=>'ID do período','sucesso'=>'Sucesso','justificativa'=>'Justificativa'];
$authorName=static fn(array $author):string=>$author['id']===null?'Sistema':((string)($author['nome']??'')!==''?(string)$author['nome']:'Usuário #'.$author['id']);
$authorValues=array_map(static fn(array $author):string=>$author['id']===null?'sistema':(string)$author['id'],$authors);
$pageUrl=static fn(int $number):string=>'/admin/auditoria?'.http_build_query(array_filter($filters,static fn($value):bool=>$value!=='')+['pagina'=>$number]);
$renderSnapshot=static function(?string $raw)use($fieldNames):void{
    if($raw===null||$raw===''){echo '<p>Sem snapshot registrado.</p>';return;}
    try{$snapshot=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){echo '<p>Snapshot inválido. O conteúdo original foi preservado e não pode ser exibido com segurança.</p>';return;}
    if(!is_array($snapshot)){echo '<p>Snapshot sem campos estruturados para exibição.</p>';return;}
    $renderFields=static function(array $fields)use(&$renderFields,$fieldNames):void{
        echo '<dl class="audit-snapshot">';
        foreach($fields as$key=>$value){
            // Never expose historic credentials, including secrets nested in a snapshot.
            if(preg_match('/senha|password|passwd|token|secret|segredo|credenc|credential|cookie|authorization|api.?key|chave|csrf|dsn|connection.?string/i',(string)$key))continue;
            echo '<dt>'.e($fieldNames[$key]??str_replace('_',' ',(string)$key)).'</dt><dd>';
            if(is_array($value))$renderFields($value);else echo nl2br(e($value===null?'Não informado':(is_bool($value)?($value?'Sim':'Não'):(string)$value)));
            echo '</dd>';
        }
        echo '</dl>';
    };
    if($snapshot===[])echo '<p>Snapshot sem campos registrados.</p>';else$renderFields($snapshot);
};
ob_start();?>
<nav class="breadcrumbs" aria-label="Caminho"><a href="/conselho">Painel</a> <span aria-hidden="true">›</span> <a href="/admin">Administração</a> <span aria-hidden="true">›</span> Auditoria</nav>
<section class="page-heading"><div><p class="eyebrow">Segurança</p><h1>Histórico de atividades</h1><p>Consulte o histórico completo das ações registradas pelo sistema.</p></div></section>
<section class="card">
    <form method="get" action="/admin/auditoria" class="query-filters"><div class="grid">
        <label>Buscar no histórico completo<input type="search" name="q" maxlength="250" value="<?=e($filters['q'])?>" placeholder="Autor, ação, item, ID ou origem"></label>
        <label>Autor<select name="usuario"><option value="">Todos os autores</option><?php foreach($authors as$author):$value=$author['id']===null?'sistema':(string)$author['id'];?><option value="<?=e($value)?>" <?=$value===$filters['usuario']?'selected':''?>><?=e($authorName($author))?></option><?php endforeach;?><?php if($filters['usuario']!==''&&!in_array($filters['usuario'],$authorValues,true)):?><option value="<?=e($filters['usuario'])?>" selected><?=e($filters['usuario']==='sistema'?'Sistema':'Usuário #'.$filters['usuario'])?> (sem registros)</option><?php endif;?></select></label>
        <label>Ação<select name="acao"><option value="">Todas as ações</option><?php foreach($actions as$action):?><option value="<?=e($action)?>" <?=$action===$filters['acao']?'selected':''?>><?=e(($actionNames[$action]??$action).' · '.$action)?></option><?php endforeach;?><?php if($filters['acao']!==''&&!in_array($filters['acao'],$actions,true)):?><option value="<?=e($filters['acao'])?>" selected><?=e($filters['acao'])?> (sem registros)</option><?php endif;?></select></label>
        <label>Desde<input type="date" name="de" value="<?=e($filters['de'])?>"></label><label>Até (inclusive)<input type="date" name="ate" value="<?=e($filters['ate'])?>"></label>
    </div><p class="helper">Os filtros pesquisam todos os registros, com até 25 resultados por página. A busca é literal e não diferencia maiúsculas de minúsculas; detalhes antes/depois são consultados em cada registro.</p><div class="actions"><button type="submit" class="primary">Aplicar filtros</button><a class="button" href="/admin/auditoria">Limpar filtros</a></div></form>
</section>
<section class="card"><div class="section-heading"><div><h2>Registros</h2><p><?=e($total)?> registro(s) nos filtros · Página <?=e($page)?> de <?=e($pages)?></p></div></div>
<?php if(!$rows):?><div class="empty-state"><h3>Nenhum registro corresponde aos filtros</h3><p>Altere ou limpe os filtros para consultar outras atividades.</p></div><?php else:?>
<div class="table"><table id="audit-table"><thead><tr><th>Data e hora</th><th>Usuário</th><th>Ação</th><th>Item alterado</th><th>ID</th><th>Endereço de origem</th><th>Detalhes</th></tr></thead><tbody><?php foreach($rows as$row):?><tr>
    <td data-label="Data"><?=e(date('d/m/Y H:i',strtotime($row['criado_em'])))?></td><td data-label="Usuário"><strong><?=e($authorName(['id'=>$row['usuario_id'],'nome'=>$row['usuario_nome']]))?></strong></td>
    <td data-label="Ação"><strong><?=e($actionNames[$row['acao']]??$row['acao'])?></strong><small><?=e($row['acao'])?></small></td><td data-label="Item"><?=e($entityNames[$row['entidade']]??$row['entidade'])?><small><?=e($row['entidade'])?></small></td><td data-label="ID"><?=e($row['entidade_id'])?></td><td data-label="Origem"><?=e($row['endereco_ip'])?></td>
    <td data-label="Detalhes"><details><summary>Ver antes e depois <span class="sr-only">do registro <?=e($row['id'])?></span></summary><p>Campos registrados nesta atividade; snapshots podem ser parciais. Valores confidenciais são omitidos.</p><?php if($row['acao']==='CRIAR'):?><p>Criação registrada.</p><?php elseif(in_array($row['acao'],['EXCLUIR','EXCLUIR_TODOS'],true)):?><p>Remoção registrada.</p><?php endif;?><h3>Antes</h3><?php $renderSnapshot($row['dados_anteriores']);?><h3>Depois</h3><?php $renderSnapshot($row['dados_novos']);?></details></td>
</tr><?php endforeach;?></tbody></table></div><?php endif;?>
<nav class="pagination" aria-label="Paginação"><?php if($page>1):?><a class="button" href="<?=e($pageUrl($page-1))?>">← Anterior</a><?php endif;?><span aria-current="page">Página <?=e($page)?> de <?=e($pages)?></span><?php if($page<$pages):?><a class="button" href="<?=e($pageUrl($page+1))?>">Próxima →</a><?php endif;?></nav></section>
<?php $content=ob_get_clean();require __DIR__.'/layout.php';

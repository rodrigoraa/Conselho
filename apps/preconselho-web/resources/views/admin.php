<?php
use PreConselho\Support\{Cpf,Csrf};

$bindingsByProfessor=[];
foreach($bindings as $binding){
    $professorId=(int)$binding['usuario_id'];
    $bindingsByProfessor[$professorId]??=['nome'=>$binding['professor_nome'],'rows'=>[]];
    $bindingsByProfessor[$professorId]['rows'][]=$binding;
}
$activeMorning=count(array_filter($bindings,static fn(array $binding):bool=>(bool)$binding['ativo']&&$binding['turno']==='MATUTINO'));
$activeAfternoon=count(array_filter($bindings,static fn(array $binding):bool=>(bool)$binding['ativo']&&$binding['turno']==='VESPERTINO'));

ob_start();
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">Configurações</p>
        <h1>Administração</h1>
        <p>Gerencie os acessos e organize o trabalho pedagógico.</p>
    </div>
    <a class="button" href="/admin/auditoria">Ver auditoria</a>
</section>

<nav class="admin-nav" aria-label="Seções da administração">
    <a href="#usuarios">Usuários</a>
    <a href="#vinculos">Vínculos</a>
    <a href="#periodos">Períodos</a>
</nav>

<div class="admin-workspace">
    <section class="card admin-section admin-section-block" id="usuarios">
        <details class="admin-module">
            <summary><span><span class="eyebrow">Acessos</span><strong>Usuários</strong><small><?=count($users)?> usuário(s) cadastrado(s)</small></span></summary>
            <div class="admin-module-body">
            <div class="admin-module-tools">
            <label class="search">
                <span class="sr-only">Buscar usuário</span>
                <span aria-hidden="true">⌕</span>
                <input type="search" placeholder="Buscar por nome, CPF ou perfil" data-card-search="#user-management-list" data-empty-target="#users-empty">
            </label>
            </div>

        <details class="admin-collapsible admin-create-panel">
            <summary><span><strong>Novo usuário</strong><small>Crie um acesso para professor, coordenação ou administrador.</small></span></summary>
            <div class="admin-collapsible-body">
                <form method="post" action="/admin/usuarios">
                    <input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>">
                    <div class="grid">
                        <label>Nome completo<input name="nome" required maxlength="150" placeholder="Ex.: Maria Aparecida"></label>
                        <label>CPF<input name="cpf" inputmode="numeric" maxlength="14" required placeholder="000.000.000-00" data-cpf-input></label>
                        <label>Tipo de acesso<select name="perfil"><option value="PROFESSOR">Professor — preenche suas turmas</option><option value="COORDENADOR">Coordenação — acompanha os conselhos</option><option value="ADMIN">Administrador — gerencia o sistema</option></select></label>
                    </div>
                    <button class="primary">Criar usuário</button>
                </form>
            </div>
        </details>

        <div class="user-management-list" id="user-management-list">
            <?php foreach($users as $u):$isCurrent=(int)$u['id']===(int)$_SESSION['user']['id'];?>
                <article class="user-management-card">
                    <div class="user-card-summary">
                        <span class="avatar" aria-hidden="true"><?=e(mb_strtoupper(mb_substr($u['nome'],0,1)))?></span>
                        <span class="user-card-identity">
                            <strong><?=e($u['nome'])?></strong>
                            <small><?=e(Cpf::format($u['cpf']??''))?></small>
                            <span><span class="badge"><?=e(['PROFESSOR'=>'Professor','COORDENADOR'=>'Coordenação','ADMIN'=>'Administrador'][$u['perfil']]??$u['perfil'])?></span> <span class="badge status-<?=$u['ativo']?'aprovado':'pendente'?>"><?=e($u['ativo']?'Ativo':'Inativo')?></span></span>
                        </span>
                    </div>
                    <div class="user-card-actions">
                        <details class="user-editor">
                            <summary class="button">Editar</summary>
                            <form method="post" action="/admin/usuarios/<?=e($u['id'])?>/editar">
                                <input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>">
                                <div class="grid">
                                    <label>Nome completo<input name="nome" value="<?=e($u['nome'])?>" required maxlength="150"></label>
                                    <label>CPF<input name="cpf" inputmode="numeric" maxlength="14" value="<?=e(Cpf::format($u['cpf']??''))?>" required data-cpf-input></label>
                                </div>
                                <label>Tipo de acesso<select name="perfil"><option value="PROFESSOR" <?=$u['perfil']==='PROFESSOR'?'selected':''?>>Professor</option><option value="COORDENADOR" <?=$u['perfil']==='COORDENADOR'?'selected':''?>>Coordenação</option><option value="ADMIN" <?=$u['perfil']==='ADMIN'?'selected':''?>>Administrador</option></select></label>
                                <div class="actions"><button type="button" data-close-details>Cancelar</button><button class="primary">Salvar alterações</button></div>
                            </form>
                        </details>
                        <?php if(!$isCurrent):?>
                            <form class="inline-form" method="post" action="/admin/usuarios/<?=e($u['id'])?>/alternar"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button data-confirm="Confirma que deseja <?=e($u['ativo']?'desativar':'ativar')?> este usuário?"><?=e($u['ativo']?'Desativar':'Ativar')?></button></form>
                            <form class="inline-form" method="post" action="/admin/usuarios/<?=e($u['id'])?>/excluir"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button class="danger" data-confirm="Excluir o usuário <?=e($u['nome'])?>? O acesso será removido, mas o histórico será preservado.">Excluir</button></form>
                        <?php else:?><span class="helper">Seu usuário</span><?php endif;?>
                    </div>
                </article>
            <?php endforeach;?>
        </div>
        <div id="users-empty" class="empty-state compact" <?=!$users?'':'hidden'?>>
            <p><?=$users?'Nenhum usuário corresponde à busca.':'Nenhum usuário cadastrado.'?></p>
        </div>
            </div>
        </details>
    </section>

    <section class="card admin-section admin-section-block binding-section" id="vinculos">
        <details class="admin-module">
            <summary><span><span class="eyebrow">Distribuição</span><strong>Vínculos</strong><small><?=count($bindings)?> vínculo(s) cadastrado(s)</small></span></summary>
            <div class="admin-module-body">
        <?php if($bindings):?><div class="admin-module-tools"><label class="search"><span class="sr-only">Buscar vínculo</span><span aria-hidden="true">⌕</span><input type="search" placeholder="Buscar professor, turno ou turma" data-card-search="#binding-management-list" data-empty-target="#bindings-empty"></label></div><?php endif;?>
        <details class="admin-collapsible admin-create-panel">
            <summary><span><strong>Novo vínculo</strong><small>Escolha o professor, o turno e as turmas em que ele leciona.</small></span></summary>
            <div class="admin-collapsible-body">
                <form method="post" action="/admin/vinculos" id="binding-form">
                    <input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>">
                    <div class="binding-step"><span class="step-number">1</span><div><label for="professor_id">Professor</label><?php if($professors):?><select id="professor_id" name="professor_id" required><option value="">Selecione um professor</option><?php foreach($professors as $p):?><option value="<?=e($p['id'])?>"><?=e($p['nome'])?> — <?=e(Cpf::format($p['cpf']??''))?></option><?php endforeach;?></select><?php else:?><p class="error">Nenhum professor ativo cadastrado.</p><?php endif;?></div></div>
                    <div class="binding-step"><span class="step-number">2</span><div><label for="binding-shift">Turno</label><select id="binding-shift" name="turno" required><option value="MATUTINO">Matutino</option><option value="VESPERTINO">Vespertino</option></select><p class="helper">O professor pode ser vinculado aos dois turnos em cadastros separados.</p></div></div>
                    <div class="binding-step"><span class="step-number">3</span><div><div class="choice-heading"><div><label>Turmas</label><p class="helper">Marque todas as turmas desse professor no turno escolhido.</p></div><?php if($classes):?><label class="search"><span class="sr-only">Buscar turma</span><span aria-hidden="true">⌕</span><input type="search" placeholder="Buscar turma" data-choice-filter="#class-choices"></label><?php endif;?></div><?php if($classesError):?><p class="error" role="alert"><?=e($classesError)?></p><?php elseif(!$classes):?><p class="empty-state compact">Nenhuma turma disponível na secretaria.</p><?php else:?><div class="choice-grid" id="class-choices"><?php foreach($classes as $class):?><label class="choice-card"><input type="checkbox" name="turma_ids[]" value="<?=e($class['id'])?>"><span><strong><?=e($class['nome_turma'])?></strong></span></label><?php endforeach;?></div><p class="selection-count"><strong data-selection-count="#class-choices">0</strong> turma(s) selecionada(s)</p><?php endif;?></div></div>
                    <div class="binding-submit"><p class="helper">Será criado um vínculo para cada turma selecionada, sem necessidade de disciplina.</p><button class="primary" <?=(!$professors||!$classes)?'disabled':''?>>Criar vínculos</button></div>
                </form>
            </div>
        </details>
        <?php if(!$bindings):?><div class="empty-state compact"><p>Nenhum vínculo cadastrado.</p></div><?php else:?><div class="binding-professor-list" id="binding-management-list">
            <?php foreach($bindingsByProfessor as $group):$rows=$group['rows'];$classCount=count(array_unique(array_column($rows,'turma_externa_id')));$shiftCount=count(array_unique(array_column($rows,'turno')));$activeCount=count(array_filter($rows,static fn(array $row):bool=>(bool)$row['ativo']));?>
                <article class="binding-professor-group">
                    <details class="binding-professor-details">
                        <summary>
                            <span class="professor-identity"><span class="avatar" aria-hidden="true"><?=e(mb_strtoupper(mb_substr($group['nome'],0,1)))?></span><span><strong><?=e($group['nome'])?></strong><small><?=count($rows)?> vínculo(s) · <?=$classCount?> turma(s) · <?=$shiftCount?> turno(s)</small></span></span>
                            <span class="badge status-<?=$activeCount?'aprovado':'pendente'?>"><?=$activeCount?> ativo(s)</span>
                        </summary>
                        <div class="binding-group-body">
                            <?php foreach($rows as $b):?><div class="binding-management-card">
                                <div><strong><?=e($b['turma_nome_snapshot'])?></strong><span><?=e($b['turno']==='MATUTINO'?'Turno matutino':'Turno vespertino')?></span><small><?=e($b['ativo']?'Ativo':'Inativo')?></small></div>
                                <div class="user-card-actions">
                                    <details class="user-editor binding-editor"><summary class="button">Editar</summary><form method="post" action="/admin/vinculos/<?=e($b['id'])?>/editar"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><label>Professor<select name="professor_id" required><?php foreach($professors as $p):?><option value="<?=e($p['id'])?>" <?=(int)$p['id']===(int)$b['usuario_id']?'selected':''?>><?=e($p['nome'])?></option><?php endforeach;?></select></label><label>Turno<select name="turno" required><option value="MATUTINO" <?=$b['turno']==='MATUTINO'?'selected':''?>>Matutino</option><option value="VESPERTINO" <?=$b['turno']==='VESPERTINO'?'selected':''?>>Vespertino</option></select></label><label>Turma<select name="turma_id" required><?php foreach($classes as $c):?><option value="<?=e($c['id'])?>" <?=(int)$c['id']===(int)$b['turma_externa_id']?'selected':''?>><?=e($c['nome_turma'])?></option><?php endforeach;?></select></label><div class="actions"><button type="button" data-close-details>Cancelar</button><button class="primary">Salvar vínculo</button></div></form></details>
                                    <form class="inline-form" method="post" action="/admin/vinculos/<?=e($b['id'])?>/alternar"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button data-confirm="Confirma a alteração deste vínculo?"><?=e($b['ativo']?'Desativar':'Ativar')?></button></form>
                                    <form class="inline-form" method="post" action="/admin/vinculos/<?=e($b['id'])?>/excluir"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button class="danger" data-confirm="Excluir este vínculo de turma? Os documentos de períodos já abertos serão preservados.">Excluir</button></form>
                                </div>
                            </div><?php endforeach;?>
                        </div>
                    </details>
                </article>
            <?php endforeach;?>
        </div><div id="bindings-empty" class="empty-state compact" hidden><p>Nenhum professor corresponde à busca.</p></div><?php endif;?>
            </div>
        </details>
    </section>

    <section class="card admin-section admin-section-block" id="periodos">
        <details class="admin-module">
            <summary><span><span class="eyebrow">Calendário</span><strong>Períodos</strong><small><?=count($periods)?> período(s) cadastrado(s)</small></span></summary>
            <div class="admin-module-body">
        <details class="admin-collapsible admin-create-panel">
            <summary><span><strong>Novo período</strong><small>Defina a etapa e o prazo de preenchimento.</small></span></summary>
            <div class="admin-collapsible-body">
                <div class="period-readiness"><strong>Antes de abrir</strong><span><?=e($activeMorning)?> vínculo(s) matutino(s) e <?=e($activeAfternoon)?> vespertino(s) estão ativos.</span><span class="<?=$unboundCount?'warning-text':'success-text'?>"><?=e($unboundCount)?> professor(es) ativo(s) sem vínculo de turma.</span></div>
                <form method="post" action="/admin/periodos">
                    <input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>">
                    <div class="grid"><label>Nome do período<input name="nome" required placeholder="Ex.: 2º bimestre"></label><label>Ano letivo<input name="ano_letivo" type="number" min="2000" max="2100" value="<?=date('Y')?>" required></label><label>Turno do conselho<select name="turno" required><option value="MATUTINO">Matutino</option><option value="VESPERTINO">Vespertino</option></select></label><label class="period-stage">Etapa<input name="etapa" required placeholder="Ex.: 2º bimestre"></label><div class="grid period-date-grid"><label>Data de início<input name="data_inicio" type="date" required></label><label>Data final<input name="data_fim" type="date" required></label></div></div>
                    <button class="primary">Criar período</button>
                </form>
            </div>
        </details>
        <?php if(!$periods):?><div class="empty-state compact"><p>Nenhum período cadastrado.</p></div><?php else:?><div class="table"><table><thead><tr><th>Período</th><th>Ano</th><th>Turno</th><th>Status</th><th>Ação</th></tr></thead><tbody>
            <?php foreach($periods as $p):?><tr>
                <td data-label="Período"><strong><?=e($p['nome'])?></strong><small><?=e($p['etapa'])?></small></td>
                <td data-label="Ano"><?=e($p['ano_letivo'])?></td>
                <td data-label="Turno"><?=e($p['turno']==='MATUTINO'?'Matutino':'Vespertino')?></td>
                <td data-label="Status"><span class="badge status-<?=e(strtolower($p['status']))?>"><?=e($p['status']==='RASCUNHO'?'Em preparação':($p['status']==='ABERTO'?'Aberto':'Encerrado'))?></span></td>
                <td class="row-action"><div class="row-actions"><?php if($p['status']==='RASCUNHO'):?><form class="inline-form" method="post" action="/admin/periodos/<?=e($p['id'])?>/abrir"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button class="primary" data-confirm="Abrir este período? Será criado um documento coletivo com as turmas e responsabilidades dos professores vinculados.">Abrir</button></form><?php elseif($p['status']==='ABERTO'):?><form class="inline-form" method="post" action="/admin/periodos/<?=e($p['id'])?>/encerrar"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button data-confirm="Encerrar o período? Esta ação bloqueará novas edições.">Encerrar</button></form><?php endif;?><form class="inline-form" method="post" action="/admin/periodos/<?=e($p['id'])?>/excluir"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button class="danger" data-confirm="Excluir o período <?=e($p['nome'])?>? O documento coletivo e os dados relacionados serão apagados. Esta ação não pode ser desfeita.">Excluir</button></form></div></td>
            </tr><?php endforeach;?>
        </tbody></table></div><?php endif;?>
            </div>
        </details>
    </section>
</div>
<?php
$content=ob_get_clean();
require __DIR__.'/layout.php';

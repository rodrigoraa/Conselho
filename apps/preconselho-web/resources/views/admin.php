<?php
use PreConselho\Support\Csrf;

$bindingsByProfessor=[];
foreach($bindings as $binding){
    $professorId=(int)$binding['usuario_id'];
    $bindingsByProfessor[$professorId]??=['nome'=>$binding['professor_nome'],'rows'=>[]];
    $bindingsByProfessor[$professorId]['rows'][]=$binding;
}

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
    <a href="#disciplinas">Disciplinas</a>
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
                <input type="search" placeholder="Buscar por nome, e-mail ou perfil" data-card-search="#user-management-list" data-empty-target="#users-empty">
            </label>
            </div>

        <details class="admin-collapsible admin-create-panel">
            <summary><span><strong>Novo usuário</strong><small>Crie um acesso para professor, coordenação ou administrador.</small></span></summary>
            <div class="admin-collapsible-body">
                <form method="post" action="/admin/usuarios">
                    <input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>">
                    <div class="grid">
                        <label>Nome completo<input name="nome" required maxlength="150" placeholder="Ex.: Maria Aparecida"></label>
                        <label>E-mail institucional<input name="email" type="email" required placeholder="nome@escola.com.br"></label>
                        <label>Tipo de acesso<select name="perfil"><option value="PROFESSOR">Professor — preenche relatórios</option><option value="COORDENADOR">Coordenação — confere relatórios</option><option value="ADMIN">Administrador — gerencia o sistema</option></select></label>
                        <label>Senha inicial<input name="senha" type="password" minlength="6" required placeholder="No mínimo 6 caracteres"></label>
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
                            <small><?=e($u['email'])?></small>
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
                                    <label>E-mail<input name="email" type="email" value="<?=e($u['email'])?>" required></label>
                                </div>
                                <label>Tipo de acesso<select name="perfil"><option value="PROFESSOR" <?=$u['perfil']==='PROFESSOR'?'selected':''?>>Professor</option><option value="COORDENADOR" <?=$u['perfil']==='COORDENADOR'?'selected':''?>>Coordenação</option><option value="ADMIN" <?=$u['perfil']==='ADMIN'?'selected':''?>>Administrador</option></select></label>
                                <label>Redefinir senha <small>(opcional)</small><input name="senha" type="password" minlength="6" autocomplete="new-password" placeholder="Não é necessário saber a senha atual"></label>
                                <p class="helper">Ao redefinir, o usuário criará uma senha pessoal no próximo acesso.</p>
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

    <section class="card admin-section admin-section-block" id="disciplinas">
        <details class="admin-module">
            <summary><span><span class="eyebrow">Organização pedagógica</span><strong>Disciplinas</strong><small><?=count($disciplines)?> disciplina(s) cadastrada(s)</small></span></summary>
            <div class="admin-module-body">
        <details class="admin-collapsible admin-create-panel">
            <summary><span><strong>Nova disciplina</strong><small>Adicione uma disciplina para atribuí-la aos professores.</small></span></summary>
            <div class="admin-collapsible-body">
                <form method="post" action="/admin/disciplinas">
                    <input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>">
                    <label>Nome da disciplina<input name="nome" required maxlength="100" placeholder="Ex.: Língua Portuguesa"></label>
                    <button class="primary">Adicionar disciplina</button>
                </form>
            </div>
        </details>
        <?php if(!$disciplines):?><div class="empty-state compact"><p>Nenhuma disciplina cadastrada.</p></div><?php else:?><div class="discipline-list">
            <?php foreach($disciplines as $d):?><div class="discipline-item">
                <span><strong><?=e($d['nome'])?></strong><small><?=e($d['vinculos'])?> vínculo(s)</small></span>
                <form class="inline-form" method="post" action="/admin/disciplinas/<?=e($d['id'])?>/excluir"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button class="danger small-button" data-confirm="Excluir a disciplina <?=e($d['nome'])?>?<?=(int)$d['vinculos']>0?' Os vínculos, relatórios e preenchimentos relacionados também serão apagados.':''?> Esta ação não pode ser desfeita.">Excluir</button></form>
            </div><?php endforeach;?>
        </div><?php endif;?>
            </div>
        </details>
    </section>

    <section class="card admin-section admin-section-block binding-section" id="vinculos">
        <details class="admin-module">
            <summary><span><span class="eyebrow">Distribuição</span><strong>Vínculos</strong><small><?=count($bindings)?> vínculo(s) cadastrado(s)</small></span></summary>
            <div class="admin-module-body">
        <?php if($bindings):?><div class="admin-module-tools"><label class="search"><span class="sr-only">Buscar vínculo</span><span aria-hidden="true">⌕</span><input type="search" placeholder="Buscar professor, turma ou disciplina" data-card-search="#binding-management-list" data-empty-target="#bindings-empty"></label></div><?php endif;?>
        <details class="admin-collapsible admin-create-panel">
            <summary><span><strong>Novo vínculo</strong><small>Escolha um professor e selecione suas turmas e disciplinas.</small></span></summary>
            <div class="admin-collapsible-body">
                <form method="post" action="/admin/vinculos" id="binding-form">
                    <input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>">
                    <div class="binding-step"><span class="step-number">1</span><div><label for="professor_id">Professor</label><?php if($professors):?><select id="professor_id" name="professor_id" required><option value="">Selecione um professor</option><?php foreach($professors as $p):?><option value="<?=e($p['id'])?>"><?=e($p['nome'])?> — <?=e($p['email'])?></option><?php endforeach;?></select><?php else:?><p class="error">Nenhum professor ativo cadastrado.</p><?php endif;?></div></div>
                    <div class="binding-step"><span class="step-number">2</span><div><div class="choice-heading"><div><label>Turmas</label><p class="helper">Você pode marcar várias turmas.</p></div><?php if($classes):?><label class="search"><span class="sr-only">Buscar turma</span><span aria-hidden="true">⌕</span><input type="search" placeholder="Buscar turma" data-choice-filter="#class-choices"></label><?php endif;?></div><?php if($classesError):?><p class="error" role="alert"><?=e($classesError)?></p><?php elseif(!$classes):?><p class="empty-state compact">Nenhuma turma disponível na secretaria.</p><?php else:?><div class="choice-grid" id="class-choices"><?php foreach($classes as $class):?><label class="choice-card"><input type="checkbox" name="turma_ids[]" value="<?=e($class['id'])?>"><span><strong><?=e($class['nome_turma'])?></strong></span></label><?php endforeach;?></div><p class="selection-count"><strong data-selection-count="#class-choices">0</strong> turma(s) selecionada(s)</p><?php endif;?></div></div>
                    <div class="binding-step"><span class="step-number">3</span><div><label>Disciplinas</label><p class="helper">Marque todas as disciplinas ministradas nas turmas selecionadas.</p><?php if(!$disciplines):?><p class="empty-state compact">Cadastre ao menos uma disciplina para continuar.</p><?php else:?><div class="choice-grid compact-choices" id="discipline-choices"><?php foreach($disciplines as $d):?><label class="choice-card"><input type="checkbox" name="disciplina_ids[]" value="<?=e($d['id'])?>"><span><strong><?=e($d['nome'])?></strong></span></label><?php endforeach;?></div><p class="selection-count"><strong data-selection-count="#discipline-choices">0</strong> disciplina(s) selecionada(s)</p><?php endif;?></div></div>
                    <div class="binding-submit"><p class="helper">Exemplo: 2 turmas × 3 disciplinas criarão 6 vínculos.</p><button class="primary" <?=(!$professors||!$classes||!$disciplines)?'disabled':''?>>Criar vínculos</button></div>
                </form>
            </div>
        </details>
        <?php if(!$bindings):?><div class="empty-state compact"><p>Nenhum vínculo cadastrado.</p></div><?php else:?><div class="binding-professor-list" id="binding-management-list">
            <?php foreach($bindingsByProfessor as $group):$rows=$group['rows'];$classCount=count(array_unique(array_column($rows,'turma_externa_id')));$disciplineCount=count(array_unique(array_column($rows,'disciplina_id')));$activeCount=count(array_filter($rows,static fn(array $row):bool=>(bool)$row['ativo']));?>
                <article class="binding-professor-group">
                    <details class="binding-professor-details">
                        <summary>
                            <span class="professor-identity"><span class="avatar" aria-hidden="true"><?=e(mb_strtoupper(mb_substr($group['nome'],0,1)))?></span><span><strong><?=e($group['nome'])?></strong><small><?=count($rows)?> vínculo(s) · <?=$classCount?> turma(s) · <?=$disciplineCount?> disciplina(s)</small></span></span>
                            <span class="badge status-<?=$activeCount?'aprovado':'pendente'?>"><?=$activeCount?> ativo(s)</span>
                        </summary>
                        <div class="binding-group-body">
                            <?php foreach($rows as $b):?><div class="binding-management-card">
                                <div><strong><?=e($b['turma_nome_snapshot'])?></strong><span><?=e($b['disciplina_nome'])?></span><small><?=e($b['relatorios'])?> relatório(s) · <?=e($b['ativo']?'Ativo':'Inativo')?></small></div>
                                <div class="user-card-actions">
                                    <?php if((int)$b['relatorios']===0):?><details class="user-editor binding-editor"><summary class="button">Editar</summary><form method="post" action="/admin/vinculos/<?=e($b['id'])?>/editar"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><label>Professor<select name="professor_id" required><?php foreach($professors as $p):?><option value="<?=e($p['id'])?>" <?=(int)$p['id']===(int)$b['usuario_id']?'selected':''?>><?=e($p['nome'])?></option><?php endforeach;?></select></label><label>Turma<select name="turma_id" required><?php foreach($classes as $c):?><option value="<?=e($c['id'])?>" <?=(int)$c['id']===(int)$b['turma_externa_id']?'selected':''?>><?=e($c['nome_turma'])?></option><?php endforeach;?></select></label><label>Disciplina<select name="disciplina_id" required><?php foreach($disciplines as $d):?><option value="<?=e($d['id'])?>" <?=(int)$d['id']===(int)$b['disciplina_id']?'selected':''?>><?=e($d['nome'])?></option><?php endforeach;?></select></label><div class="actions"><button type="button" data-close-details>Cancelar</button><button class="primary">Salvar vínculo</button></div></form></details><?php endif;?>
                                    <form class="inline-form" method="post" action="/admin/vinculos/<?=e($b['id'])?>/alternar"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button data-confirm="Confirma a alteração deste vínculo?"><?=e($b['ativo']?'Desativar':'Ativar')?></button></form>
                                    <form class="inline-form" method="post" action="/admin/vinculos/<?=e($b['id'])?>/excluir"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button class="danger" data-confirm="Excluir este vínculo?<?=(int)$b['relatorios']>0?' Os '.e($b['relatorios']).' relatório(s) e preenchimentos relacionados também serão apagados.':''?> Esta ação não pode ser desfeita.">Excluir</button></form>
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
                <div class="period-readiness"><strong>Antes de abrir</strong><span><?=e($bindingCount)?> vínculo(s) ativo(s) gerarão relatórios.</span><span class="<?=$unboundCount?'warning-text':'success-text'?>"><?=e($unboundCount)?> professor(es) ativo(s) sem vínculo.</span></div>
                <form method="post" action="/admin/periodos">
                    <input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>">
                    <div class="grid"><label>Nome do período<input name="nome" required placeholder="Ex.: 2º bimestre"></label><label>Ano letivo<input name="ano_letivo" type="number" min="2000" max="2100" value="<?=date('Y')?>" required></label><label class="period-stage">Etapa<input name="etapa" required placeholder="Ex.: 2º bimestre"></label><div class="grid period-date-grid"><label>Data de início<input name="data_inicio" type="date" required></label><label>Data final<input name="data_fim" type="date" required></label></div></div>
                    <button class="primary">Criar período</button>
                </form>
            </div>
        </details>
        <?php if(!$periods):?><div class="empty-state compact"><p>Nenhum período cadastrado.</p></div><?php else:?><div class="table"><table><thead><tr><th>Período</th><th>Ano</th><th>Status</th><th>Ação</th></tr></thead><tbody>
            <?php foreach($periods as $p):?><tr>
                <td data-label="Período"><strong><?=e($p['nome'])?></strong><small><?=e($p['etapa'])?></small></td>
                <td data-label="Ano"><?=e($p['ano_letivo'])?></td>
                <td data-label="Status"><span class="badge status-<?=e(strtolower($p['status']))?>"><?=e($p['status']==='RASCUNHO'?'Em preparação':($p['status']==='ABERTO'?'Aberto':'Encerrado'))?></span></td>
                <td class="row-action"><div class="row-actions"><?php if($p['status']==='RASCUNHO'):?><form class="inline-form" method="post" action="/admin/periodos/<?=e($p['id'])?>/abrir"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button class="primary" data-confirm="Abrir este período? Serão gerados relatórios para <?=e($bindingCount)?> vínculo(s) ativo(s).">Abrir</button></form><?php elseif($p['status']==='ABERTO'):?><form class="inline-form" method="post" action="/admin/periodos/<?=e($p['id'])?>/encerrar"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button data-confirm="Encerrar o período? Esta ação bloqueará novas edições.">Encerrar</button></form><?php endif;?><form class="inline-form" method="post" action="/admin/periodos/<?=e($p['id'])?>/excluir"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><button class="danger" data-confirm="Excluir o período <?=e($p['nome'])?>? Todos os relatórios e preenchimentos dele serão apagados. Esta ação não pode ser desfeita.">Excluir</button></form></div></td>
            </tr><?php endforeach;?>
        </tbody></table></div><?php endif;?>
            </div>
        </details>
    </section>
</div>
<?php
$content=ob_get_clean();
require __DIR__.'/layout.php';

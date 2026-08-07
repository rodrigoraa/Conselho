<?php ob_start();?>
<section class="page-heading"><div><p class="eyebrow">Visão da coordenação</p><h1>Consolidado do conselho</h1><p>Relatos de turma presentes nos documentos já aprovados.</p></div><div class="actions"><a class="button" href="?formato=csv">Exportar planilha (CSV)</a><button class="primary" type="button" data-print-page>Imprimir</button></div></section>
<section class="card">
    <div class="section-heading"><div><h2>Relatos aprovados</h2><p><?=count($rows)?> seção(ões) de turma</p></div><?php if($rows):?><label class="search"><span class="sr-only">Buscar no consolidado</span><span aria-hidden="true">⌕</span><input type="search" placeholder="Buscar turma, professor ou disciplina" data-table-search="#consolidated-table"></label><?php endif;?></div>
    <?php if(!$rows):?><div class="empty-state"><span aria-hidden="true">✓</span><h3>Ainda não há documentos aprovados</h3><p>Os relatos aparecerão aqui após a conferência dos documentos completos.</p></div><?php else:?>
    <div class="table"><table id="consolidated-table"><thead><tr><th>Período</th><th>Turma</th><th>Disciplina</th><th>Professor</th><th>Relato</th></tr></thead><tbody><?php foreach($rows as$row):?><tr><td data-label="Período"><strong><?=e($row['periodo'])?></strong><small><?=e($row['ano_letivo'])?></small></td><td data-label="Turma"><?=e($row['turma'])?></td><td data-label="Disciplina"><?=e($row['disciplina'])?></td><td data-label="Professor"><?=e($row['professor'])?></td><td data-label="Relato" class="consolidated-narrative"><?=nl2br(e($row['relato']))?></td></tr><?php endforeach;?></tbody></table></div>
    <?php endif;?>
</section>
<?php $content=ob_get_clean();require __DIR__.'/layout.php';

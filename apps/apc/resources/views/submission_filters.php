<?php
// Keep active filters visible when their last file was deleted or no accessible option exists.
// Generic labels use only the normalized filter value; no additional metadata lookup is needed.
$missingYear=isset($filters['ano'])&&!in_array((string)$filters['ano'],array_map('strval',$filterOptions['years']),true);
$missingEvent=isset($filters['evento'])&&!in_array((string)$filters['evento'],array_map('strval',array_column($filterOptions['events'],'id')),true);
$missingProfessor=isset($filters['professor'])&&!in_array((string)$filters['professor'],array_map('strval',array_column($filterOptions['professors'],'id')),true);
?>
<form method="get" action="/apc" class="apc-file-filters" data-apc-server-filters>
<input type="hidden" name="aba" value="<?=e($filterView)?>">
<div class="apc-filter-fields">
<label>Ano <select name="ano"><option value="">Todos os anos</option><?php if($missingYear):?><option value="<?=e($filters['ano'])?>" selected>Ano <?=e($filters['ano'])?> (filtro selecionado)</option><?php endif;?><?php foreach($filterOptions['years']as$option):?><option value="<?=e($option)?>" <?=($filters['ano']??'')===(string)$option?'selected':''?>><?=e($option)?></option><?php endforeach;?></select></label>
<label>Evento <select name="evento" data-apc-file-event><option value="">Todos os eventos</option><?php if($missingEvent):?><option value="<?=e($filters['evento'])?>" selected>Evento #<?=e($filters['evento'])?> (filtro selecionado)</option><?php endif;?><?php foreach($filterOptions['events']as$option):?><option value="<?=e($option['id'])?>" <?=($filters['evento']??'')===(string)$option['id']?'selected':''?>><?=e(date('d/m/Y',strtotime($option['data'])).' · '.$option['titulo'].' · '.\Apc\Support\NavigationContext::shift($option))?></option><?php endforeach;?></select></label>
<?php if($isManagement&&$filterView!=='mine'):?><label>Professor <select name="professor"><option value="">Todos os professores</option><?php if($missingProfessor):?><option value="<?=e($filters['professor'])?>" selected>Professor #<?=e($filters['professor'])?> (filtro selecionado)</option><?php endif;?><?php foreach($filterOptions['professors']as$option):?><option value="<?=e($option['id'])?>" <?=($filters['professor']??'')===(string)$option['id']?'selected':''?>><?=e($option['nome'])?></option><?php endforeach;?></select></label><?php endif;?>
<label>Buscar <input type="search" name="q" data-apc-file-search maxlength="200" value="<?=e($filters['q']??'')?>" placeholder="Professor, evento, turma ou arquivo"></label>
<?php if($filterView!=='tracking'):?><label>Situação <select name="situacao"><option value="">Todas</option><?php foreach(['ontime'=>'No prazo','late'=>'Com atraso']as$value=>$label):?><option value="<?=e($value)?>" <?=($filters['situacao']??'')===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label><label>Formato <select name="formato"><option value="">Todos os formatos</option><?php foreach(['pdf'=>'PDF','doc'=>'Word DOC','docx'=>'Word DOCX','odt'=>'ODT','image'=>'Imagem']as$value=>$label):?><option value="<?=e($value)?>" <?=($filters['formato']??'')===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label><?php endif;?>
</div>
<div class="apc-filter-actions"><button class="primary" type="submit">Aplicar filtros</button><a class="button" href="/apc?aba=<?=e($filterView)?>">Limpar filtros</a></div>
</form>

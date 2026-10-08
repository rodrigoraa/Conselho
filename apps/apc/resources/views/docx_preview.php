<?php
$docxViewerPath=dirname(__DIR__,3).'/preconselho-web/public/assets/apc-docx-viewer.html';
$docxViewerVersion=is_file($docxViewerPath)?(string)filemtime($docxViewerPath):'1';
?>
<div class="apc-docx-preview" data-apc-docx-preview<?=isset($docxSource)?' data-docx-source="'.e($docxSource).'"':''?><?=!empty($docxHidden)?' hidden':''?>>
    <p class="apc-docx-status" role="status" data-apc-docx-status>Carregando a pré-visualização do documento…</p>
    <template data-apc-docx-template><iframe title="Pré-visualização do documento Word" sandbox="allow-scripts" referrerpolicy="same-origin" src="/assets/apc-docx-viewer.html?v=<?=e($docxViewerVersion)?>"></iframe></template>
    <p class="helper">A prévia pode apresentar diferenças de formatação em relação ao Word. O arquivo original permanece disponível.</p>
    <noscript><p>Ative o JavaScript para visualizar o DOCX ou abra o arquivo em um aplicativo compatível.</p></noscript>
</div>

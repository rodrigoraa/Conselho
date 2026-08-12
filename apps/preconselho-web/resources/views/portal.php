<?php
$firstName=explode(' ',trim((string)($_SESSION['user']['nome']??'')))[0];
ob_start();
?>
<section class="page-heading portal-heading"><div><p class="eyebrow">Gestão pedagógica</p><h1>Olá, <?=e($firstName)?>!</h1><p>Escolha o sistema que deseja acessar.</p></div></section>
<section class="portal-grid" aria-label="Sistemas disponíveis">
    <article class="card portal-card council-card"><span class="portal-icon" aria-hidden="true">CC</span><div><p class="eyebrow">Documentos pedagógicos</p><h2>Conselho de Classe</h2><p>Documentos coletivos, acompanhamento dos períodos e consolidação do conselho.</p></div><a class="button primary" href="/conselho">Acessar Conselho <span aria-hidden="true">→</span></a></article>
    <article class="card portal-card apc-card"><span class="portal-icon" aria-hidden="true">APC</span><div><p class="eyebrow">Envio de documentos</p><h2>APCs</h2><p><strong>Atividades Pedagógicas Complementares</strong><br>Anexe o modelo pronto para cada turma e acompanhe a situação dos envios.</p></div><a class="button primary" href="/apc">Acessar APCs <span aria-hidden="true">→</span></a></article>
</section>
<?php $content=ob_get_clean();require __DIR__.'/layout.php';

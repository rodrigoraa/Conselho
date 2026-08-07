<?php
use PreConselho\Support\Csrf;
ob_start();
?>
<div class="login-shell">
    <section class="login-hero" aria-labelledby="login-hero-title">
        <span class="hero-badge">Conselho institucional</span>
        <h1 id="login-hero-title">Conselho de Classe EESJ.</h1>
        <p>Todos os relatos das suas turmas organizados em um único documento.</p>
        <div class="hero-points">
            <div><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm3 15h4M9 6h6v8H9z"/></svg><span>Um documento para todas as turmas</span></div>
            <div><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m12 3 7 3v5c0 4.6-2.9 8.2-7 10-4.1-1.8-7-5.4-7-10V6l7-3Zm-3 9 2 2 4-5"/></svg><span>Controle e conferência pedagógica</span></div>
            <div><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M6 3v3m12-3v3M4 9h16M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm4 9 2 2 4-4"/></svg><span>Preenchimento simples por período</span></div>
        </div>
    </section>

    <section class="login-panel">
        <div class="panel-brand">
            <div class="login-brand-mark"><img src="/assets/logo_escola.png" alt="Logo da Escola Estadual São José" class="brand-logo"></div>
            <div><strong>Conselho de Classe</strong><span>Acesso institucional</span></div>
        </div>

        <div class="panel-copy"><h2>Entrar no sistema</h2><p>Informe seu CPF para continuar.</p></div>

        <?php if(!empty($loginError)):?><div class="login-alert" role="alert"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 8v5m0 3h.01M10.3 4.4 2.8 17.3A1.8 1.8 0 0 0 4.4 20h15.2a1.8 1.8 0 0 0 1.6-2.7L13.7 4.4a2 2 0 0 0-3.4 0Z"/></svg><span><?=e($loginError)?></span></div><?php endif;?>

        <form method="post" action="/login" class="login-form">
            <input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>">
            <div class="login-field"><label for="cpf">CPF</label><input id="cpf" name="cpf" type="text" inputmode="numeric" autocomplete="username" maxlength="14" value="<?=e($cpfInput??'')?>" placeholder="000.000.000-00" data-cpf-input required autofocus></div>
            <button class="login-submit">Acessar conselho</button>
        </form>

        <div class="panel-footer"><small>O acesso de professores e demais usuários é feito somente pelo CPF cadastrado.</small></div>
        <div class="login-assistance">
            <div><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M9.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7.5-1 2 2 3-4"/></svg><span>Professor preenche todas as turmas em um documento.</span></div>
            <div><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.6 2.9 8.2 7 10 4.1-1.8 7-5.4 7-10V6l-7-3Zm-2 9 2 2 3-4"/></svg><span>Coordenação confere e acompanha os envios.</span></div>
        </div>
    </section>
</div>
<?php $content=ob_get_clean();require __DIR__.'/layout.php';

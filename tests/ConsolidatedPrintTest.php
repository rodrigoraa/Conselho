<?php declare(strict_types=1);

namespace Tests;

use PreConselho\Controllers\WebController;
use PreConselho\Integration\SecretariaApiClient;
use PreConselho\Repositories\AppRepository;
use Shared\Http\Request;
use Shared\Support\View;

final class ConsolidatedPrintTest extends ApcTestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['user']);
        $_SERVER['REQUEST_URI']='/';
    }

    public function testConsolidatedPageRendersWithPrintButtonAndNarratives(): void
    {
        $_SESSION['user']=['id'=>1,'nome'=>'Admin','perfil'=>'ADMIN'];
        $_SERVER['REQUEST_URI']='/consolidados';
        $view=new View(dirname(__DIR__).'/apps/preconselho-web/resources/views');
        $controller=new WebController(new AppRepository($this->mainDatabase()),$view,new SecretariaApiClient());
        $response=$controller->consolidated(new Request('GET','/consolidados',[],[],[]));
        self::assertSame(200,$response->status);
        self::assertStringContainsString('class="consolidated-page"',$response->body);
        self::assertStringContainsString('data-print-page',$response->body);
        self::assertStringContainsString('Ainda não há contribuições',$response->body);

        $html=$view->render('consolidated',['rows'=>[
            ['periodo'=>'1º Bimestre','ano_letivo'=>2026,'turno'=>'MATUTINO','turma'=>'7º A','relato'=>"Atenção à evolução.\nContinuação com acento.",'atualizado_por'=>'Coordenação','atualizado_em'=>'2026-09-28 10:00:00'],
            ['periodo'=>'1º Bimestre','ano_letivo'=>2026,'turno'=>'VESPERTINO','turma'=>'8º A','relato'=>'Outro relato coletivo.','atualizado_por'=>'Admin','atualizado_em'=>'2026-09-28 11:00:00'],
        ]]);
        self::assertStringContainsString('id="consolidated-table"',$html);
        self::assertSame(2,substr_count($html,'class="consolidated-narrative"'));
        self::assertStringContainsString('Atenção à evolução.<br',$html);
        self::assertStringContainsString('Outro relato coletivo.',$html);
    }

    public function testPrintRulesAreScopedAndOverrideMobileTableLayout(): void
    {
        $css=(string)file_get_contents(dirname(__DIR__).'/apps/preconselho-web/public/assets/app.css');
        $report=(string)file_get_contents(dirname(__DIR__).'/apps/preconselho-web/resources/views/report.php');
        self::assertDoesNotMatchRegularExpression('/(?<![\w:])main\s*>\s*\*\s*\{\s*display\s*:\s*none\s*!important/i',$css);
        self::assertStringContainsString('main:has(>.print-document)>*{display:none!important}',$css);
        self::assertStringContainsString('main>.print-document{display:grid!important',$css);
        self::assertStringContainsString('class="print-document"',$report);
        self::assertStringContainsString('@page consolidated{size:A4 landscape;margin:10mm}',$css);
        self::assertStringContainsString('body.consolidated-page{page:consolidated}',$css);
        foreach(['table!important','table-header-group!important','table-row-group!important','table-row!important','table-cell!important'] as $display){
            self::assertStringContainsString('display:'.$display,$css);
        }
        self::assertStringContainsString('.consolidated-page #consolidated-table td::before{display:none!important;content:none!important}',$css);
        self::assertStringContainsString('.consolidated-page #consolidated-table .consolidated-narrative{min-width:0!important',$css);
        self::assertStringContainsString('.consolidated-page #consolidated-table tr,.consolidated-page #consolidated-table tr[hidden]{display:table-row!important',$css);
        $js=(string)file_get_contents(dirname(__DIR__).'/apps/preconselho-web/public/assets/app.js');
        self::assertStringContainsString("[data-print-page]').forEach(button=>button.addEventListener('click',()=>window.print())",$js);
    }
}

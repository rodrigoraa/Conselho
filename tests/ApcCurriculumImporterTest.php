<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\{AuditRepository,CurriculumRepository};
use Apc\Services\CurriculumImporter;
use Apc\Controllers\CurriculumController;
use RuntimeException;
use Shared\Http\Request;

final class ApcCurriculumImporterTest extends ApcTestCase
{
    private function importer(\PDO$db):CurriculumImporter{return new CurriculumImporter(new CurriculumRepository($db),new AuditRepository($db),dirname(__DIR__).'/apps/apc/resources/curriculo');}
    public function testOfficialCatalogImportsIdempotentlyWithProvenanceAndTvtPartialScope():void
    {
        $db=$this->apcDatabase();$first=$this->importer($db)->import();$second=$this->importer($db)->import();self::assertSame(36,$first['componentes']);self::assertSame(2045,$first['habilidades']);self::assertSame($first['habilidades'],$second['habilidades']);self::assertSame(2045,(int)$db->query('SELECT COUNT(*) FROM apc_habilidades_curriculares')->fetchColumn());self::assertSame(29,(int)$db->query("SELECT COUNT(*) FROM apc_habilidades_curriculares WHERE origem='SED_MS_MATRIZ_HABILIDADES_ESSENCIAIS'")->fetchColumn());self::assertSame(0,(int)$db->query("SELECT COUNT(*) FROM apc_habilidades_curriculares WHERE componente_id IN(SELECT id FROM apc_componentes_curriculares WHERE sigla='TVT') AND escopo<>'ESSENCIAL_RECOMPOSICAO'")->fetchColumn());self::assertSame(2,(int)$db->query("SELECT COUNT(*) FROM apc_auditoria WHERE acao='CURRICULO_IMPORTADO'")->fetchColumn());
    }
    public function testUnknownComponentIsRejectedBeforeAnyCatalogWrite():void
    {
        $db=$this->apcDatabase();$dir=sys_get_temp_dir().'/apc-curriculo-'.bin2hex(random_bytes(6));mkdir($dir);file_put_contents($dir.'/componentes.csv',"chave,nome,sigla,modalidade,etapa,area_conhecimento,ordem,ativo\nEF_AI_MAT,Matemática,MAT,GERAL,EF_AI,Matemática,1,1\n");file_put_contents($dir.'/habilidades_ef_ms.csv',"etapa,anos_series,componente,sigla,codigo,descricao,unidade_tematica,objeto_conhecimento,origem,escopo,fonte_documento,fonte_pagina,tipo_associacao\nEF_AI,EF1,História,HIS,,Descrição válida,,,OUTRO_OFICIAL,CURRICULO_COMPLETO,TESTE,,CURRICULAR\n");try{(new CurriculumImporter(new CurriculumRepository($db),new AuditRepository($db),$dir))->import(['componentes.csv','habilidades_ef_ms.csv']);self::fail('Componente desconhecido deveria invalidar toda a importação.');}catch(RuntimeException){self::assertSame(0,(int)$db->query('SELECT COUNT(*) FROM apc_componentes_curriculares')->fetchColumn());}finally{unlink($dir.'/componentes.csv');unlink($dir.'/habilidades_ef_ms.csv');rmdir($dir);}
    }
    public function testAuthenticatedSearchDataIsFilteredByStageYearComponentAndLimited():void
    {
        $db=$this->apcDatabase();$this->importer($db)->import();$component=(int)$db->query("SELECT id FROM apc_componentes_curriculares WHERE etapa='EF_AF' AND sigla='CIE'")->fetchColumn();$response=(new CurriculumController(new CurriculumRepository($db)))->search(new Request('GET','/apc/habilidades',['etapa'=>'EF_AF','ano'=>'EF8','componente'=>(string)$component,'q'=>'solo'],[],[]));$data=json_decode($response->body,true,512,JSON_THROW_ON_ERROR);self::assertLessThanOrEqual(30,count($data['resultados']));self::assertSame(30,$data['limite']);foreach($data['resultados']as$row)self::assertSame($component,(int)$row['componente_id']);
    }
}

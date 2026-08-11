<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\{AuditRepository,CurriculumRepository};
use Apc\Services\{CurriculumImporter,CurriculumService};
use Shared\Exceptions\HttpException;

final class ApcCurriculumAdminTest extends ApcTestCase
{
    public function testAdminCreatesTvtAndAbilityWithNullCodeWhileProfessorIsForbidden():void
    {
        $db=$this->apcDatabase();$service=new CurriculumService(new CurriculumRepository($db),new AuditRepository($db));$admin=['id'=>1,'perfil'=>'ADMIN'];$component=$service->saveComponent(null,['nome'=>'Terra – Vida – Trabalho','sigla'=>'TVT','modalidade'=>'EDUCACAO_DO_CAMPO','etapa'=>'EF_AI','area_conhecimento'=>'Educação do Campo','ordem'=>10,'ativo'=>1],$admin,'127.0.0.1','phpunit');$ability=$service->saveAbility(null,['componente_id'=>$component,'codigo'=>'','descricao'=>'Habilidade oficial cadastrada manualmente','origem'=>'REFERENCIAL_TVT','escopo'=>'CURRICULO_COMPLETO','fonte_documento'=>'REFERENCIAL_OFICIAL','anos_series'=>['EF1'],'tipo_associacao'=>'CURRICULAR','ativo'=>1],$admin,'127.0.0.1','phpunit');self::assertNull((new CurriculumRepository($db))->ability($ability)['codigo']);try{$service->saveComponent(null,['nome'=>'Ciências'],['id'=>3,'perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');self::fail('Professor não pode administrar catálogo.');}catch(HttpException$exception){self::assertSame(403,$exception->status);}
    }
    public function testEditingKeepsDistinctCurricularAndRecompositionAssociations():void
    {
        $db=$this->apcDatabase();$repository=new CurriculumRepository($db);$audit=new AuditRepository($db);(new CurriculumImporter($repository,$audit,dirname(__DIR__).'/apps/apc/resources/curriculo'))->import();$id=(int)$db->query("SELECT h.id FROM apc_habilidades_curriculares h JOIN apc_habilidade_anos_series a ON a.habilidade_id=h.id WHERE h.codigo='MS.EF01TV01.c.01' GROUP BY h.id HAVING COUNT(*)=2")->fetchColumn();$ability=$repository->ability($id);self::assertNotNull($ability);$tokens=array_map(static fn(array$row):string=>$row['ano_serie'].'|'.$row['tipo_associacao'],$ability['associacoes']);(new CurriculumService($repository,$audit))->saveAbility($id,array_replace($ability,['associacoes'=>$tokens]),['id'=>1,'perfil'=>'ADMIN'],'127.0.0.1','phpunit');$saved=$repository->ability($id);self::assertSame(['CURRICULAR','RECOMPOSICAO'],array_column($saved['associacoes'],'tipo_associacao'));
    }
}

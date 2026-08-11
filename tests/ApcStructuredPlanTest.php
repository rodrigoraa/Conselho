<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\{AccessRepository,AuditRepository,CurriculumRepository,EventRepository,PlanRepository};
use Apc\Services\{AuthorizationService,CurriculumImporter,EventWindow,PlanService};
use Shared\Exceptions\HttpException;

final class ApcStructuredPlanTest extends ApcTestCase
{
    private function services():array{$main=$this->mainDatabase();$this->seedMain($main);$db=$this->apcDatabase();$this->seedEvent($db);$curriculum=new CurriculumRepository($db);(new CurriculumImporter($curriculum,new AuditRepository($db),dirname(__DIR__).'/apps/apc/resources/curriculo'))->import();$plans=new PlanRepository($db);$access=new AccessRepository($main);$auth=new AuthorizationService($plans,$access);$service=new PlanService($plans,new EventRepository($db),$access,new AuditRepository($db),$auth,null,$curriculum,new EventWindow('2026-08-11'));return[$db,$curriculum,$service,$plans];}
    private function ids(\PDO$db):array{$components=[];foreach(['TVT','CIE','MAT','HIS']as$sigla){$statement=$db->prepare("SELECT id FROM apc_componentes_curriculares WHERE etapa='EF_AF' AND sigla=?");$statement->execute([$sigla]);$components[$sigla]=(int)$statement->fetchColumn();}$abilities=[];foreach($components as$sigla=>$component){$statement=$db->prepare("SELECT h.id FROM apc_habilidades_curriculares h JOIN apc_habilidade_anos_series a ON a.habilidade_id=h.id WHERE h.componente_id=? AND a.ano_serie='EF7' LIMIT 1");$statement->execute([$component]);$abilities[$sigla]=(int)$statement->fetchColumn();}return[$components,$abilities];}
    private function input(array$components,array$abilities):array{return['evento_id'=>1,'turma_id_externo'=>10,'etapa'=>'EF_AF','ano_serie'=>'EF7','componentes'=>$components,'habilidades'=>$abilities,'competencias_habilidades'=>'Integração com o território','conteudos'=>'Território, medidas e ambiente','descricao_atividade'=>'Investigação interdisciplinar na comunidade','estrategia_devolucao'=>'Socialização e devolutiva coletiva'];}
    public function testInterdisciplinaryPlanPersistsTvtScienceMathAndMultipleAbilities():void
    {
        [$db,,$service,$plans]=$this->services();[$components,$abilities]=$this->ids($db);$id=$service->create($this->input([$components['TVT'],$components['CIE'],$components['MAT']],[$abilities['TVT'],$abilities['CIE'],$abilities['MAT']]),['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');self::assertSame(3,(int)$db->query("SELECT COUNT(*) FROM apc_plano_componentes WHERE plano_id=$id")->fetchColumn());self::assertSame(3,(int)$db->query("SELECT COUNT(*) FROM apc_plano_habilidades WHERE plano_id=$id")->fetchColumn());self::assertSame('EF_AF',$plans->find($id)['etapa']);self::assertStringContainsString('Terra – Vida – Trabalho',$plans->find($id)['componente_curricular']);
    }
    public function testAbilityFromUnselectedComponentIsRejectedServerSide():void
    {
        [$db,,$service]=$this->services();[$components,$abilities]=$this->ids($db);$this->expectException(HttpException::class);$service->create($this->input([$components['MAT']],[$abilities['HIS']]),['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');
    }
    public function testInactiveItemsRemainInHistoricalPlanButCannotEnterNewPlan():void
    {
        [$db,$curriculum,$service]=$this->services();[$components,$abilities]=$this->ids($db);$input=$this->input([$components['TVT']],[$abilities['TVT']]);$id=$service->create($input,['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');$curriculum->setAbilityActive($abilities['TVT'],false);$service->update($id,$input,['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');self::assertCount(1,$curriculum->planCurriculum($id)['habilidades']);$db->exec("UPDATE apc_planos SET componente_curricular='outro' WHERE id=$id");$this->expectException(HttpException::class);$service->create($input+['turma_id_externo'=>10],['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');
    }
    public function testFrontendFilterKeepsOnlyComponentsFromSelectedStageVisible():void
    {
        $javascript=(string)file_get_contents(dirname(__DIR__).'/apps/preconselho-web/public/assets/app.js');$css=(string)file_get_contents(dirname(__DIR__).'/apps/preconselho-web/public/assets/app.css');self::assertStringContainsString("const show=!!stage?.value&&label.dataset.stage===stage.value",$javascript);self::assertStringContainsString('.curriculum-choice[hidden]{display:none!important}',$css);
    }
}

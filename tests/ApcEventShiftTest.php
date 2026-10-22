<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,ScheduleRepository,SubmissionRepository,TermRepository};
use Apc\Services\{CalendarImporter,EventService,ScheduleService,SubmissionService,XlsxScheduleParser};
use Shared\Exceptions\HttpException;
use PDO;

final class ApcEventShiftTest extends ApcTestCase
{
    public function testManualEventPersistsSelectedShiftAndAuditsIt():void
    {
        $db=$this->apcDatabase();$events=new EventRepository($db);$service=new EventService($events,new AuditRepository($db));
        $id=$service->save(null,$this->input('2026-10-23','MATUTINO'),1,'127.0.0.1','test');
        self::assertSame('MATUTINO',$events->find($id)['turno']??null);
        $service->save($id,$this->input('2026-10-23','VESPERTINO'),1,'127.0.0.1','test');
        self::assertSame('VESPERTINO',$events->find($id)['turno']);
        $audit=json_decode($db->query('SELECT dados_depois FROM apc_auditoria ORDER BY id DESC LIMIT 1')->fetchColumn(),true);
        self::assertSame('VESPERTINO',$audit['turno']);
        $all=$service->save(null,$this->input('2026-10-26','TODOS'),1,'127.0.0.1','test');
        self::assertNull($events->find($all)['turno']);
    }

    public function testInvalidShiftIsRejectedWithoutSavingOrAuditing():void
    {
        $db=$this->apcDatabase();$service=new EventService(new EventRepository($db),new AuditRepository($db));
        foreach(['NOTURNO',['MATUTINO']]as$shift){
            try{$service->save(null,$this->input('2026-10-23',$shift),1,'127.0.0.1','test');self::fail('Turno inválido deveria ser rejeitado.');}
            catch(HttpException$exception){self::assertSame(422,$exception->status);self::assertSame('APC_EVENT_SHIFT',$exception->errorCode);}
        }
        self::assertSame(0,(int)$db->query('SELECT COUNT(*) FROM apc_eventos')->fetchColumn());
        self::assertSame(0,(int)$db->query('SELECT COUNT(*) FROM apc_auditoria')->fetchColumn());
        self::assertFalse($db->inTransaction());
    }

    public function testCalendarImportDoesNotAbsorbManualEventRestrictedToOneShift():void
    {
        $db=$this->apcDatabase();$events=new EventRepository($db);$service=new EventService($events,new AuditRepository($db));
        $id=$service->save(null,$this->input('2026-10-23','MATUTINO'),1,'127.0.0.1','test');$before=$events->find($id);
        $importer=new CalendarImporter($events,new AuditRepository($db),'unused.csv');
        $summary=$importer->importRows([['chave_importacao'=>'TEST_2026_10_23','ano_letivo'=>2026,'data'=>'2026-10-23','titulo'=>'Evento do calendário','tipo'=>'EXCEPCIONAL','origem'=>'SED','descricao'=>'Calendário oficial','numero_processo'=>'','documento_referencia'=>'Calendário 2026','fonte_pagina'=>1,'status'=>'ATIVO']],1);
        self::assertSame(1,$summary['criados']);self::assertSame(0,$summary['conciliados']);
        self::assertSame($before,$events->find($id));self::assertNull($events->findByImportKey('TEST_2026_10_23')['turno']);
    }

    public function testMigrationPreservesExistingEventsSubmissionsAndSnapshots():void
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
        $directory=dirname(__DIR__).'/apps/apc/database/migrations/';$files=glob($directory.'*.sql')?:[];sort($files);
        foreach($files as$file){if(basename($file)==='010_turno_evento.sql')break;$db->exec((string)file_get_contents($file));}
        $this->seedEvent($db);$this->import($db,'MATUTINO',[$this->row(10,3,5)]);
        $row=$db->query('SELECT h.*,i.turno FROM apc_horarios h JOIN apc_horario_importacoes i ON i.id=h.importacao_id')->fetch();
        (new ScheduleRepository($db))->saveSnapshot(1,[$row]);
        $db->exec("INSERT INTO apc_envios(id,evento_id,bimestre_id,professor_usuario_id,professor_nome_snapshot,etapa,ano_serie,turma_id_externo,nome_original,nome_armazenado,mime_type,tamanho_bytes,sha256,caminho_relativo)VALUES(7,1,3,3,'Professor Um','EF_AF','EF7',10,'historico.pdf','historico.pdf','application/pdf',1,'".str_repeat('a',64)."','envios/historico.pdf');INSERT INTO apc_envio_turmas(envio_id,turma_id_externo,turma_nome_snapshot)VALUES(7,10,'7º A')");
        $tables=['apc_envios','apc_envio_turmas','apc_evento_obrigacoes','apc_evento_obrigacao_estados'];$before=[];
        foreach($tables as$table)$before[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();$event=(new EventRepository($db))->find(1);
        $db->beginTransaction();$db->exec((string)file_get_contents($directory.'010_turno_evento.sql'));$db->commit();
        self::assertSame($event+['turno'=>null],(new EventRepository($db))->find(1));
        foreach($tables as$table)self::assertSame($before[$table],$db->query('SELECT * FROM '.$table)->fetchAll());
        self::assertSame([],$db->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function testSelectedShiftNeedsOnlyItsOwnScheduleAndOnlyItsOfficialWeekday():void
    {
        [$main,$apc,$schedules,$events]=$this->context();
        $this->import($apc,'MATUTINO',[$this->row(10,3,5),$this->row(20,4,1)]);
        $service=new EventService($events,new AuditRepository($apc),$schedules);
        $friday=$service->save(null,$this->input('2026-10-23','MATUTINO'),1,'127.0.0.1','test');
        $state=$schedules->ensureEvent($events->find($friday));
        self::assertSame('CONFIGURADO',$state['status']);self::assertCount(1,$state['requirements']);
        self::assertSame(3,(int)$state['requirements'][0]['professor_usuario_id']);
        self::assertSame('MATUTINO',$state['requirements'][0]['turno']);
        $monday=$service->save(null,$this->input('2026-10-26','MATUTINO'),1,'127.0.0.1','test');
        self::assertSame([4],array_map('intval',array_column($schedules->ensureEvent($events->find($monday))['requirements'],'professor_usuario_id')));
        $afternoon=$service->save(null,$this->input('2026-10-26','VESPERTINO'),1,'127.0.0.1','test');
        $missing=$schedules->ensureEvent($events->find($afternoon));
        self::assertSame('NAO_CONFIGURADA',$missing['status']);self::assertSame(['VESPERTINO'],$missing['missing_shifts']);
    }

    public function testAfternoonSelectionFiltersBeforeDeduplicatingSameTeacherAndClass():void
    {
        [$main,$apc,$schedules,$events]=$this->context();
        $this->import($apc,'MATUTINO',[$this->row(10,3,5)]);
        $this->import($apc,'VESPERTINO',[$this->row(10,3,5),$this->row(30,4,1)]);
        $service=new EventService($events,new AuditRepository($apc),$schedules);
        $id=$service->save(null,$this->input('2026-10-23','VESPERTINO'),1,'127.0.0.1','test');
        $requirements=$schedules->ensureEvent($events->find($id))['requirements'];
        self::assertCount(1,$requirements);self::assertSame('VESPERTINO',$requirements[0]['turno']);
        self::assertSame(3,(int)$requirements[0]['professor_usuario_id']);
        $schedules->recalculateConfigured($id,1,'127.0.0.1','test');
        self::assertSame('VESPERTINO',(new ScheduleRepository($apc))->obligations($id)[0]['turno']);
    }

    public function testShiftCannotChangeAfterSnapshotAndOtherShiftPostIsRejected():void
    {
        [$main,$apc,$schedules,$events]=$this->context();
        $this->import($apc,'MATUTINO',[$this->row(10,3,5)]);
        $this->import($apc,'VESPERTINO',[$this->row(30,4,5)]);
        $service=new EventService($events,new AuditRepository($apc),$schedules);
        $id=$service->save(null,$this->input('2026-10-23','MATUTINO'),1,'127.0.0.1','test');
        $before=(new ScheduleRepository($apc))->obligations($id);
        try{$service->save($id,$this->input('2026-10-23','VESPERTINO'),1,'127.0.0.1','test');self::fail('Turno com snapshot deve permanecer protegido.');}
        catch(HttpException$exception){self::assertSame('APC_EVENT_REQUIREMENTS_LOCKED',$exception->errorCode);}
        self::assertSame('MATUTINO',$events->find($id)['turno']);
        self::assertSame($before,(new ScheduleRepository($apc))->obligations($id));
        $submissions=new SubmissionService(new SubmissionRepository($apc),$events,new TermRepository($apc),new AccessRepository($main),new AuditRepository($apc),sys_get_temp_dir(),1048576,null,null,'2026-10-23',null,null,$schedules);
        try{$submissions->submit(['evento_id'=>$id,'etapa'=>'EF_AF','ano_serie'=>'EF8','turma_id'=>30],[],['id'=>4,'nome'=>'Professor Dois','perfil'=>'PROFESSOR'],'127.0.0.1','test');self::fail('POST de outro turno deveria ser bloqueado.');}
        catch(HttpException$exception){self::assertSame(403,$exception->status);self::assertSame('APC_SCHEDULE_FORBIDDEN',$exception->errorCode);}
        self::assertSame(0,(int)$apc->query('SELECT COUNT(*) FROM apc_envios')->fetchColumn());
    }

    public function testAdminFormOffersShiftForCreationAndKeepsSelectedShiftOnEdit():void
    {
        [$main,$apc,$schedules,$events]=$this->context();
        (new EventService($events,new AuditRepository($apc)))->save(null,$this->input('2026-10-23','VESPERTINO'),1,'127.0.0.1','test');
        $_SESSION['user']=['id'=>1,'nome'=>'Admin','perfil'=>'ADMIN'];$_SESSION['_csrf']='shift-test';$_SERVER['REQUEST_URI']='/apc/admin';
        try{
            $module=new \Apc\Support\Module($apc,$main,new \PreConselho\Integration\SecretariaApiClient(),dirname(__DIR__));
            $html=$module->admin->index(new \Shared\Http\Request('GET','/apc/admin',[],[],[]))->body;
            $document=new \DOMDocument();@$document->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new \DOMXPath($document);
            self::assertSame(1,$xpath->query('//form[@action="/apc/admin/eventos"]//select[@name="turno"]')->length);
            self::assertSame(3,$xpath->query('//form[@action="/apc/admin/eventos"]//select[@name="turno"]/option[@value="TODOS" or @value="MATUTINO" or @value="VESPERTINO"]')->length);
            self::assertSame(1,$xpath->query('//form[@action="/apc/admin/eventos/1"]//select[@name="turno"]/option[@value="VESPERTINO" and @selected]')->length);
        }finally{unset($_SESSION['user'],$_SESSION['_csrf']);$_SERVER['REQUEST_URI']='/';}
    }

    private function context():array
    {
        $main=$this->mainDatabase();$this->seedMain($main);$apc=$this->apcDatabase();
        $main->exec("INSERT INTO vinculos_professor_turma(professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno)VALUES(1,10,'7º A',2026,'VESPERTINO'),(2,30,'8º B',2026,'VESPERTINO')");
        $events=new EventRepository($apc);$schedules=new ScheduleService(new ScheduleRepository($apc),$events,new AccessRepository($main),new AuditRepository($apc),new XlsxScheduleParser(),sys_get_temp_dir(),1048576);
        return[$main,$apc,$schedules,$events];
    }

    private function input(string$date,mixed$shift):array{return['ano_letivo'=>2026,'data'=>$date,'titulo'=>'APC por turno','tipo'=>'EXCEPCIONAL','origem'=>'ESCOLA','turno'=>$shift];}
    private function import(PDO$db,string$shift,array$rows):void{(new ScheduleRepository($db))->createImport(['ano_letivo'=>2026,'turno'=>$shift,'vigente_de'=>'2026-01-01','vigente_ate'=>'2026-12-31','nome_arquivo'=>'teste.xlsx','sha256'=>str_repeat('a',64),'usuario_id'=>1],$rows);}
    private function row(int$class,int$teacher,int$day):array{return['turma_id_externo'=>$class,'turma_nome_snapshot'=>$class===10?'7º A':($class===20?'8º A':'8º B'),'dia_semana'=>$day,'numero_aula'=>1,'disciplina'=>'Arte','professor_usuario_id'=>$teacher,'professor_nome_snapshot'=>$teacher===3?'Professor Um':'Professor Dois','professor_nome_importado'=>'Professor'];}
}

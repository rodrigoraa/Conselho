<?php declare(strict_types=1);

namespace Tests;

use Apc\Controllers\ScheduleAdminController;
use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,ScheduleRepository,SubmissionRepository,TermRepository};
use Apc\Services\{ScheduleService,SubmissionService,XlsxScheduleParser};
use Shared\Exceptions\HttpException;
use Shared\Http\Request;
use Shared\Support\View;

final class ApcScheduleTest extends ApcTestCase
{
    private string $directory;
    protected function setUp():void{$this->directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'apc-grade-'.bin2hex(random_bytes(6));mkdir($this->directory,0770,true);}
    protected function tearDown():void{$this->remove($this->directory);}

    public function testAdministrativeSchedulePostsRequireCsrf():void
    {
        [$main,$apc,$service]=$this->context();$_SESSION['user']=['id'=>1,'perfil'=>'ADMIN'];$_SESSION['_csrf']='valid-token';$controller=new ScheduleAdminController($service,new ScheduleRepository($apc),new View(dirname(__DIR__).'/apps/apc/resources/views'));try{$controller->analyze(new Request('POST','/apc/admin/horarios/analisar',[],[],[]));self::fail('POST sem CSRF deveria falhar.');}catch(HttpException$exception){self::assertSame(419,$exception->status);self::assertSame('CSRF_INVALID',$exception->errorCode);}unset($_SESSION['user'],$_SESSION['_csrf']);
    }

    public function testMondayAndThursdayUseOnlyTeachersScheduledOnOfficialEventDate():void
    {
        [$main,$apc,$schedules]=$this->context();$this->events($apc);$this->import($apc,[
            $this->row(10,'7º A',1,1,3,'Professor Um'),$this->row(20,'8º A',4,1,4,'Professor Dois'),
        ]);
        $monday=$schedules->ensureEvent((new EventRepository($apc))->find(1));$thursday=$schedules->ensureEvent((new EventRepository($apc))->find(2));
        self::assertSame([3],array_map('intval',array_column($monday['requirements'],'professor_usuario_id')));self::assertSame([4],array_map('intval',array_column($thursday['requirements'],'professor_usuario_id')));
    }

    public function testRepeatedLessonsDeduplicateObligationAndMultipleClassesRemainIndependent():void
    {
        [$main,$apc,$schedules]=$this->context();$main->exec("INSERT INTO vinculos_professor_turma(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno)VALUES(3,1,30,'8º B',2026,'MATUTINO'),(4,1,40,'9º C',2026,'MATUTINO')");$this->events($apc);$this->import($apc,[$this->row(10,'7º A',4,1,3,'Professor Um'),$this->row(10,'7º A',4,5,3,'Professor Um'),$this->row(30,'8º B',4,2,3,'Professor Um'),$this->row(40,'9º C',4,3,3,'Professor Um')]);$result=$schedules->ensureEvent((new EventRepository($apc))->find(2));self::assertCount(3,$result['requirements']);self::assertSame([10,30,40],array_map('intval',array_column($result['requirements'],'turma_id_externo')));
    }

    public function testMissingWrongYearAndWrongShiftSchedulesAreExplicitlyUnconfigured():void
    {
        [$main,$apc,$schedules]=$this->context();$this->events($apc);self::assertSame('NAO_CONFIGURADA',$schedules->ensureEvent((new EventRepository($apc))->find(2))['status']);$this->import($apc,[$this->row(10,'7º A',4,1,3,'Professor Um')],2027,'MATUTINO');self::assertSame('NAO_CONFIGURADA',$schedules->ensureEvent((new EventRepository($apc))->find(2))['status']);$this->import($apc,[$this->row(10,'7º A',4,1,3,'Professor Um')],2026,'VESPERTINO');self::assertSame('NAO_CONFIGURADA',$schedules->ensureEvent((new EventRepository($apc))->find(2))['status']);
    }

    public function testInactiveTeacherOrBindingDoesNotGenerateObligation():void
    {
        [$main,$apc,$schedules]=$this->context();$this->events($apc);$this->import($apc,[$this->row(10,'7º A',4,1,3,'Professor Um'),$this->row(20,'8º A',4,2,4,'Professor Dois')]);$hash=password_hash('interno',PASSWORD_DEFAULT);$statement=$main->prepare("INSERT INTO usuarios(id,nome,email,cpf,senha_hash,perfil)VALUES(5,'Professor Apoio','apoio@test','39053344705',?,'PROFESSOR')");$statement->execute([$hash]);$main->exec("INSERT INTO professores(id,usuario_id)VALUES(3,5);INSERT INTO vinculos_professor_turma(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno)VALUES(3,3,30,'9º A',2026,'MATUTINO');UPDATE usuarios SET ativo=0 WHERE id=3;UPDATE vinculos_professor_turma SET ativo=0 WHERE turma_externa_id=20");$result=$schedules->ensureEvent((new EventRepository($apc))->find(2));self::assertSame('CONFIGURADO',$result['status']);self::assertCount(0,$result['requirements']);
    }

    public function testSnapshotIsNotChangedByLaterScheduleVersion():void
    {
        [$main,$apc,$schedules]=$this->context();$this->events($apc);$old=$this->import($apc,[$this->row(10,'7º A',4,1,3,'Professor Um')],2026,'MATUTINO','2026-01-01','2026-07-31');$apc->exec("UPDATE apc_eventos SET data='2026-07-30' WHERE id=2");$first=$schedules->ensureEvent((new EventRepository($apc))->find(2));self::assertSame(3,(int)$first['requirements'][0]['professor_usuario_id']);(new ScheduleRepository($apc))->deactivate($old,1);$this->import($apc,[$this->row(10,'7º A',4,1,4,'Professor Dois')],2026,'MATUTINO','2026-08-01',null);$again=$schedules->ensureEvent((new EventRepository($apc))->find(2));self::assertSame(3,(int)$again['requirements'][0]['professor_usuario_id']);self::assertSame(1,(int)$apc->query('SELECT COUNT(*) FROM apc_evento_obrigacoes WHERE evento_id=2')->fetchColumn());
    }

    public function testNewVersionRecalculatesCoveredEventOnlyWhenItHasNoSubmission():void
    {
        [$main,$apc,$service]=$this->context();$this->events($apc);$old=$this->import($apc,[$this->row(10,'7º A',4,1,3,'Professor Um')]);$event=(new EventRepository($apc))->find(2);self::assertSame(3,(int)$service->ensureEvent($event)['requirements'][0]['professor_usuario_id']);(new ScheduleRepository($apc))->deactivate($old,1);$xlsx=$this->xlsx([['TURMA','AULA','QUINTA'],['8º A','1','Arte(Professor Dois)']]);$analysis=$service->analyze(['name'=>'nova.xlsx','tmp_name'=>$xlsx,'error'=>UPLOAD_ERR_OK,'size'=>filesize($xlsx)],['ano_letivo'=>2026,'turno'=>'MATUTINO','vigente_de'=>'2026-08-01']);$service->confirm($analysis,[],1,'127.0.0.1','test');$updated=$service->ensureEvent($event);self::assertSame(4,(int)$updated['requirements'][0]['professor_usuario_id']);self::assertSame(20,(int)$updated['requirements'][0]['turma_id_externo']);self::assertSame(1,(int)$apc->query("SELECT COUNT(*) FROM apc_auditoria WHERE acao='RECALCULAR_OBRIGACOES_APC'")->fetchColumn());
    }

    public function testEventWithExistingSubmissionBecomesLegacyWithoutChangingTheFileRecord():void
    {
        [$main,$apc,$schedules]=$this->context();$this->events($apc);$this->submission($apc,2,3,10);$this->import($apc,[$this->row(20,'8º A',4,1,4,'Professor Dois')]);$result=$schedules->ensureEvent((new EventRepository($apc))->find(2));self::assertSame('LEGADO',$result['status']);self::assertCount(0,$result['requirements']);$stored=(new SubmissionRepository($apc))->find(1);self::assertNotNull($stored);self::assertSame(10,(int)$stored['turma_id_externo']);
    }

    public function testTrackingUsesSnapshotsForPendingPartialAndCompleteAndExcludesNoClassTeacher():void
    {
        [$main,$apc,$schedules]=$this->context();$main->exec("INSERT INTO vinculos_professor_turma(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno)VALUES(3,1,30,'8º B',2026,'MATUTINO')");$this->events($apc);$this->import($apc,[$this->row(10,'7º A',4,1,3,'Professor Um'),$this->row(30,'8º B',4,2,3,'Professor Um'),$this->row(20,'8º A',4,3,4,'Professor Dois')]);$schedules->ensureEvent((new EventRepository($apc))->find(2));$this->submission($apc,2,3,10);$service=$this->submissionService($main,$apc,$schedules);$tracked=$service->tracking()['events'];$event=current(array_filter($tracked,static fn($item)=>$item['id']==2));$byId=array_column($event['professors'],null,'professor_usuario_id');self::assertSame('PARCIAL',$byId[3]['status']);self::assertSame('PENDENTE',$byId[4]['status']);self::assertSame(2,$event['professor_count']);$this->submission($apc,2,3,30,2);$this->submission($apc,2,4,20,3);$event=current(array_filter($service->tracking()['events'],static fn($item)=>$item['id']==2));self::assertSame(2,$event['complete_count']);
    }

    public function testTeacherDashboardShowsOnlyScheduledClassesForTheEvent():void
    {
        [$main,$apc,$schedules]=$this->context();$main->exec("INSERT INTO vinculos_professor_turma(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno)VALUES(3,1,30,'8º B',2026,'MATUTINO')");$this->events($apc);$this->import($apc,[$this->row(10,'7º A',4,1,3,'Professor Um'),$this->row(20,'8º A',4,2,4,'Professor Dois')]);$service=$this->submissionService($main,$apc,$schedules);$dashboard=$service->teacherDashboard((new AccessRepository($main))->seriesFor(3,'PROFESSOR'),[],3);$event=current(array_filter($dashboard['available'],static fn($item)=>$item['id']==2));self::assertSame(1,$event['total_count']);self::assertSame('7º A',$event['requirements'][0]['turma_nome']);self::assertArrayNotHasKey(30,$dashboard['eligible_events']);$other=$service->teacherDashboard((new AccessRepository($main))->seriesFor(4,'PROFESSOR'),[],4);$monday=current(array_filter($other['available'],static fn($item)=>$item['id']==1));self::assertSame('SEM_AULA',$monday['status']);
    }

    public function testSubmitRejectsClassOutsideScheduleTamperingInactiveTeacherAndInactiveBinding():void
    {
        [$main,$apc,$schedules]=$this->context();$this->events($apc);$this->import($apc,[$this->row(10,'7º A',4,1,3,'Professor Um')]);$service=$this->submissionService($main,$apc,$schedules);
        try{$service->submit(['evento_id'=>2,'etapa'=>'EF_AF','ano_serie'=>'EF8','turma_id'=>20],[] ,['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','test');self::fail('POST adulterado deveria falhar.');}catch(HttpException$exception){self::assertSame(403,$exception->status);}
        $main->exec('UPDATE usuarios SET ativo=0 WHERE id=3');try{$service->submit(['evento_id'=>2,'etapa'=>'EF_AF','ano_serie'=>'EF7','turma_id'=>10],[],['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','test');self::fail('Professor inativo deveria falhar.');}catch(HttpException$exception){self::assertSame('APC_FORBIDDEN',$exception->errorCode);}$main->exec('UPDATE usuarios SET ativo=1 WHERE id=3;UPDATE vinculos_professor_turma SET ativo=0 WHERE id=1');try{$service->submit(['evento_id'=>2,'etapa'=>'EF_AF','ano_serie'=>'EF7','turma_id'=>10],[],['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','test');self::fail('Vínculo inativo deveria falhar.');}catch(HttpException$exception){self::assertSame('APC_CLASS_FORBIDDEN',$exception->errorCode);}
    }

    public function testValidScheduledSubmissionSucceedsOncePerProfessorAndClass():void
    {
        [$main,$apc,$schedules]=$this->context();$this->events($apc);$this->import($apc,[$this->row(10,'7º A',4,1,3,'Professor Um'),$this->row(10,'7º A',4,5,3,'Professor Um')]);$service=$this->submissionService($main,$apc,$schedules);$input=['evento_id'=>2,'etapa'=>'EF_AF','ano_serie'=>'EF7','turma_id'=>10];$user=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$id=$service->submit($input,$this->upload('apc.png'),$user,'127.0.0.1','test');self::assertGreaterThan(0,$id);self::assertSame(1,(int)$apc->query('SELECT COUNT(*) FROM apc_envios')->fetchColumn());try{$service->submit($input,$this->upload('outra.png'),$user,'127.0.0.1','test');self::fail('Obrigação duplicada não deveria aceitar outro envio.');}catch(HttpException$exception){self::assertSame('APC_SUBMISSION_ALREADY_EXISTS',$exception->errorCode);}
    }

    public function testRealXlsxAnalysisNormalizesNamesAndRequiresUnknownAssociations():void
    {
        [$main,$apc,$service]=$this->context();$xlsx=$this->xlsx([['TURMA','AULA','SEGUNDA','QUINTA'],['7º A','1','Matemática(Proféssor Um)','Arte(Desconhecida)'],['Turma inexistente','2','Ciências(Professor Um)','']]);$file=['name'=>'grade.xlsx','tmp_name'=>$xlsx,'error'=>UPLOAD_ERR_OK,'size'=>filesize($xlsx)];$analysis=$service->analyze($file,['ano_letivo'=>2026,'turno'=>'MATUTINO','vigente_de'=>'2026-02-01','vigente_ate'=>'2026-12-20']);self::assertCount(3,$analysis['rows']);self::assertSame('safe',$analysis['rows'][0]['professor_match']['status']);self::assertContains($analysis['rows'][1]['professor_match']['status'],['unmatched','not_found']);self::assertNotSame('safe',$analysis['rows'][2]['turma_match']['status']);self::assertSame(0,(int)$apc->query('SELECT COUNT(*) FROM apc_horario_importacoes')->fetchColumn());try{$service->confirm($analysis,[],1,'127.0.0.1','test');self::fail('Associação não resolvida deveria impedir confirmação.');}catch(HttpException$exception){self::assertSame('APC_SCHEDULE_UNRESOLVED',$exception->errorCode);}self::assertSame(0,(int)$apc->query('SELECT COUNT(*) FROM apc_horario_importacoes')->fetchColumn());
    }

    public function testValidXlsxConfirmationIsTransactionalAndOverlappingVersionIsRejected():void
    {
        [$main,$apc,$service]=$this->context();$this->events($apc);$xlsx=$this->xlsx([['TURMA','AULA','SEGUNDA','QUINTA'],['7º A','1','Matemática(Professor Um)','Arte(Professor Um)']]);$analysis=$service->analyze(['name'=>'grade.xlsx','tmp_name'=>$xlsx,'error'=>UPLOAD_ERR_OK,'size'=>filesize($xlsx)],['ano_letivo'=>2026,'turno'=>'MATUTINO','vigente_de'=>'2026-02-01','vigente_ate'=>'2026-12-20']);$id=$service->confirm($analysis,[],1,'127.0.0.1','test');self::assertGreaterThan(0,$id);self::assertSame(2,(int)$apc->query('SELECT COUNT(*) FROM apc_horarios')->fetchColumn());self::assertSame('CONFIRMAR_HORARIO_APC',$apc->query('SELECT acao FROM apc_auditoria ORDER BY id DESC LIMIT 1')->fetchColumn());$xlsx2=$this->xlsx([['TURMA','AULA','QUINTA'],['7º A','1','Arte(Professor Um)']]);$overlap=$service->analyze(['name'=>'outra.xlsx','tmp_name'=>$xlsx2,'error'=>UPLOAD_ERR_OK,'size'=>filesize($xlsx2)],['ano_letivo'=>2026,'turno'=>'MATUTINO','vigente_de'=>'2026-08-01','vigente_ate'=>'2026-12-31']);try{$service->confirm($overlap,[],1,'127.0.0.1','test');self::fail('Vigência sobreposta deveria falhar.');}catch(HttpException$exception){self::assertSame('APC_SCHEDULE_OVERLAP',$exception->errorCode);}self::assertSame(1,(int)$apc->query('SELECT COUNT(*) FROM apc_horario_importacoes')->fetchColumn());
    }

    public function testInvalidXlsxStructureDoesNotWritePartialRecords():void
    {
        [$main,$apc,$service]=$this->context();$xlsx=$this->xlsx([['TURMA','AULA','SEGUNDA'],['7º A','1','Sem parenteses']]);try{$service->analyze(['name'=>'invalida.xlsx','tmp_name'=>$xlsx,'error'=>UPLOAD_ERR_OK,'size'=>filesize($xlsx)],['ano_letivo'=>2026,'turno'=>'MATUTINO','vigente_de'=>'2026-02-01']);self::fail('Estrutura inválida deveria falhar.');}catch(HttpException$exception){self::assertSame('APC_SCHEDULE_STRUCTURE',$exception->errorCode);}self::assertSame(0,(int)$apc->query('SELECT COUNT(*) FROM apc_horario_importacoes')->fetchColumn());
    }

    private function context():array{$main=$this->mainDatabase();$this->seedMain($main);$apc=$this->apcDatabase();$service=new ScheduleService(new ScheduleRepository($apc),new EventRepository($apc),new AccessRepository($main),new AuditRepository($apc),new XlsxScheduleParser(),$this->directory,1048576,static fn(string$path):bool=>is_file($path),static fn(string$from,string$to):bool=>rename($from,$to));return[$main,$apc,$service];}
    private function events(\PDO$apc):void{$apc->exec("INSERT INTO apc_eventos(id,ano_letivo,data,titulo,tipo,origem,descricao,status,criado_por)VALUES(1,2026,'2026-10-12','Segunda','OUTRO','ESCOLA','','ATIVO',1),(2,2026,'2026-10-15','Quinta','OUTRO','ESCOLA','','ATIVO',1)");}
    private function import(\PDO$apc,array$rows,int$year=2026,string$shift='MATUTINO',string$from='2026-01-01',?string$to='2026-12-31'):int{return(new ScheduleRepository($apc))->createImport(['ano_letivo'=>$year,'turno'=>$shift,'vigente_de'=>$from,'vigente_ate'=>$to,'nome_arquivo'=>'teste.xlsx','sha256'=>str_repeat('a',64),'usuario_id'=>1],$rows);}
    private function row(int$classId,string$className,int$day,int$lesson,int$teacherId,string$teacherName):array{return['turma_id_externo'=>$classId,'turma_nome_snapshot'=>$className,'dia_semana'=>$day,'numero_aula'=>$lesson,'disciplina'=>'Componente','professor_usuario_id'=>$teacherId,'professor_nome_snapshot'=>$teacherName,'professor_nome_importado'=>$teacherName];}
    private function submissionService(\PDO$main,\PDO$apc,ScheduleService$schedules):SubmissionService{return new SubmissionService(new SubmissionRepository($apc),new EventRepository($apc),new TermRepository($apc),new AccessRepository($main),new AuditRepository($apc),$this->directory,1048576,static fn(string$path):bool=>is_file($path),static fn(string$from,string$to):bool=>rename($from,$to),'2026-10-15',null,null,$schedules);}
    private function submission(\PDO$db,int$event,int$user,int$class,int$id=1):void{$db->exec("INSERT INTO apc_envios(id,evento_id,bimestre_id,professor_usuario_id,professor_nome_snapshot,etapa,ano_serie,turma_id_externo,nome_original,nome_armazenado,mime_type,tamanho_bytes,sha256,caminho_relativo,atrasado,dias_atraso,enviado_em)VALUES($id,$event,4,$user,'Professor','EF_AF','EF7',$class,'a.pdf','".str_pad((string)$id,32,'a').".pdf','application/pdf',1,'".str_repeat((string)$id,64)."','envios/$id.pdf',0,0,'2026-10-15 10:00:00');INSERT INTO apc_envio_turmas(envio_id,turma_id_externo,turma_nome_snapshot)VALUES($id,$class,'Turma')");}
    private function upload(string$name):array{$path=$this->directory.DIRECTORY_SEPARATOR.bin2hex(random_bytes(5)).'.png';file_put_contents($path,(string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));return['name'=>$name,'tmp_name'=>$path,'error'=>UPLOAD_ERR_OK,'size'=>filesize($path)];}
    private function xlsx(array$rows):string{$zip=$this->directory.DIRECTORY_SEPARATOR.bin2hex(random_bytes(5)).'.zip';$archive=new \PharData($zip,0,null,\Phar::ZIP);$archive['[Content_Types].xml']='<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>';$archive['_rels/.rels']='<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';$archive['xl/workbook.xml']='<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Grade" sheetId="1" r:id="rId1"/></sheets></workbook>';$archive['xl/_rels/workbook.xml.rels']='<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';$xml='<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';foreach($rows as$r=>$cells){$xml.='<row r="'.($r+1).'">';foreach($cells as$c=>$value){$column='';$number=$c+1;while($number>0){$number--;$column=chr(65+$number%26).$column;$number=intdiv($number,26);}$xml.='<c r="'.$column.($r+1).'" t="inlineStr"><is><t>'.htmlspecialchars((string)$value,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>';}$xml.='</row>';}$xml.='</sheetData></worksheet>';$archive['xl/worksheets/sheet1.xml']=$xml;unset($archive);$xlsx=substr($zip,0,-4).'.xlsx';rename($zip,$xlsx);return$xlsx;}
    private function remove(string$path):void{if(!is_dir($path))return;foreach(scandir($path)?:[]as$item){if($item==='.'||$item==='..')continue;$target=$path.DIRECTORY_SEPARATOR.$item;if(is_dir($target))$this->remove($target);else unlink($target);}rmdir($path);}
}

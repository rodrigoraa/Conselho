<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,SubmissionRepository,TermRepository};
use Apc\Services\SubmissionService;
use Shared\Exceptions\HttpException;

final class ApcSubmissionServiceTest extends ApcTestCase
{
    private string$directory;

    protected function setUp():void{$this->directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'apc-envios-'.bin2hex(random_bytes(6));mkdir($this->directory,0770,true);}
    protected function tearDown():void{$this->remove($this->directory);}

    public function testProfessorUploadsUsingCouncilBindingAndCannotSendTheSameApcAgain():void
    {
        [$db,$repository,$service]=$this->service('2026-08-11');$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$id=$service->submit($this->input(),$this->file('modelo.png'),$teacher,'127.0.0.1','phpunit');$first=$repository->find($id);self::assertSame(0,(int)$first['atrasado']);self::assertSame('7º A',$first['turmas']);self::assertFileExists($firstPath=$service->file($id,$teacher)['caminho_absoluto']);$lateService=$this->serviceFor($db,$repository,'2026-08-20');try{$lateService->submit($this->input(),$this->file('modelo-corrigido.png'),$teacher,'127.0.0.1','phpunit');self::fail('O mesmo evento e turma não deveriam aceitar reenvio.');}catch(HttpException$exception){self::assertSame(409,$exception->status);self::assertSame('APC_SUBMISSION_ALREADY_EXISTS',$exception->errorCode);}self::assertSame('modelo.png',$repository->find($id)['nome_original']);self::assertFileExists($firstPath);self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM apc_envios')->fetchColumn());self::assertSame(['ANEXAR_ARQUIVO_APC'],$db->query("SELECT acao FROM apc_auditoria WHERE entidade='apc_envios' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testActiveApcFromAnotherSemesterIsOpenWithoutManualRelease():void
    {
        [$db,$repository]=$this->service('2026-08-12');$db->exec("INSERT INTO apc_eventos(id,ano_letivo,data,titulo,tipo,origem,descricao,status,criado_por)VALUES(2,2026,'2026-03-15','APC do primeiro semestre','OUTRO','ESCOLA','','ATIVO',1)");$service=$this->serviceFor($db,$repository,'2026-08-12');$id=$service->submit($this->input(eventId:2),$this->file('primeiro-semestre.png'),['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');self::assertSame(150,(int)$repository->find($id)['dias_atraso']);self::assertSame(2,(int)$repository->find($id)['evento_id']);
    }

    public function testCoordinationAndAdminCanDeleteSubmissionButTeacherCannot():void
    {
        [$db,$repository,$service]=$this->service('2026-08-12');$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$id=$service->submit($this->input(),$this->file('apagar.png'),$teacher,'127.0.0.1','phpunit');$path=$service->file($id,$teacher)['caminho_absoluto'];try{$service->delete($id,$teacher,'127.0.0.1','phpunit');self::fail('Professor não deveria excluir o próprio envio.');}catch(HttpException$exception){self::assertSame(403,$exception->status);}self::assertFileExists($path);$service->delete($id,['id'=>2,'nome'=>'Coordenação','perfil'=>'COORDENADOR'],'127.0.0.1','phpunit');self::assertNull($repository->find($id));self::assertFileDoesNotExist($path);$adminId=$service->submit($this->input(),$this->file('apagar-admin.png'),$teacher,'127.0.0.1','phpunit');$service->delete($adminId,['id'=>1,'nome'=>'Admin','perfil'=>'ADMIN'],'127.0.0.1','phpunit');self::assertNull($repository->find($adminId));self::assertSame(0,(int)$db->query('SELECT COUNT(*) FROM apc_envio_turmas')->fetchColumn());self::assertSame(['ANEXAR_ARQUIVO_APC','EXCLUIR','ANEXAR_ARQUIVO_APC','EXCLUIR'],$db->query("SELECT acao FROM apc_auditoria WHERE entidade='apc_envios' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testSeriesAndDownloadsFollowCouncilAuthorization():void
    {
        [$db,$repository,$service]=$this->service('2026-08-11');try{$service->submit($this->input('EF_AF','EF8'),$this->file('sem-vinculo.png'),['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');self::fail('Turma fora da série vinculada deveria ser recusada.');}catch(HttpException$exception){self::assertSame('APC_CLASS_FORBIDDEN',$exception->errorCode);}$id=$service->submit($this->input(),$this->file('permitido.png'),['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');try{$service->file($id,['id'=>4,'perfil'=>'PROFESSOR']);self::fail('Outro professor não deveria baixar o arquivo.');}catch(HttpException$exception){self::assertSame(403,$exception->status);}self::assertSame($id,(int)$service->file($id,['id'=>2,'perfil'=>'COORDENADOR'])['id']);
    }

    public function testCoordinatorWithActiveTeachingBindingCanSubmitOwnApc():void
    {
        [$db,$repository,$service,$main]=$this->service('2026-08-11');$coordinator=['id'=>2,'nome'=>'Coordenação','perfil'=>'COORDENADOR'];
        try{$service->submit($this->input(),$this->file('sem-cadastro-docente.png'),$coordinator,'127.0.0.1','phpunit');self::fail('Coordenação sem cadastro docente não deveria enviar APC.');}catch(HttpException$exception){self::assertSame(403,$exception->status);self::assertSame('APC_FORBIDDEN',$exception->errorCode);}
        $main->exec("INSERT INTO professores(id,usuario_id)VALUES(3,2);INSERT INTO vinculos_professor_turma(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno)VALUES(3,3,30,'9º A',2026,'MATUTINO')");
        $id=$service->submit($this->input('EF_AF','EF9',30),$this->file('coordenacao-professora.png'),$coordinator,'127.0.0.1','phpunit');$submission=$repository->find($id);self::assertSame(2,(int)$submission['professor_usuario_id']);self::assertSame('9º A',$submission['turmas']);
        try{$service->submit($this->input(),$this->file('turma-de-outro-professor.png'),$coordinator,'127.0.0.1','phpunit');self::fail('O vínculo docente da coordenação não deve liberar turmas de outros professores.');}catch(HttpException$exception){self::assertSame(403,$exception->status);self::assertSame('APC_CLASS_FORBIDDEN',$exception->errorCode);}
    }

    private function service(string$today):array
    {
        $main=$this->mainDatabase();$this->seedMain($main);$db=$this->apcDatabase();$this->seedEvent($db);$repository=new SubmissionRepository($db);return[$db,$repository,new SubmissionService($repository,new EventRepository($db),new TermRepository($db),new AccessRepository($main),new AuditRepository($db),$this->directory,1048576,static fn(string$path):bool=>is_file($path),static fn(string$from,string$to):bool=>rename($from,$to),$today),$main];
    }
    private function serviceFor(\PDO$db,SubmissionRepository$repository,string$today):SubmissionService
    {
        $main=$this->mainDatabase();$this->seedMain($main);return new SubmissionService($repository,new EventRepository($db),new TermRepository($db),new AccessRepository($main),new AuditRepository($db),$this->directory,1048576,static fn(string$path):bool=>is_file($path),static fn(string$from,string$to):bool=>rename($from,$to),$today);
    }
    private function input(string$stage='EF_AF',string$year='EF7',int$classId=10,int$eventId=1):array{return['evento_id'=>$eventId,'etapa'=>$stage,'ano_serie'=>$year,'turma_id'=>$classId];}
    private function file(string$name):array{$path=$this->directory.DIRECTORY_SEPARATOR.bin2hex(random_bytes(5)).'.png';file_put_contents($path,(string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));return['name'=>$name,'tmp_name'=>$path,'error'=>UPLOAD_ERR_OK,'size'=>filesize($path)];}
    private function remove(string$path):void{if(!is_dir($path))return;foreach(scandir($path)?:[]as$item){if($item==='.'||$item==='..')continue;$target=$path.DIRECTORY_SEPARATOR.$item;if(is_dir($target))$this->remove($target);else unlink($target);}rmdir($path);}
}

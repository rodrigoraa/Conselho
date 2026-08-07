<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use PreConselho\Controllers\CollaborationController;
use PreConselho\Repositories\AppRepository;
use PreConselho\Services\CouncilDocumentService;
use PreConselho\Support\CollaborationToken;
use Shared\Exceptions\HttpException;
use Shared\Http\Request;

final class CollaborationControllerTest extends TestCase
{
    private const SECRET='0123456789abcdef0123456789abcdef0123456789abcdef';
    private PDO $db;
    private CollaborationController $controller;
    private int $classId;

    protected function setUp(): void
    {
        putenv('COLLABORATION_SECRET='.self::SECRET);
        $this->db=new PDO('sqlite::memory:');$this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $migrations=glob(dirname(__DIR__).'/apps/preconselho-web/database/migrations/*.sql')?:[];sort($migrations);foreach($migrations as$migration)$this->db->exec((string)file_get_contents($migration));
        $hash=password_hash('interno',PASSWORD_DEFAULT);
        $this->db->exec("INSERT INTO usuarios(id,nome,email,senha_hash,perfil)VALUES(1,'Professor Um','p@test','$hash','PROFESSOR');INSERT INTO professores(id,usuario_id)VALUES(1,1);INSERT INTO vinculos_professor_turma(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno)VALUES(1,1,10,'1º ano',2026,'MATUTINO');INSERT INTO periodos_pre_conselho(id,nome,ano_letivo,etapa,data_inicio,data_fim,status,criado_por,turno)VALUES(1,'3º Bimestre',2026,'3º','2020-01-01','2099-12-31','ABERTO',1,'MATUTINO')");
        $repository=new AppRepository($this->db);(new CouncilDocumentService($repository))->synchronizePeriod(1);
        $this->classId=(int)$this->db->query('SELECT id FROM documento_turmas')->fetchColumn();$this->controller=new CollaborationController($repository);
    }

    protected function tearDown(): void
    {
        putenv('COLLABORATION_SECRET');
    }

    public function testApiInternaCarregaESalvaAlteracaoValidada(): void
    {
        $token=CollaborationToken::issue(['sub'=>1,'name'=>'Professor Um','role'=>'PROFESSOR','period'=>1,'class'=>$this->classId],self::SECRET,60);
        $snapshot=$this->controller->snapshot($this->request(['token'=>$token,'document'=>'council:1:'.$this->classId]));
        $loaded=json_decode($snapshot->body,true,512,JSON_THROW_ON_ERROR);
        self::assertSame(1,$loaded['version']);self::assertSame('',$loaded['content']);self::assertSame('Professor Um',$loaded['user']['name']);
        $saved=$this->controller->save($this->request(['token'=>$token,'document'=>'council:1:'.$this->classId,'content'=>'Texto ao vivo.','version'=>1,'operations'=>[['start'=>0,'delete'=>0,'insert'=>'Texto ao vivo.']]]));
        $result=json_decode($saved->body,true,512,JSON_THROW_ON_ERROR);
        self::assertTrue($result['success']);self::assertSame(2,$result['version']);
        self::assertSame('Texto ao vivo.',(string)$this->db->query('SELECT conteudo FROM documento_turmas')->fetchColumn());
    }

    public function testApiInternaRecusaSegredoOuDocumentoDiferente(): void
    {
        $token=CollaborationToken::issue(['sub'=>1,'name'=>'Professor Um','role'=>'PROFESSOR','period'=>1,'class'=>$this->classId],self::SECRET,60);
        try{$this->controller->snapshot($this->request(['token'=>$token,'document'=>'council:1:999'],'incorreto'));self::fail('Segredo incorreto deveria ser recusado.');}
        catch(HttpException$exception){self::assertSame(403,$exception->status);}
        try{$this->controller->snapshot($this->request(['token'=>$token,'document'=>'council:1:999']));self::fail('Documento diferente deveria ser recusado.');}
        catch(HttpException$exception){self::assertSame('COLLABORATION_DOCUMENT_MISMATCH',$exception->errorCode);}
    }

    private function request(array $body,string $secret=self::SECRET): Request
    {
        return new Request('POST','/internal/collaboration/test',[],$body,['HTTP_X_COLLABORATION_SECRET'=>$secret,'REMOTE_ADDR'=>'127.0.0.1']);
    }
}

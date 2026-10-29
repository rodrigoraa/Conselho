<?php declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use PreConselho\Support\FormRecovery;
use Shared\Exceptions\HttpException;
use Shared\Http\Request;

final class FormRecoveryTest extends TestCase
{
    protected function setUp():void { $_SESSION=['user'=>['id'=>1,'perfil'=>'ADMIN']]; }
    protected function tearDown():void { unset($_SESSION['_form_recovery'],$_SESSION['user']); }

    private function request(string $path,array $body=[],array $server=[]):Request
    { return new Request('POST',$path,[],$body,$server); }

    public function testDuplicateCpfReturnsToItsFormAndConsumesValuesOnlyOnce():void
    {
        $response=FormRecovery::recover($this->request('/admin/usuarios',['nome'=>'Nome mantido','cpf'=>'52998224725','perfil'=>'PROFESSOR','_csrf'=>'old']),new HttpException(422,'CPF_IN_USE','Este CPF já está sendo utilizado.'),1000);
        self::assertSame(303,$response?->status);self::assertSame('/admin#usuarios',$response?->headers['Location']);
        self::assertNull(FormRecovery::consume('/apc',1001));
        $recovery=FormRecovery::consume('/admin',1001);
        self::assertSame('/admin/usuarios',$recovery['action']);self::assertSame('Nome mantido',$recovery['values']['nome']);self::assertSame('cpf',$recovery['field']);self::assertArrayNotHasKey('_csrf',$recovery['values']);
        self::assertNull(FormRecovery::consume('/admin',1002));
    }

    public function testReviewKeepsAssociationsAndRequiresTheSameValidatedToken():void
    {
        $token=str_repeat('a',36);$values=['token'=>$token,'turma_map'=>['7'=>'10'],'professor_map'=>['nome'=>'3']];
        $response=FormRecovery::recover($this->request('/apc/admin/horarios/confirmar',$values),new HttpException(422,'MAP_INVALID','Confira as associações.'),1000);
        self::assertSame('/apc/admin/horarios/revisar?token='.$token,$response?->headers['Location']);
        self::assertNull(FormRecovery::consume('/apc/admin/horarios/revisar?token='.str_repeat('b',36),1001));
        $recovery=FormRecovery::consume('/apc/admin/horarios/revisar?token='.$token,1001);
        self::assertSame(['7'=>'10'],$recovery['values']['turma_map']);self::assertArrayNotHasKey('token',$recovery['values']);
        self::assertNull(FormRecovery::recover($this->request('/apc/admin/horarios/confirmar',['token'=>'https://outside.invalid']),new HttpException(422,'INVALID','Erro'),1000));
    }

    public function testAuthorizationAutosaveUnknownActionsAndAjaxAreNotRedirected():void
    {
        foreach([403,404,500]as$status)self::assertNull(FormRecovery::recover($this->request('/admin/usuarios'),new HttpException($status,'ERROR','Erro'),1000));
        foreach(['/documentos/1/titulo/autosave','/apc/planos/1/reabrir','/logout','https://outside.invalid/','//outside.invalid/']as$path)self::assertNull(FormRecovery::recover($this->request($path),new HttpException(422,'ERROR','Erro'),1000));
        self::assertNull(FormRecovery::recover($this->request('/admin/usuarios',[],['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']),new HttpException(422,'ERROR','Erro'),1000));
        unset($_SESSION['user']);self::assertNull(FormRecovery::recover($this->request('/admin/usuarios'),new HttpException(422,'ERROR','Erro'),1000));
    }

    public function testValuesExpireAndNeverCrossUsers():void
    {
        $request=$this->request('/admin/usuarios',['nome'=>'Privado']);$error=new HttpException(422,'INVALID','Erro');
        FormRecovery::recover($request,$error,1000);self::assertNull(FormRecovery::consume('/admin',1601));
        FormRecovery::recover($request,$error,2000);$_SESSION['user']['id']=2;self::assertNull(FormRecovery::consume('/admin',2001));$_SESSION['user']['id']=1;self::assertNull(FormRecovery::consume('/admin',2002));
    }

    public function testSecretsAreRemovedAndFileNeedsSelectionAgain():void
    {
        $request=$this->request('/apc/envios',['evento_id'=>'1','turma_id'=>'10','arquivo'=>'not a file','senha'=>'secret','password'=>'secret','nested'=>['csrf'=>'old','safe'=>'value']],['CONTENT_TYPE'=>'multipart/form-data; boundary=example']);
        FormRecovery::recover($request,new HttpException(422,'APC_UPLOAD_INVALID','Arquivo inválido.'),1000);$recovery=FormRecovery::consume('/apc',1001);
        self::assertTrue($recovery['reselectFile']);self::assertSame('10',$recovery['values']['turma_id']);self::assertArrayNotHasKey('arquivo',$recovery['values']);self::assertArrayNotHasKey('senha',$recovery['values']);self::assertArrayNotHasKey('password',$recovery['values']);self::assertSame(['safe'=>'value'],$recovery['values']['nested']);
    }

    public function testOversizedPayloadDoesNotEnterTheSession():void
    {
        self::assertNull(FormRecovery::recover($this->request('/admin/usuarios',['nome'=>str_repeat('x',300000)]),new HttpException(422,'INVALID','Erro'),1000));self::assertArrayNotHasKey('_form_recovery',$_SESSION);
    }

    public function testCurriculumErrorKeepsOnlyBoundedInternalFilterContextAndEditingId():void
    {
        $context=['etapa'=>'EF_AF','q'=>'50% _ texto','pagina'=>'3','origem'=>['invalid'],'return'=>'https://outside.invalid','ativo'=>'1'];
        $response=FormRecovery::recover($this->request('/apc/admin/curriculo/habilidades/7',['descricao'=>'Edição mantida','contexto'=>$context]),new HttpException(422,'INVALID','Confira a habilidade.'),1000);
        $url=$response?->headers['Location']??'';
        self::assertSame('/apc/admin/curriculo',parse_url($url,PHP_URL_PATH));self::assertSame('habilidades',parse_url($url,PHP_URL_FRAGMENT));
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        self::assertSame(['etapa'=>'EF_AF','ativo'=>'1','q'=>'50% _ texto','pagina'=>'3','editar_habilidade'=>'7'],$query);
        self::assertNull(FormRecovery::consume('/apc/admin/curriculo?editar_habilidade=8',1001));
        self::assertSame('Edição mantida',FormRecovery::consume($url,1001)['values']['descricao']);
    }

    public function testNewPlanErrorRetainsTheSelectedEventForTheTwoStepForm():void
    {
        $request=$this->request('/apc/planos',['evento_id'=>'12','turma_id'=>'10','conteudos'=>'Texto mantido']);
        $response=FormRecovery::recover($request,new HttpException(422,'INVALID','Confira o plano.'),1000);
        self::assertSame('/apc/planos/novo?evento=12',$response?->headers['Location']);
        self::assertNull(FormRecovery::consume('/apc/planos/novo?evento=13',1001));
        self::assertSame('Texto mantido',FormRecovery::consume('/apc/planos/novo?evento=12',1001)['values']['conteudos']);
        $invalid=FormRecovery::recover($this->request('/apc/planos',['evento_id'=>'https://outside.invalid']),new HttpException(422,'INVALID','Erro'),1000);
        self::assertSame('/apc/planos/novo',$invalid?->headers['Location']);
    }
}

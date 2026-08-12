<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Services\AttachmentService;
use PreConselho\Support\Csrf;
use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};

final class AttachmentController
{
    public function __construct(private readonly AttachmentService $service) {}

    public function upload(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$files=$this->files($_FILES['arquivos']??null);$this->service->storeMany((int)$params['id'],$files,$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']=count($files).' anexo(s) armazenado(s) com segurança.';
        $delivery=$this->service->fileForDeliveryRedirect((int)$params['id']);return Response::redirect('/apc/planos/'.$delivery['plano_id'].'/entregas#aluno-'.$delivery['aluno_id_externo']);
    }

    public function download(Request $request,array $params): Response
    {
        $stored=$this->service->contents((int)$params['id'],$_SESSION['user']);$file=$stored['file'];$contents=$stored['contents'];
        $fallback='anexo.'.pathinfo((string)$file['nome_armazenado'],PATHINFO_EXTENSION);$disposition='attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode((string)$file['nome_original']);
        return new Response($contents,200,['Content-Type'=>(string)$file['mime_type'],'Content-Length'=>(string)strlen($contents),'Content-Disposition'=>$disposition,'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }

    public function delete(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$planId=$this->service->delete((int)$params['id'],$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Anexo removido e ação registrada na auditoria.';return Response::redirect('/apc/planos/'.$planId.'/entregas');
    }

    private function files(mixed $input): array
    {
        if(!is_array($input)||!isset($input['name']))return[];
        if(!is_array($input['name']))return[$input];$files=[];
        foreach($input['name']as$index=>$name)$files[]=['name'=>$name,'type'=>$input['type'][$index]??'','tmp_name'=>$input['tmp_name'][$index]??'','error'=>$input['error'][$index]??UPLOAD_ERR_NO_FILE,'size'=>$input['size'][$index]??0];
        return$files;
    }
}

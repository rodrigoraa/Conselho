<?php declare(strict_types=1);

namespace PreConselho\Controllers;

use PreConselho\Repositories\AppRepository;
use PreConselho\Services\CouncilDocumentService;
use PreConselho\Support\CollaborationToken;
use Shared\Env;
use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};

final class CollaborationController
{
    public function __construct(private readonly AppRepository $repository) {}

    public function snapshot(Request $request): Response
    {
        $claims=$this->authorize($request);
        $state=(new CouncilDocumentService($this->repository))->collaborationState((int)$claims['period'],(int)$claims['class'],(int)$claims['sub'],(string)$claims['role']);
        return Response::json(['success'=>true,'user'=>['id'=>(int)$claims['sub'],'name'=>(string)$claims['name'],'role'=>(string)$claims['role']]]+$state);
    }

    public function save(Request $request): Response
    {
        $claims=$this->authorize($request);
        $operations=$request->body['operations']??[];
        if(!is_array($operations))throw new HttpException(422,'INVALID_EDIT','A lista de alterações colaborativas é inválida.');
        $saved=(new CouncilDocumentService($this->repository))->saveClass(
            (int)$claims['period'],
            (int)$claims['class'],
            (string)($request->body['content']??''),
            (int)($request->body['version']??0),
            (int)$claims['sub'],
            (string)$claims['role'],
            mb_substr((string)($request->body['ip']??'collaboration-service'),0,64),
            mb_substr((string)($request->body['user_agent']??'collaboration-service'),0,255),
            $operations
        );
        return Response::json(['success'=>true]+$saved);
    }

    private function authorize(Request $request): array
    {
        $secret=Env::get('COLLABORATION_SECRET','')??'';
        $provided=$request->header('X-Collaboration-Secret')??'';
        if(strlen($secret)<32||!hash_equals($secret,$provided))throw new HttpException(403,'COLLABORATION_SERVICE_FORBIDDEN','Serviço de colaboração não autorizado.');
        $claims=CollaborationToken::verify((string)($request->body['token']??''),$secret);
        $document=(string)($request->body['document']??'');
        $expected='council:'.(int)$claims['period'].':'.(int)$claims['class'];
        if($document!==$expected)throw new HttpException(403,'COLLABORATION_DOCUMENT_MISMATCH','A credencial não pertence a este documento.');
        return$claims;
    }
}

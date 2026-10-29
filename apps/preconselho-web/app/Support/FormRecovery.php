<?php declare(strict_types=1);

namespace PreConselho\Support;

use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};

/** One-use values for a failed form, kept only in the submitting user's session. */
final class FormRecovery
{
    private const KEY='_form_recovery';
    private const LIFETIME=600;
    private const MAX_BYTES=262144;

    public static function isAjax(Request $request):bool
    {
        return strcasecmp($request->header('X-Requested-With')??'','XMLHttpRequest')===0
            ||str_contains(strtolower($request->header('Accept')??''),'application/json');
    }

    public static function recover(Request $request,HttpException $error,?int $now=null):?Response
    {
        $userId=(int)($_SESSION['user']['id']??0);
        if($request->method!=='POST'||$userId<1||self::isAjax($request)||!in_array($error->status,[409,419,422],true))return null;
        $destination=self::destination($request);
        if($destination===null)return null;
        $values=self::values($request->body);
        $encoded=json_encode($values);
        if($encoded===false||strlen($encoded)>self::MAX_BYTES)return null;
        $_SESSION[self::KEY]=[
            'userId'=>$userId,'expiresAt'=>($now??time())+self::LIFETIME,
            'destination'=>$destination,'action'=>$request->path,'values'=>$values,
            'message'=>$error->getMessage(),'field'=>self::field($error->errorCode),
            'reselectFile'=>str_starts_with(strtolower((string)($request->server['CONTENT_TYPE']??'')),'multipart/form-data'),
        ];
        return new Response('',303,['Location'=>$destination,'Cache-Control'=>'no-store']);
    }

    public static function consume(string $uri,?int $now=null):?array
    {
        $recovery=$_SESSION[self::KEY]??null;
        if(!is_array($recovery))return null;
        if((int)($recovery['userId']??0)!==(int)($_SESSION['user']['id']??0)||(int)($recovery['expiresAt']??0)<($now??time())){
            unset($_SESSION[self::KEY]);return null;
        }
        $destination=(string)$recovery['destination'];
        if(parse_url($destination,PHP_URL_PATH)!==parse_url($uri,PHP_URL_PATH))return null;
        parse_str((string)parse_url($destination,PHP_URL_QUERY),$required);
        parse_str((string)parse_url($uri,PHP_URL_QUERY),$actual);
        foreach($required as$key=>$value)if(($actual[$key]??null)!==$value)return null;
        unset($_SESSION[self::KEY]);
        return array_intersect_key($recovery,array_flip(['action','values','message','field','reselectFile']));
    }

    private static function destination(Request $request):?string
    {
        $path=$request->path;
        if($path==='/apc/planos'){
            $event=filter_var($request->body['evento_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
            return'/apc/planos/novo'.($event!==false?'?evento='.$event:'');
        }
        if(preg_match('#^/apc/admin/curriculo/(componentes|habilidades)(?:/([1-9][0-9]*))?$#',$path,$match)){
            $context=is_array($request->body['contexto']??null)?$request->body['contexto']:[];
            $filters=[];
            foreach(['etapa','ano_serie','componente','origem','escopo','ativo','q']as$key){
                $value=$context[$key]??null;
                if(is_scalar($value)&&trim((string)$value)!=='')$filters[$key]=mb_substr(trim((string)$value),0,160);
            }
            if(isset($context['pagina'])&&is_scalar($context['pagina']))$filters['pagina']=max(1,min(2147483647,(int)$context['pagina']));
            if($match[1]==='habilidades'&&isset($match[2]))$filters['editar_habilidade']=$match[2];
            return'/apc/admin/curriculo'.($filters?'?'.http_build_query($filters):'').'#'.$match[1];
        }
        $routes=[
            '#^/admin/usuarios(?:/[1-9][0-9]*/editar)?$#'=>'/admin#usuarios',
            '#^/admin/vinculos(?:/[1-9][0-9]*/editar)?$#'=>'/admin#vinculos',
            '#^/admin/periodos$#'=>'/admin#periodos',
            '#^/apc/envios$#'=>'/apc#apc-upload',
            '#^/apc/admin/eventos(?:/[1-9][0-9]*)?$#'=>'/apc/admin#calendario',
            '#^/apc/admin/horarios/analisar$#'=>'/apc/admin/horarios',
            '#^/apc/admin/calendario/analisar$#'=>'/apc/admin#calendario',
        ];
        foreach($routes as$pattern=>$target)if(preg_match($pattern,$path))return$target;
        if(preg_match('#^/apc/planos/([1-9][0-9]*)(?:/finalizar)?$#',$path,$match))return'/apc/planos/'.$match[1];
        if(preg_match('#^/apc/planos/([1-9][0-9]*)/entregas/([1-9][0-9]*)$#',$path,$match))return'/apc/planos/'.$match[1].'/entregas#aluno-'.$match[2];
        if(in_array($path,['/apc/admin/horarios/confirmar','/apc/admin/horarios/aba','/apc/admin/calendario/confirmar'],true)){
            $token=$request->body['token']??null;
            if(!is_string($token)||!preg_match('/^[a-f0-9]{36}$/D',$token))return null;
            $base=str_contains($path,'/horarios/')?'/apc/admin/horarios/revisar':'/apc/admin/calendario/revisar';
            return$base.'?token='.$token;
        }
        return null;
    }

    private static function values(array $values,int $depth=0):array
    {
        if($depth>12)return[];
        $safe=[];
        foreach(array_slice($values,0,2000,true)as$key=>$value){
            if(preg_match('/password|senha|csrf|secret|credential|api_key|(?:^|_)token(?:$|_)|^arquivos?$|^_return/i',(string)$key))continue;
            if(is_array($value))$safe[$key]=self::values($value,$depth+1);
            elseif(is_scalar($value)||$value===null)$safe[$key]=$value;
        }
        return$safe;
    }

    private static function field(string $code):?string
    {
        if(str_contains($code,'CPF'))return'cpf';
        return match($code){
            'INVALID_SHIFT'=>'turno','APC_EVENT_NOT_FOUND'=>'evento_id',
            'APC_UPLOAD_INVALID','APC_FILE_TOO_LARGE','APC_FILE_TYPE_INVALID'=>'arquivo',
            default=>null,
        };
    }
}

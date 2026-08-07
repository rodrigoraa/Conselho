<?php declare(strict_types=1);

namespace PreConselho\Support;

use Shared\Exceptions\HttpException;

final class CollaborationToken
{
    public static function issue(array $claims,string $secret,int $ttl=900): string
    {
        if(strlen($secret)<32)throw new \RuntimeException('COLLABORATION_SECRET precisa ter ao menos 32 caracteres.');
        $payload=$claims+['iat'=>time(),'exp'=>time()+$ttl];
        $encoded=self::base64UrlEncode((string)json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return$encoded.'.'.self::base64UrlEncode(hash_hmac('sha256',$encoded,$secret,true));
    }

    public static function verify(string $token,string $secret): array
    {
        if(strlen($secret)<32||!str_contains($token,'.'))throw new HttpException(401,'INVALID_COLLABORATION_TOKEN','Credencial de colaboração inválida.');
        [$encoded,$signature]=explode('.',$token,2);
        $expected=self::base64UrlEncode(hash_hmac('sha256',$encoded,$secret,true));
        if(!hash_equals($expected,$signature))throw new HttpException(401,'INVALID_COLLABORATION_TOKEN','Credencial de colaboração inválida.');
        $json=self::base64UrlDecode($encoded);$claims=json_decode($json,true);
        if(!is_array($claims)||(int)($claims['exp']??0)<time())throw new HttpException(401,'EXPIRED_COLLABORATION_TOKEN','A credencial de colaboração expirou. Recarregue a página.');
        foreach(['sub','name','role','period','class']as$claim)if(!array_key_exists($claim,$claims))throw new HttpException(401,'INVALID_COLLABORATION_TOKEN','Credencial de colaboração incompleta.');
        if(!in_array($claims['role'],['PROFESSOR','COORDENADOR','ADMIN'],true))throw new HttpException(401,'INVALID_COLLABORATION_TOKEN','Perfil de colaboração inválido.');
        return$claims;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding=(4-strlen($value)%4)%4;$decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',$padding),true);
        if($decoded===false)throw new HttpException(401,'INVALID_COLLABORATION_TOKEN','Credencial de colaboração inválida.');
        return$decoded;
    }
}

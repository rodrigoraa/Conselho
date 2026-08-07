<?php declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use PreConselho\Support\CollaborationToken;
use Shared\Exceptions\HttpException;

final class CollaborationTokenTest extends TestCase
{
    private const SECRET='0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testTokenAssinadoPreservaEscopoDoUsuarioEDaTurma(): void
    {
        $token=CollaborationToken::issue(['sub'=>7,'name'=>'Professora Ana','role'=>'PROFESSOR','period'=>3,'class'=>12],self::SECRET,60);
        $claims=CollaborationToken::verify($token,self::SECRET);
        self::assertSame(7,$claims['sub']);
        self::assertSame('Professora Ana',$claims['name']);
        self::assertSame('PROFESSOR',$claims['role']);
        self::assertSame(3,$claims['period']);
        self::assertSame(12,$claims['class']);
        self::assertGreaterThan(time(),$claims['exp']);
    }

    public function testTokenAlteradoOuExpiradoERecusado(): void
    {
        $valid=CollaborationToken::issue(['sub'=>7,'name'=>'Professora Ana','role'=>'PROFESSOR','period'=>3,'class'=>12],self::SECRET,60);
        try{CollaborationToken::verify($valid.'x',self::SECRET);self::fail('Uma assinatura alterada deveria ser recusada.');}
        catch(HttpException$exception){self::assertSame(401,$exception->status);}
        $expired=CollaborationToken::issue(['sub'=>7,'name'=>'Professora Ana','role'=>'PROFESSOR','period'=>3,'class'=>12],self::SECRET,-1);
        try{CollaborationToken::verify($expired,self::SECRET);self::fail('Um token expirado deveria ser recusado.');}
        catch(HttpException$exception){self::assertSame('EXPIRED_COLLABORATION_TOKEN',$exception->errorCode);}
    }
}

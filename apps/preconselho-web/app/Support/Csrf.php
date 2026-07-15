<?php declare(strict_types=1);
namespace PreConselho\Support;use Shared\Exceptions\HttpException;
final class Csrf{public static function token():string{if(empty($_SESSION['_csrf']))$_SESSION['_csrf']=bin2hex(random_bytes(32));return$_SESSION['_csrf'];}public static function verify(mixed$t):void{if(!is_string($t)||!hash_equals($_SESSION['_csrf']??'',$t))throw new HttpException(419,'CSRF_INVALID','A sessão do formulário expirou. Tente novamente.');}}

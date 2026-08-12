<?php declare(strict_types=1);

namespace PreConselho\Services;

use Closure;
use PDO;

final class PersistentLoginService
{
    public const COOKIE_NAME='preconselho_professor_login';

    private readonly Closure $cookieWriter;
    private readonly Closure $clock;

    public function __construct(
        private readonly PDO $db,
        private readonly int $lifetimeDays=15,
        private readonly bool $secure=false,
        private readonly string $sameSite='Lax',
        ?Closure $cookieWriter=null,
        ?Closure $clock=null,
    ) {
        $this->cookieWriter=$cookieWriter??static fn(string$name,string$value,array$options):bool=>setcookie($name,$value,$options);
        $this->clock=$clock??static fn():int=>time();
    }

    public function remember(array $user): ?int
    {
        if((string)($user['perfil']??'')!=='PROFESSOR')return null;
        $now=$this->now();$expires=$now+max(1,$this->lifetimeDays)*86400;$selector=bin2hex(random_bytes(9));$validator=bin2hex(random_bytes(32));
        $this->deleteExpired($now);
        $statement=$this->db->prepare('INSERT INTO sessoes_persistentes_professor(usuario_id,seletor,token_hash,expira_em)VALUES(:usuario,:seletor,:hash,:expira)');
        $statement->execute([':usuario'=>(int)$user['id'],':seletor'=>$selector,':hash'=>hash('sha256',$validator),':expira'=>$expires]);
        $this->writeCookie($selector.'.'.$validator,$expires);
        return$expires;
    }

    public function restore(?string $cookie): ?array
    {
        $parts=$this->parts($cookie);if($parts===null)return null;[$selector,$validator]=$parts;$now=$this->now();$this->deleteExpired($now);
        $statement=$this->db->prepare("SELECT s.token_hash,s.expira_em,u.id,u.nome,u.perfil,u.alterar_senha,u.ativo,u.excluido_em FROM sessoes_persistentes_professor s JOIN usuarios u ON u.id=s.usuario_id WHERE s.seletor=:seletor LIMIT 1");$statement->execute([':seletor'=>$selector]);$row=$statement->fetch();
        if(!$row||(int)$row['expira_em']<=$now||(int)$row['ativo']!==1||$row['excluido_em']!==null||$row['perfil']!=='PROFESSOR'||!hash_equals((string)$row['token_hash'],hash('sha256',$validator))){$this->deleteSelector($selector);$this->clearCookie();return null;}
        $this->db->prepare('UPDATE sessoes_persistentes_professor SET ultimo_uso_em=CURRENT_TIMESTAMP WHERE seletor=:seletor')->execute([':seletor'=>$selector]);
        return['id'=>(int)$row['id'],'nome'=>(string)$row['nome'],'perfil'=>'PROFESSOR','alterar_senha'=>(bool)$row['alterar_senha'],'persistent_expires_at'=>(int)$row['expira_em']];
    }

    public function forget(?string $cookie): void
    {
        $parts=$this->parts($cookie);if($parts!==null)$this->deleteSelector($parts[0]);$this->clearCookie();
    }

    private function parts(?string $cookie): ?array
    {
        if(!is_string($cookie)||!preg_match('/^([a-f0-9]{18})\.([a-f0-9]{64})$/D',$cookie,$matches))return null;return[$matches[1],$matches[2]];
    }

    private function deleteExpired(int $now): void
    {
        $this->db->prepare('DELETE FROM sessoes_persistentes_professor WHERE expira_em<=:agora')->execute([':agora'=>$now]);
    }

    private function deleteSelector(string $selector): void
    {
        $this->db->prepare('DELETE FROM sessoes_persistentes_professor WHERE seletor=:seletor')->execute([':seletor'=>$selector]);
    }

    private function writeCookie(string $value,int $expires): void
    {
        ($this->cookieWriter)(self::COOKIE_NAME,$value,['expires'=>$expires,'path'=>'/','secure'=>$this->secure,'httponly'=>true,'samesite'=>$this->sameSite]);
    }

    private function clearCookie(): void
    {
        $this->writeCookie('',$this->now()-42000);
    }

    private function now(): int{return(int)($this->clock)();}
}

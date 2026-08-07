<?php declare(strict_types=1);
namespace PreConselho\Services;

use PreConselho\Repositories\AppRepository;
use PreConselho\Support\Cpf;
use Shared\Exceptions\HttpException;

final class AuthService
{
    public function __construct(private readonly AppRepository $repository) {}

    public function login(string $cpf, string $ip, string $userAgent): array
    {
        $cpf=Cpf::normalize($cpf);
        if(!Cpf::isValid($cpf))throw new HttpException(422,'CPF_INVALID','CPF inválido.');
        $user=$this->repository->userByCpf($cpf);
        if(!$user||!(bool)$user['ativo'])throw new HttpException(401,'LOGIN_INVALID','CPF não cadastrado ou acesso inativo.');
        $this->repository->db->prepare('UPDATE usuarios SET tentativas_login=0,bloqueado_ate=NULL,ultimo_login_em=CURRENT_TIMESTAMP WHERE id=:id')->execute([':id'=>$user['id']]);
        $this->repository->audit((int)$user['id'],'LOGIN','usuarios',(int)$user['id'],null,['sucesso'=>true],$ip,$userAgent);
        return $user;
    }
}

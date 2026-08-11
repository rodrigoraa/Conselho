<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\AccessRepository;
use Apc\Repositories\PlanRepository;
use Shared\Exceptions\HttpException;

final class AuthorizationService
{
    public function __construct(private readonly PlanRepository $plans,private readonly AccessRepository $access) {}

    public function plan(int $planId,int $userId,string $role): array
    {
        $plan=$this->plans->find($planId)??throw new HttpException(404,'APC_PLAN_NOT_FOUND','Plano APC não encontrado.');
        if($role==='PROFESSOR'){
            if((int)$plan['professor_usuario_id']!==$userId||!$this->access->classFor((int)$plan['turma_id_externo'],$userId,$role))throw new HttpException(403,'APC_FORBIDDEN','Você não possui acesso a esta APC.');
        }elseif(!in_array($role,['ADMIN','COORDENADOR'],true))throw new HttpException(403,'APC_FORBIDDEN','Você não possui acesso a esta APC.');
        return$plan;
    }

    public function editablePlan(int $planId,int $userId,string $role): array
    {
        $plan=$this->plan($planId,$userId,$role);
        if($plan['status']!=='RASCUNHO')throw new HttpException(422,'APC_PLAN_LOCKED','O plano está finalizado e precisa ser reaberto pela coordenação ou administração.');
        return$plan;
    }
}

<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,PlanRepository};
use Apc\Support\Input;
use PDOException;
use PreConselho\Integration\SecretariaApiClient;
use Shared\Exceptions\HttpException;

final class PlanService
{
    public function __construct(private readonly PlanRepository $plans,private readonly EventRepository $events,private readonly AccessRepository $access,private readonly AuditRepository $audit,private readonly AuthorizationService $authorization,private readonly ?SecretariaApiClient $api=null) {}

    public function create(array $input,array $user,string $ip,string $userAgent): int
    {
        $eventId=Input::positiveInt($input['evento_id']??null);$event=$this->events->find($eventId)??throw new HttpException(422,'APC_EVENT_NOT_FOUND','Evento APC não encontrado.');
        if($event['status']!=='ATIVO')throw new HttpException(422,'APC_EVENT_CANCELLED','Não é possível criar plano para evento cancelado.');
        $classId=Input::positiveInt($input['turma_id_externo']??null);$class=$this->access->classFor($classId,(int)$user['id'],(string)$user['perfil']);
        if(!$class)throw new HttpException(403,'APC_CLASS_FORBIDDEN','Você não está vinculado a esta turma.');
        $totalStudents=null;if($this->api)try{$totalStudents=count($this->api->alunosDaTurma($classId));}catch(\Throwable){}
        $data=$this->fields($input)+['evento_id'=>$eventId,'professor_usuario_id'=>(int)$user['id'],'professor_nome_snapshot'=>(string)$user['nome'],'turma_id_externo'=>$classId,'turma_nome_snapshot'=>(string)$class['nome'],'total_alunos_snapshot'=>$totalStudents];
        $this->plans->db->beginTransaction();
        try{$id=$this->plans->insert($data);$this->audit->record((int)$user['id'],'CRIAR','apc_planos',$id,null,$data,$ip,$userAgent);$this->plans->db->commit();return$id;}
        catch(PDOException $exception){if($this->plans->db->inTransaction())$this->plans->db->rollBack();if($exception->getCode()==='23000')throw new HttpException(422,'APC_PLAN_DUPLICATE','Já existe um plano deste componente para o professor, turma e evento.');throw$exception;}
        catch(\Throwable $exception){if($this->plans->db->inTransaction())$this->plans->db->rollBack();throw$exception;}
    }

    public function update(int $id,array $input,array $user,string $ip,string $userAgent): void
    {
        $before=$this->authorization->editablePlan($id,(int)$user['id'],(string)$user['perfil']);$data=$this->fields($input);
        $this->plans->db->beginTransaction();
        try{$this->plans->update($id,$data);$this->audit->record((int)$user['id'],'ALTERAR','apc_planos',$id,$this->auditFields($before),$data,$ip,$userAgent);$this->plans->db->commit();}
        catch(\Throwable $exception){if($this->plans->db->inTransaction())$this->plans->db->rollBack();throw$exception;}
    }

    public function finalize(int $id,array $user,string $ip,string $userAgent): void
    {
        $plan=$this->authorization->editablePlan($id,(int)$user['id'],(string)$user['perfil']);
        foreach(['competencias_habilidades','conteudos','descricao_atividade','estrategia_devolucao']as$field)if(trim((string)$plan[$field])==='')throw new HttpException(422,'APC_PLAN_INCOMPLETE','Preencha todos os campos obrigatórios antes de finalizar.');
        $this->plans->db->beginTransaction();
        try{$this->plans->finalize($id);$this->audit->record((int)$user['id'],'FINALIZAR','apc_planos',$id,['status'=>'RASCUNHO'],['status'=>'FINALIZADO'],$ip,$userAgent);$this->plans->db->commit();}
        catch(\Throwable $exception){if($this->plans->db->inTransaction())$this->plans->db->rollBack();throw$exception;}
    }

    public function reopen(int $id,string $reason,array $user,string $ip,string $userAgent): void
    {
        if(!in_array($user['perfil'],['ADMIN','COORDENADOR'],true))throw new HttpException(403,'APC_FORBIDDEN','Apenas coordenação ou administração pode reabrir o plano.');
        $plan=$this->authorization->plan($id,(int)$user['id'],(string)$user['perfil']);$reason=Input::text($reason,'Motivo da reabertura',1000);
        if($plan['status']!=='FINALIZADO')throw new HttpException(422,'APC_INVALID_STATUS','Somente planos finalizados podem ser reabertos.');
        $this->plans->db->beginTransaction();
        try{$this->plans->reopen($id,(int)$user['id'],$reason);$this->audit->record((int)$user['id'],'REABRIR','apc_planos',$id,['status'=>'FINALIZADO'],['status'=>'RASCUNHO','motivo'=>$reason],$ip,$userAgent);$this->plans->db->commit();}
        catch(\Throwable $exception){if($this->plans->db->inTransaction())$this->plans->db->rollBack();throw$exception;}
    }

    private function fields(array $input): array
    {
        return[
            'componente_curricular'=>Input::text($input['componente_curricular']??null,'Componente curricular',160),
            'competencias_habilidades'=>Input::text($input['competencias_habilidades']??'','Competências e habilidades',12000,false),
            'conteudos'=>Input::text($input['conteudos']??'','Conteúdos',12000,false),
            'descricao_atividade'=>Input::text($input['descricao_atividade']??'','Descrição da atividade',20000,false),
            'estrategia_devolucao'=>Input::text($input['estrategia_devolucao']??'','Estratégia de devolução',12000,false),
        ];
    }

    private function auditFields(array $plan): array
    {
        return array_intersect_key($plan,array_flip(['componente_curricular','competencias_habilidades','conteudos','descricao_atividade','estrategia_devolucao','status']));
    }
}

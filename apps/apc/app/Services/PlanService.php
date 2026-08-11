<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\{AccessRepository,AuditRepository,CurriculumRepository,EventRepository,PlanRepository};
use Apc\Support\Input;
use PDOException;
use PreConselho\Integration\SecretariaApiClient;
use Shared\Exceptions\HttpException;

final class PlanService
{
    private const STAGES=['EF_AI','EF_AF','EM'];
    private const YEARS=['EF1','EF2','EF3','EF4','EF5','EF6','EF7','EF8','EF9','EM1','EM2','EM3'];
    public function __construct(private readonly PlanRepository $plans,private readonly EventRepository $events,private readonly AccessRepository $access,private readonly AuditRepository $audit,private readonly AuthorizationService $authorization,private readonly ?SecretariaApiClient $api=null,private readonly ?CurriculumRepository $curriculum=null,private readonly ?EventWindow $eventWindow=null) {}

    public function create(array $input,array $user,string $ip,string $userAgent): int
    {
        $eventId=Input::positiveInt($input['evento_id']??null);$event=$this->events->find($eventId)??throw new HttpException(422,'APC_EVENT_NOT_FOUND','Evento APC não encontrado.');
        $this->window()->assertOpen($event);
        $classId=Input::positiveInt($input['turma_id_externo']??null);$class=$this->access->classFor($classId,(int)$user['id'],(string)$user['perfil']);
        if(!$class)throw new HttpException(403,'APC_CLASS_FORBIDDEN','Você não está vinculado a esta turma.');
        $totalStudents=null;if($this->api)try{$totalStudents=count($this->api->alunosDaTurma($classId));}catch(\Throwable){}
        $structured=$this->curriculumFields($input);$data=$this->fields($input,$structured)+['evento_id'=>$eventId,'professor_usuario_id'=>(int)$user['id'],'professor_nome_snapshot'=>(string)$user['nome'],'turma_id_externo'=>$classId,'turma_nome_snapshot'=>(string)$class['nome'],'total_alunos_snapshot'=>$totalStudents];
        $this->plans->db->beginTransaction();
        try{$id=$this->plans->insert($data);if($structured){$this->curriculum?->syncPlan($id,$structured['componentes'],$structured['habilidades']);$this->audit->record((int)$user['id'],'CURRICULO_PLANO_ATUALIZADO','apc_planos',$id,null,$this->curriculumAudit($structured),$ip,$userAgent);}$this->audit->record((int)$user['id'],'CRIAR','apc_planos',$id,null,$data,$ip,$userAgent);$this->plans->db->commit();return$id;}
        catch(PDOException $exception){if($this->plans->db->inTransaction())$this->plans->db->rollBack();if($exception->getCode()==='23000')throw new HttpException(422,'APC_PLAN_DUPLICATE','Já existe um plano deste componente para o professor, turma e evento.');throw$exception;}
        catch(\Throwable $exception){if($this->plans->db->inTransaction())$this->plans->db->rollBack();throw$exception;}
    }

    public function update(int $id,array $input,array $user,string $ip,string $userAgent): void
    {
        $before=$this->authorization->editablePlan($id,(int)$user['id'],(string)$user['perfil']);$this->window()->assertOpen($before);$structured=$this->curriculumFields($input,$id);$data=$this->fields($input,$structured);
        $this->plans->db->beginTransaction();
        try{$this->plans->update($id,$data);if($structured){$old=$this->curriculum?->planCurriculum($id);$this->curriculum?->syncPlan($id,$structured['componentes'],$structured['habilidades']);$this->audit->record((int)$user['id'],'CURRICULO_PLANO_ATUALIZADO','apc_planos',$id,$old,$this->curriculumAudit($structured),$ip,$userAgent);}$this->audit->record((int)$user['id'],'ALTERAR','apc_planos',$id,$this->auditFields($before),$data,$ip,$userAgent);$this->plans->db->commit();}
        catch(\Throwable $exception){if($this->plans->db->inTransaction())$this->plans->db->rollBack();throw$exception;}
    }

    public function finalize(int $id,array $user,string $ip,string $userAgent): void
    {
        $plan=$this->authorization->editablePlan($id,(int)$user['id'],(string)$user['perfil']);$this->window()->assertOpen($plan);
        foreach(['conteudos','descricao_atividade','estrategia_devolucao']as$field)if(trim((string)$plan[$field])==='')throw new HttpException(422,'APC_PLAN_INCOMPLETE','Preencha todos os campos obrigatórios antes de finalizar.');$selected=$this->curriculum?->planCurriculum($id);if($selected&&$selected['componentes']) {if(!$selected['habilidades']&&trim((string)$plan['competencias_habilidades'])==='')throw new HttpException(422,'APC_PLAN_INCOMPLETE','Selecione ao menos uma habilidade ou preencha o complemento curricular.');}elseif(trim((string)$plan['competencias_habilidades'])==='')throw new HttpException(422,'APC_PLAN_INCOMPLETE','Preencha todos os campos obrigatórios antes de finalizar.');
        $this->plans->db->beginTransaction();
        try{$this->plans->finalize($id);$this->audit->record((int)$user['id'],'FINALIZAR','apc_planos',$id,['status'=>'RASCUNHO'],['status'=>'FINALIZADO'],$ip,$userAgent);$this->plans->db->commit();}
        catch(\Throwable $exception){if($this->plans->db->inTransaction())$this->plans->db->rollBack();throw$exception;}
    }

    public function reopen(int $id,string $reason,array $user,string $ip,string $userAgent): void
    {
        if(!in_array($user['perfil'],['ADMIN','COORDENADOR'],true))throw new HttpException(403,'APC_FORBIDDEN','Apenas coordenação ou administração pode reabrir o plano.');
        $plan=$this->authorization->plan($id,(int)$user['id'],(string)$user['perfil']);$this->window()->assertOpen($plan);$reason=Input::text($reason,'Motivo da reabertura',1000);
        if($plan['status']!=='FINALIZADO')throw new HttpException(422,'APC_INVALID_STATUS','Somente planos finalizados podem ser reabertos.');
        $this->plans->db->beginTransaction();
        try{$this->plans->reopen($id,(int)$user['id'],$reason);$this->audit->record((int)$user['id'],'REABRIR','apc_planos',$id,['status'=>'FINALIZADO'],['status'=>'RASCUNHO','motivo'=>$reason],$ip,$userAgent);$this->plans->db->commit();}
        catch(\Throwable $exception){if($this->plans->db->inTransaction())$this->plans->db->rollBack();throw$exception;}
    }

    private function fields(array $input,?array $structured=null): array
    {
        return[
            'componente_curricular'=>$structured?implode(' · ',array_column($structured['componentes'],'nome')):Input::text($input['componente_curricular']??null,'Componente curricular',160),
            'competencias_habilidades'=>Input::text($input['competencias_habilidades']??'','Competências e habilidades',12000,false),
            'conteudos'=>Input::text($input['conteudos']??'','Conteúdos',12000,false),
            'descricao_atividade'=>Input::text($input['descricao_atividade']??'','Descrição da atividade',20000,false),
            'estrategia_devolucao'=>Input::text($input['estrategia_devolucao']??'','Estratégia de devolução',12000,false),
            'etapa'=>$structured['etapa']??null,
            'ano_serie'=>$structured['ano_serie']??null,
        ];
    }

    private function curriculumFields(array $input,int $planId=0): ?array
    {
        if(!$this->curriculum)return null;$stage=trim((string)($input['etapa']??''));$year=trim((string)($input['ano_serie']??''));$componentIds=$this->ids($input['componentes']??[]);$abilityIds=$this->ids($input['habilidades']??[]);if($stage===''&&$year===''&&!$componentIds&&!$abilityIds)return null;if(!in_array($stage,self::STAGES,true)||!in_array($year,self::YEARS,true)||$this->stageForYear($year)!==$stage)throw new HttpException(422,'APC_INVALID_CURRICULUM','Etapa e ano/série não correspondem.');if(!$componentIds)throw new HttpException(422,'APC_INVALID_CURRICULUM','Selecione ao menos um componente curricular.');$components=$this->curriculum->selectableComponents($componentIds,$planId);if(count($components)!==count($componentIds))throw new HttpException(422,'APC_INVALID_CURRICULUM','Há componente inexistente ou inativo na seleção.');foreach($components as$component)if($component['etapa']!==$stage)throw new HttpException(422,'APC_INVALID_CURRICULUM','O componente não pertence à etapa selecionada.');$abilities=$this->curriculum->selectableAbilities($abilityIds,$planId);if(count($abilities)!==count($abilityIds))throw new HttpException(422,'APC_INVALID_CURRICULUM','Há habilidade inexistente ou inativa na seleção.');$componentSet=array_fill_keys($componentIds,true);foreach($abilities as$ability)if(!isset($componentSet[(int)$ability['componente_id']]))throw new HttpException(422,'APC_INVALID_CURRICULUM','Uma habilidade selecionada pertence a componente não associado ao plano.');$allowed=$this->curriculum->abilityIdsAllowedForYear($abilityIds,$year);sort($allowed);$expected=$abilityIds;sort($expected);if($allowed!==$expected)throw new HttpException(422,'APC_INVALID_CURRICULUM','Uma habilidade selecionada não corresponde ao ano/série informado.');return['etapa'=>$stage,'ano_serie'=>$year,'componentes'=>$components,'habilidades'=>$abilities];
    }

    private function ids(mixed $values): array{$ids=[];foreach((array)$values as$value)if(filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false)$ids[]=(int)$value;return array_values(array_unique($ids));}
    private function stageForYear(string$year):string{return str_starts_with($year,'EM')?'EM':((int)substr($year,2)<=5?'EF_AI':'EF_AF');}
    private function curriculumAudit(array$data):array{return['etapa'=>$data['etapa'],'ano_serie'=>$data['ano_serie'],'componentes'=>array_map('intval',array_column($data['componentes'],'id')),'habilidades'=>array_map('intval',array_column($data['habilidades'],'id'))];}

    private function auditFields(array $plan): array
    {
        return array_intersect_key($plan,array_flip(['componente_curricular','competencias_habilidades','conteudos','descricao_atividade','estrategia_devolucao','status']));
    }

    private function window(): EventWindow{return$this->eventWindow??new EventWindow();}
}

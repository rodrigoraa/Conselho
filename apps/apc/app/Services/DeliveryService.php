<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\{AuditRepository,DeliveryRepository,PlanRepository,SettingsRepository};
use Apc\Support\Input;
use PreConselho\Integration\SecretariaApiClient;
use Shared\Exceptions\HttpException;

final class DeliveryService
{
    public function __construct(private readonly PlanRepository $plans,private readonly DeliveryRepository $deliveries,private readonly SettingsRepository $settings,private readonly AuditRepository $audit,private readonly AuthorizationService $authorization,private readonly SecretariaApiClient $api) {}

    public function students(int $planId,array $user): array
    {
        $plan=$this->authorization->plan($planId,(int)$user['id'],(string)$user['perfil']);$local=$this->deliveries->forPlan($planId);$localByStudent=[];
        foreach($local as$row)$localByStudent[(int)$row['aluno_id_externo']]=$row;
        $attachments=[];foreach($this->deliveries->attachmentsForPlan($planId)as$file)$attachments[(int)$file['entrega_id']][]=$file;
        $students=[];$error=null;
        try{
            $apiStudents=$this->api->alunosDaTurma((int)$plan['turma_id_externo']);
            foreach($apiStudents as$student){
                $id=(int)$student['id'];$delivery=$localByStudent[$id]??null;
                $students[]=$this->studentRow($id,(string)$student['nome_completo'],$delivery,$attachments,false);unset($localByStudent[$id]);
            }
            $this->plans->updateStudentTotal($planId,count($apiStudents));$plan['total_alunos_snapshot']=count($apiStudents);
        }catch(\Throwable){$error='Não foi possível carregar os alunos desta turma. Os registros já salvos continuam disponíveis.';}
        foreach($localByStudent as$delivery)$students[]=$this->studentRow((int)$delivery['aluno_id_externo'],(string)$delivery['aluno_nome_snapshot'],$delivery,$attachments,true);
        usort($students,static fn(array $a,array $b):int=>strnatcasecmp($a['nome'],$b['nome']));
        return compact('plan','students','error')+['settings'=>$this->settings->all()];
    }

    public function save(int $planId,int $studentId,array $input,array $user,string $ip,string $userAgent): int
    {
        $plan=$this->authorization->editablePlan($planId,(int)$user['id'],(string)$user['perfil']);$before=$this->deliveries->findByStudent($planId,$studentId);$student=null;
        try{$candidate=$this->api->aluno($studentId);if((int)($candidate['id_turma']??0)!==(int)$plan['turma_id_externo'])throw new HttpException(422,'APC_STUDENT_CLASS_MISMATCH','O aluno não pertence à turma deste plano.');$student=$candidate;}
        catch(HttpException $exception){throw$exception;}
        catch(\Throwable){if($before)$student=['id'=>$studentId,'nome_completo'=>$before['aluno_nome_snapshot'],'id_turma'=>$plan['turma_id_externo']];else throw new HttpException(503,'SECRETARIA_UNAVAILABLE','Não foi possível validar o aluno na Secretaria API. Tente novamente.');}
        $delivered=!empty($input['entregue']);$date=null;$grade=null;
        if($delivered){$date=Input::date($input['data_entrega']??null,'Data da entrega');$grade=$this->grade($input['nota']??null);}
        $data=['plano_id'=>$planId,'aluno_id_externo'=>$studentId,'aluno_nome_snapshot'=>Input::text($student['nome_completo']??'','Nome do aluno',200),'entregue'=>$delivered?1:0,'data_entrega'=>$date,'nota'=>$grade,'observacao'=>Input::text($input['observacao']??'','Observação',4000,false)];
        $this->deliveries->db->beginTransaction();
        try{$id=$this->deliveries->upsert($data);$action=$before?'ALTERAR':'REGISTRAR';$this->audit->record((int)$user['id'],$action,'apc_entregas',$id,$before,$data,$ip,$userAgent);if($before&&$before['nota']!=$grade)$this->audit->record((int)$user['id'],'ALTERAR_NOTA','apc_entregas',$id,['nota'=>$before['nota']],['nota'=>$grade],$ip,$userAgent);$this->deliveries->db->commit();return$id;}
        catch(\Throwable $exception){if($this->deliveries->db->inTransaction())$this->deliveries->db->rollBack();throw$exception;}
    }

    private function grade(mixed $value): ?float
    {
        if($value===''||$value===null)return null;
        $normalized=str_replace(',','.',trim((string)$value));if(!is_numeric($normalized))throw new HttpException(422,'APC_INVALID_GRADE','Informe uma nota numérica válida.');
        $settings=$this->settings->all();$grade=(float)$normalized;$min=(float)$settings['nota_min'];$max=(float)$settings['nota_max'];$decimals=(int)$settings['nota_decimais'];
        if($grade<$min||$grade>$max||$decimals<0||$decimals>4||abs($grade-round($grade,$decimals))>0.0000001)throw new HttpException(422,'APC_INVALID_GRADE',"A nota deve estar entre $min e $max, com até $decimals casa(s) decimal(is).");
        return$grade;
    }

    private function studentRow(int $id,string $name,?array $delivery,array $attachments,bool $outsideCurrentList): array
    {
        return['id'=>$id,'nome'=>$name,'entrega'=>$delivery,'anexos'=>$delivery?($attachments[(int)$delivery['id']]??[]):[],'fora_lista_atual'=>$outsideCurrentList];
    }
}

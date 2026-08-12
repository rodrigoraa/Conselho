<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\{AuditRepository,EventRepository};
use Apc\Support\Input;
use Shared\Exceptions\HttpException;

final class EventService
{
    private const TYPES=['JORNADA_FORMATIVA','CONSELHO_CLASSE','EMENDA_FERIADO','EXCEPCIONAL','OUTRO'];

    public function __construct(private readonly EventRepository $events,private readonly AuditRepository $audit) {}

    public function save(?int $id,array $input,int $userId,string $ip,string $userAgent): int
    {
        $data=$this->validate($input);$before=$id===null?null:$this->events->find($id);
        if($id!==null&&!$before)throw new HttpException(404,'APC_EVENT_NOT_FOUND','Evento APC não encontrado.');
        $this->events->db->beginTransaction();
        try{
            if($id===null){$data['criado_por']=$userId;$id=$this->events->insert($data);}else{$this->events->update($id,$data);}
            $this->audit->record($userId,$before?'ALTERAR':'CRIAR','apc_eventos',$id,$before,$data,$ip,$userAgent);$this->events->db->commit();
        }catch(\Throwable $exception){if($this->events->db->inTransaction())$this->events->db->rollBack();throw$exception;}
        return$id;
    }

    public function cancel(int $id,int $userId,string $ip,string $userAgent): void
    {
        $before=$this->events->find($id)??throw new HttpException(404,'APC_EVENT_NOT_FOUND','Evento APC não encontrado.');
        if($before['status']==='CANCELADO')return;
        $this->events->db->beginTransaction();
        try{$this->events->cancel($id);$this->audit->record($userId,'CANCELAR','apc_eventos',$id,['status'=>$before['status']],['status'=>'CANCELADO'],$ip,$userAgent);$this->events->db->commit();}
        catch(\Throwable $exception){if($this->events->db->inTransaction())$this->events->db->rollBack();throw$exception;}
    }

    public function reactivate(int $id,int $userId,string $ip,string $userAgent): void
    {
        $before=$this->events->find($id)??throw new HttpException(404,'APC_EVENT_NOT_FOUND','Evento APC não encontrado.');
        if($before['status']==='ATIVO')return;
        $this->events->db->beginTransaction();
        try{$this->events->reactivate($id);$this->audit->record($userId,'REATIVAR','apc_eventos',$id,['status'=>$before['status']],['status'=>'ATIVO'],$ip,$userAgent);$this->events->db->commit();}
        catch(\Throwable $exception){if($this->events->db->inTransaction())$this->events->db->rollBack();throw$exception;}
    }

    private function validate(array $input): array
    {
        $year=Input::positiveInt($input['ano_letivo']??null,'Ano letivo inválido.');
        if($year<2000||$year>2100)throw new HttpException(422,'VALIDATION_ERROR','Ano letivo inválido.');
        $type=mb_strtoupper(trim((string)($input['tipo']??'')));$origin=mb_strtoupper(trim((string)($input['origem']??'')));
        if(!in_array($type,self::TYPES,true)||!in_array($origin,['SED','ESCOLA'],true))throw new HttpException(422,'VALIDATION_ERROR','Confira o tipo e a origem do evento.');
        $status=mb_strtoupper(trim((string)($input['status']??'ATIVO')));if(!in_array($status,['ATIVO','CANCELADO'],true))throw new HttpException(422,'VALIDATION_ERROR','Status de evento inválido.');
        return[
            'ano_letivo'=>$year,'data'=>Input::date($input['data']??null,'Data'),
            'titulo'=>Input::text($input['titulo']??null,'Título',160),'tipo'=>$type,'origem'=>$origin,
            'descricao'=>Input::text($input['descricao']??'','Descrição',4000,false),
            'justificativa'=>Input::text($input['justificativa']??'','Justificativa',2000,false)?:null,
            'numero_processo'=>Input::text($input['numero_processo']??'','Número do processo',120,false)?:null,
            'documento_referencia'=>Input::text($input['documento_referencia']??'','Documento de referência',255,false)?:null,
            'atividade_fornecida_sed'=>!empty($input['atividade_fornecida_sed'])?1:0,'status'=>$status,
        ];
    }

}

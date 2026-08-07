<?php declare(strict_types=1);
namespace PreConselho\Controllers;

use PreConselho\Integration\SecretariaApiClient;
use PreConselho\Repositories\AppRepository;
use PreConselho\Services\CouncilDocumentService;
use PreConselho\Support\Csrf;
use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};

final class BindingController
{
    public function __construct(private readonly AppRepository $repository,private readonly SecretariaApiClient $api) {}

    public function create(Request $request): Response
    {
        Csrf::verify($request->body['_csrf']??null);
        $userId=filter_var($request->body['professor_id']??null,FILTER_VALIDATE_INT);
        $classIds=$this->ids($request->body['turma_ids']??[]);
        $shifts=$this->shifts($request->body['turnos']??($request->body['turno']??[]));
        if(!$userId||!$classIds||!$shifts||count($classIds)>100)throw new HttpException(422,'VALIDATION_ERROR','Selecione um professor, ao menos um turno e uma turma.');
        $professor=$this->repository->professorByUser((int)$userId);
        if(!$professor)throw new HttpException(422,'VALIDATION_ERROR','O usuário selecionado não é um professor ativo.');
        $classes=[];foreach($classIds as$classId)$classes[]=$this->api->turma($classId);

        $this->repository->db->beginTransaction();$created=0;
        try{
            $insert=$this->repository->db->prepare('INSERT OR IGNORE INTO vinculos_professor_turma(professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno)VALUES(:professor,:turma,:nome,:ano,:turno)');
            foreach($shifts as$shift)foreach($classes as$class){
                $insert->execute([':professor'=>$professor['id'],':turma'=>$class['id'],':nome'=>$class['nome_turma'],':ano'=>$class['ano_letivo'],':turno'=>$shift]);
                if($insert->rowCount()===0)continue;
                $created++;$id=(int)$this->repository->db->lastInsertId();
                $this->repository->audit((int)$_SESSION['user']['id'],'CRIAR','vinculos_professor_turma',$id,null,['professor_id'=>$professor['id'],'turma_externa_id'=>$class['id'],'turno'=>$shift],$request->ip(),$request->header('User-Agent')??'');
            }
            $this->repository->db->commit();
        }catch(\Throwable$exception){if($this->repository->db->inTransaction())$this->repository->db->rollBack();throw$exception;}
        $periods=$this->repository->db->prepare("SELECT id FROM periodos_pre_conselho WHERE status='ABERTO' AND turno=:turno");
        foreach($shifts as$shift){$periods->execute([':turno'=>$shift]);foreach($periods->fetchAll(\PDO::FETCH_COLUMN)as$periodId)(new CouncilDocumentService($this->repository))->synchronizePeriod((int)$periodId);}
        $_SESSION['flash']=$created.' vínculo(s) de turma criado(s).'.($created===0?' As combinações selecionadas já existiam.':'');
        return Response::redirect('/admin#vinculos');
    }

    public function update(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$id=(int)$params['id'];
        $statement=$this->repository->db->prepare('SELECT * FROM vinculos_professor_turma WHERE id=:id');$statement->execute([':id'=>$id]);
        $before=$statement->fetch()?:throw new HttpException(404,'BINDING_NOT_FOUND','Vínculo não encontrado.');
        $userId=filter_var($request->body['professor_id']??null,FILTER_VALIDATE_INT);$classId=filter_var($request->body['turma_id']??null,FILTER_VALIDATE_INT);$shift=$this->shift($request->body['turno']??'');
        $professor=$userId?$this->repository->professorByUser((int)$userId):null;
        if(!$professor||!$classId)throw new HttpException(422,'VALIDATION_ERROR','Selecione professor, turno e turma.');
        $class=$this->api->turma((int)$classId);$after=['professor_id'=>$professor['id'],'turma_externa_id'=>$class['id'],'turma_nome_snapshot'=>$class['nome_turma'],'turma_ano_letivo_snapshot'=>$class['ano_letivo'],'turno'=>$shift];
        $this->repository->db->beginTransaction();
        try{
            $this->repository->db->prepare('UPDATE vinculos_professor_turma SET professor_id=:professor,turma_externa_id=:turma,turma_nome_snapshot=:nome,turma_ano_letivo_snapshot=:ano,turno=:turno,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id')->execute([':professor'=>$after['professor_id'],':turma'=>$after['turma_externa_id'],':nome'=>$after['turma_nome_snapshot'],':ano'=>$after['turma_ano_letivo_snapshot'],':turno'=>$after['turno'],':id'=>$id]);
            $this->repository->audit((int)$_SESSION['user']['id'],'EDITAR','vinculos_professor_turma',$id,$before,$after,$request->ip(),$request->header('User-Agent')??'');$this->repository->db->commit();
        }catch(\PDOException$exception){if($this->repository->db->inTransaction())$this->repository->db->rollBack();throw new HttpException(422,'BINDING_DUPLICATE','Este professor já está vinculado a essa turma nesse turno.');}
        catch(\Throwable$exception){if($this->repository->db->inTransaction())$this->repository->db->rollBack();throw$exception;}
        $_SESSION['flash']='Vínculo de turma atualizado.';return Response::redirect('/admin#vinculos');
    }

    public function toggle(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$id=(int)$params['id'];$statement=$this->repository->db->prepare('SELECT * FROM vinculos_professor_turma WHERE id=:id');$statement->execute([':id'=>$id]);
        $before=$statement->fetch()?:throw new HttpException(404,'BINDING_NOT_FOUND','Vínculo não encontrado.');$active=(int)!((bool)$before['ativo']);
        $this->repository->db->prepare('UPDATE vinculos_professor_turma SET ativo=:ativo,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id')->execute([':ativo'=>$active,':id'=>$id]);
        $this->repository->audit((int)$_SESSION['user']['id'],$active?'ATIVAR':'DESATIVAR','vinculos_professor_turma',$id,['ativo'=>$before['ativo']],['ativo'=>$active],$request->ip(),$request->header('User-Agent')??'');
        $_SESSION['flash']='Situação do vínculo atualizada.';return Response::redirect('/admin#vinculos');
    }

    private function shift(mixed $value): string
    {
        $shift=mb_strtoupper(trim((string)$value));
        if(!in_array($shift,['MATUTINO','VESPERTINO'],true))throw new HttpException(422,'INVALID_SHIFT','Selecione o turno matutino ou vespertino.');
        return$shift;
    }

    private function shifts(mixed $value): array
    {
        $values=is_array($value)?$value:[$value];$shifts=[];
        foreach($values as$item){$shift=mb_strtoupper(trim((string)$item));if(in_array($shift,['MATUTINO','VESPERTINO'],true))$shifts[]=$shift;}
        return array_values(array_unique($shifts));
    }

    private function ids(mixed $value): array
    {
        if(!is_array($value))return[];$ids=[];
        foreach($value as$item){$id=filter_var($item,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id!==false)$ids[]=(int)$id;}
        return array_values(array_unique($ids));
    }
}

<?php declare(strict_types=1);
namespace PreConselho\Services;

use PreConselho\Repositories\AppRepository;
use Shared\Exceptions\HttpException;

final class AdminDeletionService
{
    public function __construct(private readonly AppRepository $repository) {}

    public function deleteReport(int $id, int $actorId, string $ip, string $userAgent): void
    {
        $report=$this->repository->report($id)??throw new HttpException(404,'REPORT_NOT_FOUND','Relatório não encontrado.');
        $this->transaction(function()use($id,$actorId,$ip,$userAgent,$report):void{
            $this->deleteReports([$id]);
            $this->repository->audit($actorId,'EXCLUIR','relatorios_pre_conselho',$id,[
                'periodo'=>$report['periodo'],
                'professor'=>$report['professor_nome'],
                'turma'=>$report['turma_nome_snapshot'],
                'disciplina'=>$report['disciplina'],
                'status'=>$report['status'],
            ],null,$ip,$userAgent);
        });
    }

    public function deletePeriod(int $id, int $actorId, string $ip, string $userAgent): void
    {
        $statement=$this->repository->db->prepare('SELECT * FROM periodos_pre_conselho WHERE id=:id');
        $statement->execute([':id'=>$id]);
        $period=$statement->fetch()?:throw new HttpException(404,'PERIOD_NOT_FOUND','Período não encontrado.');
        $reportIds=$this->reportIds('periodo_id',$id);
        $this->transaction(function()use($id,$actorId,$ip,$userAgent,$period,$reportIds):void{
            $this->deleteReports($reportIds);
            $this->repository->db->prepare('DELETE FROM periodos_pre_conselho WHERE id=:id')->execute([':id'=>$id]);
            $this->repository->audit($actorId,'EXCLUIR','periodos_pre_conselho',$id,[
                'nome'=>$period['nome'],
                'ano_letivo'=>$period['ano_letivo'],
                'etapa'=>$period['etapa'],
                'status'=>$period['status'],
                'relatorios_excluidos'=>count($reportIds),
            ],null,$ip,$userAgent);
        });
    }

    public function deleteBinding(int $id, int $actorId, string $ip, string $userAgent): void
    {
        $statement=$this->repository->db->prepare('SELECT v.*,u.nome professor_nome,d.nome disciplina_nome FROM vinculos_professor_turma_disciplina v JOIN professores p ON p.id=v.professor_id JOIN usuarios u ON u.id=p.usuario_id JOIN disciplinas d ON d.id=v.disciplina_id WHERE v.id=:id');
        $statement->execute([':id'=>$id]);
        $binding=$statement->fetch()?:throw new HttpException(404,'BINDING_NOT_FOUND','Vínculo não encontrado.');
        $reportIds=$this->reportIds('vinculo_id',$id);
        $this->transaction(function()use($id,$actorId,$ip,$userAgent,$binding,$reportIds):void{
            $this->deleteReports($reportIds);
            $this->repository->db->prepare('DELETE FROM vinculos_professor_turma_disciplina WHERE id=:id')->execute([':id'=>$id]);
            $this->repository->audit($actorId,'EXCLUIR','vinculos_professor_turma_disciplina',$id,[
                'professor'=>$binding['professor_nome'],
                'turma'=>$binding['turma_nome_snapshot'],
                'disciplina'=>$binding['disciplina_nome'],
                'relatorios_excluidos'=>count($reportIds),
            ],null,$ip,$userAgent);
        });
    }

    public function deleteDiscipline(int $id, int $actorId, string $ip, string $userAgent): void
    {
        $statement=$this->repository->db->prepare('SELECT * FROM disciplinas WHERE id=:id');
        $statement->execute([':id'=>$id]);
        $discipline=$statement->fetch()?:throw new HttpException(404,'DISCIPLINE_NOT_FOUND','Disciplina não encontrada.');
        $bindingStatement=$this->repository->db->prepare('SELECT id FROM vinculos_professor_turma_disciplina WHERE disciplina_id=:id');
        $bindingStatement->execute([':id'=>$id]);
        $bindingIds=array_map('intval',$bindingStatement->fetchAll(\PDO::FETCH_COLUMN));
        $reportIds=[];
        if($bindingIds){
            $marks=implode(',',array_fill(0,count($bindingIds),'?'));
            $reportStatement=$this->repository->db->prepare("SELECT id FROM relatorios_pre_conselho WHERE vinculo_id IN ($marks)");
            $reportStatement->execute($bindingIds);
            $reportIds=array_map('intval',$reportStatement->fetchAll(\PDO::FETCH_COLUMN));
        }
        $this->transaction(function()use($id,$actorId,$ip,$userAgent,$discipline,$bindingIds,$reportIds):void{
            $this->deleteReports($reportIds);
            $this->repository->db->prepare('DELETE FROM vinculos_professor_turma_disciplina WHERE disciplina_id=:id')->execute([':id'=>$id]);
            $this->repository->db->prepare('DELETE FROM disciplinas WHERE id=:id')->execute([':id'=>$id]);
            $this->repository->audit($actorId,'EXCLUIR','disciplinas',$id,[
                'nome'=>$discipline['nome'],
                'vinculos_excluidos'=>count($bindingIds),
                'relatorios_excluidos'=>count($reportIds),
            ],null,$ip,$userAgent);
        });
    }

    private function reportIds(string $column, int $id): array
    {
        if(!in_array($column,['periodo_id','vinculo_id'],true))throw new \InvalidArgumentException('Coluna inválida.');
        $statement=$this->repository->db->prepare("SELECT id FROM relatorios_pre_conselho WHERE $column=:id");
        $statement->execute([':id'=>$id]);
        return array_map('intval',$statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function deleteReports(array $ids): void
    {
        if(!$ids)return;
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $this->repository->db->prepare("DELETE FROM historico_status_relatorio WHERE relatorio_id IN ($marks)")->execute($ids);
        $this->repository->db->prepare("DELETE FROM relatorio_alunos WHERE relatorio_id IN ($marks)")->execute($ids);
        $this->repository->db->prepare("DELETE FROM relatorios_pre_conselho WHERE id IN ($marks)")->execute($ids);
    }

    private function transaction(callable $operation): void
    {
        $this->repository->db->beginTransaction();
        try{$operation();$this->repository->db->commit();}
        catch(\Throwable $exception){if($this->repository->db->inTransaction())$this->repository->db->rollBack();throw $exception;}
    }
}

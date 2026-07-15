<?php declare(strict_types=1);
namespace PreConselho\Controllers;

use PreConselho\Integration\SecretariaApiClient;
use PreConselho\Repositories\AppRepository;
use PreConselho\Services\ReportService;
use PreConselho\Support\Csrf;
use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};
use Shared\Support\View;

final class ManagementController
{
    public function __construct(private readonly AppRepository $repository, private readonly SecretariaApiClient $api, private readonly View $view) {}

    public function toggleUser(Request $request, array $params): Response
    {
        Csrf::verify($request->body['_csrf'] ?? null);$id=(int)$params['id'];
        if ($id === (int)$_SESSION['user']['id']) throw new HttpException(422,'SELF_DEACTIVATION','Você não pode desativar o próprio usuário.');
        $before=$this->repository->user($id)??throw new HttpException(404,'USER_NOT_FOUND','Usuário não encontrado.');$active=(int)!((bool)$before['ativo']);
        $this->repository->db->beginTransaction();
        try{$this->repository->db->prepare('UPDATE usuarios SET ativo=:ativo,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id')->execute([':ativo'=>$active,':id'=>$id]);$this->repository->db->prepare('UPDATE professores SET ativo=:ativo,atualizado_em=CURRENT_TIMESTAMP WHERE usuario_id=:id')->execute([':ativo'=>$active,':id'=>$id]);$this->repository->audit($_SESSION['user']['id'],$active?'ATIVAR':'DESATIVAR','usuarios',$id,['ativo'=>$before['ativo']],['ativo'=>$active],$request->ip(),$request->header('User-Agent')??'');$this->repository->db->commit();}catch(\Throwable$e){if($this->repository->db->inTransaction())$this->repository->db->rollBack();throw$e;}
        return Response::redirect('/admin');
    }

    public function reopen(Request $request, array $params): Response
    { Csrf::verify($request->body['_csrf']??null);(new ReportService($this->repository,$this->api))->reopen((int)$params['id'],(string)($request->body['justificativa']??''),$_SESSION['user']['id'],$request->ip(),$request->header('User-Agent')??'');return Response::redirect('/relatorios/'.$params['id']); }

    public function deleteDiscipline(Request $request, array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$id=(int)$params['id'];$statement=$this->repository->db->prepare('SELECT d.*,COUNT(v.id) vinculos FROM disciplinas d LEFT JOIN vinculos_professor_turma_disciplina v ON v.disciplina_id=d.id WHERE d.id=:id GROUP BY d.id');$statement->execute([':id'=>$id]);$discipline=$statement->fetch()?:throw new HttpException(404,'DISCIPLINE_NOT_FOUND','Disciplina não encontrada.');if((int)$discipline['vinculos']>0)throw new HttpException(422,'DISCIPLINE_IN_USE','Esta disciplina está sendo usada e não pode ser excluída.');$this->repository->db->beginTransaction();try{$this->repository->db->prepare('DELETE FROM disciplinas WHERE id=:id')->execute([':id'=>$id]);$this->repository->audit($_SESSION['user']['id'],'EXCLUIR','disciplinas',$id,['nome'=>$discipline['nome']],null,$request->ip(),$request->header('User-Agent')??'');$this->repository->db->commit();}catch(\Throwable$e){if($this->repository->db->inTransaction())$this->repository->db->rollBack();throw$e;}$_SESSION['flash']='Disciplina excluída com sucesso.';return Response::redirect('/admin#disciplinas');
    }

    public function audit(Request $request): Response
    {
        $page=max(1,(int)($request->query['pagina']??1));$limit=25;$offset=($page-1)*$limit;
        $statement=$this->repository->db->prepare('SELECT a.*,u.nome usuario_nome FROM auditoria a LEFT JOIN usuarios u ON u.id=a.usuario_id ORDER BY a.id DESC LIMIT :limite OFFSET :offset');$statement->bindValue(':limite',$limit,\PDO::PARAM_INT);$statement->bindValue(':offset',$offset,\PDO::PARAM_INT);$statement->execute();$rows=$statement->fetchAll();$total=(int)$this->repository->db->query('SELECT COUNT(*) FROM auditoria')->fetchColumn();
        return new Response($this->view->render('audit',['title'=>'Auditoria','rows'=>$rows,'page'=>$page,'pages'=>max(1,(int)ceil($total/$limit))]));
    }
}

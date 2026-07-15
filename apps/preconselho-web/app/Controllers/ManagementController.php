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

    public function editUser(Request $request, array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$id=(int)$params['id'];$statement=$this->repository->db->prepare('SELECT id,nome,email,perfil,ativo,alterar_senha FROM usuarios WHERE id=:id AND excluido_em IS NULL');$statement->execute([':id'=>$id]);$before=$statement->fetch()?:throw new HttpException(404,'USER_NOT_FOUND','Usuário não encontrado.');$name=trim((string)($request->body['nome']??''));$email=filter_var($request->body['email']??'',FILTER_VALIDATE_EMAIL);$role=(string)($request->body['perfil']??'');$password=(string)($request->body['senha']??'');if($name===''||mb_strlen($name)>150||!$email||!in_array($role,['ADMIN','COORDENADOR','PROFESSOR'],true)||($password!==''&&strlen($password)<10))throw new HttpException(422,'VALIDATION_ERROR','Confira os dados. A nova senha, quando informada, deve ter ao menos 10 caracteres.');if($id===(int)$_SESSION['user']['id']&&$role!=='ADMIN')throw new HttpException(422,'SELF_ROLE_CHANGE','Você não pode remover o próprio acesso administrativo.');$this->repository->db->beginTransaction();try{if($before['perfil']==='PROFESSOR'&&$role!=='PROFESSOR'){$check=$this->repository->db->prepare('SELECT COUNT(*) FROM vinculos_professor_turma_disciplina v JOIN professores p ON p.id=v.professor_id WHERE p.usuario_id=:id');$check->execute([':id'=>$id]);if((int)$check->fetchColumn()>0)throw new HttpException(422,'PROFESSOR_IN_USE','O perfil não pode ser alterado porque este professor possui vínculos.');$this->repository->db->prepare('UPDATE professores SET ativo=0,atualizado_em=CURRENT_TIMESTAMP WHERE usuario_id=:id')->execute([':id'=>$id]);}if($role==='PROFESSOR')$this->repository->db->prepare('INSERT INTO professores(usuario_id,ativo)VALUES(:id,1) ON CONFLICT(usuario_id) DO UPDATE SET ativo=1,atualizado_em=CURRENT_TIMESTAMP')->execute([':id'=>$id]);$sql='UPDATE usuarios SET nome=:nome,email=:email,perfil=:perfil,atualizado_em=CURRENT_TIMESTAMP'.($password!==''?',senha_hash=:senha,alterar_senha=:alterar_senha':'').' WHERE id=:id';$values=[':nome'=>$name,':email'=>mb_strtolower((string)$email),':perfil'=>$role,':id'=>$id];if($password!==''){$values[':senha']=password_hash($password,PASSWORD_DEFAULT);$values[':alterar_senha']=$id===(int)$_SESSION['user']['id']?0:1;}$this->repository->db->prepare($sql)->execute($values);$after=['nome'=>$name,'email'=>mb_strtolower((string)$email),'perfil'=>$role,'senha_alterada'=>$password!=='','troca_obrigatoria'=>$password!==''&&$id!==(int)$_SESSION['user']['id']];$this->repository->audit($_SESSION['user']['id'],'EDITAR','usuarios',$id,$before,$after,$request->ip(),$request->header('User-Agent')??'');$this->repository->db->commit();}catch(\PDOException$e){if($this->repository->db->inTransaction())$this->repository->db->rollBack();if($e->getCode()==='23000')throw new HttpException(422,'EMAIL_IN_USE','Este e-mail já está sendo utilizado.');throw$e;}catch(\Throwable$e){if($this->repository->db->inTransaction())$this->repository->db->rollBack();throw$e;}if($id===(int)$_SESSION['user']['id']){$_SESSION['user']['nome']=$name;$_SESSION['user']['perfil']=$role;if($password!=='')$_SESSION['user']['alterar_senha']=0;}$_SESSION['flash']=$password!==''&&$id!==(int)$_SESSION['user']['id']?'Usuário atualizado. Ele deverá criar uma nova senha no próximo acesso.':'Usuário atualizado com sucesso.';return Response::redirect('/admin#usuarios-lista');
    }

    public function deleteUser(Request $request, array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$id=(int)$params['id'];if($id===(int)$_SESSION['user']['id'])throw new HttpException(422,'SELF_DELETION','Você não pode excluir o próprio usuário.');$statement=$this->repository->db->prepare('SELECT id,nome,email,perfil,ativo FROM usuarios WHERE id=:id AND excluido_em IS NULL');$statement->execute([':id'=>$id]);$before=$statement->fetch()?:throw new HttpException(404,'USER_NOT_FOUND','Usuário não encontrado.');$this->repository->db->beginTransaction();try{$deletedEmail='excluido-'.$id.'-'.time().'@usuario.invalid';$this->repository->db->prepare("UPDATE usuarios SET ativo=0,email=:email,excluido_em=CURRENT_TIMESTAMP,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id")->execute([':email'=>$deletedEmail,':id'=>$id]);$this->repository->db->prepare('UPDATE professores SET ativo=0,atualizado_em=CURRENT_TIMESTAMP WHERE usuario_id=:id')->execute([':id'=>$id]);$this->repository->audit($_SESSION['user']['id'],'EXCLUIR','usuarios',$id,$before,null,$request->ip(),$request->header('User-Agent')??'');$this->repository->db->commit();}catch(\Throwable$e){if($this->repository->db->inTransaction())$this->repository->db->rollBack();throw$e;}$_SESSION['flash']='Usuário excluído com sucesso. O histórico foi preservado.';return Response::redirect('/admin#usuarios-lista');
    }

    public function audit(Request $request): Response
    {
        $page=max(1,(int)($request->query['pagina']??1));$limit=25;$offset=($page-1)*$limit;
        $statement=$this->repository->db->prepare('SELECT a.*,u.nome usuario_nome FROM auditoria a LEFT JOIN usuarios u ON u.id=a.usuario_id ORDER BY a.id DESC LIMIT :limite OFFSET :offset');$statement->bindValue(':limite',$limit,\PDO::PARAM_INT);$statement->bindValue(':offset',$offset,\PDO::PARAM_INT);$statement->execute();$rows=$statement->fetchAll();$total=(int)$this->repository->db->query('SELECT COUNT(*) FROM auditoria')->fetchColumn();
        return new Response($this->view->render('audit',['title'=>'Auditoria','rows'=>$rows,'page'=>$page,'pages'=>max(1,(int)ceil($total/$limit))]));
    }
}

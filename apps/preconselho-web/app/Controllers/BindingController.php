<?php declare(strict_types=1);
namespace PreConselho\Controllers;
use PreConselho\Integration\SecretariaApiClient;use PreConselho\Repositories\AppRepository;use PreConselho\Support\Csrf;use Shared\Exceptions\HttpException;use Shared\Http\{Request,Response};
final class BindingController
{
 public function __construct(private readonly AppRepository$r,private readonly SecretariaApiClient$api){}
 public function create(Request$q):Response
 {
  Csrf::verify($q->body['_csrf']??null);$userId=filter_var($q->body['professor_id']??null,FILTER_VALIDATE_INT);$class=filter_var($q->body['turma_id']??null,FILTER_VALIDATE_INT);$discipline=filter_var($q->body['disciplina_id']??null,FILTER_VALIDATE_INT);if(!$userId||!$class||!$discipline)throw new HttpException(422,'VALIDATION_ERROR','Vínculo inválido.');$professor=$this->r->professorByUser((int)$userId);if(!$professor)throw new HttpException(422,'VALIDATION_ERROR','O usuário informado não é um professor ativo.');$turma=$this->api->turma((int)$class);
  $s=$this->r->db->prepare('INSERT INTO vinculos_professor_turma_disciplina(professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,disciplina_id)VALUES(:p,:t,:n,:a,:d)');$s->execute([':p'=>$professor['id'],':t'=>$turma['id'],':n'=>$turma['nome_turma'],':a'=>$turma['ano_letivo'],':d'=>$discipline]);$id=(int)$this->r->db->lastInsertId();$this->r->audit($_SESSION['user']['id'],'CRIAR','vinculos_professor_turma_disciplina',$id,null,['professor_id'=>$professor['id'],'turma_externa_id'=>$turma['id'],'disciplina_id'=>$discipline],$q->ip(),$q->header('User-Agent')??'');return Response::redirect('/admin');
 }
}

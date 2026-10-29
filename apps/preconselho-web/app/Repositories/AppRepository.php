<?php declare(strict_types=1);
namespace PreConselho\Repositories;
use PDO;
use PreConselho\Support\CouncilClass;
use Shared\Exceptions\HttpException;
final class AppRepository
{
 public function __construct(public readonly PDO $db){}
 public function userByEmail(string$e):?array{$s=$this->db->prepare('SELECT * FROM usuarios WHERE email=:e');$s->execute([':e'=>$e]);return$s->fetch()?:null;}
 public function userByCpf(string$cpf):?array{$s=$this->db->prepare('SELECT * FROM usuarios WHERE cpf=:cpf AND excluido_em IS NULL');$s->execute([':cpf'=>$cpf]);return$s->fetch()?:null;}
 public function user(int$id):?array{$s=$this->db->prepare('SELECT id,nome,email,cpf,perfil,ativo FROM usuarios WHERE id=:id');$s->execute([':id'=>$id]);return$s->fetch()?:null;}
 public function professorByUser(int$id):?array{$s=$this->db->prepare('SELECT * FROM professores WHERE usuario_id=:id AND ativo=1');$s->execute([':id'=>$id]);return$s->fetch()?:null;}
 public function report(int$id):?array{$s=$this->db->prepare("SELECT r.*,p.nome periodo,p.ano_letivo,p.etapa,p.data_inicio,p.data_fim,p.status periodo_status,v.turma_externa_id,v.turma_nome_snapshot,v.turma_ano_letivo_snapshot,d.nome disciplina,u.nome professor_nome,pr.usuario_id professor_usuario_id FROM relatorios_pre_conselho r JOIN periodos_pre_conselho p ON p.id=r.periodo_id JOIN vinculos_professor_turma_disciplina v ON v.id=r.vinculo_id JOIN disciplinas d ON d.id=v.disciplina_id JOIN professores pr ON pr.id=v.professor_id JOIN usuarios u ON u.id=pr.usuario_id WHERE r.id=:id");$s->execute([':id'=>$id]);return$s->fetch()?:null;}
 public function reportStudents(int$id):array{$s=$this->db->prepare('SELECT * FROM relatorio_alunos WHERE relatorio_id=:id ORDER BY aluno_nome_snapshot');$s->execute([':id'=>$id]);return$s->fetchAll();}
 public function history(int$id):array{$s=$this->db->prepare('SELECT h.*,u.nome usuario_nome FROM historico_status_relatorio h JOIN usuarios u ON u.id=h.usuario_id WHERE relatorio_id=:id ORDER BY h.id');$s->execute([':id'=>$id]);return$s->fetchAll();}
 public function professorReports(int$user):array{$s=$this->db->prepare("SELECT r.*,p.nome periodo,p.data_fim,p.ano_letivo,p.status periodo_status,v.turma_nome_snapshot,d.nome disciplina FROM relatorios_pre_conselho r JOIN periodos_pre_conselho p ON p.id=r.periodo_id JOIN vinculos_professor_turma_disciplina v ON v.id=r.vinculo_id JOIN disciplinas d ON d.id=v.disciplina_id JOIN professores pr ON pr.id=v.professor_id WHERE pr.usuario_id=:u ORDER BY CASE p.status WHEN 'ABERTO' THEN 0 ELSE 1 END,p.data_fim,r.id");$s->execute([':u'=>$user]);return$s->fetchAll();}
 public function coordinationReports():array{return$this->db->query("SELECT r.*,p.nome periodo,p.ano_letivo,p.data_fim,p.status periodo_status,v.turma_nome_snapshot,d.nome disciplina,u.nome professor_nome,pr.usuario_id professor_usuario_id FROM relatorios_pre_conselho r JOIN periodos_pre_conselho p ON p.id=r.periodo_id JOIN vinculos_professor_turma_disciplina v ON v.id=r.vinculo_id JOIN disciplinas d ON d.id=v.disciplina_id JOIN professores pr ON pr.id=v.professor_id JOIN usuarios u ON u.id=pr.usuario_id WHERE p.status='ABERTO' ORDER BY p.data_fim,u.nome")->fetchAll();}
 public function documentReports(int$period,int$professorUser):array{$s=$this->db->prepare("SELECT r.*,p.nome periodo,p.ano_letivo,p.etapa,p.data_inicio,p.data_fim,p.status periodo_status,v.turma_externa_id,v.turma_nome_snapshot,v.turma_ano_letivo_snapshot,d.nome disciplina,u.nome professor_nome,pr.usuario_id professor_usuario_id FROM relatorios_pre_conselho r JOIN periodos_pre_conselho p ON p.id=r.periodo_id JOIN vinculos_professor_turma_disciplina v ON v.id=r.vinculo_id JOIN disciplinas d ON d.id=v.disciplina_id JOIN professores pr ON pr.id=v.professor_id JOIN usuarios u ON u.id=pr.usuario_id WHERE r.periodo_id=:periodo AND pr.usuario_id=:professor ORDER BY v.turma_nome_snapshot COLLATE NOCASE,d.nome COLLATE NOCASE,r.id");$s->execute([':periodo'=>$period,':professor'=>$professorUser]);return$s->fetchAll();}
 public function documentHistory(int$period,int$professorUser):array{$s=$this->db->prepare("SELECT h.*,u.nome usuario_nome,v.turma_nome_snapshot,d.nome disciplina FROM historico_status_relatorio h JOIN relatorios_pre_conselho r ON r.id=h.relatorio_id JOIN vinculos_professor_turma_disciplina v ON v.id=r.vinculo_id JOIN disciplinas d ON d.id=v.disciplina_id JOIN professores pr ON pr.id=v.professor_id JOIN usuarios u ON u.id=h.usuario_id WHERE r.periodo_id=:periodo AND pr.usuario_id=:professor ORDER BY h.criado_em,h.id");$s->execute([':periodo'=>$period,':professor'=>$professorUser]);return$s->fetchAll();}
 public static function councilFilters(array $query):array
 {
  $filters=['ano'=>self::filterValue($query,'ano'),'periodo'=>self::filterValue($query,'periodo'),'turno'=>self::filterValue($query,'turno'),'q'=>self::filterValue($query,'q')];
  foreach(['ano','periodo']as$key)if($filters[$key]!==''&&(!ctype_digit($filters[$key])||(int)$filters[$key]<1||(int)$filters[$key]>($key==='ano'?2100:PHP_INT_MAX)))throw new HttpException(422,'INVALID_FILTER','Confira o ano e o período selecionados.');
  if($filters['ano']!==''&&(int)$filters['ano']<2000)throw new HttpException(422,'INVALID_FILTER','Confira o ano selecionado.');
  foreach(['ano','periodo']as$key)if($filters[$key]!=='')$filters[$key]=ltrim($filters[$key],'0');
  if(!in_array($filters['turno'],['','MATUTINO','VESPERTINO'],true))throw new HttpException(422,'INVALID_FILTER','Selecione um turno válido.');
  return$filters;
 }

 public function consolidatedRows(array $filters):array
 {
  $where=["TRIM(dt.conteudo)<>''"];$params=[];
  foreach(['ano'=>'p.ano_letivo','periodo'=>'p.id','turno'=>'p.turno']as$key=>$column)if($filters[$key]!==''){$where[]=$column.'=:'.$key;$params[':'.$key]=$filters[$key];}
  $statement=$this->db->prepare("SELECT dt.id documento_turma_id,p.id periodo_id,p.nome periodo,p.ano_letivo,p.turno,dt.turma_nome_snapshot turma,dt.conteudo relato,COALESCE(u.nome,'—') atualizado_por,dt.atualizado_em FROM documento_turmas dt JOIN periodos_pre_conselho p ON p.id=dt.periodo_id LEFT JOIN usuarios u ON u.id=dt.atualizado_por WHERE ".implode(' AND ',$where));$statement->execute($params);$rows=$statement->fetchAll();
  foreach($rows as&$row){$identity=CouncilClass::identify((string)$row['turma']);$source=$row['turma'];$row['turma']=$identity['name'];$row['_ordem_turma']=$identity['order'];$row['_busca']=implode(' ',[$row['periodo'],$row['ano_letivo'],$row['turno'],$source,$row['turma'],$row['relato'],$row['atualizado_por']]);}unset($row);
  if($filters['q']!=='')$rows=array_values(array_filter($rows,static fn(array$row):bool=>mb_stripos($row['_busca'],$filters['q'])!==false));
  usort($rows,static fn(array$a,array$b):int=>((int)$b['ano_letivo']<=>(int)$a['ano_letivo'])?:((int)$b['periodo_id']<=>(int)$a['periodo_id'])?:((int)$a['_ordem_turma']<=>(int)$b['_ordem_turma'])?:strnatcasecmp($a['turma'],$b['turma'])?:((int)$a['documento_turma_id']<=>(int)$b['documento_turma_id']));
  foreach($rows as&$row)unset($row['_ordem_turma'],$row['_busca']);unset($row);return$rows;
 }

 public static function auditFilters(array $query):array
 {
  $filters=[];foreach(['q','usuario','acao','de','ate']as$key)$filters[$key]=self::filterValue($query,$key);
  if($filters['usuario']!==''&&$filters['usuario']!=='sistema'&&(!ctype_digit($filters['usuario'])||(int)$filters['usuario']<1))throw new HttpException(422,'INVALID_FILTER','Selecione um autor válido.');
  if($filters['usuario']!==''&&$filters['usuario']!=='sistema')$filters['usuario']=ltrim($filters['usuario'],'0');
  foreach(['de','ate']as$key)if($filters[$key]!==''){$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$filters[$key]);if(!$date||$date->format('Y-m-d')!==$filters[$key])throw new HttpException(422,'INVALID_FILTER','Informe datas válidas para consultar a auditoria.');}
  if($filters['de']!==''&&$filters['ate']!==''&&$filters['de']>$filters['ate'])throw new HttpException(422,'INVALID_FILTER','A data inicial deve ser anterior ou igual à data final.');
  return$filters;
 }

 public function auditPage(array $filters,mixed $requestedPage):array
 {
  $where=[];$params=[];
  if($filters['usuario']==='sistema')$where[]='a.usuario_id IS NULL';elseif($filters['usuario']!==''){$where[]='a.usuario_id=:usuario';$params[':usuario']=$filters['usuario'];}
  if($filters['acao']!==''){$where[]='a.acao=:acao';$params[':acao']=$filters['acao'];}
  if($filters['de']!==''){$where[]='a.criado_em>=:de';$params[':de']=$filters['de'].' 00:00:00';}
  if($filters['ate']!==''){$where[]='a.criado_em<:ate';$params[':ate']=(new \DateTimeImmutable($filters['ate']))->modify('+1 day')->format('Y-m-d').' 00:00:00';}
  if($filters['q']!==''){
   // Literal Unicode search, shared by count and rows; %, _ and quotes are text.
   $this->db->sqliteCreateFunction('council_contains',static fn(?string$text,string$term):int=>mb_stripos($text??'',$term)!==false?1:0,2);
   $where[]="council_contains(COALESCE(u.nome,CASE WHEN a.usuario_id IS NULL THEN 'Sistema' ELSE 'Usuário #'||a.usuario_id END)||' '||a.acao||' '||a.entidade||' '||COALESCE(a.entidade_id,'')||' '||COALESCE(a.endereco_ip,''),:q)=1";$params[':q']=$filters['q'];
  }
  $from=' FROM auditoria a LEFT JOIN usuarios u ON u.id=a.usuario_id'.($where?' WHERE '.implode(' AND ',$where):'');
  $count=$this->db->prepare('SELECT COUNT(*)'.$from);$count->execute($params);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/25));
  $page=min($pages,max(1,is_scalar($requestedPage)?(int)$requestedPage:1));
  $statement=$this->db->prepare('SELECT a.*,u.nome usuario_nome'.$from.' ORDER BY a.id DESC LIMIT :limite OFFSET :offset');
  foreach($params as$key=>$value)$statement->bindValue($key,$value,PDO::PARAM_STR);$statement->bindValue(':limite',25,PDO::PARAM_INT);$statement->bindValue(':offset',($page-1)*25,PDO::PARAM_INT);$statement->execute();$rows=$statement->fetchAll();
  return compact('rows','page','pages','total');
 }

 private static function filterValue(array $query,string $key):string
 {
  $value=$query[$key]??'';if(!is_scalar($value)||mb_strlen((string)$value)>250)throw new HttpException(422,'INVALID_FILTER','O filtro informado é inválido.');return trim((string)$value);
 }
 public function audit(?int$uid,string$a,string$e,?int$id,mixed$before,mixed$after,string$ip,string$ua):void{$s=$this->db->prepare('INSERT INTO auditoria(usuario_id,acao,entidade,entidade_id,dados_anteriores,dados_novos,endereco_ip,user_agent)VALUES(:u,:a,:e,:i,:b,:n,:ip,:ua)');$s->execute([':u'=>$uid,':a'=>$a,':e'=>$e,':i'=>$id,':b'=>$before===null?null:json_encode($before,JSON_UNESCAPED_UNICODE),':n'=>$after===null?null:json_encode($after,JSON_UNESCAPED_UNICODE),':ip'=>$ip,':ua'=>mb_substr($ua,0,255)]);}
}

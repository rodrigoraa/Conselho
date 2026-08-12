<?php declare(strict_types=1);

namespace Apc\Repositories;

use PDO;

final class AccessRepository
{
    public function __construct(private readonly PDO $db) {}

    public function isActiveTeacher(int$userId):bool
    {
        $statement=$this->db->prepare('SELECT 1 FROM professores p JOIN usuarios u ON u.id=p.usuario_id WHERE p.usuario_id=:usuario AND p.ativo=1 AND u.ativo=1 AND u.excluido_em IS NULL LIMIT 1');
        $statement->execute([':usuario'=>$userId]);return(bool)$statement->fetchColumn();
    }

    public function classesFor(int $userId,string $role): array
    {
        if($role==='PROFESSOR'){
            $statement=$this->db->prepare("SELECT DISTINCT v.turma_externa_id id,v.turma_nome_snapshot nome,v.turma_ano_letivo_snapshot ano_letivo FROM vinculos_professor_turma v JOIN professores p ON p.id=v.professor_id JOIN usuarios u ON u.id=p.usuario_id WHERE p.usuario_id=:usuario AND v.ativo=1 AND p.ativo=1 AND u.ativo=1 AND u.excluido_em IS NULL ORDER BY v.turma_nome_snapshot COLLATE NOCASE");
            $statement->execute([':usuario'=>$userId]);
            return$statement->fetchAll();
        }
        return$this->db->query("SELECT v.turma_externa_id id,MAX(v.turma_nome_snapshot) nome,MAX(v.turma_ano_letivo_snapshot) ano_letivo FROM vinculos_professor_turma v JOIN professores p ON p.id=v.professor_id JOIN usuarios u ON u.id=p.usuario_id WHERE v.ativo=1 AND p.ativo=1 AND u.ativo=1 AND u.excluido_em IS NULL GROUP BY v.turma_externa_id ORDER BY nome COLLATE NOCASE")->fetchAll();
    }

    public function classFor(int $classId,int $userId,string $role): ?array
    {
        foreach($this->classesFor($userId,$role)as$class)if((int)$class['id']===$classId)return$class;
        return null;
    }

    public function seriesFor(int$userId,string$role):array
    {
        $grouped=[];foreach($this->classesFor($userId,$role)as$class){$series=$this->seriesFromName((string)$class['nome']);if($series===null)continue;$key=$series['etapa'].'|'.$series['ano_serie'];if(!isset($grouped[$key]))$grouped[$key]=$series+['turmas'=>[]];$grouped[$key]['turmas'][]=$class;}usort($grouped,static fn(array$a,array$b):int=>[$a['ordem_etapa'],$a['ordem_serie']]<=>[$b['ordem_etapa'],$b['ordem_serie']]);return array_values($grouped);
    }

    public function classesForSeries(int$userId,string$role,string$stage,string$year):array
    {
        foreach($this->seriesFor($userId,$role)as$series)if($series['etapa']===$stage&&$series['ano_serie']===$year)return$series['turmas'];return[];
    }

    /**
     * @return array{requirements: array<int, array<string, mixed>>, without_series: array<int, array<string, mixed>>}
     */
    public function submissionRoster():array
    {
        $statement=$this->db->query("SELECT u.id professor_usuario_id,u.nome professor_nome,v.turma_externa_id id,v.turma_nome_snapshot nome,v.turma_ano_letivo_snapshot ano_letivo FROM usuarios u JOIN professores p ON p.usuario_id=u.id AND p.ativo=1 LEFT JOIN vinculos_professor_turma v ON v.professor_id=p.id AND v.ativo=1 WHERE u.ativo=1 AND u.excluido_em IS NULL ORDER BY u.nome COLLATE NOCASE,v.turma_nome_snapshot COLLATE NOCASE");

        $professors=[];$requirements=[];
        foreach($statement->fetchAll()as$row){
            $userId=(int)$row['professor_usuario_id'];if(!isset($professors[$userId]))$professors[$userId]=['professor_usuario_id'=>$userId,'professor_nome'=>(string)$row['professor_nome'],'recognized'=>false];
            if($row['id']===null)continue;
            $series=$this->seriesFromName((string)$row['nome']);if($series===null)continue;
            $professors[$userId]['recognized']=true;$classId=(int)$row['id'];$key=$userId.'|'.$classId;
            if(!isset($requirements[$key])){$class=['id'=>$classId,'nome'=>(string)$row['nome'],'ano_letivo'=>(int)$row['ano_letivo']];$requirements[$key]=['professor_usuario_id'=>$userId,'professor_nome'=>(string)$row['professor_nome'],'turma_id_externo'=>$classId,'turma_nome'=>(string)$row['nome']]+$series+['turmas'=>[$classId=>$class]];}
        }

        foreach($requirements as&$requirement)$requirement['turmas']=array_values($requirement['turmas']);unset($requirement);
        usort($requirements,static fn(array$a,array$b):int=>[$a['professor_nome'],$a['ordem_etapa'],$a['ordem_serie']]<=>[$b['professor_nome'],$b['ordem_etapa'],$b['ordem_serie']]);
        $withoutSeries=[];foreach($professors as$professor)if(!$professor['recognized']){$withoutSeries[]=['professor_usuario_id'=>$professor['professor_usuario_id'],'professor_nome'=>$professor['professor_nome']];}
        return['requirements'=>array_values($requirements),'without_series'=>$withoutSeries];
    }

    private function seriesFromName(string$name):?array
    {
        $upper=mb_strtoupper(trim($name));if(!preg_match('/(?:^|\D)([1-9])\s*(?:º|°|ª)?/u',$upper,$matches))return null;$number=(int)$matches[1];$highSchool=$number<=3&&(preg_match('/\b(?:EM|ENSINO\s+M[EÉ]DIO|M[EÉ]DIO|S[EÉ]RIE)\b/u',$upper)===1||preg_match('/[1-3]\s*ª/u',$upper)===1);if($highSchool)return['etapa'=>'EM','ano_serie'=>'EM'.$number,'rotulo_etapa'=>'Ensino Médio','rotulo_serie'=>$number.'ª série','ordem_etapa'=>3,'ordem_serie'=>$number];if($number<=5)return['etapa'=>'EF_AI','ano_serie'=>'EF'.$number,'rotulo_etapa'=>'Ensino Fundamental — Anos Iniciais','rotulo_serie'=>$number.'º ano','ordem_etapa'=>1,'ordem_serie'=>$number];return['etapa'=>'EF_AF','ano_serie'=>'EF'.$number,'rotulo_etapa'=>'Ensino Fundamental — Anos Finais','rotulo_serie'=>$number.'º ano','ordem_etapa'=>2,'ordem_serie'=>$number];
    }
}

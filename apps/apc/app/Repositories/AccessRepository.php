<?php declare(strict_types=1);

namespace Apc\Repositories;

use PDO;

final class AccessRepository
{
    public function __construct(private readonly PDO $db) {}

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

    private function seriesFromName(string$name):?array
    {
        $upper=mb_strtoupper(trim($name));if(!preg_match('/(?:^|\D)([1-9])\s*(?:º|°|ª)?/u',$upper,$matches))return null;$number=(int)$matches[1];$highSchool=$number<=3&&(preg_match('/\b(?:EM|ENSINO\s+M[EÉ]DIO|M[EÉ]DIO|S[EÉ]RIE)\b/u',$upper)===1||preg_match('/[1-3]\s*ª/u',$upper)===1);if($highSchool)return['etapa'=>'EM','ano_serie'=>'EM'.$number,'rotulo_etapa'=>'Ensino Médio','rotulo_serie'=>$number.'ª série','ordem_etapa'=>3,'ordem_serie'=>$number];if($number<=5)return['etapa'=>'EF_AI','ano_serie'=>'EF'.$number,'rotulo_etapa'=>'Ensino Fundamental — Anos Iniciais','rotulo_serie'=>$number.'º ano','ordem_etapa'=>1,'ordem_serie'=>$number];return['etapa'=>'EF_AF','ano_serie'=>'EF'.$number,'rotulo_etapa'=>'Ensino Fundamental — Anos Finais','rotulo_serie'=>$number.'º ano','ordem_etapa'=>2,'ordem_serie'=>$number];
    }
}

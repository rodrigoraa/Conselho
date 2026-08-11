<?php declare(strict_types=1);

namespace Apc\Repositories;

use PDO;

final class CurriculumRepository
{
    public function __construct(public readonly PDO $db) {}

    public function components(?string $stage=null,bool $includeInactive=false): array
    {
        $where=[];$params=[];
        if($stage!==null&&$stage!==''){$where[]='etapa=:etapa';$params[':etapa']=$stage;}
        if(!$includeInactive)$where[]='ativo=1';
        $sql='SELECT c.*,(SELECT COUNT(*) FROM apc_plano_componentes pc WHERE pc.componente_id=c.id) uso_planos FROM apc_componentes_curriculares c'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY etapa,ordem,nome COLLATE NOCASE';
        $statement=$this->db->prepare($sql);$statement->execute($params);return$statement->fetchAll();
    }

    public function component(int $id): ?array
    {
        $statement=$this->db->prepare('SELECT c.*,(SELECT COUNT(*) FROM apc_plano_componentes pc WHERE pc.componente_id=c.id) uso_planos FROM apc_componentes_curriculares c WHERE c.id=:id');$statement->execute([':id'=>$id]);return$statement->fetch()?:null;
    }

    public function ability(int $id): ?array
    {
        $statement=$this->db->prepare('SELECT h.*,c.nome componente_nome,c.sigla componente_sigla,c.etapa componente_etapa,c.ativo componente_ativo,(SELECT COUNT(*) FROM apc_plano_habilidades ph WHERE ph.habilidade_id=h.id) uso_planos FROM apc_habilidades_curriculares h JOIN apc_componentes_curriculares c ON c.id=h.componente_id WHERE h.id=:id');$statement->execute([':id'=>$id]);$ability=$statement->fetch();if(!$ability)return null;$ability['associacoes']=$this->abilityAssociations($id);return$ability;
    }

    public function abilities(array $filters=[],int $limit=100,int $offset=0): array
    {
        $where=[];$params=[];
        foreach(['etapa'=>'c.etapa','componente'=>'h.componente_id','origem'=>'h.origem','escopo'=>'h.escopo']as$key=>$column)if(($filters[$key]??'')!==''){$where[]="$column=:$key";$params[":$key"]=$key==='componente'?(int)$filters[$key]:(string)$filters[$key];}
        if(($filters['ativo']??'')!==''){$where[]='h.ativo=:ativo';$params[':ativo']=(int)$filters['ativo'];}
        if(($filters['ano_serie']??'')!==''){$where[]='EXISTS(SELECT 1 FROM apc_habilidade_anos_series ha WHERE ha.habilidade_id=h.id AND ha.ano_serie=:ano)';$params[':ano']=$filters['ano_serie'];}
        if(($filters['q']??'')!==''){$where[]="(COALESCE(h.codigo,'') LIKE :q ESCAPE '\\' OR h.descricao LIKE :q ESCAPE '\\')";$params[':q']='%'.$this->escapeLike((string)$filters['q']).'%';}
        $sql='SELECT h.*,c.nome componente_nome,c.sigla componente_sigla,c.etapa componente_etapa,(SELECT GROUP_CONCAT(ha.ano_serie||\':\'||ha.tipo_associacao,\'|\') FROM apc_habilidade_anos_series ha WHERE ha.habilidade_id=h.id) associacoes,(SELECT COUNT(*) FROM apc_plano_habilidades ph WHERE ph.habilidade_id=h.id) uso_planos FROM apc_habilidades_curriculares h JOIN apc_componentes_curriculares c ON c.id=h.componente_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY c.etapa,c.ordem,COALESCE(h.codigo,\'\'),h.descricao LIMIT :limite OFFSET :offset';
        $statement=$this->db->prepare($sql);foreach($params as$key=>$value)$statement->bindValue($key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);$statement->bindValue(':limite',$limit,PDO::PARAM_INT);$statement->bindValue(':offset',$offset,PDO::PARAM_INT);$statement->execute();return$statement->fetchAll();
    }

    public function search(string $stage,string $year,int $componentId,string $query,int $limit=30): array
    {
        $sql="SELECT h.id,h.codigo,h.descricao,h.unidade_tematica,h.objeto_conhecimento,h.origem,h.escopo,c.id componente_id,c.nome componente_nome,c.sigla componente_sigla,GROUP_CONCAT(DISTINCT ha.tipo_associacao) tipos_associacao FROM apc_habilidades_curriculares h JOIN apc_componentes_curriculares c ON c.id=h.componente_id JOIN apc_habilidade_anos_series ha ON ha.habilidade_id=h.id WHERE h.ativo=1 AND c.ativo=1 AND c.etapa=:etapa AND c.id=:componente AND ha.ano_serie=:ano AND (COALESCE(h.codigo,'') LIKE :q ESCAPE '\\' OR h.descricao LIKE :q ESCAPE '\\' OR h.objeto_conhecimento LIKE :q ESCAPE '\\' OR h.unidade_tematica LIKE :q ESCAPE '\\') GROUP BY h.id ORDER BY CASE WHEN h.codigo LIKE :inicio THEN 0 ELSE 1 END,COALESCE(h.codigo,''),h.descricao LIMIT :limite";
        $statement=$this->db->prepare($sql);$needle='%'.$this->escapeLike($query).'%';$statement->bindValue(':etapa',$stage);$statement->bindValue(':componente',$componentId,PDO::PARAM_INT);$statement->bindValue(':ano',$year);$statement->bindValue(':q',$needle);$statement->bindValue(':inicio',$this->escapeLike($query).'%');$statement->bindValue(':limite',$limit,PDO::PARAM_INT);$statement->execute();return$statement->fetchAll();
    }

    public function planCurriculum(int $planId): array
    {
        $componentStatement=$this->db->prepare('SELECT pc.*,c.etapa,c.modalidade,c.ativo FROM apc_plano_componentes pc JOIN apc_componentes_curriculares c ON c.id=pc.componente_id WHERE pc.plano_id=:plano ORDER BY c.ordem,c.nome');$componentStatement->execute([':plano'=>$planId]);
        $abilityStatement=$this->db->prepare('SELECT ph.*,h.unidade_tematica,h.objeto_conhecimento,h.origem,h.escopo,h.ativo FROM apc_plano_habilidades ph JOIN apc_habilidades_curriculares h ON h.id=ph.habilidade_id WHERE ph.plano_id=:plano ORDER BY ph.componente_nome_snapshot,COALESCE(ph.habilidade_codigo_snapshot,\'\'),ph.id');$abilityStatement->execute([':plano'=>$planId]);
        return['componentes'=>$componentStatement->fetchAll(),'habilidades'=>$abilityStatement->fetchAll()];
    }

    public function selectableComponents(array $ids,int $planId=0): array
    {
        if(!$ids)return[];$placeholders=implode(',',array_fill(0,count($ids),'?'));$params=array_values($ids);$sql="SELECT * FROM apc_componentes_curriculares c WHERE c.id IN($placeholders) AND (c.ativo=1".($planId>0?' OR EXISTS(SELECT 1 FROM apc_plano_componentes pc WHERE pc.plano_id=? AND pc.componente_id=c.id)':'').')';if($planId>0)$params[]=$planId;$statement=$this->db->prepare($sql);$statement->execute($params);return$statement->fetchAll();
    }

    public function selectableAbilities(array $ids,int $planId=0): array
    {
        if(!$ids)return[];$placeholders=implode(',',array_fill(0,count($ids),'?'));$params=array_values($ids);$sql="SELECT h.*,c.nome componente_nome,c.sigla componente_sigla FROM apc_habilidades_curriculares h JOIN apc_componentes_curriculares c ON c.id=h.componente_id WHERE h.id IN($placeholders) AND (h.ativo=1".($planId>0?' OR EXISTS(SELECT 1 FROM apc_plano_habilidades ph WHERE ph.plano_id=? AND ph.habilidade_id=h.id)':'').')';if($planId>0)$params[]=$planId;$statement=$this->db->prepare($sql);$statement->execute($params);return$statement->fetchAll();
    }

    public function abilityIdsAllowedForYear(array $ids,string $year): array
    {
        if(!$ids)return[];$placeholders=implode(',',array_fill(0,count($ids),'?'));$statement=$this->db->prepare("SELECT DISTINCT habilidade_id FROM apc_habilidade_anos_series WHERE habilidade_id IN($placeholders) AND ano_serie=?");$statement->execute(array_merge(array_values($ids),[$year]));return array_map('intval',$statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function syncPlan(int $planId,array $components,array $abilities): void
    {
        $componentIds=array_map(static fn(array$row):int=>(int)$row['id'],$components);$abilityIds=array_map(static fn(array$row):int=>(int)$row['id'],$abilities);
        $this->deleteMissing('apc_plano_habilidades','habilidade_id',$planId,$abilityIds);
        $this->deleteMissing('apc_plano_componentes','componente_id',$planId,$componentIds);
        $componentStatement=$this->db->prepare('INSERT INTO apc_plano_componentes(plano_id,componente_id,componente_nome_snapshot,componente_sigla_snapshot)VALUES(:plano,:componente,:nome,:sigla) ON CONFLICT(plano_id,componente_id) DO UPDATE SET componente_nome_snapshot=excluded.componente_nome_snapshot,componente_sigla_snapshot=excluded.componente_sigla_snapshot');
        foreach($components as$component)$componentStatement->execute([':plano'=>$planId,':componente'=>$component['id'],':nome'=>$component['nome'],':sigla'=>$component['sigla']]);
        $abilityStatement=$this->db->prepare('INSERT INTO apc_plano_habilidades(plano_id,habilidade_id,componente_id,habilidade_codigo_snapshot,habilidade_descricao_snapshot,componente_nome_snapshot)VALUES(:plano,:habilidade,:componente,:codigo,:descricao,:nome) ON CONFLICT(plano_id,habilidade_id) DO UPDATE SET habilidade_codigo_snapshot=excluded.habilidade_codigo_snapshot,habilidade_descricao_snapshot=excluded.habilidade_descricao_snapshot,componente_nome_snapshot=excluded.componente_nome_snapshot');
        foreach($abilities as$ability)$abilityStatement->execute([':plano'=>$planId,':habilidade'=>$ability['id'],':componente'=>$ability['componente_id'],':codigo'=>$ability['codigo'],':descricao'=>$ability['descricao'],':nome'=>$ability['componente_nome']]);
    }

    public function upsertComponent(array $data): int
    {
        $statement=$this->db->prepare('INSERT INTO apc_componentes_curriculares(chave,nome,sigla,modalidade,etapa,area_conhecimento,ativo,ordem)VALUES(:chave,:nome,:sigla,:modalidade,:etapa,:area,:ativo,:ordem) ON CONFLICT(chave) DO UPDATE SET nome=excluded.nome,sigla=excluded.sigla,modalidade=excluded.modalidade,etapa=excluded.etapa,area_conhecimento=excluded.area_conhecimento,ordem=excluded.ordem,atualizado_em=CURRENT_TIMESTAMP');$statement->execute([':chave'=>$data['chave'],':nome'=>$data['nome'],':sigla'=>$data['sigla'],':modalidade'=>$data['modalidade'],':etapa'=>$data['etapa'],':area'=>$data['area_conhecimento'],':ativo'=>$data['ativo'],':ordem'=>$data['ordem']]);$find=$this->db->prepare('SELECT id FROM apc_componentes_curriculares WHERE chave=:chave');$find->execute([':chave'=>$data['chave']]);return(int)$find->fetchColumn();
    }

    public function upsertAbility(array $data): int
    {
        $statement=$this->db->prepare('INSERT INTO apc_habilidades_curriculares(chave_estavel,componente_id,codigo,descricao,unidade_tematica,objeto_conhecimento,origem,escopo,fonte_documento,fonte_pagina,ativo)VALUES(:chave,:componente,:codigo,:descricao,:unidade,:objeto,:origem,:escopo,:documento,:pagina,:ativo) ON CONFLICT(chave_estavel) DO UPDATE SET componente_id=excluded.componente_id,codigo=excluded.codigo,descricao=excluded.descricao,unidade_tematica=excluded.unidade_tematica,objeto_conhecimento=excluded.objeto_conhecimento,origem=excluded.origem,escopo=excluded.escopo,fonte_documento=excluded.fonte_documento,fonte_pagina=excluded.fonte_pagina,atualizado_em=CURRENT_TIMESTAMP');$statement->execute([':chave'=>$data['chave_estavel'],':componente'=>$data['componente_id'],':codigo'=>$data['codigo'],':descricao'=>$data['descricao'],':unidade'=>$data['unidade_tematica'],':objeto'=>$data['objeto_conhecimento'],':origem'=>$data['origem'],':escopo'=>$data['escopo'],':documento'=>$data['fonte_documento'],':pagina'=>$data['fonte_pagina'],':ativo'=>$data['ativo']??1]);$find=$this->db->prepare('SELECT id FROM apc_habilidades_curriculares WHERE chave_estavel=:chave');$find->execute([':chave'=>$data['chave_estavel']]);return(int)$find->fetchColumn();
    }

    public function syncAbilityAssociations(int $abilityId,array $associations): void
    {
        $this->db->prepare('DELETE FROM apc_habilidade_anos_series WHERE habilidade_id=:id')->execute([':id'=>$abilityId]);$statement=$this->db->prepare('INSERT INTO apc_habilidade_anos_series(habilidade_id,etapa,ano_serie,tipo_associacao)VALUES(:habilidade,:etapa,:ano,:tipo)');foreach($associations as$association)$statement->execute([':habilidade'=>$abilityId,':etapa'=>$association['etapa'],':ano'=>$association['ano_serie'],':tipo'=>$association['tipo_associacao']]);
    }

    public function addAbilityAssociation(int $abilityId,array $association): void
    {
        $this->db->prepare('INSERT OR IGNORE INTO apc_habilidade_anos_series(habilidade_id,etapa,ano_serie,tipo_associacao)VALUES(:habilidade,:etapa,:ano,:tipo)')->execute([':habilidade'=>$abilityId,':etapa'=>$association['etapa'],':ano'=>$association['ano_serie'],':tipo'=>$association['tipo_associacao']]);
    }

    public function updateComponent(int $id,array $data): void
    {
        $statement=$this->db->prepare('UPDATE apc_componentes_curriculares SET nome=:nome,sigla=:sigla,modalidade=:modalidade,etapa=:etapa,area_conhecimento=:area,ordem=:ordem,ativo=:ativo,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id');$statement->execute([':nome'=>$data['nome'],':sigla'=>$data['sigla'],':modalidade'=>$data['modalidade'],':etapa'=>$data['etapa'],':area'=>$data['area_conhecimento'],':ordem'=>$data['ordem'],':ativo'=>$data['ativo'],':id'=>$id]);
    }

    public function updateAbility(int $id,array $data): void
    {
        $statement=$this->db->prepare('UPDATE apc_habilidades_curriculares SET componente_id=:componente,codigo=:codigo,descricao=:descricao,unidade_tematica=:unidade,objeto_conhecimento=:objeto,origem=:origem,escopo=:escopo,fonte_documento=:documento,fonte_pagina=:pagina,ativo=:ativo,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id');$statement->execute([':componente'=>$data['componente_id'],':codigo'=>$data['codigo'],':descricao'=>$data['descricao'],':unidade'=>$data['unidade_tematica'],':objeto'=>$data['objeto_conhecimento'],':origem'=>$data['origem'],':escopo'=>$data['escopo'],':documento'=>$data['fonte_documento'],':pagina'=>$data['fonte_pagina'],':ativo'=>$data['ativo'],':id'=>$id]);
    }

    public function setComponentActive(int $id,bool $active): void
    {
        $this->db->prepare('UPDATE apc_componentes_curriculares SET ativo=:ativo,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id')->execute([':ativo'=>$active?1:0,':id'=>$id]);
    }

    public function setAbilityActive(int $id,bool $active): void
    {
        $this->db->prepare('UPDATE apc_habilidades_curriculares SET ativo=:ativo,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id')->execute([':ativo'=>$active?1:0,':id'=>$id]);
    }

    private function abilityAssociations(int $id): array
    {
        $statement=$this->db->prepare('SELECT * FROM apc_habilidade_anos_series WHERE habilidade_id=:id ORDER BY etapa,ano_serie,tipo_associacao');$statement->execute([':id'=>$id]);return$statement->fetchAll();
    }

    private function deleteMissing(string $table,string $column,int $planId,array $ids): void
    {
        if(!$ids){$this->db->prepare("DELETE FROM $table WHERE plano_id=:plano")->execute([':plano'=>$planId]);return;}$placeholders=implode(',',array_fill(0,count($ids),'?'));$statement=$this->db->prepare("DELETE FROM $table WHERE plano_id=? AND $column NOT IN($placeholders)");$statement->execute(array_merge([$planId],array_values($ids)));
    }

    private function escapeLike(string $value): string{return str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$value);}
}

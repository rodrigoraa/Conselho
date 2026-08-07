<?php declare(strict_types=1);

namespace PreConselho\Services;

use PreConselho\Repositories\AppRepository;
use PreConselho\Support\CouncilClass;
use Shared\Exceptions\HttpException;
use Throwable;

final class CouncilDocumentService
{
    private const LOCK_SECONDS=60;
    private const OPENING_TEMPLATE='No dia ___ de __________ de ______, às ______ horas, reuniram-se nas dependências da Escola Estadual São José a direção, a coordenação pedagógica e os professores do turno __________ para deliberar sobre o Conselho de Classe referente ao __________ bimestre. Foram tratados assuntos relacionados à aprendizagem dos estudantes. A diretora Claudia Regina realizou a abertura, dando as boas-vindas e agradecendo a presença de todos. A seguir, foram registradas as observações das turmas.';

    public function __construct(private readonly AppRepository $repository) {}

    public function synchronizePeriod(int $periodId): void
    {
        $period=$this->period($periodId);
        if(!in_array($period['status'],['RASCUNHO','ABERTO'],true))return;
        $db=$this->repository->db;
        $db->prepare('INSERT OR IGNORE INTO documento_aberturas(periodo_id,texto)VALUES(:periodo,:texto)')->execute([':periodo'=>$periodId,':texto'=>$this->openingTemplate($period['turno'])]);
        $db->prepare("INSERT OR IGNORE INTO documento_turmas(periodo_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot) SELECT :periodo,v.turma_externa_id,v.turma_nome_snapshot,v.turma_ano_letivo_snapshot FROM vinculos_professor_turma v JOIN professores pr ON pr.id=v.professor_id JOIN usuarios u ON u.id=pr.usuario_id WHERE v.turno=:turno AND v.ativo=1 AND pr.ativo=1 AND u.ativo=1 AND u.excluido_em IS NULL GROUP BY v.turma_externa_id,v.turma_nome_snapshot,v.turma_ano_letivo_snapshot")->execute([':periodo'=>$periodId,':turno'=>$period['turno']]);
        $db->prepare("INSERT OR IGNORE INTO documento_turma_professores(documento_turma_id,professor_usuario_id) SELECT dt.id,pr.usuario_id FROM documento_turmas dt JOIN vinculos_professor_turma v ON v.turma_externa_id=dt.turma_externa_id AND v.turno=:turno JOIN professores pr ON pr.id=v.professor_id JOIN usuarios u ON u.id=pr.usuario_id WHERE dt.periodo_id=:periodo AND v.ativo=1 AND pr.ativo=1 AND u.ativo=1 AND u.excluido_em IS NULL GROUP BY dt.id,pr.usuario_id")->execute([':periodo'=>$periodId,':turno'=>$period['turno']]);
    }

    public function summaries(int $actorId,string $role): array
    {
        foreach($this->repository->db->query("SELECT id FROM periodos_pre_conselho WHERE status='ABERTO'")->fetchAll(\PDO::FETCH_COLUMN) as$periodId)$this->synchronizePeriod((int)$periodId);
        if($role==='PROFESSOR'){
            $statement=$this->repository->db->prepare("SELECT p.id periodo_id,p.nome periodo,p.ano_letivo,p.turno,p.data_fim,p.status periodo_status,(SELECT COUNT(*) FROM documento_turmas dt WHERE dt.periodo_id=p.id) total_turmas,(SELECT COUNT(*) FROM documento_turma_professores c JOIN documento_turmas dt ON dt.id=c.documento_turma_id WHERE dt.periodo_id=p.id AND c.professor_usuario_id=:usuario) minhas_turmas,(SELECT COUNT(*) FROM documento_turma_professores c JOIN documento_turmas dt ON dt.id=c.documento_turma_id WHERE dt.periodo_id=p.id AND c.professor_usuario_id=:usuario AND c.finalizado=1) finalizadas FROM periodos_pre_conselho p WHERE EXISTS(SELECT 1 FROM documento_turma_professores c JOIN documento_turmas dt ON dt.id=c.documento_turma_id WHERE dt.periodo_id=p.id AND c.professor_usuario_id=:usuario) ORDER BY CASE p.status WHEN 'ABERTO' THEN 0 ELSE 1 END,p.data_fim DESC,p.id DESC");
            $statement->execute([':usuario'=>$actorId]);
            return$statement->fetchAll();
        }
        return$this->repository->db->query("SELECT p.id periodo_id,p.nome periodo,p.ano_letivo,p.turno,p.data_fim,p.status periodo_status,COUNT(DISTINCT dt.id) total_turmas,COUNT(DISTINCT c.professor_usuario_id) professores,COUNT(c.id) contribuicoes,COALESCE(SUM(c.finalizado),0) finalizadas FROM periodos_pre_conselho p JOIN documento_turmas dt ON dt.periodo_id=p.id LEFT JOIN documento_turma_professores c ON c.documento_turma_id=dt.id GROUP BY p.id ORDER BY CASE p.status WHEN 'ABERTO' THEN 0 ELSE 1 END,p.data_fim DESC,p.id DESC")->fetchAll();
    }

    public function document(int $periodId,int $actorId,string $role): array
    {
        $this->synchronizePeriod($periodId);
        $period=$this->period($periodId);
        $mine=$this->repository->db->prepare('SELECT COUNT(*) FROM documento_turma_professores c JOIN documento_turmas dt ON dt.id=c.documento_turma_id WHERE dt.periodo_id=:periodo AND c.professor_usuario_id=:usuario');
        $mine->execute([':periodo'=>$periodId,':usuario'=>$actorId]);
        if($role==='PROFESSOR'&&(int)$mine->fetchColumn()===0)throw new HttpException(403,'FORBIDDEN','Você não possui turmas neste período.');

        $statement=$this->repository->db->prepare("SELECT dt.*,up.nome atualizado_por_nome,COALESCE(mine.finalizado,0) meu_finalizado,CASE WHEN mine.id IS NULL THEN 0 ELSE 1 END minha_turma FROM documento_turmas dt LEFT JOIN usuarios up ON up.id=dt.atualizado_por LEFT JOIN documento_turma_professores mine ON mine.documento_turma_id=dt.id AND mine.professor_usuario_id=:usuario WHERE dt.periodo_id=:periodo ORDER BY dt.turma_nome_snapshot COLLATE NOCASE,dt.id");
        $statement->execute([':usuario'=>$actorId,':periodo'=>$periodId]);
        $classes=$statement->fetchAll();
        if($classes===[])throw new HttpException(404,'DOCUMENT_NOT_FOUND','O documento coletivo ainda não possui turmas.');
        foreach($classes as&$class){$identity=CouncilClass::identify((string)$class['turma_nome_snapshot']);$class['nome_conselho']=$identity['name'];$class['ordem_conselho']=$identity['order'];}unset($class);
        usort($classes,static fn(array$a,array$b):int=>[$a['ordem_conselho'],mb_strtolower((string)$a['turma_nome_snapshot']),(int)$a['id']]<=>[$b['ordem_conselho'],mb_strtolower((string)$b['turma_nome_snapshot']),(int)$b['id']]);

        $editStatement=$this->repository->db->prepare("SELECT edit.*,u.nome autor_nome_atual FROM documento_turma_edicoes edit JOIN documento_turmas dt ON dt.id=edit.documento_turma_id LEFT JOIN usuarios u ON u.id=edit.autor_usuario_id WHERE dt.periodo_id=:periodo ORDER BY edit.documento_turma_id,edit.id");
        $editStatement->execute([':periodo'=>$periodId]);$editsByClass=[];
        foreach($editStatement->fetchAll()as$edit)$editsByClass[(int)$edit['documento_turma_id']][]=$edit;
        $segmentStatement=$this->repository->db->prepare("SELECT segment.*,u.nome autor_nome_atual FROM documento_turma_segmentos segment JOIN documento_turmas dt ON dt.id=segment.documento_turma_id LEFT JOIN usuarios u ON u.id=segment.autor_usuario_id WHERE dt.periodo_id=:periodo AND segment.conteudo<>'' ORDER BY segment.documento_turma_id,segment.ordem");
        $segmentStatement->execute([':periodo'=>$periodId]);$segmentsByClass=[];
        foreach($segmentStatement->fetchAll()as$segment)$segmentsByClass[(int)$segment['documento_turma_id']][]=$segment;
        $lockStatement=$this->repository->db->prepare('SELECT lock.* FROM documento_turma_bloqueios lock JOIN documento_turmas dt ON dt.id=lock.documento_turma_id WHERE dt.periodo_id=:periodo AND lock.expira_em>CURRENT_TIMESTAMP');$lockStatement->execute([':periodo'=>$periodId]);$locksByClass=[];
        foreach($lockStatement->fetchAll()as$lock)$locksByClass[(int)$lock['documento_turma_id']]=$lock;
        foreach($classes as&$class){$class['edicoes']=$editsByClass[(int)$class['id']]??[];$class['segmentos']=$segmentsByClass[(int)$class['id']]??[];$class['bloqueio']=$locksByClass[(int)$class['id']]??null;}
        unset($class);

        $completion=$this->repository->db->prepare("SELECT c.*,u.nome professor_nome,dt.turma_externa_id,dt.turma_nome_snapshot FROM documento_turma_professores c JOIN documento_turmas dt ON dt.id=c.documento_turma_id JOIN usuarios u ON u.id=c.professor_usuario_id WHERE dt.periodo_id=:periodo ORDER BY dt.turma_nome_snapshot COLLATE NOCASE,u.nome COLLATE NOCASE");
        $completion->execute([':periodo'=>$periodId]);
        $byClass=[];
        foreach($completion->fetchAll()as$row)$byClass[(int)$row['documento_turma_id']][]=$row;
        $openingStatement=$this->repository->db->prepare('SELECT da.*,u.nome atualizado_por_nome FROM documento_aberturas da LEFT JOIN usuarios u ON u.id=da.atualizado_por WHERE da.periodo_id=:periodo');$openingStatement->execute([':periodo'=>$periodId]);
        $opening=$openingStatement->fetch()?:['periodo_id'=>$periodId,'texto'=>'','versao'=>1,'atualizado_por'=>null,'atualizado_por_nome'=>null,'atualizado_em'=>null];
        return compact('period','classes','opening')+['conclusoes'=>$byClass];
    }

    public function saveOpening(int $periodId,string $text,int $version,int $actorId,string $role): array
    {
        if(!in_array($role,['ADMIN','COORDENADOR'],true))throw new HttpException(403,'FORBIDDEN','Somente a coordenação e a administração podem editar a abertura da ata.');
        $period=$this->period($periodId);
        if($period['status']!=='ABERTO')throw new HttpException(422,'DOCUMENT_LOCKED','A abertura só pode ser editada durante o período aberto.');
        $text=trim($text);
        if(mb_strlen($text)>12000)throw new HttpException(422,'TEXT_TOO_LONG','O texto de abertura excedeu o limite permitido.');
        $statement=$this->repository->db->prepare('UPDATE documento_aberturas SET texto=:texto,versao=versao+1,atualizado_por=:usuario,atualizado_em=CURRENT_TIMESTAMP WHERE periodo_id=:periodo AND versao=:versao');
        $statement->execute([':texto'=>$text,':usuario'=>$actorId,':periodo'=>$periodId,':versao'=>$version]);
        if($statement->rowCount()!==1)throw new HttpException(409,'VERSION_CONFLICT','A abertura foi atualizada em outra sessão. Recarregue a página.');
        $fresh=$this->repository->db->prepare('SELECT da.versao,da.atualizado_em,u.nome atualizado_por_nome FROM documento_aberturas da JOIN usuarios u ON u.id=da.atualizado_por WHERE da.periodo_id=:periodo');$fresh->execute([':periodo'=>$periodId]);$saved=$fresh->fetch();
        return['version'=>(int)$saved['versao'],'saved_at'=>date('H:i',strtotime($saved['atualizado_em'])),'updated_by'=>$saved['atualizado_por_nome']];
    }

    public function acquireClassLock(int $periodId,int $classDocumentId,int $actorId,string $role,string $currentToken=''): array
    {
        $row=$this->editableClass($periodId,$classDocumentId,$actorId,$role);
        if($row['periodo_status']!=='ABERTO')throw new HttpException(422,'DOCUMENT_LOCKED','Este período não está aberto para preenchimento.');
        if($role==='PROFESSOR'&&(bool)$row['finalizado'])throw new HttpException(422,'CLASS_FINALIZED','A coordenação ou administração precisa liberar uma nova edição.');
        $db=$this->repository->db;$db->beginTransaction();
        try{
            $author=$db->prepare('SELECT nome FROM usuarios WHERE id=:id');$author->execute([':id'=>$actorId]);$authorName=(string)$author->fetchColumn();
            $requestedToken=$currentToken!==''?$currentToken:bin2hex(random_bytes(32));$expires=gmdate('Y-m-d H:i:s',time()+self::LOCK_SECONDS);
            $upsert=$db->prepare('INSERT INTO documento_turma_bloqueios(documento_turma_id,usuario_id,usuario_nome_snapshot,token,expira_em)VALUES(:turma,:usuario,:nome,:token,:expira) ON CONFLICT(documento_turma_id) DO UPDATE SET usuario_id=excluded.usuario_id,usuario_nome_snapshot=excluded.usuario_nome_snapshot,token=CASE WHEN documento_turma_bloqueios.usuario_id=excluded.usuario_id THEN documento_turma_bloqueios.token ELSE excluded.token END,expira_em=excluded.expira_em,atualizado_em=CURRENT_TIMESTAMP WHERE documento_turma_bloqueios.expira_em<=CURRENT_TIMESTAMP OR documento_turma_bloqueios.usuario_id=excluded.usuario_id');
            $upsert->execute([':turma'=>$classDocumentId,':usuario'=>$actorId,':nome'=>$authorName,':token'=>$requestedToken,':expira'=>$expires]);
            $statement=$db->prepare('SELECT * FROM documento_turma_bloqueios WHERE documento_turma_id=:turma');$statement->execute([':turma'=>$classDocumentId]);$lock=$statement->fetch();$db->commit();
            if((int)$lock['usuario_id']!==$actorId)return['acquired'=>false,'locked_by'=>$lock['usuario_nome_snapshot'],'expires_at'=>$lock['expira_em']];
            return['acquired'=>true,'token'=>$lock['token'],'locked_by'=>$authorName,'expires_at'=>$lock['expira_em'],'ttl'=>self::LOCK_SECONDS];
        }catch(Throwable$exception){if($db->inTransaction())$db->rollBack();throw$exception;}
    }

    public function releaseClassLock(int $periodId,int $classDocumentId,int $actorId,string $token): void
    {
        $statement=$this->repository->db->prepare('DELETE FROM documento_turma_bloqueios WHERE documento_turma_id=:turma AND usuario_id=:usuario AND token=:token AND EXISTS(SELECT 1 FROM documento_turmas dt WHERE dt.id=:turma AND dt.periodo_id=:periodo)');
        $statement->execute([':turma'=>$classDocumentId,':usuario'=>$actorId,':token'=>$token,':periodo'=>$periodId]);
    }

    public function collaborationState(int $periodId,int $classDocumentId,int $actorId,string $role): array
    {
        $row=$this->editableClass($periodId,$classDocumentId,$actorId,$role);
        if($row['periodo_status']!=='ABERTO')throw new HttpException(422,'DOCUMENT_LOCKED','Este período não está aberto para preenchimento.');
        if($role==='PROFESSOR'&&(bool)$row['finalizado'])throw new HttpException(422,'CLASS_FINALIZED','A coordenação ou administração precisa liberar uma nova edição.');
        return['period'=>$periodId,'class'=>$classDocumentId,'content'=>(string)$row['conteudo'],'version'=>(int)$row['versao']];
    }

    public function saveClass(int $periodId,int $classDocumentId,string $content,int $version,int $actorId,string $role,string $ip,string $userAgent,array $operations=[],string $lockToken=''): array
    {
        if(!in_array($role,['PROFESSOR','COORDENADOR','ADMIN'],true))throw new HttpException(403,'FORBIDDEN','Você não pode editar as turmas.');
        $content=str_replace(["\r\n","\r"],"\n",$content);
        if(mb_strlen($content)>60000)throw new HttpException(422,'TEXT_TOO_LONG','O texto da turma excedeu o limite permitido.');
        $row=$this->editableClass($periodId,$classDocumentId,$actorId,$role);
        if($row['periodo_status']!=='ABERTO')throw new HttpException(422,'DOCUMENT_LOCKED','Este período não está aberto para preenchimento.');
        if($role==='PROFESSOR'&&(bool)$row['finalizado'])throw new HttpException(422,'CLASS_FINALIZED','A coordenação ou administração precisa liberar uma nova edição.');
        if($lockToken!=='')$this->refreshClassLock($classDocumentId,$actorId,$lockToken);
        if($version!==(int)$row['versao'])throw new HttpException(409,'VERSION_CONFLICT','O texto desta turma foi atualizado por outro professor. Recarregue a página antes de continuar.');
        $oldContent=str_replace(["\r\n","\r"],"\n",(string)$row['conteudo']);
        if($content===$oldContent)return['version'=>(int)$row['versao'],'saved_at'=>date('H:i',strtotime((string)$row['atualizado_em'])),'updated_by'=>null];
        $author=$this->repository->db->prepare('SELECT nome FROM usuarios WHERE id=:id');$author->execute([':id'=>$actorId]);$authorName=(string)$author->fetchColumn();
        $segments=$this->segmentsForClass($classDocumentId,$oldContent);
        if($operations===[]){
            $insertions=$this->insertionChunks($oldContent,$content);
            if($insertions===null)throw new HttpException(422,'SAVED_TEXT_PROTECTED','Você só pode alterar os trechos que escreveu. Recarregue a página e tente novamente.');
            $offset=0;foreach($insertions as$insertion){$operations[]=['start'=>$insertion['position']+$offset,'delete'=>0,'insert'=>$insertion['text']];$offset+=mb_strlen($insertion['text']);}
        }
        [$newSegments,$insertions]=$this->applyOwnedOperations($segments,$operations,$actorId,$authorName,$content,in_array($role,['ADMIN','COORDENADOR'],true));

        $db=$this->repository->db;$db->beginTransaction();
        try{
            $statement=$db->prepare('UPDATE documento_turmas SET conteudo=:conteudo,versao=versao+1,atualizado_por=:usuario,atualizado_em=CURRENT_TIMESTAMP WHERE id=:turma AND periodo_id=:periodo AND versao=:versao');
            $statement->execute([':conteudo'=>$content,':usuario'=>$actorId,':turma'=>$classDocumentId,':periodo'=>$periodId,':versao'=>$version]);
            if($statement->rowCount()!==1)throw new HttpException(409,'VERSION_CONFLICT','O texto desta turma foi atualizado por outro professor. Recarregue a página antes de continuar.');
            $fresh=$db->prepare('SELECT versao,atualizado_em FROM documento_turmas WHERE id=:turma');$fresh->execute([':turma'=>$classDocumentId]);$saved=$fresh->fetch();
            $db->prepare('DELETE FROM documento_turma_segmentos WHERE documento_turma_id=:turma')->execute([':turma'=>$classDocumentId]);
            $segmentInsert=$db->prepare('INSERT INTO documento_turma_segmentos(documento_turma_id,ordem,autor_usuario_id,autor_nome_snapshot,conteudo)VALUES(:turma,:ordem,:usuario,:autor,:conteudo)');
            foreach($newSegments as$order=>$segment)$segmentInsert->execute([':turma'=>$classDocumentId,':ordem'=>$order+1,':usuario'=>$segment['author_id'],':autor'=>$segment['author_name'],':conteudo'=>$segment['text']]);
            $history=$db->prepare('INSERT INTO documento_turma_edicoes(documento_turma_id,autor_usuario_id,autor_nome_snapshot,texto_inserido,posicao,versao_resultante)VALUES(:turma,:usuario,:autor,:texto,:posicao,:versao)');
            foreach($insertions as$insertion)$history->execute([':turma'=>$classDocumentId,':usuario'=>$actorId,':autor'=>$authorName,':texto'=>$insertion['text'],':posicao'=>$insertion['position'],':versao'=>$saved['versao']]);
            $db->commit();
            return['version'=>(int)$saved['versao'],'saved_at'=>date('H:i',strtotime($saved['atualizado_em'])),'updated_by'=>$authorName];
        }catch(Throwable$exception){if($db->inTransaction())$db->rollBack();throw$exception;}
    }

    public function finalizeClass(int $periodId,int $classDocumentId,int $actorId,string $role,bool $finalize,string $ip,string $userAgent): void
    {
        if($role!=='PROFESSOR')throw new HttpException(403,'FORBIDDEN','Somente professores podem finalizar sua participação.');
        if(!$finalize)throw new HttpException(403,'REOPEN_REQUIRES_COORDINATION','Somente a coordenação ou a administração pode liberar uma nova edição.');
        $row=$this->editableClass($periodId,$classDocumentId,$actorId,$role);
        if($row['periodo_status']!=='ABERTO')throw new HttpException(422,'DOCUMENT_LOCKED','Este período não está aberto.');
        if($finalize&&trim((string)$row['conteudo'])==='')throw new HttpException(422,'CONTENT_REQUIRED','Escreva no texto da turma antes de finalizar.');
        $db=$this->repository->db;$db->beginTransaction();
        try{
            $db->prepare('UPDATE documento_turma_professores SET finalizado=:finalizado,finalizado_em=CASE WHEN :finalizado=1 THEN CURRENT_TIMESTAMP ELSE NULL END,atualizado_em=CURRENT_TIMESTAMP WHERE documento_turma_id=:turma AND professor_usuario_id=:usuario')->execute([':finalizado'=>$finalize?1:0,':turma'=>$classDocumentId,':usuario'=>$actorId]);
            $db->prepare('DELETE FROM documento_turma_bloqueios WHERE documento_turma_id=:turma AND usuario_id=:usuario')->execute([':turma'=>$classDocumentId,':usuario'=>$actorId]);
            $this->repository->audit($actorId,$finalize?'FINALIZAR_TURMA':'REABRIR_TURMA','documento_turmas',$classDocumentId,['finalizado'=>(bool)$row['finalizado']],['finalizado'=>$finalize],$ip,$userAgent);
            $db->commit();
        }catch(Throwable$exception){if($db->inTransaction())$db->rollBack();throw$exception;}
    }

    public function reopenParticipation(int $periodId,int $classDocumentId,int $teacherId,int $actorId,string $role,string $ip,string $userAgent): void
    {
        if(!in_array($role,['ADMIN','COORDENADOR'],true))throw new HttpException(403,'FORBIDDEN','Somente a coordenação ou a administração pode liberar uma nova edição.');
        $statement=$this->repository->db->prepare('SELECT c.finalizado,p.status periodo_status FROM documento_turma_professores c JOIN documento_turmas dt ON dt.id=c.documento_turma_id JOIN periodos_pre_conselho p ON p.id=dt.periodo_id WHERE c.documento_turma_id=:turma AND c.professor_usuario_id=:professor AND dt.periodo_id=:periodo');
        $statement->execute([':turma'=>$classDocumentId,':professor'=>$teacherId,':periodo'=>$periodId]);$row=$statement->fetch();
        if(!$row)throw new HttpException(404,'PARTICIPATION_NOT_FOUND','Participação do professor não encontrada nesta turma.');
        if($row['periodo_status']!=='ABERTO')throw new HttpException(422,'DOCUMENT_LOCKED','O período precisa estar aberto para liberar uma nova edição.');
        $db=$this->repository->db;$db->beginTransaction();
        try{
            $db->prepare('UPDATE documento_turma_professores SET finalizado=0,finalizado_em=NULL,atualizado_em=CURRENT_TIMESTAMP WHERE documento_turma_id=:turma AND professor_usuario_id=:professor')->execute([':turma'=>$classDocumentId,':professor'=>$teacherId]);
            $this->repository->audit($actorId,'LIBERAR_REEDICAO_TURMA','documento_turma_professores',$classDocumentId,['professor_usuario_id'=>$teacherId,'finalizado'=>(bool)$row['finalizado']],['professor_usuario_id'=>$teacherId,'finalizado'=>false],$ip,$userAgent);
            $db->commit();
        }catch(Throwable$exception){if($db->inTransaction())$db->rollBack();throw$exception;}
    }

    private function editableClass(int $periodId,int $classDocumentId,int $actorId,string $role): array
    {
        if(in_array($role,['ADMIN','COORDENADOR'],true)){
            $statement=$this->repository->db->prepare('SELECT dt.*,0 finalizado,p.status periodo_status FROM documento_turmas dt JOIN periodos_pre_conselho p ON p.id=dt.periodo_id WHERE dt.id=:turma AND dt.periodo_id=:periodo');
            $statement->execute([':turma'=>$classDocumentId,':periodo'=>$periodId]);
            return$statement->fetch()?:throw new HttpException(404,'CLASS_NOT_FOUND','Turma não encontrada neste documento.');
        }
        if($role!=='PROFESSOR')throw new HttpException(403,'FORBIDDEN','Você não pode editar esta turma.');
        $statement=$this->repository->db->prepare('SELECT dt.*,c.finalizado,p.status periodo_status FROM documento_turmas dt JOIN documento_turma_professores c ON c.documento_turma_id=dt.id AND c.professor_usuario_id=:usuario JOIN periodos_pre_conselho p ON p.id=dt.periodo_id WHERE dt.id=:turma AND dt.periodo_id=:periodo');
        $statement->execute([':usuario'=>$actorId,':turma'=>$classDocumentId,':periodo'=>$periodId]);
        return$statement->fetch()?:throw new HttpException(403,'FORBIDDEN','Você não leciona nesta turma.');
    }

    private function refreshClassLock(int $classDocumentId,int $actorId,string $token): void
    {
        $expires=gmdate('Y-m-d H:i:s',time()+self::LOCK_SECONDS);$statement=$this->repository->db->prepare('UPDATE documento_turma_bloqueios SET expira_em=:expira,atualizado_em=CURRENT_TIMESTAMP WHERE documento_turma_id=:turma AND usuario_id=:usuario AND token=:token AND expira_em>CURRENT_TIMESTAMP');
        $statement->execute([':expira'=>$expires,':turma'=>$classDocumentId,':usuario'=>$actorId,':token'=>$token]);
        if($statement->rowCount()!==1)throw new HttpException(423,'CLASS_LOCK_REQUIRED','O bloqueio de edição expirou ou pertence a outro usuário. Reabra a turma para continuar.');
    }

    private function segmentsForClass(int $classDocumentId,string $oldContent): array
    {
        $statement=$this->repository->db->prepare('SELECT autor_usuario_id,autor_nome_snapshot,conteudo FROM documento_turma_segmentos WHERE documento_turma_id=:turma AND conteudo<>\'\' ORDER BY ordem');
        $statement->execute([':turma'=>$classDocumentId]);$segments=$statement->fetchAll();
        $stored=implode('',array_column($segments,'conteudo'));
        if($stored===$oldContent)return$segments;
        return$oldContent===''?[]:[['autor_usuario_id'=>null,'autor_nome_snapshot'=>'Conteúdo anterior ao controle por trecho','conteudo'=>$oldContent]];
    }

    private function applyOwnedOperations(array $segments,array $operations,int $actorId,string $authorName,string $expectedContent,bool $canEditAll=false): array
    {
        if(count($operations)>2000)throw new HttpException(422,'TOO_MANY_EDITS','Foram feitas alterações demais de uma só vez. Recarregue a página e tente novamente.');
        $characters=[];$owners=[];$names=[];
        foreach($segments as$segment){$owner=$segment['autor_usuario_id']===null?null:(int)$segment['autor_usuario_id'];$name=(string)$segment['autor_nome_snapshot'];foreach(mb_str_split((string)$segment['conteudo'])as$character){$characters[]=$character;$owners[]=$owner;$names[]=$name;}}
        $insertions=[];
        foreach($operations as$operation){
            if(!is_array($operation)||!isset($operation['start'],$operation['delete'])||!array_key_exists('insert',$operation))throw new HttpException(422,'INVALID_EDIT','A edição enviada é inválida. Recarregue a página.');
            $start=filter_var($operation['start'],FILTER_VALIDATE_INT);$delete=filter_var($operation['delete'],FILTER_VALIDATE_INT);$insert=str_replace(["\r\n","\r"],"\n",(string)$operation['insert']);
            if($start===false||$delete===false||$start<0||$delete<0||$start>count($characters)||$start+$delete>count($characters))throw new HttpException(422,'INVALID_EDIT','A posição da edição é inválida. Recarregue a página.');
            for($index=$start;$index<$start+$delete;$index++)if(!$canEditAll&&$owners[$index]!==$actorId)throw new HttpException(422,'FOREIGN_TEXT_PROTECTED','Você só pode alterar ou apagar os trechos que escreveu.');
            $insertChars=mb_str_split($insert);
            array_splice($characters,$start,$delete,$insertChars);array_splice($owners,$start,$delete,array_fill(0,count($insertChars),$actorId));array_splice($names,$start,$delete,array_fill(0,count($insertChars),$authorName));
            if($insert!=='')$insertions[]=['position'=>$start,'text'=>$insert];
        }
        if(implode('',$characters)!==$expectedContent)throw new HttpException(422,'EDIT_MISMATCH','Não foi possível validar as alterações. Recarregue a página e tente novamente.');
        $rebuilt=[];
        foreach($characters as$index=>$character){$last=count($rebuilt)-1;if($last>=0&&$rebuilt[$last]['author_id']===$owners[$index]&&$rebuilt[$last]['author_name']===$names[$index]){$rebuilt[$last]['text'].=$character;continue;}$rebuilt[]=['author_id'=>$owners[$index],'author_name'=>$names[$index],'text'=>$character];}
        return[$rebuilt,$insertions];
    }

    private function insertionChunks(string $old,string $new): ?array
    {
        $oldChars=mb_str_split($old);$newChars=mb_str_split($new);$oldIndex=0;$newIndex=0;$insertions=[];$oldLength=count($oldChars);$newLength=count($newChars);
        while($newIndex<$newLength){
            if($oldIndex<$oldLength&&$newChars[$newIndex]===$oldChars[$oldIndex]){$oldIndex++;$newIndex++;continue;}
            $position=$oldIndex;$text='';
            while($newIndex<$newLength&&($oldIndex>=$oldLength||$newChars[$newIndex]!==$oldChars[$oldIndex])){$text.=$newChars[$newIndex];$newIndex++;}
            if($text!=='')$insertions[]=['position'=>$position,'text'=>$text];
        }
        return$oldIndex===$oldLength?$insertions:null;
    }

    private function period(int $periodId): array
    {
        $statement=$this->repository->db->prepare('SELECT * FROM periodos_pre_conselho WHERE id=:id');$statement->execute([':id'=>$periodId]);
        return$statement->fetch()?:throw new HttpException(404,'PERIOD_NOT_FOUND','Período não encontrado.');
    }

    private function openingTemplate(string $shift): string
    {
        return str_replace('turno __________','turno '.mb_strtolower($shift),self::OPENING_TEMPLATE);
    }
}

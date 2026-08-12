<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\{AuditRepository,DeliveryRepository,SubmissionRepository};
use Apc\Storage\{NameSanitizer,StagedUpload,StorageContext,StorageManager,StoredFile};
use Closure;

final class StorageMigrationService
{
    public function __construct(private readonly SubmissionRepository$submissions,private readonly DeliveryRepository$deliveries,private readonly AuditRepository$audit,private readonly StorageManager$storage) {}

    /**
     * @param array{dry_run?:bool,limit?:int,id?:?int,type?:?string,delete_local?:bool} $options
     * @return array{selected:int,migrated:int,failed:int,local_deleted:int}
     */
    public function migrate(string$target,array$options,?Closure$output=null):array
    {
        if($target!=='google_drive')throw new \InvalidArgumentException('O destino suportado por esta ferramenta é google_drive.');
        $limit=max(1,(int)($options['limit']??100));$id=isset($options['id'])?(int)$options['id']:null;$type=$options['type']??null;
        if($type!==null&&!in_array($type,['submission','attachment'],true))throw new \InvalidArgumentException('Use --type=submission ou --type=attachment.');
        $records=[];
        if($type!== 'attachment')foreach($this->submissions->localForMigration($id,$limit)as$record)$records[]=['type'=>'submission','record'=>$record];
        if($type!== 'submission')foreach($this->deliveries->localAttachmentsForMigration($id,$limit)as$record)$records[]=['type'=>'attachment','record'=>$record];
        $records=array_slice($records,0,$limit);$summary=['selected'=>count($records),'migrated'=>0,'failed'=>0,'local_deleted'=>0];$emit=$output??static function(string$message):void{};
        foreach($records as$position=>$item){$record=$item['record'];$recordType=$item['type'];$prefix='['.($position+1).'/'.$summary['selected'].'] '.$recordType.' #'.$record['id'];
            if(!empty($options['dry_run'])){$emit($prefix.' seria migrado.');continue;}
            $stored=null;
            try{
                $path=$this->storage->localAbsolutePath($record);if($path===null||!is_file($path))throw new \RuntimeException('Arquivo local não encontrado.');$actualHash=hash_file('sha256',$path);if(!is_string($actualHash)||!hash_equals((string)$record['sha256'],$actualHash))throw new \RuntimeException('SHA-256 do arquivo local não corresponde ao banco.');
                $upload=new StagedUpload($path,(string)$record['nome_original'],(string)$record['nome_armazenado'],(string)$record['mime_type'],(int)$record['tamanho_bytes'],(string)$record['sha256'],(string)$record['caminho_relativo'],hash('sha256','apc-storage-migration|'.$recordType.'|'.$record['id'].'|'.$record['sha256']));$context=$this->context($recordType,$record,$upload);$stored=$this->storage->store($upload,$context,$target);$databaseStorage=$stored->databaseFields();$databaseStorage['caminho_relativo']=$record['caminho_relativo'];
                $db=$this->submissions->db;$db->beginTransaction();
                try{if($recordType==='submission')$this->submissions->updateStorage((int)$record['id'],$databaseStorage);else$this->deliveries->updateAttachmentStorage((int)$record['id'],$databaseStorage);$this->audit->record(null,'MIGRAR_STORAGE',($recordType==='submission'?'apc_envios':'apc_anexos'),(int)$record['id'],['storage_driver'=>'local'],['storage_driver'=>$target,'storage_file_id'=>$stored->fileId,'local_copy_preserved'=>true],'cli','apc-migrate-storage');$db->commit();}
                catch(\Throwable$exception){if($db->inTransaction())$db->rollBack();try{$this->storage->deleteStored($stored);}catch(\Throwable$cleanup){error_log('APC storage migration compensation failed type='.$recordType.' id='.$record['id'].' driver='.$target.' file_id='.($stored->fileId??'').' error='.get_class($cleanup));}throw$exception;}
                $summary['migrated']++;$emit($prefix.' migrado para '.$stored->fileId.'.');
                if(!empty($options['delete_local'])){try{$this->storage->storage('local')->delete((string)$record['caminho_relativo']);if($recordType==='submission')$this->submissions->updateStorage((int)$record['id'],$stored->databaseFields());else$this->deliveries->updateAttachmentStorage((int)$record['id'],$stored->databaseFields());$summary['local_deleted']++;$emit($prefix.' cópia local removida.');}catch(\Throwable$exception){$emit($prefix.' migrado, mas a cópia local não pôde ser removida completamente.');error_log('APC storage migration local cleanup failed type='.$recordType.' id='.$record['id'].' error='.get_class($exception));}}
            }catch(\Throwable$exception){$summary['failed']++;$emit($prefix.' falhou: '.$exception->getMessage());if($stored instanceof StoredFile)error_log('APC storage migration failed type='.$recordType.' id='.$record['id'].' driver='.$target.' file_id='.($stored->fileId??'').' error='.get_class($exception));}
        }
        return$summary;
    }

    private function context(string$type,array$record,StagedUpload$upload):StorageContext
    {
        if($type==='submission')return new StorageContext('submission',['APCs',(string)$record['ano_letivo'],(int)$record['bimestre_numero'].'º Bimestre',(string)$record['evento_titulo'],(string)($record['turmas']?:'Turma histórica'),(string)$record['professor_nome_snapshot']],NameSanitizer::file('APC - '.$record['professor_nome_snapshot'].' - '.($record['turmas']?:'Turma histórica').' - '.$record['evento_titulo'].' - '.$upload->originalName),['event_id'=>(string)$record['evento_id'],'teacher_id'=>(string)$record['professor_usuario_id'],'class_id'=>(string)($record['turma_id_externo']??'')]);
        return new StorageContext('attachment',['APCs',(string)$record['ano_letivo'],'Entregas de alunos',(string)$record['evento_titulo'],(string)$record['turma_nome_snapshot'],(string)$record['professor_nome_snapshot'],(string)$record['aluno_nome_snapshot']],NameSanitizer::file('Anexo - '.$record['aluno_nome_snapshot'].' - '.$upload->originalName),['event_id'=>(string)$record['evento_id'],'teacher_id'=>(string)$record['professor_usuario_id'],'class_id'=>(string)$record['turma_id_externo'],'delivery_id'=>(string)$record['entrega_id']]);
    }
}

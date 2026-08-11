<?php declare(strict_types=1);

use PreConselho\Support\Cpf;
use Apc\Repositories\{AuditRepository,CurriculumRepository,EventRepository};
use Apc\Services\{CalendarImporter,CurriculumImporter};
use Shared\Database\ConnectionFactory;
use Shared\Env;

require dirname(__DIR__).'/vendor/autoload.php';
Env::load(dirname(__DIR__).'/.env');
$command=$argv[1]??'help';
$root=dirname(__DIR__);

$migrate=function(\PDO $db,string $directory,string $label):void{
    $db->exec('CREATE TABLE IF NOT EXISTS migrations(id INTEGER PRIMARY KEY AUTOINCREMENT,nome TEXT NOT NULL UNIQUE,executada_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $files=glob(rtrim($directory,'/\\').'/*.sql')?:[];
    sort($files);
    foreach($files as$file){
        $name=basename($file);$check=$db->prepare('SELECT 1 FROM migrations WHERE nome=?');$check->execute([$name]);
        if($check->fetchColumn())continue;
        $db->beginTransaction();
        try{$db->exec((string)file_get_contents($file));$db->prepare('INSERT INTO migrations(nome)VALUES(?)')->execute([$name]);$db->commit();echo "Aplicada: $name\n";}
        catch(Throwable$e){if($db->inTransaction())$db->rollBack();throw$e;}
    }
    echo 'Migrations'.($label===''?'':' '.$label)." concluídas.\n";
};

try{
    if($command==='migrate-apc'){
        $path=Env::get('APC_DB_PATH',$root.'/storage/apc.db')??'';
        $migrate(ConnectionFactory::apc($path),$root.'/apps/apc/database/migrations','do APC');
    }elseif($command==='apc-importar-curriculo'){
        $path=Env::get('APC_DB_PATH',$root.'/storage/apc.db')??'';$db=ConnectionFactory::apc($path);$importer=new CurriculumImporter(new CurriculumRepository($db),new AuditRepository($db),$root.'/apps/apc/resources/curriculo');$summary=$importer->import();
        echo "Componentes processados: {$summary['componentes']}\nHabilidades únicas processadas: {$summary['habilidades']}\nAssociações ano/série processadas: {$summary['associacoes']}\nLinhas curriculares lidas: {$summary['linhas']}\n";
        foreach($summary['advertencias']as$warning)echo "Advertência: $warning\n";
        echo "Importação curricular do APC concluída.\n";
    }elseif($command==='apc-importar-calendario'){
        $path=Env::get('APC_DB_PATH',$root.'/storage/apc.db')??'';$db=ConnectionFactory::apc($path);$importer=new CalendarImporter(new EventRepository($db),new AuditRepository($db),$root.'/apps/apc/resources/calendario/eventos_ee_sao_jose_2026.csv');$summary=$importer->import();
        echo "Eventos processados: {$summary['total']}\nCriados: {$summary['criados']}\nAtualizados: {$summary['atualizados']}\nConciliados: {$summary['conciliados']}\nInalterados: {$summary['inalterados']}\n";
        echo "Jornadas formativas: {$summary['por_tipo']['JORNADA_FORMATIVA']}\nEmendas de feriado: {$summary['por_tipo']['EMENDA_FERIADO']}\nConselhos de classe: {$summary['por_tipo']['CONSELHO_CLASSE']}\nCalendário escolar do APC importado.\n";
    }else{
        $path=Env::get('PRECONSELHO_DB_PATH',$root.'/storage/preconselho.db')??'';
        $db=ConnectionFactory::preconselho($path);
        if($command==='migrate'){
            $migrate($db,$root.'/apps/preconselho-web/database/migrations','');
        }elseif($command==='seed'){
        $users=[
            ['Administrador','admin@escola.local','52998224725','ADMIN'],
            ['Coordenação','coordenacao@escola.local','11144477735','COORDENADOR'],
            ['Professor Um','professor1@escola.local','12345678909','PROFESSOR'],
            ['Professor Dois','professor2@escola.local','93541134780','PROFESSOR'],
        ];
        $db->beginTransaction();
        try{
            foreach($users as[$name,$email,$cpf,$role]){
                $hash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
                $db->prepare('INSERT OR IGNORE INTO usuarios(nome,email,cpf,senha_hash,perfil,alterar_senha)VALUES(?,?,?,?,?,0)')->execute([$name,$email,$cpf,$hash,$role]);
                $db->prepare('UPDATE usuarios SET cpf=?,alterar_senha=0 WHERE email=?')->execute([$cpf,$email]);
                if($role==='PROFESSOR'){$statement=$db->prepare('SELECT id FROM usuarios WHERE email=?');$statement->execute([$email]);$id=(int)$statement->fetchColumn();$db->prepare('INSERT OR IGNORE INTO professores(usuario_id)VALUES(?)')->execute([$id]);}
            }
            foreach(['Língua Portuguesa','Matemática','Ciências']as$name)$db->prepare('INSERT OR IGNORE INTO disciplinas(nome)VALUES(?)')->execute([$name]);
            $db->commit();
        }catch(Throwable$e){if($db->inTransaction())$db->rollBack();throw$e;}
        echo "Dados de exemplo criados. O acesso é feito somente pelo CPF.\n";
    }elseif($command==='create-admin'){
        $cpf=Cpf::normalize($argv[2]??'');$name=trim($argv[3]??'Administrador');
        if(!Cpf::isValid($cpf)||$name==='')throw new RuntimeException('Uso: create-admin CPF "Nome do administrador"');
        $email='cpf-'.$cpf.'@usuario.local';$hash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO usuarios(nome,email,cpf,senha_hash,perfil,alterar_senha)VALUES(?,?,?,?,'ADMIN',0)")->execute([$name,$email,$cpf,$hash]);
        echo 'Administrador criado. Entre com '.Cpf::format($cpf).".\n";
    }elseif($command==='set-cpf'){
        $email=mb_strtolower(trim($argv[2]??''));$cpf=Cpf::normalize($argv[3]??'');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!Cpf::isValid($cpf))throw new RuntimeException('Uso: set-cpf EMAIL-ANTIGO CPF');
        $statement=$db->prepare('UPDATE usuarios SET cpf=?,alterar_senha=0,atualizado_em=CURRENT_TIMESTAMP WHERE email=? AND excluido_em IS NULL');$statement->execute([$cpf,$email]);
        if($statement->rowCount()!==1)throw new RuntimeException('Usuário não encontrado pelo e-mail informado.');
        echo 'CPF vinculado. O usuário já pode entrar com '.Cpf::format($cpf).".\n";
        }else{
            echo "Comandos: migrate | migrate-apc | apc-importar-curriculo | apc-importar-calendario | seed | create-admin CPF NOME | set-cpf EMAIL-ANTIGO CPF\n";
        }
    }
}catch(Throwable$e){fwrite(STDERR,"Erro: {$e->getMessage()}\n");exit(1);}

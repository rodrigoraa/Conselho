<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\{AuditRepository,EventRepository};
use Apc\Services\{CalendarImporter,EventWindow};

final class ApcSchoolCalendarImporterTest extends ApcTestCase
{
    private const DATES=['2026-02-03','2026-02-04','2026-02-05','2026-02-06','2026-04-20','2026-04-30','2026-05-09','2026-07-16','2026-08-04','2026-08-05','2026-08-06','2026-08-07','2026-09-30','2026-10-02','2026-10-13','2026-10-16','2026-12-05','2026-12-07'];

    private function importer(\PDO $db): CalendarImporter
    {
        return new CalendarImporter(new EventRepository($db),new AuditRepository($db),dirname(__DIR__).'/apps/apc/resources/calendario/eventos_ee_sao_jose_2026.csv');
    }

    public function testOfficialSchoolCalendarImportsIdempotentlyWithExactApcDates(): void
    {
        $db=$this->apcDatabase();$events=new EventRepository($db);$first=$this->importer($db)->import();$idsBefore=$db->query('SELECT id FROM apc_eventos ORDER BY chave_importacao')->fetchAll(\PDO::FETCH_COLUMN);$second=$this->importer($db)->import();$idsAfter=$db->query('SELECT id FROM apc_eventos ORDER BY chave_importacao')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(18,$first['total']);self::assertSame(18,$first['criados']);self::assertSame(10,$first['por_tipo']['JORNADA_FORMATIVA']);self::assertSame(3,$first['por_tipo']['EMENDA_FERIADO']);self::assertSame(5,$first['por_tipo']['CONSELHO_CLASSE']);self::assertSame(18,$second['inalterados']);self::assertSame(0,$second['criados']);self::assertSame($idsBefore,$idsAfter);self::assertSame(self::DATES,$db->query('SELECT data FROM apc_eventos ORDER BY data,id')->fetchAll(\PDO::FETCH_COLUMN));self::assertSame(18,(int)$db->query('SELECT COUNT(DISTINCT chave_importacao) FROM apc_eventos')->fetchColumn());self::assertSame(2,(int)$db->query("SELECT COUNT(*) FROM apc_auditoria WHERE acao='CALENDARIO_ESCOLAR_IMPORTADO'")->fetchColumn());self::assertSame(['2026-09-30','2026-10-02','2026-10-13','2026-10-16','2026-12-05'],array_column($events->upcoming(5,'2026-08-11'),'data'));$window=new EventWindow('2026-08-11');self::assertSame(['2026-08-04','2026-08-05','2026-08-06','2026-08-07'],array_column(array_values(array_filter($events->active(),static fn(array$event):bool=>$window->describe($event)['state']==='AGUARDANDO_LIBERACAO')),'data'));
    }

    public function testExistingManualEventIsReconciledWithoutLosingItsIdentity(): void
    {
        $db=$this->apcDatabase();$db->exec("INSERT INTO apc_eventos(ano_letivo,data,titulo,tipo,origem,descricao,justificativa,atividade_fornecida_sed,status,criado_por)VALUES(2026,'2026-04-20','APC já cadastrada','EMENDA_FERIADO','ESCOLA','Cadastro manual','Observação preservada',0,'ATIVO',7)");$id=(int)$db->lastInsertId();$summary=$this->importer($db)->import(7,'127.0.0.1','phpunit');$event=(new EventRepository($db))->find($id);
        self::assertSame(17,$summary['criados']);self::assertSame(1,$summary['conciliados']);self::assertSame(18,(int)$db->query('SELECT COUNT(*) FROM apc_eventos')->fetchColumn());self::assertSame('EE_SAO_JOSE_2026_EM_2026_04_20',$event['chave_importacao']);self::assertSame('Observação preservada',$event['justificativa']);self::assertSame(7,(int)$event['criado_por']);
    }
}

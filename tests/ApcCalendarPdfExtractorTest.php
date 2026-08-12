<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\{AuditRepository,EventRepository};
use Apc\Services\{CalendarImporter,CalendarPdfExtractor};
use Apc\Storage\UploadPreparer;
use Shared\Exceptions\HttpException;

final class ApcCalendarPdfExtractorTest extends ApcTestCase
{
    private string$directory;

    protected function setUp():void{$this->directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'apc-calendar-'.bin2hex(random_bytes(5));mkdir($this->directory,0770,true);}
    protected function tearDown():void{foreach(glob($this->directory.DIRECTORY_SEPARATOR.'*')?:[]as$file)@unlink($file);@rmdir($this->directory);}

    public function testExtractsAndChecksAllOfficialApcDatesFromAnnualCalendarPattern():void
    {
        $analysis=$this->extractor()->analyzePages($this->pages(),'Calendário Escolar EE São José 2026.pdf',str_repeat('a',64));
        self::assertSame(2026,$analysis['year']);self::assertSame(18,$analysis['declared_total']);self::assertTrue($analysis['verified']);self::assertSame([],$analysis['warnings']);self::assertSame(10,$analysis['counts']['JORNADA_FORMATIVA']);self::assertSame(3,$analysis['counts']['EMENDA_FERIADO']);self::assertSame(5,$analysis['counts']['CONSELHO_CLASSE']);self::assertSame(['2026-02-03','2026-02-04','2026-02-05','2026-02-06','2026-04-20','2026-04-30','2026-05-09','2026-07-16','2026-08-04','2026-08-05','2026-08-06','2026-08-07','2026-09-30','2026-10-02','2026-10-13','2026-10-16','2026-12-05','2026-12-07'],array_column($analysis['events'],'data'));self::assertSame('29/090121/2022',$analysis['process_number']);
    }

    public function testRequiresHumanReviewThenImportsConfirmedRowsIdempotently():void
    {
        $extractor=$this->extractor();$analysis=$extractor->analyzePages($this->pages(),'calendario-2026.pdf');
        try{$extractor->confirmedRows($analysis,[]);self::fail('A confirmação humana deveria ser obrigatória.');}catch(HttpException$exception){self::assertSame('APC_CALENDAR_REVIEW_REQUIRED',$exception->errorCode);}
        $events=[];foreach($analysis['events']as$index=>$event)$events[$index]=['incluir'=>'1','data'=>$event['data'],'tipo'=>$event['tipo'],'titulo'=>$event['titulo']];$rows=$extractor->confirmedRows($analysis,['revisado'=>'1','eventos'=>$events]);self::assertCount(18,$rows);self::assertArrayNotHasKey('evidencia',$rows[0]);self::assertSame('EE_SAO_JOSE_2026_JF_2026_02_03',$rows[0]['chave_importacao']);
        $db=$this->apcDatabase();$importer=new CalendarImporter(new EventRepository($db),new AuditRepository($db),'unused.csv');$first=$importer->importRows($rows,1,'127.0.0.1','phpunit',$analysis['file_name']);$second=$importer->importRows($rows,1,'127.0.0.1','phpunit',$analysis['file_name']);self::assertSame(18,$first['criados']);self::assertSame(18,$second['inalterados']);self::assertSame(18,(int)$db->query('SELECT COUNT(*) FROM apc_eventos')->fetchColumn());
    }

    public function testPdfUploadIsTemporaryAndDeclaredMismatchIsHighlighted():void
    {
        $pages=$this->pages();$pages[2]=str_replace('TOTAL DE APCS NO ANO LETIVO: 18','TOTAL DE APCS NO ANO LETIVO: 19',$pages[2]);$source=$this->directory.DIRECTORY_SEPARATOR.'entrada.pdf';file_put_contents($source,"%PDF-1.4\n%%EOF");$extractor=new CalendarPdfExtractor(new UploadPreparer($this->directory,1048576,['application/pdf'=>'pdf'],static fn(string$path):bool=>is_file($path),static fn(string$from,string$to):bool=>rename($from,$to)),static fn(string$path):array=>$pages);$analysis=$extractor->analyze(['name'=>'Calendário 2026.pdf','tmp_name'=>$source,'error'=>UPLOAD_ERR_OK]);self::assertFalse($analysis['verified']);self::assertStringContainsString('declara 19 APC(s)',implode(' ',$analysis['warnings']));self::assertSame([],glob($this->directory.DIRECTORY_SEPARATOR.'*.upload')?:[]);self::assertFileDoesNotExist($source);
    }

    private function extractor():CalendarPdfExtractor{return new CalendarPdfExtractor(new UploadPreparer($this->directory,1048576,['application/pdf'=>'pdf']));}

    /** @return array<int,string> */
    private function pages():array
    {
        return[
            "CALENDÁRIO ESCOLAR 20 26\nJANEIRO FEVEREIRO MARÇO\nFérias Escolares\n3 a 6 - Jornada Formativa (JF) Letivo com APC\n17 dias letivos\n22 dias letivos\nABRIL MAIO JUNHO\n20 - Emenda (EM) com APC\n30 - Conselho de Classe (CC), Lançamento da Média Final - Letivo com APC\n19 dias letivos\n9 - Jornada Formativa (JF) Letivo com APC\n20 dias letivos\n20 dias letivos\nProcesso: 29/090121/2022",
            "JULHO AGOSTO SETEMBRO\n16 - Conselho de Classe (CC) - Letivo com APC\n13 dias letivos\n4 a 7 - Jornada Formativa (JF) Letivo com APC\n22 dias letivos\n30 - Conselho de Classe (CC) - Letivo com APC\n21 dias letivos\nOUTUBRO NOVEMBRO DEZEMBRO\n2 - Início de Bimestre e Jornada Formativa (JF) Letivo com\nAPC\n13 e 16 - Emenda (EM) com APC\n19 dias letivos\n20 dias letivos\n5 e 7 - Conselho de Classe (CC) Letivo com APC\n7 dias letivos",
            "TOTAL DE APCS NO ANO LETIVO: 18, SENDO 10 DE JORNADA FORMATIVA, 3 DE EMENDAS DE FERIADO E 5 DE CONSELHO DE CLASSE.",
        ];
    }
}

<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\AccessRepository;

final class ApcCouncilBindingSeriesTest extends ApcTestCase
{
    public function testStagesAndSeriesAreDerivedFromActiveCouncilClassBindings():void
    {
        $db=$this->mainDatabase();$this->seedMain($db);$db->exec("INSERT INTO vinculos_professor_turma(professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno)VALUES(1,30,'1ª A - Ensino Médio',2026,'VESPERTINO')");$series=(new AccessRepository($db))->seriesFor(3,'PROFESSOR');self::assertSame(['EF7','EM1'],array_column($series,'ano_serie'));self::assertSame('7º A',$series[0]['turmas'][0]['nome']);self::assertSame('1ª A - Ensino Médio',$series[1]['turmas'][0]['nome']);
    }

    public function testCoordinationRosterUsesTheSameActiveBindingsShownInCouncil():void
    {
        $db=$this->mainDatabase();$this->seedMain($db);$db->exec("UPDATE vinculos_professor_turma SET turma_nome_snapshot='7º Ano - Ens. Fundamental',turma_ano_letivo_snapshot=2025 WHERE id=1;UPDATE vinculos_professor_turma SET turma_nome_snapshot='1º Ano - Ens. Médio',turma_ano_letivo_snapshot=2025 WHERE id=2");$roster=(new AccessRepository($db))->submissionRoster();self::assertSame([],$roster['without_series']);self::assertSame(['Professor Dois'=>'EM1','Professor Um'=>'EF7'],array_column($roster['requirements'],'ano_serie','professor_nome'));
    }
}

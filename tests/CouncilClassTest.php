<?php declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use PreConselho\Support\CouncilClass;

final class CouncilClassTest extends TestCase
{
    public function testNormalizesAndOrdersFundamentalBeforeSecondaryEducation(): void
    {
        $sources=['3º Ano Ensino Médio','9º ano','1º EM','4º A','2º Ano - Ensino Médio','1º ano','8º','3º ano','7 A','6º Ano Fundamental','5º ano','2º ano'];
        $identified=array_map(static fn(string$name):array=>CouncilClass::identify($name),$sources);
        usort($identified,static fn(array$a,array$b):int=>$a['order']<=>$b['order']);
        self::assertSame([
            '1º Ano - Ensino Fundamental','2º Ano - Ensino Fundamental','3º Ano - Ensino Fundamental',
            '4º Ano - Ensino Fundamental','5º Ano - Ensino Fundamental','6º Ano - Ensino Fundamental',
            '7º Ano - Ensino Fundamental','8º Ano - Ensino Fundamental','9º Ano - Ensino Fundamental',
            '1º Ano - Ensino Médio','2º Ano - Ensino Médio','3º Ano - Ensino Médio',
        ],array_column($identified,'name'));
        self::assertSame(range(1,12),array_column($identified,'order'));
    }
}

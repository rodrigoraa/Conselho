<?php declare(strict_types=1);
namespace Tests;

use PHPUnit\Framework\TestCase;
use PreConselho\Support\Cpf;

final class CpfTest extends TestCase
{
    public function testNormalizaFormataEValidaCpf(): void
    {
        self::assertSame('52998224725',Cpf::normalize('529.982.247-25'));
        self::assertSame('529.982.247-25',Cpf::format('52998224725'));
        self::assertTrue(Cpf::isValid('529.982.247-25'));
    }

    public function testRejeitaDigitosIguaisEDigitoVerificadorErrado(): void
    {
        self::assertFalse(Cpf::isValid('111.111.111-11'));
        self::assertFalse(Cpf::isValid('529.982.247-24'));
    }
}

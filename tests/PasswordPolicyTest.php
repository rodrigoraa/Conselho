<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PreConselho\Support\PasswordPolicy;

final class PasswordPolicyTest extends TestCase
{
    public function testAcceptsPasswordsWithSixOrMoreCharacters(): void
    {
        self::assertSame(6,PasswordPolicy::MIN_LENGTH);
        self::assertTrue(PasswordPolicy::accepts('abc123'));
        self::assertTrue(PasswordPolicy::accepts('escola'));
        self::assertFalse(PasswordPolicy::accepts('12345'));
    }
}

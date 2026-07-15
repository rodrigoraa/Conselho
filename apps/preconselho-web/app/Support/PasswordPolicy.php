<?php declare(strict_types=1);
namespace PreConselho\Support;

final class PasswordPolicy
{
    public const MIN_LENGTH=6;

    public static function accepts(string $password): bool
    {
        return mb_strlen($password)>=self::MIN_LENGTH;
    }
}

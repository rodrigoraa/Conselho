<?php declare(strict_types=1);
namespace Shared\Exceptions;
use RuntimeException;
class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message, public readonly array $errors = []) { parent::__construct($message); }
}

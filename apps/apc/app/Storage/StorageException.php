<?php declare(strict_types=1);

namespace Apc\Storage;

use RuntimeException;

final class StorageException extends RuntimeException
{
    public function __construct(string $message,public readonly bool $temporary=false,?\Throwable $previous=null)
    {
        parent::__construct($message,0,$previous);
    }
}

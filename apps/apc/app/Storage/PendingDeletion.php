<?php declare(strict_types=1);

namespace Apc\Storage;

interface PendingDeletion
{
    public function commit(): void;

    public function rollback(): void;
}

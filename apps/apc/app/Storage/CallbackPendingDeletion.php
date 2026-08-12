<?php declare(strict_types=1);

namespace Apc\Storage;

use Closure;

final class CallbackPendingDeletion implements PendingDeletion
{
    private bool $finished=false;

    public function __construct(private readonly Closure $commitAction,private readonly Closure $rollbackAction) {}

    public function commit(): void
    {
        if($this->finished)return;
        ($this->commitAction)();
        $this->finished=true;
    }

    public function rollback(): void
    {
        if($this->finished)return;
        ($this->rollbackAction)();
        $this->finished=true;
    }
}

<?php declare(strict_types=1);

namespace Apc\Storage;

final class CompositePendingDeletion implements PendingDeletion
{
    /** @param array<int, PendingDeletion> $deletions */
    public function __construct(private readonly array$deletions) {}

    public function commit():void{$this->finish('commit');}
    public function rollback():void{$this->finish('rollback');}

    private function finish(string$method):void
    {
        $first=null;foreach($this->deletions as$deletion){try{$deletion->{$method}();}catch(\Throwable$exception){$first??=$exception;}}if($first!==null)throw$first;
    }
}

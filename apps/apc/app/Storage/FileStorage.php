<?php declare(strict_types=1);

namespace Apc\Storage;

interface FileStorage
{
    public function driver(): string;

    public function store(StagedUpload $upload, StorageContext $context): StoredFile;

    public function contents(string $identifier): string;

    public function delete(string $identifier): void;

    public function exists(string $identifier): bool;

    public function beginDeletion(string $identifier): PendingDeletion;

    /** @return array<string, string> */
    public function healthCheck(): array;
}

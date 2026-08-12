<?php declare(strict_types=1);

namespace Apc\Storage;

final readonly class StorageContext
{
    /**
     * @param array<int, string> $folders
     * @param array<string, string> $appProperties
     */
    public function __construct(
        public string $recordType,
        public array $folders,
        public string $visibleName,
        public array $appProperties,
    ) {}
}

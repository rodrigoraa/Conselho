<?php declare(strict_types=1);

namespace Apc\Storage;

final readonly class StagedUpload
{
    public function __construct(
        public string $path,
        public string $originalName,
        public string $storedName,
        public string $mimeType,
        public int $size,
        public string $sha256,
        public string $relativePath,
        public string $operationId,
    ) {}
}

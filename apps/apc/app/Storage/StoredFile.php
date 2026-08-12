<?php declare(strict_types=1);

namespace Apc\Storage;

final readonly class StoredFile
{
    public function __construct(
        public string $driver,
        public string $identifier,
        public ?string $relativePath=null,
        public ?string $fileId=null,
        public ?string $folderId=null,
    ) {}

    /** @return array{storage_driver:string,storage_file_id:?string,storage_folder_id:?string,caminho_relativo:?string} */
    public function databaseFields(): array
    {
        return['storage_driver'=>$this->driver,'storage_file_id'=>$this->fileId,'storage_folder_id'=>$this->folderId,'caminho_relativo'=>$this->relativePath];
    }
}

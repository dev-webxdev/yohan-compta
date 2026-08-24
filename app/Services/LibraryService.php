<?php

namespace App\Services;

use App\Models\DocumentFolder;
use App\Models\LibraryDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class LibraryService
{
    /** @return array{folder:?DocumentFolder,breadcrumbs:list<DocumentFolder>,folders:mixed,documents:mixed} */
    public function browse(?int $folderId): array
    {
        $folder = $folderId === null ? null : DocumentFolder::query()->findOrFail($folderId);

        return [
            'folder' => $folder,
            'breadcrumbs' => $folder ? $this->breadcrumbs($folder) : [],
            'folders' => DocumentFolder::query()
                ->where('parent_id', $folder?->id)
                ->withCount(['children', 'documents'])
                ->orderBy('name')
                ->get(),
            'documents' => LibraryDocument::query()
                ->where('folder_id', $folder?->id)
                ->orderBy('original_name')
                ->get(),
        ];
    }

    public function createFolder(?int $parentId, string $name): DocumentFolder
    {
        if ($parentId !== null) {
            DocumentFolder::query()->findOrFail($parentId);
        }
        $this->assertUniqueFolderName($parentId, $name);

        return DocumentFolder::query()->create(['parent_id' => $parentId, 'name' => $name]);
    }

    public function renameFolder(DocumentFolder $folder, string $name): void
    {
        $this->assertUniqueFolderName($folder->parent_id, $name, $folder->id);
        $folder->update(['name' => $name]);
    }

    public function deleteFolder(DocumentFolder $folder): void
    {
        $folderIds = $this->descendantIds($folder->id);
        $storageNames = LibraryDocument::query()->whereIn('folder_id', $folderIds)->pluck('storage_name');

        DB::transaction(fn () => $folder->delete());
        foreach ($storageNames as $storageName) {
            @unlink($this->filePath((string) $storageName));
        }
    }

    public function storeDocument(?int $folderId, UploadedFile $file): LibraryDocument
    {
        if ($folderId !== null) {
            DocumentFolder::query()->findOrFail($folderId);
        }

        File::ensureDirectoryExists($this->libraryPath());
        $storageName = (string) Str::uuid();
        $target = $this->filePath($storageName);
        if (!$file->move($this->libraryPath(), $storageName)) {
            throw new RuntimeException('Impossible d’enregistrer le fichier importé.');
        }

        try {
            return LibraryDocument::query()->create([
                'folder_id' => $folderId,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'storage_name' => $storageName,
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => filesize($target) ?: 0,
            ]);
        } catch (\Throwable $error) {
            @unlink($target);
            throw $error;
        }
    }

    public function deleteDocument(LibraryDocument $document): void
    {
        $path = $this->filePath($document->storage_name);
        $document->delete();
        @unlink($path);
    }

    public function documentPath(LibraryDocument $document): string
    {
        $path = $this->filePath($document->storage_name);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Le fichier est introuvable sur le disque.');
        }

        return $path;
    }

    public function libraryPath(): string
    {
        return rtrim((string) config('filesystems.disks.local.root'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'library';
    }

    private function filePath(string $storageName): string
    {
        return $this->libraryPath().DIRECTORY_SEPARATOR.basename($storageName);
    }

    /** @return list<DocumentFolder> */
    private function breadcrumbs(DocumentFolder $folder): array
    {
        $items = [];
        $current = $folder;
        while ($current) {
            array_unshift($items, $current);
            $current = $current->parent_id ? DocumentFolder::query()->find($current->parent_id) : null;
        }

        return $items;
    }

    /** @return list<int> */
    private function descendantIds(int $folderId): array
    {
        $ids = [$folderId];
        $pending = [$folderId];
        while ($pending !== []) {
            $children = DocumentFolder::query()->whereIn('parent_id', $pending)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $ids = array_merge($ids, $children);
            $pending = $children;
        }

        return $ids;
    }

    private function assertUniqueFolderName(?int $parentId, string $name, ?int $ignoreId = null): void
    {
        $query = DocumentFolder::query()->where('parent_id', $parentId)->whereRaw('LOWER(name) = LOWER(?)', [$name]);
        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }
        if ($query->exists()) {
            throw new RuntimeException('Un dossier portant ce nom existe déjà à cet emplacement.');
        }
    }
}

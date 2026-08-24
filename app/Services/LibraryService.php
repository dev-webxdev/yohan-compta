<?php

namespace App\Services;

use App\Models\DocumentFolder;
use App\Models\LibraryDocument;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class LibraryService
{
    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
    private const IMAGE_EXTENSION_MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    /** @return array{folder:?DocumentFolder,breadcrumbs:list<DocumentFolder>,folders:mixed,documents:mixed,trash_count:int} */
    public function browse(?int $folderId): array
    {
        $folder = $folderId === null ? null : $this->accessibleFolder($folderId);

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
            'trash_count' => $this->trashCount(),
        ];
    }

    /** @return array{folders:mixed,documents:mixed,trash_count:int} */
    public function trash(): array
    {
        return [
            'folders' => DocumentFolder::onlyTrashed()->with('parent')->orderByDesc('deleted_at')->get(),
            'documents' => LibraryDocument::onlyTrashed()->with('folder')->orderByDesc('deleted_at')->get(),
            'trash_count' => $this->trashCount(),
        ];
    }

    public function createFolder(?int $parentId, string $name): DocumentFolder
    {
        if ($parentId !== null) {
            $this->accessibleFolder($parentId);
        }
        $this->assertUniqueFolderName($parentId, $name);

        return DocumentFolder::query()->create(['parent_id' => $parentId, 'name' => $name]);
    }

    public function renameFolder(DocumentFolder $folder, string $name): void
    {
        $this->assertFolderAccessible($folder);
        $this->assertUniqueFolderName($folder->parent_id, $name, $folder->id);
        $folder->update(['name' => $name]);
    }

    public function trashFolder(DocumentFolder $folder): void
    {
        $this->assertFolderAccessible($folder);
        $folder->delete();
    }

    public function restoreFolder(DocumentFolder $folder): void
    {
        if (!$folder->trashed()) {
            throw new RuntimeException('Ce dossier n’est pas dans la corbeille.');
        }
        $this->assertParentAvailableForRestore($folder);
        $this->assertUniqueFolderName($folder->parent_id, $folder->name, $folder->id);
        $folder->restore();
    }

    public function forceDeleteFolder(DocumentFolder $folder): void
    {
        if (!$folder->trashed()) {
            throw new RuntimeException('Le dossier doit être dans la corbeille avant sa suppression définitive.');
        }

        $folderIds = $this->descendantIds($folder->id);
        $storageNames = LibraryDocument::withTrashed()->whereIn('folder_id', $folderIds)->pluck('storage_name');

        DB::transaction(fn () => $folder->forceDelete());
        foreach ($storageNames as $storageName) {
            @unlink($this->filePath((string) $storageName));
        }
    }

    public function storeDocument(int $folderId, UploadedFile $file): LibraryDocument
    {
        $this->accessibleFolder($folderId);
        $mimeType = $this->verifiedImageMime($file);

        File::ensureDirectoryExists($this->libraryPath());
        $storageName = (string) Str::uuid();
        $target = $this->filePath($storageName);
        if (!$file->move($this->libraryPath(), $storageName)) {
            throw new RuntimeException('Impossible d’enregistrer l’image importée.');
        }

        try {
            return LibraryDocument::query()->create([
                'folder_id' => $folderId,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'storage_name' => $storageName,
                'mime_type' => $mimeType,
                'size_bytes' => filesize($target) ?: 0,
            ]);
        } catch (\Throwable $error) {
            @unlink($target);
            throw $error;
        }
    }

    public function trashDocument(LibraryDocument $document): void
    {
        $this->assertDocumentAccessible($document);
        $document->delete();
    }

    public function restoreDocument(LibraryDocument $document): void
    {
        if (!$document->trashed()) {
            throw new RuntimeException('Ce fichier n’est pas dans la corbeille.');
        }
        if ($document->folder_id !== null) {
            $this->accessibleFolder((int) $document->folder_id);
        }
        $document->restore();
    }

    public function forceDeleteDocument(LibraryDocument $document): void
    {
        if (!$document->trashed()) {
            throw new RuntimeException('Le fichier doit être dans la corbeille avant sa suppression définitive.');
        }
        $path = $this->filePath($document->storage_name);
        $document->forceDelete();
        @unlink($path);
    }

    public function documentPath(LibraryDocument $document): string
    {
        $this->assertDocumentAccessible($document);
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

    private function trashCount(): int
    {
        return DocumentFolder::onlyTrashed()->count() + LibraryDocument::onlyTrashed()->count();
    }

    private function accessibleFolder(int $folderId): DocumentFolder
    {
        $folder = DocumentFolder::query()->findOrFail($folderId);
        $this->assertFolderAccessible($folder);

        return $folder;
    }

    private function assertFolderAccessible(DocumentFolder $folder): void
    {
        $current = $folder;
        while ($current->parent_id !== null) {
            $parent = DocumentFolder::withTrashed()->find($current->parent_id);
            if (!$parent || $parent->trashed()) {
                throw (new ModelNotFoundException())->setModel(DocumentFolder::class, [$folder->id]);
            }
            $current = $parent;
        }
    }

    private function assertDocumentAccessible(LibraryDocument $document): void
    {
        if ($document->trashed()) {
            throw (new ModelNotFoundException())->setModel(LibraryDocument::class, [$document->id]);
        }
        if ($document->folder_id !== null) {
            $this->accessibleFolder((int) $document->folder_id);
        }
    }

    private function assertParentAvailableForRestore(DocumentFolder $folder): void
    {
        if ($folder->parent_id === null) {
            return;
        }

        $parent = DocumentFolder::withTrashed()->find($folder->parent_id);
        if (!$parent) {
            throw new RuntimeException('Le dossier parent n’existe plus.');
        }
        if ($parent->trashed()) {
            throw new RuntimeException('Restaurez d’abord le dossier parent.');
        }
        $this->assertFolderAccessible($parent);
    }

    private function verifiedImageMime(UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new RuntimeException('Le fichier envoyé est invalide.');
        }
        if (($file->getSize() ?: 0) > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException('L’image ne doit pas dépasser 10 Mo.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $expectedMime = self::IMAGE_EXTENSION_MIMES[$extension] ?? null;
        if ($expectedMime === null) {
            throw new RuntimeException('Seules les images JPG, PNG, WEBP et GIF sont autorisées.');
        }

        $path = $file->getRealPath();
        if (!is_string($path) || $path === '' || !is_file($path)) {
            throw new RuntimeException('Impossible de vérifier l’image importée.');
        }

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $imageInfo = @getimagesize($path);
        $imageMime = is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null;
        if (!is_string($detectedMime) || !is_string($imageMime)
            || $detectedMime !== $imageMime
            || $detectedMime !== $expectedMime) {
            throw new RuntimeException('Le fichier fourni n’est pas une image valide et sûre.');
        }

        return $detectedMime;
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
            $children = DocumentFolder::withTrashed()->whereIn('parent_id', $pending)->pluck('id')->map(fn ($id): int => (int) $id)->all();
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

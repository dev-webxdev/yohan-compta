<?php

namespace App\Http\Controllers;

use App\Models\DocumentFolder;
use App\Models\LibraryDocument;
use App\Services\LibraryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class LibraryController
{
    public function index(LibraryService $library, ?int $folder = null): \Illuminate\View\View
    {
        $data = $library->browse($folder);

        return view('library', $data);
    }

    public function storeFolder(Request $request, LibraryService $library): RedirectResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:document_folders,id'],
            'name' => ['required', 'string', 'max:120', 'regex:/^[^\\\\\/]+$/'],
        ], ['name.regex' => 'Le nom du dossier ne peut pas contenir / ou \\.']);

        try {
            $library->createFolder(isset($data['parent_id']) ? (int) $data['parent_id'] : null, trim($data['name']));
        } catch (\RuntimeException $error) {
            throw ValidationException::withMessages(['name' => $error->getMessage()]);
        }

        return $this->backToFolder($data['parent_id'] ?? null)->with('status', 'Dossier créé.');
    }

    public function updateFolder(Request $request, DocumentFolder $folder, LibraryService $library): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'regex:/^[^\\\\\/]+$/'],
        ], ['name.regex' => 'Le nom du dossier ne peut pas contenir / ou \\.']);

        try {
            $library->renameFolder($folder, trim($data['name']));
        } catch (\RuntimeException $error) {
            throw ValidationException::withMessages(['name' => $error->getMessage()]);
        }

        return $this->backToFolder($folder->parent_id)->with('status', 'Dossier renommé.');
    }

    public function destroyFolder(DocumentFolder $folder, LibraryService $library): RedirectResponse
    {
        $parentId = $folder->parent_id;
        $library->deleteFolder($folder);

        return $this->backToFolder($parentId)->with('status', 'Dossier et contenu supprimés.');
    }

    public function storeDocument(Request $request, LibraryService $library): RedirectResponse
    {
        $data = $request->validate([
            'folder_id' => ['nullable', 'integer', 'exists:document_folders,id'],
            'document' => ['required', 'file', 'max:51200'],
        ]);

        try {
            $library->storeDocument(isset($data['folder_id']) ? (int) $data['folder_id'] : null, $data['document']);
        } catch (\RuntimeException $error) {
            throw ValidationException::withMessages(['document' => $error->getMessage()]);
        }

        return $this->backToFolder($data['folder_id'] ?? null)->with('status', 'Fichier importé.');
    }

    public function download(LibraryDocument $document, LibraryService $library): BinaryFileResponse
    {
        try {
            $path = $library->documentPath($document);
        } catch (\RuntimeException $error) {
            abort(404, $error->getMessage());
        }

        return response()->download($path, $document->original_name, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyDocument(LibraryDocument $document, LibraryService $library): RedirectResponse
    {
        $folderId = $document->folder_id;
        $library->deleteDocument($document);

        return $this->backToFolder($folderId)->with('status', 'Fichier supprimé.');
    }

    private function backToFolder(int|string|null $folderId): RedirectResponse
    {
        return redirect()->route('library.index', $folderId ? ['folder' => $folderId] : []);
    }
}

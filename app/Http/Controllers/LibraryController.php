<?php

namespace App\Http\Controllers;

use App\Models\DocumentFolder;
use App\Models\LibraryDocument;
use App\Services\DocumentLinkService;
use App\Services\LibraryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class LibraryController
{
    public function index(Request $request, LibraryService $library, DocumentLinkService $links, ?int $folder = null): View
    {
        $target = trim((string) $request->query('target', ''));
        $linkedDocuments = collect();
        if ($target !== '') {
            try {
                $linkedDocuments = $links->documentsForToken($target);
            } catch (\RuntimeException) {
                abort(404);
            }
        }

        $data = $library->browse($folder);
        $data['associationTargets'] = $links->targetOptions();
        $data['associationFilter'] = $target;
        $data['linkedDocuments'] = $linkedDocuments;
        $data['linkLabels'] = $data['documents']->mapWithKeys(fn (LibraryDocument $document): array => [
            $document->id => $document->links->mapWithKeys(fn ($link): array => [$link->id => $links->label($link)])->all(),
        ])->all();

        return view('library', $data);
    }

    public function trash(LibraryService $library): View
    {
        return view('library-trash', $library->trash());
    }

    public function storeFolder(Request $request, LibraryService $library): RedirectResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', Rule::exists('document_folders', 'id')->whereNull('deleted_at')],
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
        $library->trashFolder($folder);

        return $this->backToFolder($parentId)->with('status', 'Dossier déplacé dans la corbeille.');
    }

    public function restoreFolder(int $folder, LibraryService $library): RedirectResponse
    {
        $trashedFolder = DocumentFolder::onlyTrashed()->findOrFail($folder);
        try {
            $library->restoreFolder($trashedFolder);
        } catch (\RuntimeException $error) {
            return redirect()->route('library.trash')->withErrors(['trash' => $error->getMessage()]);
        }

        return redirect()->route('library.trash')->with('status', 'Dossier restauré.');
    }

    public function forceDestroyFolder(int $folder, LibraryService $library): RedirectResponse
    {
        $trashedFolder = DocumentFolder::onlyTrashed()->findOrFail($folder);
        $library->forceDeleteFolder($trashedFolder);

        return redirect()->route('library.trash')->with('status', 'Dossier supprimé définitivement.');
    }

    public function storeDocument(Request $request, LibraryService $library): RedirectResponse
    {
        $data = $request->validate([
            'folder_id' => ['required', 'integer', Rule::exists('document_folders', 'id')->whereNull('deleted_at')],
            'document' => ['required', 'file', 'max:10240'],
        ], [
            'folder_id.required' => 'Ouvrez un dossier avant d’ajouter un document.',
            'document.max' => 'Le document ne doit pas dépasser 10 Mo.',
        ]);

        try {
            $library->storeDocument((int) $data['folder_id'], $data['document']);
        } catch (\RuntimeException $error) {
            throw ValidationException::withMessages(['document' => $error->getMessage()]);
        }

        return $this->backToFolder($data['folder_id'])->with('status', 'Document ajouté.');
    }

    public function preview(LibraryDocument $document, LibraryService $library): BinaryFileResponse
    {
        try {
            $path = $library->documentPath($document);
        } catch (\RuntimeException $error) {
            abort(404, $error->getMessage());
        }

        $response = response()->file($path, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContentDisposition('inline', $document->original_name);

        return $response;
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
        $library->trashDocument($document);

        return $this->backToFolder($folderId)->with('status', 'Fichier déplacé dans la corbeille.');
    }

    public function restoreDocument(int $document, LibraryService $library): RedirectResponse
    {
        $trashedDocument = LibraryDocument::onlyTrashed()->findOrFail($document);
        try {
            $library->restoreDocument($trashedDocument);
        } catch (\RuntimeException $error) {
            return redirect()->route('library.trash')->withErrors(['trash' => $error->getMessage()]);
        }

        return redirect()->route('library.trash')->with('status', 'Fichier restauré.');
    }

    public function forceDestroyDocument(int $document, LibraryService $library): RedirectResponse
    {
        $trashedDocument = LibraryDocument::onlyTrashed()->findOrFail($document);
        $library->forceDeleteDocument($trashedDocument);

        return redirect()->route('library.trash')->with('status', 'Fichier supprimé définitivement.');
    }

    private function backToFolder(int|string|null $folderId): RedirectResponse
    {
        return redirect()->route('library.index', $folderId ? ['folder' => $folderId] : []);
    }
}

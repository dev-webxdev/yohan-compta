<?php

namespace App\Http\Controllers;

use App\Models\DocumentFolder;
use App\Models\LibraryDocument;
use App\Services\LibraryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class LibraryController
{
    public function index(LibraryService $library, ?int $folder = null): View
    {
        return view('library', $library->browse($folder));
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

    public function destroyFolder(Request $request, DocumentFolder $folder, LibraryService $library): RedirectResponse
    {
        $this->validateFolderConfirmation($request, $folder);
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

    public function forceDestroyFolder(Request $request, int $folder, LibraryService $library): RedirectResponse
    {
        $trashedFolder = DocumentFolder::onlyTrashed()->findOrFail($folder);
        $this->validateFolderConfirmation($request, $trashedFolder);
        $library->forceDeleteFolder($trashedFolder);

        return redirect()->route('library.trash')->with('status', 'Dossier supprimé définitivement.');
    }

    public function storeDocument(Request $request, LibraryService $library): RedirectResponse
    {
        $data = $request->validate([
            'folder_id' => ['required', 'integer', Rule::exists('document_folders', 'id')->whereNull('deleted_at')],
            'document' => ['required', 'file', 'max:10240', 'extensions:jpg,jpeg,png,webp,gif', 'mimes:jpg,jpeg,png,webp,gif', 'image'],
        ], [
            'folder_id.required' => 'Ouvrez un dossier avant d’ajouter une image.',
            'document.max' => 'L’image ne doit pas dépasser 10 Mo.',
            'document.extensions' => 'Seules les images JPG, PNG, WEBP et GIF sont autorisées.',
            'document.mimes' => 'Seules les images JPG, PNG, WEBP et GIF sont autorisées.',
            'document.image' => 'Le fichier fourni doit être une image valide.',
        ]);

        try {
            $library->storeDocument((int) $data['folder_id'], $data['document']);
        } catch (\RuntimeException $error) {
            throw ValidationException::withMessages(['document' => $error->getMessage()]);
        }

        return $this->backToFolder($data['folder_id'])->with('status', 'Image ajoutée.');
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

    private function validateFolderConfirmation(Request $request, DocumentFolder $folder): void
    {
        $data = $request->validate([
            'confirmation_name' => ['required', 'string', 'max:120'],
        ], ['confirmation_name.required' => 'Saisissez exactement le nom du dossier pour confirmer.']);

        if ($data['confirmation_name'] !== $folder->name) {
            throw ValidationException::withMessages([
                'confirmation_name' => 'Le nom saisi ne correspond pas exactement à « '.$folder->name.' ».',
            ]);
        }
    }

    private function backToFolder(int|string|null $folderId): RedirectResponse
    {
        return redirect()->route('library.index', $folderId ? ['folder' => $folderId] : []);
    }
}

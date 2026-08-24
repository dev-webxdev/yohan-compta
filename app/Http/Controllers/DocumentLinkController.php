<?php

namespace App\Http\Controllers;

use App\Models\DocumentLink;
use App\Models\LibraryDocument;
use App\Services\DocumentLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class DocumentLinkController
{
    public function store(Request $request, LibraryDocument $document, DocumentLinkService $links): RedirectResponse
    {
        $data = $request->validate(['target' => ['required', 'string', 'max:80']]);
        try {
            $links->attach($document, $data['target']);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['target' => $error->getMessage()]);
        }

        return back()->with('status', 'Document associé.');
    }

    public function destroy(LibraryDocument $document, DocumentLink $link, DocumentLinkService $links): RedirectResponse
    {
        try {
            $links->remove($document, $link);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['target' => $error->getMessage()]);
        }

        return back()->with('status', 'Association supprimée.');
    }
}

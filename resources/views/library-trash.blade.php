@extends('layouts.app')
@section('title', 'Corbeille · Bibliothèque')
@section('content')
@php
    $formatBytes = static function (int $bytes): string {
        if ($bytes < 1024) return $bytes.' o';
        if ($bytes < 1048576) return number_format($bytes / 1024, 1, ',', ' ').' Ko';
        return number_format($bytes / 1048576, 1, ',', ' ').' Mo';
    };
@endphp
<div class="library-page library-trash-page">
<section class="panel library-toolbar-panel">
    <div class="library-heading">
        <div class="library-title-block"><span class="library-title-icon library-trash-title-icon"><i class="fa-regular fa-trash-can"></i></span><div><h2>Corbeille</h2><p>Restaurez un élément supprimé ou effacez-le définitivement.</p></div></div>
        <div class="library-toolbar-actions"><a class="primary-button secondary-button" href="{{ route('library.index') }}"><i class="fa-solid fa-arrow-left"></i> Retour à la bibliothèque</a></div>
    </div>
    @error('trash')<div class="field-error library-upload-error">{{ $message }}</div>@enderror
    @error('confirmation_name')<div class="field-error library-upload-error">{{ $message }}</div>@enderror
</section>

<section class="panel library-content-panel">
    @if($folders->isNotEmpty())
        <div class="library-section-title"><div><i class="fa-solid fa-folder"></i><h3>Dossiers supprimés</h3></div><span>{{ $folders->count() }}</span></div>
        <div class="library-trash-grid">
            @foreach($folders as $item)
                <article class="library-trash-card">
                    <div class="library-trash-card-main">
                        <span class="library-folder-icon is-trashed"><i class="fa-solid fa-folder"></i></span>
                        <div><strong>{{ $item->name }}</strong><small>Supprimé le {{ $item->deleted_at?->format('d/m/Y à H:i') }}@if($item->parent) · dans {{ $item->parent->name }}@endif</small></div>
                    </div>
                    <div class="library-trash-actions">
                        <form method="post" action="{{ route('library.trash.folders.restore', ['folder' => $item->id]) }}">@csrf<button class="library-restore-button"><i class="fa-solid fa-rotate-left"></i> Restaurer</button></form>
                        <details class="library-permanent-delete" data-dismissable-details>
                            <summary class="library-permanent-delete-trigger"><i class="fa-solid fa-trash-can"></i> Supprimer définitivement</summary>
                            <form method="post" action="{{ route('library.trash.folders.destroy', ['folder' => $item->id]) }}" class="library-delete-folder-form library-permanent-delete-form" data-folder-delete-name="{{ $item->name }}" data-confirm data-confirm-title="Supprimer définitivement ce dossier ?" data-confirm-message="Le dossier « {{ $item->name }} », ses sous-dossiers et leurs fichiers seront définitivement effacés. Cette action est irréversible." data-confirm-action="Supprimer définitivement" data-confirm-danger="1">@csrf @method('DELETE')
                                <p>Saisissez exactement <code>{{ $item->name }}</code>.</p>
                                <input name="confirmation_name" autocomplete="off" aria-label="Nom du dossier à confirmer définitivement" required>
                                <button class="danger-button compact-button" data-folder-delete-submit disabled><i class="fa-solid fa-trash-can"></i> Supprimer définitivement</button>
                            </form>
                        </details>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if($documents->isNotEmpty())
        <div class="library-section-title library-files-title"><div><i class="fa-regular fa-images"></i><h3>Images supprimées</h3></div><span>{{ $documents->count() }}</span></div>
        <div class="library-file-list">
            @foreach($documents as $document)
                <article class="library-file-row library-trash-file-row">
                    <div class="library-file-icon is-trashed"><i class="fa-regular fa-image"></i></div>
                    <div class="library-file-name"><strong>{{ $document->original_name }}</strong><small>{{ $formatBytes($document->size_bytes) }} · supprimé le {{ $document->deleted_at?->format('d/m/Y à H:i') }}@if($document->folder) · {{ $document->folder->name }}@endif</small></div>
                    <div class="library-file-actions">
                        <form method="post" action="{{ route('library.trash.documents.restore', ['document' => $document->id]) }}">@csrf<button class="library-restore-button compact-button"><i class="fa-solid fa-rotate-left"></i> Restaurer</button></form>
                        <form method="post" action="{{ route('library.trash.documents.destroy', ['document' => $document->id]) }}" data-confirm data-confirm-title="Supprimer définitivement cette image ?" data-confirm-message="« {{ $document->original_name }} » sera définitivement effacée. Cette action est irréversible." data-confirm-action="Supprimer définitivement" data-confirm-danger="1">@csrf @method('DELETE')<button class="danger-button compact-button"><i class="fa-solid fa-trash-can"></i> Supprimer définitivement</button></form>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if($folders->isEmpty() && $documents->isEmpty())
        <div class="library-empty library-trash-empty"><span class="library-empty-icon"><i class="fa-regular fa-trash-can"></i></span><h3>La corbeille est vide</h3><p>Les dossiers et images supprimés apparaîtront ici avant leur suppression définitive.</p></div>
    @endif
</section>
</div>
@endsection

@extends('layouts.app')
@section('title', 'Bibliothèque')
@section('content')
@php
    $formatBytes = static function (int $bytes): string {
        if ($bytes < 1024) return $bytes.' o';
        if ($bytes < 1048576) return number_format($bytes / 1024, 1, ',', ' ').' Ko';
        return number_format($bytes / 1048576, 1, ',', ' ').' Mo';
    };
@endphp
<div class="library-page">
<section class="panel library-toolbar-panel">
    <div class="library-heading">
        <div>
            <h2>Bibliothèque</h2>
            <p>Organisez librement vos documents. Aucun dossier n’est créé automatiquement.</p>
        </div>
        <div class="library-toolbar-actions">
            <details class="library-create-details" @if($errors->has('name')) open @endif>
                <summary class="primary-button secondary-button"><i class="fa-solid fa-folder-plus"></i> Nouveau dossier</summary>
                <form method="post" action="{{ route('library.folders.store') }}" class="library-popover-form">@csrf
                    @if($folder)<input type="hidden" name="parent_id" value="{{ $folder->id }}">@endif
                    <label>Nom du dossier<input name="name" value="{{ old('name') }}" maxlength="120" required autofocus @error('name') aria-invalid="true" @enderror></label>
                    @error('name')<span class="field-error">{{ $message }}</span>@enderror
                    <button class="primary-button"><i class="fa-solid fa-plus"></i> Créer</button>
                </form>
            </details>
            <form method="post" action="{{ route('library.documents.store') }}" enctype="multipart/form-data" class="library-upload-form">@csrf
                @if($folder)<input type="hidden" name="folder_id" value="{{ $folder->id }}">@endif
                <label class="primary-button library-upload-button"><i class="fa-solid fa-file-arrow-up"></i> Importer un fichier<input type="file" name="document" required></label>
            </form>
        </div>
    </div>
    @error('document')<div class="field-error library-upload-error">{{ $message }}</div>@enderror

    <nav class="library-breadcrumbs" aria-label="Fil d’Ariane">
        <a href="{{ route('library.index') }}"><i class="fa-solid fa-house"></i> Bibliothèque</a>
        @foreach($breadcrumbs as $crumb)
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            @if($loop->last)<span aria-current="page">{{ $crumb->name }}</span>@else<a href="{{ route('library.index', ['folder' => $crumb->id]) }}">{{ $crumb->name }}</a>@endif
        @endforeach
    </nav>
</section>

<section class="panel library-content-panel">
    @if($folder)
        <div class="library-current-folder"><i class="fa-solid fa-folder-open"></i><div><strong>{{ $folder->name }}</strong><span>{{ $folders->count() }} dossier{{ $folders->count() > 1 ? 's' : '' }} · {{ $documents->count() }} fichier{{ $documents->count() > 1 ? 's' : '' }}</span></div></div>
    @endif

    @if($folders->isNotEmpty())
        <div class="library-section-title"><h3>Dossiers</h3><span>{{ $folders->count() }}</span></div>
        <div class="library-folder-grid">
            @foreach($folders as $item)
                <article class="library-folder-card">
                    <a class="library-folder-link" href="{{ route('library.index', ['folder' => $item->id]) }}">
                        <i class="fa-solid fa-folder"></i>
                        <span><strong>{{ $item->name }}</strong><small>{{ $item->children_count }} sous-dossier{{ $item->children_count > 1 ? 's' : '' }} · {{ $item->documents_count }} fichier{{ $item->documents_count > 1 ? 's' : '' }}</small></span>
                    </a>
                    <details class="library-item-menu">
                        <summary aria-label="Actions pour {{ $item->name }}"><i class="fa-solid fa-ellipsis-vertical"></i></summary>
                        <div class="library-item-menu-content">
                            <form method="post" action="{{ route('library.folders.update', $item) }}" class="library-rename-form">@csrf @method('PATCH')
                                <label>Renommer<input name="name" value="{{ $item->name }}" maxlength="120" required></label>
                                <button class="primary-button compact-button"><i class="fa-solid fa-check"></i> Enregistrer</button>
                            </form>
                            <form method="post" action="{{ route('library.folders.destroy', $item) }}" data-confirm data-confirm-title="Supprimer ce dossier ?" data-confirm-message="Le dossier « {{ $item->name }} », tous ses sous-dossiers et tous leurs fichiers seront définitivement supprimés." data-confirm-action="Supprimer" data-confirm-danger="1">@csrf @method('DELETE')
                                <button class="danger-button compact-button"><i class="fa-solid fa-trash"></i> Supprimer le dossier</button>
                            </form>
                        </div>
                    </details>
                </article>
            @endforeach
        </div>
    @endif

    @if($documents->isNotEmpty())
        <div class="library-section-title library-files-title"><h3>Fichiers</h3><span>{{ $documents->count() }}</span></div>
        <div class="library-file-list">
            @foreach($documents as $document)
                <article class="library-file-row">
                    <div class="library-file-icon"><i class="fa-regular fa-file"></i></div>
                    <div class="library-file-name"><strong title="{{ $document->original_name }}">{{ $document->original_name }}</strong><small>{{ $formatBytes($document->size_bytes) }}@if($document->mime_type) · {{ $document->mime_type }}@endif</small></div>
                    <div class="library-file-actions">
                        <a class="primary-button secondary-button compact-button" href="{{ route('library.documents.download', $document) }}"><i class="fa-solid fa-download"></i> Télécharger</a>
                        <form method="post" action="{{ route('library.documents.destroy', $document) }}" data-confirm data-confirm-title="Supprimer ce fichier ?" data-confirm-message="Le fichier « {{ $document->original_name }} » sera définitivement supprimé." data-confirm-action="Supprimer" data-confirm-danger="1">@csrf @method('DELETE')<button class="danger-button compact-button" aria-label="Supprimer {{ $document->original_name }}"><i class="fa-solid fa-trash"></i></button></form>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if($folders->isEmpty() && $documents->isEmpty())
        <div class="library-empty"><i class="fa-regular fa-folder-open"></i><h3>Ce dossier est vide</h3><p>Créez un dossier ou importez directement un fichier ici.</p></div>
    @endif
</section>
</div>
@endsection

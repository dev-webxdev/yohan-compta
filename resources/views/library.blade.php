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
        <div class="library-title-block">
            <span class="library-title-icon"><i class="fa-solid fa-folder-tree"></i></span>
            <div><h2>Bibliothèque</h2><p>Organisez vos images dans vos propres dossiers. Aucun dossier n’est créé automatiquement.</p></div>
        </div>
        <div class="library-toolbar-actions">
            <a class="primary-button secondary-button library-toolbar-button library-trash-link" href="{{ route('library.trash') }}"><i class="fa-regular fa-trash-can"></i><span>Corbeille</span>@if($trash_count)<strong>{{ $trash_count }}</strong>@endif</a>
            <details class="library-create-details" data-dismissable-details @if($errors->has('name')) open @endif>
                <summary class="primary-button secondary-button library-toolbar-button"><i class="fa-solid fa-folder-plus"></i> Nouveau dossier</summary>
                <form method="post" action="{{ route('library.folders.store') }}" class="library-popover-form">@csrf
                    @if($folder)<input type="hidden" name="parent_id" value="{{ $folder->id }}">@endif
                    <label>Nom du dossier<input name="name" value="{{ old('name') }}" maxlength="120" required autofocus @error('name') aria-invalid="true" @enderror></label>
                    @error('name')<span class="field-error">{{ $message }}</span>@enderror
                    <button class="primary-button"><i class="fa-solid fa-plus"></i> Créer le dossier</button>
                </form>
            </details>
            @if($folder)
                <form method="post" action="{{ route('library.documents.store') }}" enctype="multipart/form-data" class="library-upload-form">@csrf
                    <input type="hidden" name="folder_id" value="{{ $folder->id }}">
                    <label class="primary-button library-toolbar-button library-upload-button"><i class="fa-solid fa-image"></i> Ajouter une image<input type="file" name="document" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" required></label>
                </form>
            @endif
        </div>
    </div>
    @error('document')<div class="field-error library-upload-error">{{ $message }}</div>@enderror
    @error('confirmation_name')<div class="field-error library-upload-error">{{ $message }}</div>@enderror

    <nav class="library-breadcrumbs" aria-label="Fil d’Ariane">
        <a href="{{ route('library.index') }}"><i class="fa-solid fa-house"></i><span>Bibliothèque</span></a>
        @foreach($breadcrumbs as $crumb)
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            @if($loop->last)<span class="library-breadcrumb-current" aria-current="page">{{ $crumb->name }}</span>@else<a href="{{ route('library.index', ['folder' => $crumb->id]) }}">{{ $crumb->name }}</a>@endif
        @endforeach
    </nav>
</section>

<section class="panel library-content-panel">
    @if($folder)
        <div class="library-current-folder">
            <span class="library-current-folder-icon"><i class="fa-solid fa-folder-open"></i></span>
            <div><strong>{{ $folder->name }}</strong><span>{{ $folders->count() }} dossier{{ $folders->count() > 1 ? 's' : '' }} · {{ $documents->count() }} image{{ $documents->count() > 1 ? 's' : '' }}</span></div>
        </div>
    @endif

    @if($folders->isNotEmpty())
        <div class="library-section-title"><div><i class="fa-solid fa-folder"></i><h3>Dossiers</h3></div><span>{{ $folders->count() }}</span></div>
        <div class="library-folder-grid">
            @foreach($folders as $item)
                <article class="library-folder-card">
                    <a class="library-folder-link" href="{{ route('library.index', ['folder' => $item->id]) }}">
                        <span class="library-folder-icon"><i class="fa-solid fa-folder"></i></span>
                        <span class="library-folder-copy"><strong>{{ $item->name }}</strong><small>{{ $item->children_count }} sous-dossier{{ $item->children_count > 1 ? 's' : '' }} · {{ $item->documents_count }} image{{ $item->documents_count > 1 ? 's' : '' }}</small></span>
                        <i class="fa-solid fa-chevron-right library-folder-chevron" aria-hidden="true"></i>
                    </a>
                    <details class="library-item-menu" data-dismissable-details>
                        <summary aria-label="Actions pour {{ $item->name }}"><i class="fa-solid fa-ellipsis"></i></summary>
                        <div class="library-item-menu-content">
                            <div class="library-menu-title"><strong>{{ $item->name }}</strong><span>Actions du dossier</span></div>
                            <form method="post" action="{{ route('library.folders.update', $item) }}" class="library-rename-form">@csrf @method('PATCH')
                                <label>Renommer<input name="name" value="{{ $item->name }}" maxlength="120" required></label>
                                <button class="primary-button compact-button"><i class="fa-solid fa-check"></i> Enregistrer</button>
                            </form>
                            <form method="post" action="{{ route('library.folders.destroy', $item) }}" class="library-delete-folder-form" data-folder-delete-name="{{ $item->name }}" data-confirm data-confirm-title="Déplacer ce dossier dans la corbeille ?" data-confirm-message="Le dossier « {{ $item->name }} » et tout son contenu seront placés dans la corbeille. Vous pourrez les restaurer." data-confirm-action="Mettre à la corbeille" data-confirm-danger="1">@csrf @method('DELETE')
                                <div class="library-danger-zone">
                                    <strong><i class="fa-solid fa-shield-halved"></i> Suppression sécurisée</strong>
                                    <p>Saisissez exactement <code>{{ $item->name }}</code> pour déverrouiller l’action.</p>
                                    <input name="confirmation_name" autocomplete="off" aria-label="Nom du dossier à confirmer" placeholder="{{ $item->name }}" required>
                                    <button class="danger-button compact-button" data-folder-delete-submit disabled><i class="fa-regular fa-trash-can"></i> Mettre à la corbeille</button>
                                </div>
                            </form>
                        </div>
                    </details>
                </article>
            @endforeach
        </div>
    @endif

    @if($documents->isNotEmpty())
        <div class="library-section-title library-files-title"><div><i class="fa-regular fa-images"></i><h3>Images</h3></div><span>{{ $documents->count() }}</span></div>
        <div class="library-file-list">
            @foreach($documents as $document)
                <article class="library-file-row">
                    <div class="library-file-icon"><i class="fa-regular fa-image"></i></div>
                    <div class="library-file-name"><strong title="{{ $document->original_name }}">{{ $document->original_name }}</strong><small>{{ $formatBytes($document->size_bytes) }}@if($document->mime_type) · {{ str_replace('image/', '', $document->mime_type) }}@endif</small></div>
                    <div class="library-file-actions">
                        <a class="primary-button secondary-button compact-button" href="{{ route('library.documents.download', $document) }}"><i class="fa-solid fa-download"></i> Télécharger</a>
                        <form method="post" action="{{ route('library.documents.destroy', $document) }}" data-confirm data-confirm-title="Mettre cette image dans la corbeille ?" data-confirm-message="« {{ $document->original_name }} » sera déplacée dans la corbeille et pourra être restaurée." data-confirm-action="Mettre à la corbeille" data-confirm-danger="1">@csrf @method('DELETE')<button class="library-file-trash-button" aria-label="Mettre {{ $document->original_name }} à la corbeille"><i class="fa-regular fa-trash-can"></i></button></form>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if($folders->isEmpty() && $documents->isEmpty())
        <div class="library-empty">
            <span class="library-empty-icon"><i class="{{ $folder ? 'fa-regular fa-folder-open' : 'fa-solid fa-folder-plus' }}"></i></span>
            <h3>{{ $folder ? 'Ce dossier est vide' : 'Votre bibliothèque est vide' }}</h3>
            <p>{{ $folder ? 'Ajoutez une image ici ou créez un sous-dossier pour continuer à organiser votre bibliothèque.' : 'Créez votre premier dossier pour commencer. Les images ne peuvent être ajoutées qu’à l’intérieur d’un dossier.' }}</p>
        </div>
    @endif
</section>
</div>
@endsection

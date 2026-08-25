@extends('layouts.app')
@section('title', 'Documents')
@section('content')
@php
    $formatBytes = static function (int $bytes): string {
        if ($bytes < 1024) return $bytes.' o';
        if ($bytes < 1048576) return number_format($bytes / 1024, 1, ',', ' ').' Ko';
        return number_format($bytes / 1048576, 1, ',', ' ').' Mo';
    };
    $salaryAssociationTargets = $associationTargets['salary'] ?? [];
    $monthAssociationTargets = $associationTargets['month'] ?? [];
    $hasAssociationTargets = $salaryAssociationTargets !== [] || $monthAssociationTargets !== [];
    $defaultAssociationType = $salaryAssociationTargets !== [] ? 'salary' : 'month';
@endphp
<div class="library-page">
<section class="panel library-toolbar-panel">
    <div class="library-heading">
        <div class="library-title-block">
            <span class="library-title-icon"><i class="fa-solid fa-folder-tree"></i></span>
            <div><h2>Documents</h2><p>Organisez librement vos justificatifs et documents. Aucun dossier n’est créé automatiquement.</p></div>
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
                    <label class="primary-button library-toolbar-button library-upload-button"><i class="fa-solid fa-file-arrow-up"></i> Ajouter un document<input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,application/pdf,image/jpeg,image/png,image/webp,image/gif" required></label>
                </form>
            @endif
        </div>
    </div>
    @error('document')<div class="field-error library-upload-error">{{ $message }}</div>@enderror

    <nav class="library-breadcrumbs" aria-label="Fil d’Ariane">
        <a href="{{ route('library.index') }}"><i class="fa-solid fa-house"></i><span>Documents</span></a>
        @foreach($breadcrumbs as $crumb)
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            @if($loop->last)<span class="library-breadcrumb-current" aria-current="page">{{ $crumb->name }}</span>@else<a href="{{ route('library.index', ['folder' => $crumb->id]) }}">{{ $crumb->name }}</a>@endif
        @endforeach
    </nav>
</section>

@if($associationFilter)
<section class="panel linked-documents-panel">
    <div class="panel-heading"><div><h2><i class="fa-solid fa-paperclip"></i> Documents associés</h2><p>Documents rattachés à la donnée depuis laquelle vous êtes arrivé.</p></div><a class="primary-button secondary-button compact-button" href="{{ route('library.index') }}">Afficher tous les documents</a></div>
    <div class="linked-document-list">
    @forelse($linkedDocuments as $linked)
        <article><span class="library-file-icon"><i class="{{ $linked->mime_type === 'application/pdf' ? 'fa-regular fa-file-pdf' : 'fa-regular fa-image' }}"></i></span><div><strong>{{ $linked->original_name }}</strong><small>{{ $linked->folder?->name ?? 'Sans dossier' }}</small></div>
            <div class="linked-document-actions">
                <a class="primary-button secondary-button compact-button" href="{{ route('library.documents.preview', $linked) }}" target="_blank" rel="noopener"><i class="fa-regular fa-eye"></i> Voir</a>
                <a class="primary-button secondary-button compact-button" href="{{ route('library.documents.download', $linked) }}"><i class="fa-solid fa-download"></i> Télécharger</a>
            </div>
        </article>
    @empty
        <p class="muted">Aucun document n’est actuellement associé à cette donnée.</p>
    @endforelse
    </div>
</section>
@endif

<section class="panel library-content-panel">
    @if($folder)
        <div class="library-current-folder">
            <span class="library-current-folder-icon"><i class="fa-solid fa-folder-open"></i></span>
            <div><strong>{{ $folder->name }}</strong><span>{{ $folders->count() }} dossier{{ $folders->count() > 1 ? 's' : '' }} · {{ $documents->count() }} document{{ $documents->count() > 1 ? 's' : '' }}</span></div>
        </div>
    @endif

    @if($folders->isNotEmpty())
        <div class="library-section-title"><div><i class="fa-solid fa-folder"></i><h3>Dossiers</h3></div><span>{{ $folders->count() }}</span></div>
        <div class="library-folder-grid">
            @foreach($folders as $item)
                <article class="library-folder-card">
                    <a class="library-folder-link" href="{{ route('library.index', ['folder' => $item->id]) }}">
                        <span class="library-folder-icon"><i class="fa-solid fa-folder"></i></span>
                        <span class="library-folder-copy"><strong>{{ $item->name }}</strong><small>{{ $item->children_count }} sous-dossier{{ $item->children_count > 1 ? 's' : '' }} · {{ $item->documents_count }} document{{ $item->documents_count > 1 ? 's' : '' }}</small></span>
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
                            <form method="post" action="{{ route('library.folders.destroy', $item) }}" data-confirm data-confirm-title="Déplacer ce dossier dans la corbeille ?" data-confirm-message="Le dossier « {{ $item->name }} » et tout son contenu seront placés dans la corbeille. Vous pourrez les restaurer." data-confirm-action="Mettre à la corbeille" data-confirm-danger="1">@csrf @method('DELETE')<button class="danger-button compact-button"><i class="fa-regular fa-trash-can"></i> Mettre à la corbeille</button></form>
                        </div>
                    </details>
                </article>
            @endforeach
        </div>
    @endif

    @if($documents->isNotEmpty())
        <div class="library-section-title library-files-title"><div><i class="fa-regular fa-file-lines"></i><h3>Documents</h3></div><span>{{ $documents->count() }}</span></div>
        <div class="library-file-list">
            @foreach($documents as $document)
                <article class="library-file-row">
                    <div class="library-file-icon"><i class="{{ $document->mime_type === 'application/pdf' ? 'fa-regular fa-file-pdf' : 'fa-regular fa-image' }}"></i></div>
                    <div class="library-file-name"><strong title="{{ $document->original_name }}">{{ $document->original_name }}</strong><small>{{ $formatBytes($document->size_bytes) }}@if($document->mime_type) · {{ $document->mime_type === 'application/pdf' ? 'PDF' : str_replace('image/', '', $document->mime_type) }}@endif</small>
                        @if($document->links->isNotEmpty())<div class="document-link-tags">@foreach($document->links as $link)<span><i class="fa-solid fa-paperclip"></i>{{ $linkLabels[$document->id][$link->id] ?? $link->token() }}<form method="post" action="{{ route('library.documents.links.destroy', ['document'=>$document,'link'=>$link]) }}">@csrf @method('DELETE')<button aria-label="Supprimer l’association"><i class="fa-solid fa-xmark"></i></button></form></span>@endforeach</div>@endif
                    </div>
                    <div class="library-file-actions">
                        @if($hasAssociationTargets)<button type="button" class="primary-button secondary-button compact-button" data-document-associate data-associate-url="{{ route('library.documents.links.store', $document) }}" data-document-name="{{ $document->original_name }}"><i class="fa-solid fa-link"></i> Associer</button>@endif
                        <a class="primary-button secondary-button compact-button" href="{{ route('library.documents.preview', $document) }}" target="_blank" rel="noopener"><i class="fa-regular fa-eye"></i> Voir</a>
                        <a class="primary-button secondary-button compact-button" href="{{ route('library.documents.download', $document) }}"><i class="fa-solid fa-download"></i> Télécharger</a>
                        <form method="post" action="{{ route('library.documents.destroy', $document) }}" data-confirm data-confirm-title="Mettre ce document dans la corbeille ?" data-confirm-message="« {{ $document->original_name }} » sera déplacé dans la corbeille et pourra être restauré." data-confirm-action="Mettre à la corbeille" data-confirm-danger="1">@csrf @method('DELETE')<button class="library-file-trash-button" aria-label="Mettre {{ $document->original_name }} à la corbeille"><i class="fa-regular fa-trash-can"></i></button></form>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if($folders->isEmpty() && $documents->isEmpty())
        <div class="library-empty">
            <span class="library-empty-icon"><i class="{{ $folder ? 'fa-regular fa-folder-open' : 'fa-solid fa-folder-plus' }}"></i></span>
            <h3>{{ $folder ? 'Ce dossier est vide' : 'Votre espace Documents est vide' }}</h3>
            <p>{{ $folder ? 'Ajoutez un document ici ou créez un sous-dossier pour continuer à organiser vos fichiers.' : 'Créez votre premier dossier pour commencer. Les documents ne peuvent être ajoutés qu’à l’intérieur d’un dossier.' }}</p>
        </div>
    @endif
</section>
</div>

@if($hasAssociationTargets)
<dialog id="document-associate-dialog" class="document-associate-dialog" aria-labelledby="document-associate-title">
    <form method="post" action="#" id="document-associate-form">
        @csrf
        <div class="document-associate-head">
            <div><h2 id="document-associate-title">Associer le document</h2><p id="document-associate-name"></p></div>
            <button type="button" class="document-associate-close" data-document-associate-close aria-label="Fermer"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="document-associate-body">
            <fieldset class="document-associate-types">
                <legend>Associer à</legend>
                <label class="document-associate-type">
                    <input type="radio" name="association_kind" value="salary" data-association-kind {{ $defaultAssociationType === 'salary' ? 'checked' : '' }} {{ $salaryAssociationTargets === [] ? 'disabled' : '' }}>
                    <span><i class="fa-solid fa-wallet"></i><strong>Salaire</strong><small>{{ $salaryAssociationTargets === [] ? 'Aucun salaire enregistré' : count($salaryAssociationTargets).' mois disponible'.(count($salaryAssociationTargets) > 1 ? 's' : '') }}</small></span>
                </label>
                <label class="document-associate-type">
                    <input type="radio" name="association_kind" value="month" data-association-kind {{ $defaultAssociationType === 'month' ? 'checked' : '' }} {{ $monthAssociationTargets === [] ? 'disabled' : '' }}>
                    <span><i class="fa-regular fa-clock"></i><strong>Heures du mois</strong><small>{{ $monthAssociationTargets === [] ? 'Aucun mois avec des heures' : count($monthAssociationTargets).' mois disponible'.(count($monthAssociationTargets) > 1 ? 's' : '') }}</small></span>
                </label>
            </fieldset>
            <div class="document-associate-options" data-association-options="salary" {{ $defaultAssociationType !== 'salary' ? 'hidden' : '' }}>
                <label>Salaire<select name="target" data-association-target="salary" required {{ $defaultAssociationType !== 'salary' ? 'disabled' : '' }}>@foreach($salaryAssociationTargets as $target)<option value="{{ $target['value'] }}">{{ $target['label'] }}</option>@endforeach</select></label>
            </div>
            <div class="document-associate-options" data-association-options="month" {{ $defaultAssociationType !== 'month' ? 'hidden' : '' }}>
                <label>Mois avec des heures enregistrées<select name="target" data-association-target="month" required {{ $defaultAssociationType !== 'month' ? 'disabled' : '' }}>@foreach($monthAssociationTargets as $target)<option value="{{ $target['value'] }}">{{ $target['label'] }}</option>@endforeach</select></label>
            </div>
        </div>
        <div class="document-associate-actions">
            <button type="button" class="document-associate-cancel" data-document-associate-close>Annuler</button>
            <button class="primary-button"><i class="fa-solid fa-link"></i> Associer</button>
        </div>
    </form>
</dialog>
@endif
@endsection

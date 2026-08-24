@extends('layouts.app')
@php use App\Support\Money; use App\Support\Time; @endphp
@section('title', 'Paramètres')
@section('content')
<div class="settings-page">
@if($nextScheduled)<div class="future-notice" role="note"><i class="fa-solid fa-calendar-check"></i><span>Prochain changement programmé : <strong>{{ $nextScheduled->effective_from->format('d/m/Y') }}</strong> — taux {{ Money::formatCents($nextScheduled->hourly_net_rate_cents) }}/h, seuil {{ Time::formatDuration($nextScheduled->weekly_threshold_minutes) }}, panier {{ Money::formatCents($nextScheduled->meal_allowance_cents) }}.</span></div>@endif
<section class="panel"><div class="panel-heading"><div><h2>Nouveaux paramètres</h2><p>Les valeurs s’appliquent uniquement à partir de la date choisie. L’historique reste intact.</p></div></div>
<form method="post" action="{{ route('settings.store') }}" class="form-grid" data-settings-form data-values-url="{{ route('settings.values') }}" data-today="{{ now()->format('Y-m-d') }}">@csrf
<label>Date d’effet<input type="date" name="effective_from" value="{{ old('effective_from', now()->format('Y-m-d')) }}" required @error('effective_from') aria-invalid="true" @enderror>@error('effective_from')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>Heure de début par défaut<input name="default_start_time" value="{{ old('default_start_time', Time::formatClock($current->default_start_time_minutes)) }}" inputmode="numeric" required @error('default_start_time') aria-invalid="true" @enderror>@error('default_start_time')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>Taux horaire net (€)<input name="hourly_net_rate" value="{{ old('hourly_net_rate', Money::formatInput($current->hourly_net_rate_cents)) }}" inputmode="decimal" required @error('hourly_net_rate') aria-invalid="true" @enderror>@error('hourly_net_rate')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>Seuil hebdomadaire<input name="weekly_threshold" value="{{ old('weekly_threshold', Time::formatDuration($current->weekly_threshold_minutes)) }}" inputmode="numeric" required @error('weekly_threshold') aria-invalid="true" @enderror>@error('weekly_threshold')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>Montant panier (€)<input name="meal_allowance" value="{{ old('meal_allowance', Money::formatInput($current->meal_allowance_cents)) }}" inputmode="decimal" required @error('meal_allowance') aria-invalid="true" @enderror>@error('meal_allowance')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>Panier à partir de<input name="meal_allowance_time" value="{{ old('meal_allowance_time', Time::formatClock($current->meal_allowance_time_minutes)) }}" inputmode="numeric" required @error('meal_allowance_time') aria-invalid="true" @enderror>@error('meal_allowance_time')<span class="field-error">{{ $message }}</span>@enderror</label>
<p class="wide hint" id="settings-date-hint" aria-live="polite"></p>
<div class="wide"><button class="primary-button">Enregistrer à cette date</button></div>
</form></section>

<section class="panel database-maintenance">
<div class="panel-heading"><div><h2>Sauvegarde et restauration</h2><p>La sauvegarde complète inclut la base SQLite et tous les fichiers de Documents.</p></div></div>
<div class="database-actions">
<article class="maintenance-card"><div><h3>Sauvegarder toutes les données</h3><p>Télécharger une archive ZIP avec la base SQLite et tous les fichiers de Documents.</p></div><a class="primary-button" href="{{ route('settings.database.backup') }}"><i class="fa-solid fa-download"></i> Télécharger la sauvegarde</a></article>

<article class="maintenance-card"><div><h3>Restaurer une sauvegarde</h3><p>Les ZIP restaurent toutes les données. Les anciennes sauvegardes SQLite restaurent la base en conservant les Documents actuels. Une sauvegarde complète de sécurité est créée avant le remplacement.</p></div><form method="post" action="{{ route('settings.database.restore') }}" enctype="multipart/form-data" data-confirm data-confirm-title="Restaurer cette sauvegarde ?" data-confirm-message="Une sauvegarde complète de l’état actuel sera créée, puis les données seront remplacées." data-confirm-action="Restaurer">@csrf
<input type="hidden" name="confirmed" value="1">
<input type="file" name="database_file" accept=".zip,.sqlite,.db,application/zip,application/vnd.sqlite3,application/octet-stream" required @error('database_file') aria-invalid="true" @enderror>@error('database_file')<span class="field-error">{{ $message }}</span>@enderror
<button class="primary-button"><i class="fa-solid fa-upload"></i> Restaurer la sauvegarde</button>
</form></article>
</div>
</section>

<section class="panel backup-library">
<div class="panel-heading"><div><h2>Sauvegardes de sécurité</h2><p>Créées automatiquement avant chaque restauration. Elles contiennent la base SQLite et tous les Documents.</p></div></div>
<div class="backup-list">
@forelse($backups as $backup)
<article class="backup-row">
<div class="backup-info"><strong>{{ $backup['name'] }}</strong><span>{{ $backup['created_at'] }} · {{ number_format($backup['size_bytes'] / 1024, 0, ',', ' ') }} Ko</span></div>
<div class="backup-actions">
<a class="primary-button secondary-button" href="{{ route('settings.database.backups.download', ['backup' => $backup['name']]) }}"><i class="fa-solid fa-download"></i> Télécharger</a>
<form method="post" action="{{ route('settings.database.backups.restore', ['backup' => $backup['name']]) }}" data-confirm data-confirm-title="Restaurer cette sauvegarde ?" data-confirm-message="Une nouvelle sauvegarde complète de l’état actuel sera créée avant la restauration." data-confirm-action="Restaurer">@csrf<input type="hidden" name="confirmed" value="1"><button class="primary-button"><i class="fa-solid fa-clock-rotate-left"></i> Restaurer</button></form>
<form method="post" action="{{ route('settings.database.backups.delete', ['backup' => $backup['name']]) }}" data-confirm data-confirm-title="Supprimer cette sauvegarde ?" data-confirm-message="Cette sauvegarde sera définitivement supprimée." data-confirm-action="Supprimer" data-confirm-danger="1">@csrf @method('DELETE')<input type="hidden" name="confirmed" value="1"><button class="danger-button"><i class="fa-solid fa-trash"></i> Supprimer</button></form>
</div>
</article>
@empty
<p class="muted backup-empty">Aucune sauvegarde de sécurité enregistrée.</p>
@endforelse
</div>
</section>
</div>
@endsection

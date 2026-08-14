@extends('layouts.app')
@php use App\Support\Money; use App\Support\Time; @endphp
@section('title', 'Paramètres')
@section('content')
<div class="settings-page">
<section class="panel"><div class="panel-heading"><div><h2>Nouveaux paramètres</h2><p>Les valeurs s’appliquent uniquement à partir de la date choisie. L’historique reste intact.</p></div></div>
<form method="post" action="{{ route('settings.store') }}" class="form-grid">@csrf
<label>Date d’effet<input type="date" name="effective_from" value="{{ now()->format('Y-m-d') }}" required></label>
<label>Taux horaire brut (€)<input name="hourly_gross_rate" value="{{ Money::formatInput($current->hourly_gross_rate_cents) }}" inputmode="decimal" required></label>
<label>Taux horaire net (€)<input name="hourly_net_rate" value="{{ Money::formatInput($current->hourly_net_rate_cents) }}" inputmode="decimal" required></label>
<label>Seuil hebdomadaire<input name="weekly_threshold" value="{{ Time::formatDuration($current->weekly_threshold_minutes) }}" inputmode="numeric" required></label>
<label>Montant panier (€)<input name="meal_allowance" value="{{ Money::formatInput($current->meal_allowance_cents) }}" inputmode="decimal" required></label>
<label>Panier à partir de<input name="meal_allowance_time" value="{{ Time::formatClock($current->meal_allowance_time_minutes) }}" inputmode="numeric" required></label>
<div class="wide"><button class="primary-button">Enregistrer à cette date</button></div>
</form></section>

<section class="panel database-maintenance">
<div class="panel-heading"><div><h2>Sauvegarde et réinitialisation</h2><p>Gestion de la base SQLite. Les sauvegardes automatiques sont conservées dans le stockage privé de l’application.</p></div></div>
<div class="database-actions">
<article class="maintenance-card"><div><h3>Sauvegarder la BDD</h3><p>Télécharger une copie complète et cohérente de la base SQLite actuelle.</p></div><a class="primary-button" href="{{ route('settings.database.backup') }}"><i class="fa-solid fa-download"></i> Télécharger la sauvegarde</a></article>

<article class="maintenance-card"><div><h3>Restaurer une BDD SQLite</h3><p>Le fichier est vérifié avant utilisation. Une sauvegarde de la base actuelle est automatiquement créée avant le remplacement.</p></div><form method="post" action="{{ route('settings.database.restore') }}" enctype="multipart/form-data" data-confirm data-confirm-title="Restaurer la base SQLite ?" data-confirm-message="La base actuelle sera sauvegardée puis remplacée par le fichier sélectionné." data-confirm-action="Restaurer">@csrf
<input type="file" name="database_file" accept=".sqlite,.db,application/vnd.sqlite3,application/octet-stream" required>
<label class="maintenance-confirm"><input type="checkbox" name="confirmed" value="1" required> Je confirme la restauration de la base sélectionnée.</label>
<button class="primary-button"><i class="fa-solid fa-upload"></i> Restaurer la BDD</button>
</form></article>

<article class="maintenance-card danger-card"><div><h3>Réinitialiser complètement le site</h3><p>Supprime les journées, paiements et paramètres personnalisés, puis restaure les paramètres initiaux. Une sauvegarde de sécurité est créée avant l’opération.</p></div><form method="post" action="{{ route('settings.database.reset') }}" data-confirm data-confirm-title="Réinitialiser complètement le site ?" data-confirm-message="Toutes les données seront supprimées après création d’une sauvegarde de sécurité." data-confirm-action="Réinitialiser" data-confirm-danger="1">@csrf @method('DELETE')
<label class="maintenance-confirm"><input type="checkbox" name="confirmed" value="1" required> Je confirme la suppression complète des données.</label>
<button class="danger-button"><i class="fa-solid fa-triangle-exclamation"></i> Réinitialiser le site</button>
</form></article>

<article class="maintenance-card danger-card"><div><h3>Réinitialiser un mois</h3><p>Supprime uniquement les journées et paiements datés du mois sélectionné. Les paramètres restent conservés. Une sauvegarde est créée avant l’opération.</p></div><form method="post" action="{{ route('settings.database.reset-month') }}" class="month-reset-form" data-confirm data-confirm-title="Réinitialiser ce mois ?" data-confirm-message="Les journées et paiements du mois sélectionné seront supprimés après création d’une sauvegarde." data-confirm-action="Réinitialiser" data-confirm-danger="1">@csrf @method('DELETE')
<label>Année<input type="number" name="year" min="2000" max="2200" value="{{ now()->year }}" required></label>
<label>Mois<select name="month" required>@for($month=1;$month<=12;$month++)<option value="{{ $month }}" @selected($month === (int)now()->format('n'))>{{ sprintf('%02d',$month) }}</option>@endfor</select></label>
<label class="maintenance-confirm wide"><input type="checkbox" name="confirmed" value="1" required> Je confirme la suppression des données de ce mois.</label>
<div class="wide"><button class="danger-button"><i class="fa-solid fa-calendar-xmark"></i> Réinitialiser ce mois</button></div>
</form></article>
</div>
</section>

<section class="panel backup-library">
<div class="panel-heading"><div><h2>Sauvegardes de sécurité</h2><p>Les sauvegardes créées automatiquement restent disponibles ici jusqu’à leur suppression.</p></div></div>
<div class="backup-list">
@forelse($backups as $backup)
<article class="backup-row">
<div class="backup-info"><strong>{{ $backup['name'] }}</strong><span>{{ $backup['created_at'] }} · {{ number_format($backup['size_bytes'] / 1024, 0, ',', ' ') }} Ko</span></div>
<div class="backup-actions">
<a class="primary-button secondary-button" href="{{ route('settings.database.backups.download', ['backup' => $backup['name']]) }}"><i class="fa-solid fa-download"></i> Télécharger</a>
<form method="post" action="{{ route('settings.database.backups.restore', ['backup' => $backup['name']]) }}" data-confirm data-confirm-title="Restaurer cette sauvegarde ?" data-confirm-message="L’état actuel sera remplacé sans créer de nouvelle sauvegarde automatique." data-confirm-action="Restaurer">@csrf
<input type="hidden" name="confirmed" value="1"><button class="primary-button"><i class="fa-solid fa-clock-rotate-left"></i> Restaurer</button></form>
<form method="post" action="{{ route('settings.database.backups.delete', ['backup' => $backup['name']]) }}" data-confirm data-confirm-title="Supprimer cette sauvegarde ?" data-confirm-message="Cette sauvegarde sera définitivement supprimée et ne pourra plus être restaurée." data-confirm-action="Supprimer" data-confirm-danger="1">@csrf @method('DELETE')
<input type="hidden" name="confirmed" value="1"><button class="danger-button"><i class="fa-solid fa-trash"></i> Supprimer</button></form>
</div>
</article>
@empty
<p class="muted backup-empty">Aucune sauvegarde de sécurité enregistrée.</p>
@endforelse
</div>
</section>
</div>
@endsection

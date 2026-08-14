@extends('layouts.app')
@php use App\Support\Money; use App\Support\Time; @endphp
@section('title', 'Paramètres')
@section('content')
<div>
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
</div>
@endsection

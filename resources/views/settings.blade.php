@extends('layouts.app')
@php use App\Support\Money; use App\Support\Time; @endphp
@section('title', 'Paramètres')
@section('content')
<div class="two-column-page">
<section class="panel"><div class="panel-heading"><div><h2>Nouveaux paramètres</h2><p>Les valeurs s’appliquent uniquement à partir de la date choisie. L’historique reste intact.</p></div></div>
<form method="post" action="{{ route('settings.store') }}" class="form-grid">@csrf
<label>Date d’effet<input type="date" name="effective_from" value="{{ now()->format('Y-m-d') }}" required></label>
<label>Taux horaire brut (€)<input name="hourly_rate" value="{{ number_format($current->hourly_rate_cents / 100, 2, ',', '') }}" inputmode="decimal" required></label>
<label>Seuil hebdomadaire<input name="weekly_threshold" value="{{ Time::formatDuration($current->weekly_threshold_minutes) }}" inputmode="numeric" required></label>
<label>Montant panier (€)<input name="meal_allowance" value="{{ number_format($current->meal_allowance_cents / 100, 2, ',', '') }}" inputmode="decimal" required></label>
<label>Panier à partir de<input name="meal_allowance_time" value="{{ Time::formatClock($current->meal_allowance_time_minutes) }}" inputmode="numeric" required></label>
<div class="wide"><button class="primary-button">Enregistrer à cette date</button></div>
</form></section>
<section class="panel"><div class="panel-heading"><div><h2>Historique</h2><p>Une ligne = une période de règles.</p></div></div>
<div class="settings-history">@foreach($periods as $period)<article><strong>À partir du {{ $period->effective_from->format('d/m/Y') }}</strong><span>{{ Money::formatCents($period->hourly_rate_cents) }}/h · seuil {{ Time::formatDuration($period->weekly_threshold_minutes) }} · panier {{ Money::formatCents($period->meal_allowance_cents) }} dès {{ Time::formatClock($period->meal_allowance_time_minutes) }}</span></article>@endforeach</div>
</section>
</div>
@endsection

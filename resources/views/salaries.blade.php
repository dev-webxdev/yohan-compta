@extends('layouts.app')
@php use App\Support\FrenchDate; use App\Support\Money; use App\Support\Time; @endphp
@section('title', 'Salaires')
@section('content')
<div class="salary-page">
<section class="panel salary-filter-panel">
    <div class="panel-heading"><div><h2>Suivi des salaires</h2><p>Filtrez par année ou utilisez une période personnalisée. La période personnalisée est prioritaire si elle est renseignée.</p></div></div>
    <form method="get" action="{{ route('salaries.index') }}" class="salary-filters">
        <label>Année
            <select name="year">
                @foreach($report['available_years'] as $year)<option value="{{ $year }}" @selected(!$report['filters']['custom_period'] && $report['filters']['year'] === $year)>{{ $year }}</option>@endforeach
            </select>
        </label>
        <span class="salary-filter-or">ou</span>
        <label>Du <input type="month" name="from" value="{{ $report['filters']['from'] }}"></label>
        <label>Au <input type="month" name="to" value="{{ $report['filters']['to'] }}"></label>
        <div class="salary-filter-actions"><button class="primary-button"><i class="fa-solid fa-filter"></i> Filtrer</button><a class="primary-button secondary-button" href="{{ route('salaries.index', ['year' => now()->year]) }}">Réinitialiser</a></div>
    </form>
</section>

<section class="salary-entry-grid">
    <section class="panel salary-entry-panel">
        <div class="panel-heading"><div><h2>{{ $editingSalary ? 'Modifier le salaire' : 'Enregistrer un salaire' }}</h2><p>Un seul salaire net reçu peut être enregistré par mois.</p></div></div>
        <form method="post" action="{{ $editingSalary ? route('salaries.update', $editingSalary) : route('salaries.store') }}" class="form-grid">@csrf @if($editingSalary) @method('PATCH') @endif
            <label>Mois<input type="month" name="month" max="{{ now()->format('Y-m') }}" value="{{ old('month', $editingSalary?->month ?? now()->format('Y-m')) }}" required @error('month') aria-invalid="true" @enderror>@error('month')<span class="field-error">{{ $message }}</span>@enderror</label>
            <label>Salaire net reçu (€)<input name="net_amount" inputmode="decimal" value="{{ old('net_amount', $editingSalary ? Money::formatInput($editingSalary->net_amount_cents) : '') }}" placeholder="1850,00" required @error('net_amount') aria-invalid="true" @enderror>@error('net_amount')<span class="field-error">{{ $message }}</span>@enderror</label>
            <label class="wide">Note <small>(facultatif)</small><textarea name="note" rows="2" maxlength="1000" placeholder="Prime, régularisation, absence…" @error('note') aria-invalid="true" @enderror>{{ old('note', $editingSalary?->note ?? '') }}</textarea>@error('note')<span class="field-error">{{ $message }}</span>@enderror</label>
            <div class="wide form-actions"><button class="primary-button"><i class="fa-solid fa-floppy-disk"></i> {{ $editingSalary ? 'Enregistrer les modifications' : 'Enregistrer le salaire' }}</button>@if($editingSalary)<a class="primary-button secondary-button" href="{{ route('salaries.index', ['year' => substr($editingSalary->month, 0, 4)]) }}">Annuler</a>@endif</div>
        </form>
    </section>

    <section class="panel salary-summary-panel">
        <div class="panel-heading"><div><h2>Résumé de la période</h2><p>{{ $report['stats']['count'] }} salaire{{ $report['stats']['count'] > 1 ? 's' : '' }} enregistré{{ $report['stats']['count'] > 1 ? 's' : '' }}.</p></div></div>
        <div class="salary-mini-summary">
            <div><span>Total</span><strong>{{ Money::formatCents($report['stats']['total_cents']) }}</strong></div>
            <div><span>Évolution période</span><strong class="{{ ($report['stats']['period_change_cents'] ?? 0) < 0 ? 'salary-negative' : 'salary-positive' }}">@if($report['stats']['period_change_cents'] === null) — @else{{ $report['stats']['period_change_cents'] >= 0 ? '+' : '' }}{{ Money::formatCents($report['stats']['period_change_cents']) }}@endif</strong></div>
        </div>
    </section>
</section>

<section class="kpis dashboard-kpis salary-kpis">
    <article class="kpi kpi-money"><div class="kpi-icon"><i class="fa-solid fa-euro-sign"></i></div><div><span>Salaire moyen</span><strong>{{ Money::formatCents($report['stats']['average_cents']) }}</strong></div></article>
    <article class="kpi salary-kpi-high"><div class="kpi-icon"><i class="fa-solid fa-arrow-up"></i></div><div><span>Salaire le plus élevé</span><strong>{{ Money::formatCents($report['stats']['max_cents']) }}</strong></div></article>
    <article class="kpi salary-kpi-low"><div class="kpi-icon"><i class="fa-solid fa-arrow-down"></i></div><div><span>Salaire le plus faible</span><strong>{{ Money::formatCents($report['stats']['min_cents']) }}</strong></div></article>
    <article class="kpi kpi-hours"><div class="kpi-icon"><i class="fa-solid fa-calculator"></i></div><div><span>Total des salaires</span><strong>{{ Money::formatCents($report['stats']['total_cents']) }}</strong></div></article>
    <article class="kpi salary-kpi-change"><div class="kpi-icon"><i class="fa-solid fa-arrow-trend-up"></i></div><div><span>Différence premier / dernier</span><strong>@if($report['stats']['period_change_cents'] === null) — @else{{ $report['stats']['period_change_cents'] >= 0 ? '+' : '' }}{{ Money::formatCents($report['stats']['period_change_cents']) }}@endif</strong></div></article>
</section>

<section class="panel salary-chart-panel">
    <div class="panel-heading report-heading"><div><h2>Évolution du salaire net</h2><p>Montant réellement reçu, mois par mois.</p></div></div>
    @if($report['chart']['points'])
        <div class="salary-chart-wrap">
            <svg class="salary-chart" viewBox="0 0 1000 300" role="img" aria-label="Graphique de l’évolution du salaire net">
                <line x1="60" y1="60" x2="940" y2="60" class="salary-chart-grid" />
                <line x1="60" y1="152" x2="940" y2="152" class="salary-chart-grid" />
                <line x1="60" y1="245" x2="940" y2="245" class="salary-chart-grid" />
                <text x="54" y="64" text-anchor="end" class="salary-chart-axis">{{ Money::formatCents($report['chart']['max_cents']) }}</text>
                <text x="54" y="249" text-anchor="end" class="salary-chart-axis">{{ Money::formatCents($report['chart']['min_cents']) }}</text>
                @if(count($report['chart']['points']) > 1)<polyline points="{{ $report['chart']['polyline'] }}" class="salary-chart-line" />@endif
                @foreach($report['chart']['points'] as $point)
                    <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="6" class="salary-chart-point"><title>{{ $point['label'] }} : {{ Money::formatCents($point['amount_cents']) }}</title></circle>
                    <text x="{{ $point['x'] }}" y="{{ max(18, $point['y'] - 12) }}" text-anchor="middle" class="salary-chart-value">{{ Money::formatCents($point['amount_cents']) }}</text>
                    <text x="{{ $point['x'] }}" y="278" text-anchor="middle" class="salary-chart-label">{{ $point['short_label'] }}</text>
                @endforeach
            </svg>
        </div>
    @else
        <div class="salary-empty"><i class="fa-solid fa-chart-line"></i><p>Aucun salaire enregistré sur cette période.</p></div>
    @endif
</section>

<section class="panel report-panel salary-detail-panel">
    <div class="panel-heading report-heading"><div><h2>Détail et comparaison mensuelle</h2><p>Le salaire reçu est rapproché des heures travaillées, des heures supplémentaires calculées et des paiements d’heures supplémentaires reçus le même mois.</p></div></div>
    <div class="table-wrap"><table class="report-table salary-table"><thead><tr><th>Mois</th><th>Salaire net reçu</th><th>Écart mois précédent</th><th>Heures travaillées</th><th>Heures sup effectuées</th><th>Montant heures sup</th><th>Paiements heures sup reçus</th><th>Heures sup payées</th><th>Note</th><th>Actions</th></tr></thead><tbody>
    @forelse($report['rows'] as $row)
        <tr>
            <td><a href="{{ route('month.show', $row['month']) }}">{{ FrenchDate::month((int)substr($row['month'], 5, 2)) }} {{ substr($row['month'], 0, 4) }}</a></td>
            <td><strong class="salary-amount">{{ Money::formatCents($row['amount_cents']) }}</strong></td>
            <td>@if($row['delta_cents'] === null)—@else<span class="{{ $row['delta_cents'] < 0 ? 'salary-negative' : 'salary-positive' }}">{{ $row['delta_cents'] >= 0 ? '+' : '' }}{{ Money::formatCents($row['delta_cents']) }}</span>@endif</td>
            <td>{{ Time::formatDuration($row['worked_minutes']) }}</td>
            <td>{{ Time::formatDuration($row['overtime_minutes']) }}</td>
            <td>{{ Money::formatCents($row['overtime_net_cents']) }}</td>
            <td>{{ Money::formatCents($row['overtime_paid_cents']) }}</td>
            <td>@if(!$row['has_overtime_paid_minutes'])—@else{{ $row['overtime_paid_minutes_indicative'] ? '≈ ' : '' }}{{ Time::formatDuration($row['overtime_paid_minutes']) }}@endif</td>
            <td class="salary-note">{{ $row['salary']->note ?: '—' }}</td>
            <td><div class="salary-row-actions"><a class="primary-button secondary-button compact-button" href="{{ route('salaries.index', ['year' => substr($row['month'], 0, 4), 'edit' => $row['salary']->id]) }}"><i class="fa-solid fa-pen"></i> Modifier</a><form method="post" action="{{ route('salaries.destroy', $row['salary']) }}" data-confirm data-confirm-title="Supprimer ce salaire ?" data-confirm-message="Le salaire de {{ strtolower(FrenchDate::month((int)substr($row['month'],5,2))) }} {{ substr($row['month'],0,4) }} sera supprimé." data-confirm-action="Supprimer" data-confirm-danger="1">@csrf @method('DELETE')<button class="danger-button compact-button"><i class="fa-solid fa-trash"></i> Supprimer</button></form></div></td>
        </tr>
    @empty
        <tr><td colspan="10" class="salary-table-empty">Aucun salaire enregistré sur cette période.</td></tr>
    @endforelse
    </tbody></table></div>
</section>
</div>
@endsection

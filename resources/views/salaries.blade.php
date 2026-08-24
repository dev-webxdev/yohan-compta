@extends('layouts.app')
@php use App\Support\FrenchDate; use App\Support\Money; @endphp
@section('title', 'Salaires')
@section('content')
<div class="salary-page">
<section class="panel salary-entry-panel">
    <div class="panel-heading"><div><h2>{{ $editingSalary ? 'Modifier le salaire' : 'Enregistrer un salaire' }}</h2><p>Enregistrez simplement le salaire net réellement reçu pour chaque mois.</p></div></div>
    <form method="post" action="{{ $editingSalary ? route('salaries.update', $editingSalary) : route('salaries.store') }}" class="form-grid salary-form">@csrf @if($editingSalary) @method('PATCH') @endif
        <label>Mois<input type="month" name="month" max="{{ now()->format('Y-m') }}" value="{{ old('month', $editingSalary?->month ?? now()->format('Y-m')) }}" required @error('month') aria-invalid="true" @enderror>@error('month')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>Salaire net reçu (€)<input name="net_amount" inputmode="decimal" value="{{ old('net_amount', $editingSalary ? Money::formatInput($editingSalary->net_amount_cents) : '') }}" placeholder="1850,00" required @error('net_amount') aria-invalid="true" @enderror>@error('net_amount')<span class="field-error">{{ $message }}</span>@enderror</label>
        <div class="wide form-actions"><button class="primary-button"><i class="fa-solid fa-floppy-disk"></i> {{ $editingSalary ? 'Enregistrer les modifications' : 'Enregistrer le salaire' }}</button>@if($editingSalary)<a class="primary-button secondary-button" href="{{ route('salaries.index') }}">Annuler</a>@endif</div>
    </form>
</section>

<section class="kpis dashboard-kpis salary-kpis">
    <article class="kpi kpi-money"><div class="kpi-icon"><i class="fa-solid fa-euro-sign"></i></div><div><span>Salaire moyen</span><strong>{{ Money::formatCents($report['stats']['average_cents']) }}</strong></div></article>
    <article class="kpi salary-kpi-high"><div class="kpi-icon"><i class="fa-solid fa-arrow-up"></i></div><div><span>Salaire le plus élevé</span><strong>{{ Money::formatCents($report['stats']['max_cents']) }}</strong></div></article>
    <article class="kpi salary-kpi-low"><div class="kpi-icon"><i class="fa-solid fa-arrow-down"></i></div><div><span>Salaire le plus faible</span><strong>{{ Money::formatCents($report['stats']['min_cents']) }}</strong></div></article>
</section>

<section class="panel salary-chart-panel">
    <div class="panel-heading salary-chart-heading">
        <div><h2>Évolution des salaires</h2><p>{{ $report['chart']['total_count'] > 12 ? 'Les 12 derniers salaires nets enregistrés.' : 'Les salaires nets enregistrés au fil des mois.' }}</p></div>
        @if($report['chart']['points'])<div class="salary-chart-range"><span>Min <strong>{{ Money::formatCents($report['chart']['min_cents']) }}</strong></span><span>Max <strong>{{ Money::formatCents($report['chart']['max_cents']) }}</strong></span></div>@endif
    </div>
    @if($report['chart']['points'])
        <div class="salary-chart-wrap">
            <svg class="salary-chart" viewBox="0 0 1000 230" role="img" aria-label="Graphique de l’évolution des salaires nets">
                <line x1="35" y1="25" x2="965" y2="25" class="salary-chart-grid" />
                <line x1="35" y1="95" x2="965" y2="95" class="salary-chart-grid" />
                <line x1="35" y1="165" x2="965" y2="165" class="salary-chart-grid" />
                <polygon points="{{ $report['chart']['area'] }}" class="salary-chart-area" />
                @if(count($report['chart']['points']) > 1)<polyline points="{{ $report['chart']['polyline'] }}" class="salary-chart-line" />@endif
                @foreach($report['chart']['points'] as $point)
                    <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="7" class="salary-chart-point"><title>{{ $point['label'] }} : {{ Money::formatCents($point['amount_cents']) }}</title></circle>
                    <text x="{{ $point['x'] }}" y="211" text-anchor="middle" class="salary-chart-label">{{ $point['short_label'] }}</text>
                @endforeach
            </svg>
        </div>
    @else
        <div class="salary-empty"><i class="fa-solid fa-chart-line"></i><p>Le graphique apparaîtra après le premier salaire enregistré.</p></div>
    @endif
</section>

<section class="panel report-panel salary-history-panel">
    <div class="panel-heading"><div><h2>Historique des salaires</h2><p>Les salaires enregistrés, du plus récent au plus ancien.</p></div></div>
    <div class="table-wrap"><table class="report-table salary-table"><thead><tr><th>Mois</th><th>Salaire net reçu</th><th>Actions</th></tr></thead><tbody>
    @forelse($report['rows'] as $salary)
        <tr>
            <td><a href="{{ route('month.show', $salary->month) }}">{{ FrenchDate::month((int)substr($salary->month, 5, 2)) }} {{ substr($salary->month, 0, 4) }}</a></td>
            <td><strong class="salary-amount">{{ Money::formatCents($salary->net_amount_cents) }}</strong></td>
            <td><div class="salary-row-actions"><a class="primary-button secondary-button compact-button" href="{{ route('salaries.index', ['edit' => $salary->id]) }}"><i class="fa-solid fa-pen"></i> Modifier</a><form method="post" action="{{ route('salaries.destroy', $salary) }}" data-confirm data-confirm-title="Supprimer ce salaire ?" data-confirm-message="Le salaire de {{ strtolower(FrenchDate::month((int)substr($salary->month,5,2))) }} {{ substr($salary->month,0,4) }} sera supprimé." data-confirm-action="Supprimer" data-confirm-danger="1">@csrf @method('DELETE')<button class="danger-button compact-button"><i class="fa-solid fa-trash"></i> Supprimer</button></form></div></td>
        </tr>
    @empty
        <tr><td colspan="3" class="salary-table-empty">Aucun salaire enregistré.</td></tr>
    @endforelse
    </tbody></table></div>
</section>
</div>
@endsection

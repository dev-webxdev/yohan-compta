@extends('layouts.app')
@php use App\Support\Money; use App\Support\Time; use App\Support\FrenchDate; @endphp
@section('title', 'Vue annuelle '.$report['year'])
@section('content')
<div class="report-page">
<section class="year-nav"><a class="square-control" href="{{ route('year.show', $report['year']-1) }}" aria-label="Année précédente"><i class="fa-solid fa-chevron-left"></i></a><div class="month-control"><i class="fa-solid fa-chart-column"></i><strong>{{ $report['year'] }}</strong></div><a class="square-control" href="{{ route('year.show', $report['year']+1) }}" aria-label="Année suivante"><i class="fa-solid fa-chevron-right"></i></a></section>
<section class="kpis dashboard-kpis report-kpis">
<article class="kpi kpi-hours"><div class="kpi-icon"><i class="fa-regular fa-clock"></i></div><div><span>Heures travaillées</span><strong>{{ Time::formatDuration($report['totals']['worked_minutes']) }}</strong></div></article>
<article class="kpi kpi-overtime"><div class="kpi-icon"><i class="fa-solid fa-arrow-trend-up"></i></div><div><span>Heures sup</span><strong>{{ Time::formatDuration($report['totals']['overtime_minutes']) }}</strong></div></article>
<article class="kpi kpi-money"><div class="kpi-icon"><i class="fa-solid fa-euro-sign"></i></div><div><span>Heures sup</span><strong>{{ Money::formatCents($report['totals']['overtime_net_cents']) }} net</strong><small>{{ Money::formatCents($report['totals']['overtime_gross_cents']) }} brut</small></div></article>
<article class="kpi kpi-meal"><div class="kpi-icon"><i class="fa-solid fa-utensils"></i></div><div><span>Paniers</span><strong>{{ Money::formatCents($report['totals']['meal_cents']) }}</strong></div></article>
<article class="kpi kpi-due"><div class="kpi-icon"><i class="fa-solid fa-wallet"></i></div><div><span>Reste dû net</span><strong>{{ Money::formatCents($balance['remaining']) }}</strong></div></article>
</section>
<section class="panel report-panel">
<div class="panel-heading report-heading"><div><h2>Détail mensuel</h2><p>Les paiements et le reste dû sont suivis en net.</p></div></div>
<div class="table-wrap"><table class="report-table"><thead><tr><th>Mois</th><th>Heures travaillées</th><th>Heures sup</th><th>€ sup net</th><th>€ sup brut</th><th>Paniers</th><th>€ reçus</th><th>€ affectés</th><th>€ restant dû net</th></tr></thead><tbody>
@foreach($report['months'] as $month => $item)<tr><td><a href="{{ route('month.show', $month) }}">{{ FrenchDate::month((int)substr($month,5,2)) }}</a></td><td>{{ Time::formatDuration($item['worked_minutes']) }}</td><td>{{ Time::formatDuration($item['overtime_minutes']) }}</td><td>{{ Money::formatCents($item['overtime_net_cents']) }}</td><td>{{ Money::formatCents($item['overtime_gross_cents']) }}</td><td>{{ Money::formatCents($item['meal_cents']) }}</td><td>{{ Money::formatCents($item['paid_received_cents']) }}</td><td>{{ Money::formatCents($item['paid_allocated_cents']) }}</td><td><strong>{{ Money::formatCents($item['remaining_cents']) }}</strong></td></tr>@endforeach
</tbody><tfoot><tr><th>Total</th><th>{{ Time::formatDuration($report['totals']['worked_minutes']) }}</th><th>{{ Time::formatDuration($report['totals']['overtime_minutes']) }}</th><th>{{ Money::formatCents($report['totals']['overtime_net_cents']) }}</th><th>{{ Money::formatCents($report['totals']['overtime_gross_cents']) }}</th><th>{{ Money::formatCents($report['totals']['meal_cents']) }}</th><th>{{ Money::formatCents($report['totals']['paid_received_cents']) }}</th><th>{{ Money::formatCents($report['totals']['paid_allocated_cents']) }}</th><th>{{ Money::formatCents($report['totals']['remaining_cents']) }}</th></tr></tfoot></table></div>
</section>
</div>
@endsection

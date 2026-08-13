@extends('layouts.app')
@php use App\Support\Money; use App\Support\Time; use App\Support\FrenchDate; @endphp
@section('title', 'Vue annuelle '.$report['year'])
@section('content')
<section class="year-nav"><a class="icon-button" href="{{ route('year.show', $report['year']-1) }}">‹</a><div class="month-picker">{{ $report['year'] }}</div><a class="icon-button" href="{{ route('year.show', $report['year']+1) }}">›</a></section>
<section class="kpis">
<article class="kpi"><span>Heures travaillées</span><strong>{{ Time::formatDuration($report['totals']['worked_minutes']) }}</strong></article>
<article class="kpi"><span>Heures sup</span><strong>{{ Time::formatDuration($report['totals']['overtime_minutes']) }}</strong></article>
<article class="kpi"><span>€ heures sup</span><strong>{{ Money::formatCents($report['totals']['overtime_cents']) }}</strong></article>
<article class="kpi"><span>Paniers</span><strong>{{ Money::formatCents($report['totals']['meal_cents']) }}</strong></article>
<article class="kpi danger"><span>€ encore dus</span><strong>{{ Money::formatCents($balance['remaining']) }}</strong></article>
</section>
<section class="panel"><div class="table-wrap"><table class="report-table"><thead><tr><th>Mois</th><th>Heures travaillées</th><th>Heures sup</th><th>€ heures sup</th><th>Paniers</th><th>€ reçus (date)</th><th>€ affectés</th><th>€ restant dû</th></tr></thead><tbody>
@foreach($report['months'] as $month => $item)<tr><td><a href="{{ route('month.show', $month) }}">{{ FrenchDate::month((int)substr($month,5,2)) }}</a></td><td>{{ Time::formatDuration($item['worked_minutes']) }}</td><td>{{ Time::formatDuration($item['overtime_minutes']) }}</td><td>{{ Money::formatCents($item['overtime_cents']) }}</td><td>{{ Money::formatCents($item['meal_cents']) }}</td><td>{{ Money::formatCents($item['paid_received_cents']) }}</td><td>{{ Money::formatCents($item['paid_allocated_cents']) }}</td><td><strong>{{ Money::formatCents($item['remaining_cents']) }}</strong></td></tr>@endforeach
</tbody><tfoot><tr><th>Total</th><th>{{ Time::formatDuration($report['totals']['worked_minutes']) }}</th><th>{{ Time::formatDuration($report['totals']['overtime_minutes']) }}</th><th>{{ Money::formatCents($report['totals']['overtime_cents']) }}</th><th>{{ Money::formatCents($report['totals']['meal_cents']) }}</th><th>{{ Money::formatCents($report['totals']['paid_received_cents']) }}</th><th>{{ Money::formatCents($report['totals']['paid_allocated_cents']) }}</th><th>{{ Money::formatCents($report['totals']['remaining_cents']) }}</th></tr></tfoot></table></div></section>
@endsection

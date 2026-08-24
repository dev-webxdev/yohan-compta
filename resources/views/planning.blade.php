@extends('layouts.app')
@php use App\Support\FrenchDate; use App\Support\Time; @endphp
@section('title', 'Planning')
@section('content')
<div class="planning-page">
<section class="planning-toolbar">
    <div class="planning-navigation">
        <a class="square-control" href="{{ route('planning.index', ['view' => $mode, 'date' => $previous]) }}" aria-label="Période précédente"><i class="fa-solid fa-chevron-left"></i></a>
        <div class="month-control planning-period"><i class="fa-regular fa-calendar-days"></i><strong>{{ $label }}</strong></div>
        <a class="square-control" href="{{ route('planning.index', ['view' => $mode, 'date' => $next]) }}" aria-label="Période suivante"><i class="fa-solid fa-chevron-right"></i></a>
    </div>
    <div class="planning-view-switch" role="group" aria-label="Vue du planning">
        <a class="{{ $mode === 'month' ? 'active' : '' }}" href="{{ route('planning.index', ['view' => 'month', 'date' => $date]) }}">Mois</a>
        <a class="{{ $mode === 'week' ? 'active' : '' }}" href="{{ route('planning.index', ['view' => 'week', 'date' => $date]) }}">Semaine</a>
        <a href="{{ route('planning.index', ['view' => $mode, 'date' => now()->format('Y-m-d')]) }}">Aujourd’hui</a>
    </div>
</section>

<section class="panel planning-summary">
    <div class="panel-heading"><div><h2>Planning</h2><p>Les heures réelles viennent automatiquement du tableau de bord. Ici, vous renseignez uniquement les heures prévues et les congés.</p></div></div>
    <div class="planning-legend" aria-label="Légende"><span class="worked"><i></i> Travaillé</span><span class="planned"><i></i> Prévu</span><span class="leave"><i></i> Congé</span><span class="rest"><i></i> Repos</span><span class="overtime"><i></i> Heures sup</span></div>
</section>

<section class="panel planning-calendar-panel">
    <div class="planning-weekdays" aria-hidden="true"><span>Lun.</span><span>Mar.</span><span>Mer.</span><span>Jeu.</span><span>Ven.</span><span>Sam.</span><span>Dim.</span></div>
    <div class="planning-grid {{ $mode === 'week' ? 'planning-grid-week' : '' }}">
    @foreach($days as $item)
        @php($key = $item['date']->format('Y-m-d'))
        <article class="planning-day status-{{ $item['status'] }} {{ !$item['in_period'] ? 'outside-period' : '' }} {{ $key === now()->format('Y-m-d') ? 'is-today' : '' }}" data-date="{{ $key }}">
            <div class="planning-day-head"><div><strong>{{ $item['date']->format('d') }}</strong><span>{{ FrenchDate::day($item['date']) }}</span></div>@if($item['document_count'])<a class="document-count-link" href="{{ route('library.index', ['target' => 'day:'.$key]) }}" title="Voir les documents associés"><i class="fa-solid fa-paperclip"></i>{{ $item['document_count'] }}</a>@endif</div>
            <div class="planning-day-state">
                @if($item['status'] === 'leave')<span class="state-badge leave">Congé</span>
                @elseif($item['status'] === 'rest')<span class="state-badge rest">Repos</span>
                @elseif($item['status'] === 'worked')<span class="state-badge worked">Travaillé</span>
                @elseif($item['status'] === 'planned')<span class="state-badge planned">Prévu</span>
                @else<span class="state-badge empty">Non renseigné</span>@endif
            </div>
            <dl class="planning-hours">
                <div><dt>Prévu</dt><dd>{{ $item['planned'] !== null ? Time::formatDuration($item['planned']) : '—' }}</dd></div>
                <div><dt>Réalisé</dt><dd>{{ $item['worked'] > 0 ? Time::formatDuration($item['worked']) : '—' }}</dd></div>
                @if($item['overtime'] > 0)<div class="planning-overtime"><dt>Heures sup</dt><dd>+{{ Time::formatDuration($item['overtime']) }}</dd></div>@endif
            </dl>
            @if($item['in_period'] || $mode === 'week')
            <details class="planning-edit" @if($errors->any() && old('return_date') === $date && old('_planning_date') === $key) open @endif>
                <summary><i class="fa-solid fa-pen"></i> Prévision</summary>
                <form method="post" action="{{ route('planning.update', ['date' => $key]) }}">@csrf @method('PUT')
                    <input type="hidden" name="return_view" value="{{ $mode }}"><input type="hidden" name="return_date" value="{{ $date }}"><input type="hidden" name="_planning_date" value="{{ $key }}">
                    <label>Heures prévues<input name="planned" inputmode="numeric" value="{{ $item['planned'] !== null ? Time::formatDuration($item['planned']) : '' }}" placeholder="07:00"></label>
                    <label class="planning-leave-check"><input type="checkbox" name="is_leave" value="1" {{ $item['status'] === 'leave' ? 'checked' : '' }}> Congé</label>
                    <button class="primary-button compact-button">Enregistrer</button>
                </form>
            </details>
            @endif
        </article>
    @endforeach
    </div>
</section>
</div>
@endsection

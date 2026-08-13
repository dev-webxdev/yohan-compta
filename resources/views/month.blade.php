@extends('layouts.app')

@php
use App\Support\FrenchDate;
use App\Support\Money;
use App\Support\Time;
@endphp

@section('title', 'Tableau de bord')

@section('content')
<section class="month-nav">
    <a class="icon-button" href="{{ route('month.show', ['month' => $previousMonth]) }}">‹</a>
    <div class="month-picker">📅 {{ $monthLabel }}</div>
    <a class="icon-button" href="{{ route('month.show', ['month' => $nextMonth]) }}">›</a>
</section>

<section class="kpis">
    <article class="kpi"><span>Heures ce mois</span><strong>{{ Time::formatDuration($report['worked_minutes']) }}</strong></article>
    <article class="kpi"><span>Heures sup ce mois</span><strong>{{ Time::formatDuration($report['overtime_minutes']) }}</strong></article>
    <article class="kpi"><span>€ heures sup</span><strong>{{ Money::formatCents($report['overtime_cents']) }}</strong></article>
    <article class="kpi"><span>Paniers</span><strong>{{ Money::formatCents($report['meal_cents']) }}</strong></article>
    <article class="kpi danger"><span>€ encore dus</span><strong>{{ Money::formatCents($balance['remaining']) }}</strong></article>
</section>

<div class="dashboard-grid">
    <section class="panel table-panel">
        <div class="panel-heading">
            <div><h2>Jours du mois</h2><p>Seuls les jours appartenant à {{ $monthLabel }} sont affichés.</p></div>
            <div class="save-state" id="save-state">Toutes les modifications sont enregistrées</div>
        </div>
        <div class="table-wrap">
            <table class="work-table">
                <thead><tr><th>Date</th><th>Jour</th><th>Conduite</th><th>Entrepôt</th><th>Total</th><th>Repos</th><th>Fin</th><th>Panier</th><th>Note</th><th></th></tr></thead>
                <tbody>
                @foreach($calendarDays as $row)
                    @php($day = $row['work_day'])
                    <tr class="work-row" data-date="{{ $row['date']->format('Y-m-d') }}" data-meal-mode="{{ $day?->meal_allowance_mode ?? 'auto' }}" data-meal-amount="{{ $day?->meal_allowance_forced_cents !== null ? number_format($day->meal_allowance_forced_cents/100, 2, ',', '') : '' }}">
                        <td class="date-cell">{{ $row['date']->format('d/m/Y') }}</td>
                        <td class="{{ $row['date']->format('N') == 7 ? 'sunday' : '' }}">{{ FrenchDate::day($row['date']) }}</td>
                        <td><input class="time-input autosave" name="driving" inputmode="numeric" value="{{ $day ? Time::formatDuration($day->driving_minutes) : '' }}" placeholder="00:00"></td>
                        <td><input class="time-input autosave" name="warehouse" inputmode="numeric" value="{{ $day ? Time::formatDuration($day->warehouse_minutes) : '' }}" placeholder="00:00"></td>
                        <td class="calculated"><strong>{{ Time::formatDuration($row['worked']) }}</strong></td>
                        <td class="calculated">{{ Time::formatDuration($row['rest']) }}</td>
                        <td><input class="time-input autosave" name="end_time" inputmode="numeric" value="{{ $day ? Time::formatClock($day->end_time_minutes) : '' }}" placeholder="—"></td>
                        <td><button type="button" class="meal-button edit-day">{{ Money::formatCents($row['meal']) }}{{ ($day?->meal_allowance_mode ?? 'auto') === 'forced' ? ' •' : '' }}</button></td>
                        <td><input class="note-input autosave" name="note" value="{{ $day?->note ?? '' }}" placeholder="Note"></td>
                        <td><button class="more-button edit-day" type="button" aria-label="Modifier">⋮</button></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <aside class="right-column">
        <section class="panel">
            <div class="panel-heading"><div><h2>Semaines concernées</h2><p>Lundi → dimanche, même entre deux mois.</p></div></div>
            <div class="week-list">
            @foreach($report['weeks'] as $week)
                <article class="week-card">
                    <strong>Semaine du {{ $week['start']->format('d/m') }} au {{ $week['end']->format('d/m') }}</strong>
                    <div><span>Total travaillé</span><b>{{ Time::formatDuration($week['total_minutes']) }}</b></div>
                    <div><span>Heures sup</span><b>{{ Time::formatDuration($week['overtime_minutes']) }}</b></div>
                    <div><span>Montant</span><b>{{ Money::formatCents($week['overtime_cents']) }}</b></div>
                </article>
            @endforeach
            </div>
        </section>

        <section class="panel balance-panel">
            <div class="panel-heading"><div><h2>Reste dû</h2><p>Suivi global et mois par mois.</p></div></div>
            <div class="big-due">{{ Money::formatCents($balance['remaining']) }}</div><p class="muted">≈ {{ Time::formatDuration($balance['remaining_minutes_indicative']) }} d’heures sup restant dues (indicatif)</p>
            @forelse(array_reverse($balance['by_month'], true) as $month => $item)
                @if($item['generated'] || $item['paid'])
                    <div class="balance-line"><span>{{ FrenchDate::month((int)substr($month,5,2)) }} {{ substr($month,0,4) }} <small>{{ $item['remaining'] <= 0 ? '· Payée' : ($item['paid'] > 0 ? '· Partiellement payée' : '· Acquise') }}</small></span><strong>{{ Money::formatCents($item['remaining']) }}</strong></div>
                @endif
            @empty
                <p class="muted">Aucune heure supplémentaire enregistrée.</p>
            @endforelse
            @if($balance['credit'] > 0)<div class="credit">Avance / trop-perçu : {{ Money::formatCents($balance['credit']) }}</div>@endif
            <a class="primary-button full" href="{{ route('payments.index') }}">Gérer les paiements</a>
        </section>

        <section class="panel compact-summary">
            <h2>Total théorique du mois</h2>
            <div><span>Heures normales</span><strong>{{ Money::formatCents($report['normal_pay_cents']) }}</strong></div>
            <div><span>Heures sup</span><strong>{{ Money::formatCents($report['overtime_cents']) }}</strong></div>
            <div><span>Travail total</span><strong>{{ Money::formatCents($report['work_pay_cents']) }}</strong></div>
            <div><span>Paniers</span><strong>{{ Money::formatCents($report['meal_cents']) }}</strong></div>
            <div class="total-line"><span>Total</span><strong>{{ Money::formatCents($report['theoretical_total_cents']) }}</strong></div>
            <small>Heures normales + heures sup = travail total. Les heures sup ne sont jamais ajoutées une deuxième fois.</small>
        </section>
    </aside>
</div>

<dialog id="day-dialog" class="day-dialog">
    <form method="dialog" id="day-dialog-form">
        <div class="dialog-head"><div><div class="eyebrow">Édition rapide</div><h2 id="dialog-title">Journée</h2></div><button value="cancel" class="icon-button">×</button></div>
        <div class="dialog-grid">
            <label>Temps de conduite<input name="driving" placeholder="00:00" inputmode="numeric"></label>
            <label>Temps à l’entrepôt<input name="warehouse" placeholder="00:00" inputmode="numeric"></label>
            <label>Heure de fin<input name="end_time" placeholder="14:30" inputmode="numeric"></label>
        </div>
        <fieldset class="meal-fieldset">
            <legend>Panier</legend>
            <label><input type="radio" name="meal_mode" value="auto"> Automatique</label>
            <label><input type="radio" name="meal_mode" value="forced"> Forcer</label>
            <input name="meal_amount" placeholder="16,00" inputmode="decimal">
        </fieldset>
        <label>Note<textarea name="note" rows="3" placeholder="Ajouter une note…"></textarea></label>
        <div class="dialog-actions"><button type="button" class="danger-button" id="delete-day">Supprimer</button><button value="cancel" class="ghost-button">Annuler</button><button type="button" class="primary-button" id="save-day">Enregistrer</button></div>
        <div class="inline-error" id="dialog-error"></div>
    </form>
</dialog>
@endsection

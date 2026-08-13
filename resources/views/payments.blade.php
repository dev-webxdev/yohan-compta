@extends('layouts.app')
@php use App\Support\Money; use App\Support\Time; use App\Support\FrenchDate; @endphp
@section('title', 'Paiements d’heures supplémentaires')
@section('content')
<div class="two-column-page">
<section class="panel">
    <div class="panel-heading"><div><h2>Ajouter un paiement</h2><p>Le paiement est indépendant du mois où les heures ont été générées.</p></div></div>
    @if(session('payment_warning'))<div class="flash warning">{{ session('payment_warning') }}</div>@endif
    <form method="post" action="{{ route('payments.store') }}" class="form-grid">@csrf
        <label>Date du paiement<input type="date" name="payment_date" value="{{ old('payment_date', now()->format('Y-m-d')) }}" required></label>
        <label>Montant reçu (€)<input name="amount" inputmode="decimal" value="{{ old('amount') }}" placeholder="250,00" required></label>
        <label>Heures payées <small>(facultatif)</small><input name="hours_paid" inputmode="numeric" value="{{ old('hours_paid') }}" placeholder="20:00"></label>
        <label>Référence période <small>(facultatif)</small><input name="period_reference" value="{{ old('period_reference') }}" placeholder="Ex. Juillet + Août"></label>
        <label class="wide">Note <small>(facultatif)</small><textarea name="note" rows="3" placeholder="Ex. paiement partiel reçu avec la paie d’octobre">{{ old('note') }}</textarea></label>
        @if(session('payment_warning'))<label class="wide check"><input type="checkbox" name="confirm_mismatch" value="1"> Je confirme malgré l’écart montant/heures</label>@endif
        <div class="wide"><button class="primary-button">Enregistrer le paiement</button></div>
    </form>
    <p class="hint">Répartition automatique : le montant rembourse d’abord les plus anciennes dettes mensuelles. Un éventuel surplus est conservé comme avance/trop-perçu.</p>
</section>
<section class="panel balance-panel">
    <h2>Solde actuel</h2>
    <div class="big-due">{{ Money::formatCents($balance['remaining']) }}</div><p class="muted">≈ {{ Time::formatDuration($balance['remaining_minutes_indicative']) }} restantes (conversion indicative)</p>
    <div class="balance-line"><span>Heures sup acquises</span><strong>{{ Money::formatCents($balance['generated']) }}</strong></div>
    <div class="balance-line"><span>Total reçu</span><strong>{{ Money::formatCents($balance['paid']) }}</strong></div>
    @if($balance['credit'] > 0)<div class="credit">Avance / trop-perçu : {{ Money::formatCents($balance['credit']) }}</div>@endif
</section>
</div>
<section class="panel spacer-top">
    <div class="panel-heading"><div><h2>Historique des paiements</h2><p>Supprimer un paiement recalcule automatiquement tous les soldes.</p></div></div>
    <div class="payment-list">
    @forelse($payments as $payment)
        <article class="payment-row">
            <div><strong>{{ $payment->payment_date->format('d/m/Y') }}</strong><small>{{ $payment->period_reference ? $payment->period_reference.' · ' : '' }}{{ $payment->note ?: 'Paiement heures supplémentaires' }}</small></div>
            <div class="allocation-tags">
                @forelse(($allocations[$payment->id] ?? []) as $allocation)<span>{{ $allocation['month'] }} · {{ Money::formatCents($allocation['amount_cents']) }}</span>@empty<span>Avance non affectée</span>@endforelse
            </div>
            <div class="payment-amount">{{ Money::formatCents($payment->amount_cents) }}<small>{{ $paymentHours[$payment->id]['indicative'] ? '≈ ' : '' }}{{ Time::formatDuration($paymentHours[$payment->id]['minutes']) }}</small></div>
            <form method="post" action="{{ route('payments.destroy', $payment) }}" onsubmit="return confirm('Supprimer ce paiement ?')">@csrf @method('DELETE')<button class="danger-link">Supprimer</button></form>
        </article>
    @empty
        <p class="muted">Aucun paiement enregistré.</p>
    @endforelse
    </div>
</section>
@endsection

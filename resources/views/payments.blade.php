@extends('layouts.app')
@php use App\Support\FrenchDate; use App\Support\Money; use App\Support\Time; @endphp
@section('title', 'Paiements d’heures supplémentaires')
@section('content')
<div class="two-column-page">
<section class="panel">
    <div class="panel-heading"><div><h2>{{ $editingPayment ? 'Modifier le paiement' : 'Ajouter un paiement d’heures supplémentaires' }}</h2><p>Ce paiement concerne uniquement les heures supplémentaires et reste indépendant du mois où elles ont été générées.</p></div></div>
    <form method="post" action="{{ $editingPayment ? route('payments.update', $editingPayment) : route('payments.store') }}" class="form-grid">@csrf @if($editingPayment) @method('PATCH') @endif
        <label>Date du paiement<input type="date" name="payment_date" value="{{ old('payment_date', $editingPayment?->payment_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required @error('payment_date') aria-invalid="true" @enderror>@error('payment_date')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>Montant reçu pour les heures supplémentaires (€)<input name="amount" inputmode="decimal" value="{{ old('amount', $editingPayment ? Money::formatInput($editingPayment->amount_cents) : '') }}" placeholder="250,00" required @error('amount') aria-invalid="true" @enderror>@error('amount')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>Heures supplémentaires payées <small>(facultatif)</small><input name="hours_paid" inputmode="numeric" data-time-normalize value="{{ old('hours_paid', $editingPayment?->hours_paid_minutes !== null ? Time::formatDuration($editingPayment->hours_paid_minutes) : '') }}" placeholder="20:00" @error('hours_paid') aria-invalid="true" @enderror>@error('hours_paid')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>Référence période <small>(facultatif)</small><input name="period_reference" value="{{ old('period_reference', $editingPayment?->period_reference ?? '') }}" placeholder="Ex. Juillet + Août" @error('period_reference') aria-invalid="true" @enderror>@error('period_reference')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label class="wide">Note <small>(facultatif)</small><textarea name="note" rows="3" placeholder="Ex. paiement partiel reçu avec la paie d’octobre" @error('note') aria-invalid="true" @enderror>{{ old('note', $editingPayment?->note ?? '') }}</textarea>@error('note')<span class="field-error">{{ $message }}</span>@enderror</label>
        <div class="wide form-actions"><button class="primary-button">{{ $editingPayment ? 'Enregistrer les modifications' : 'Enregistrer le paiement' }}</button>@if($editingPayment)<a class="primary-button secondary-button" href="{{ route('payments.index') }}">Annuler</a>@endif</div>
    </form>
    <p class="hint">Répartition automatique : le montant rembourse d’abord les plus anciennes dettes mensuelles. Un éventuel surplus est conservé comme avance/trop-perçu.</p>
</section>
<section class="panel balance-panel payment-balance">
    <h2>Heures supplémentaires restantes à payer</h2>
    <p class="muted">Montant net restant à payer</p><div class="big-due">{{ Money::formatCents($balance['remaining']) }}</div><p class="muted">≈ {{ Time::formatDuration($balance['remaining_minutes_indicative']) }} d’heures supplémentaires restantes à payer (conversion indicative)</p>
    <div class="balance-line"><span>Montant total des heures supplémentaires</span><strong>{{ Money::formatCents($balance['generated']) }}</strong></div>
    <div class="balance-line"><span>Montant déjà payé</span><strong>{{ Money::formatCents($balance['paid']) }}</strong></div>
    @if($balance['credit'] > 0)<div class="credit">Avance / trop-perçu : {{ Money::formatCents($balance['credit']) }}</div>@endif
</section>
</div>
<section class="panel spacer-top">
    <div class="panel-heading"><div><h2>Historique des paiements d’heures supplémentaires</h2><p>Supprimer un paiement recalcule automatiquement tous les soldes d’heures supplémentaires.</p></div></div>
    <div class="payment-list">
    @forelse($payments as $payment)
        <article class="payment-row">
            <div><strong>{{ $payment->payment_date->format('d/m/Y') }}</strong><small>{{ $payment->period_reference ? $payment->period_reference.' · ' : '' }}{{ $payment->note ?: 'Paiement heures supplémentaires' }}</small></div>
            <div class="allocation-tags">
                @forelse(($allocations[$payment->id] ?? []) as $allocation)<span>{{ FrenchDate::month((int)substr($allocation['month'],5,2)) }} {{ substr($allocation['month'],0,4) }} · {{ Money::formatCents($allocation['amount_cents']) }}</span>@empty<span>Avance non affectée</span>@endforelse
            </div>
            <div class="payment-amount">{{ Money::formatCents($payment->amount_cents) }}<small>Heures sup payées : {{ $paymentHours[$payment->id]['indicative'] ? '≈ ' : '' }}{{ Time::formatDuration($paymentHours[$payment->id]['minutes']) }}</small></div>
            <div class="payment-actions">
                <a class="primary-button secondary-button compact-button" href="{{ route('payments.index', ['edit' => $payment->id]) }}"><i class="fa-solid fa-pen"></i> Modifier</a>
                <form method="post" action="{{ route('payments.destroy', $payment) }}" data-confirm data-confirm-title="Supprimer ce paiement ?" data-confirm-message="Le paiement sera supprimé et les soldes d’heures supplémentaires seront recalculés." data-confirm-action="Supprimer" data-confirm-danger="1">@csrf @method('DELETE')<button class="danger-button compact-button"><i class="fa-solid fa-trash"></i> Supprimer</button></form>
            </div>
        </article>
    @empty
        <p class="muted">Aucun paiement d’heures supplémentaires enregistré.</p>
    @endforelse
    </div>
</section>
@endsection

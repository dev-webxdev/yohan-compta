@extends('layouts.app')
@php
    use App\Support\Money;
    use App\Support\Time;
    $editValidationId = (int) old('_payment_edit', 0);
    $hasEditValidation = $editValidationId > 0;
@endphp
@section('title', 'Paiements d’heures supplémentaires')
@section('content')
<div class="two-column-page">
<section class="panel payment-entry-panel">
    <div class="panel-heading"><div><h2>Ajouter un paiement d’heures supplémentaires</h2><p>Ce paiement concerne uniquement les heures supplémentaires et reste indépendant du mois où elles ont été générées.</p></div></div>
    <form method="post" action="{{ route('payments.store') }}" class="form-grid payment-form">@csrf
        <label>Date du paiement<input type="date" name="payment_date" value="{{ $hasEditValidation ? now()->format('Y-m-d') : old('payment_date', now()->format('Y-m-d')) }}" required @if(!$hasEditValidation && $errors->has('payment_date')) aria-invalid="true" @endif>@if(!$hasEditValidation) @error('payment_date')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <label>Montant reçu pour les heures supplémentaires (€)<input name="amount" inputmode="decimal" value="{{ $hasEditValidation ? '' : old('amount') }}" placeholder="250,00" required @if(!$hasEditValidation && $errors->has('amount')) aria-invalid="true" @endif>@if(!$hasEditValidation) @error('amount')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <label>Heures supplémentaires payées <small>(facultatif, saisie libre)</small><input name="hours_paid" inputmode="numeric" data-time-normalize value="{{ $hasEditValidation ? '' : old('hours_paid') }}" placeholder="20:00" @if(!$hasEditValidation && $errors->has('hours_paid')) aria-invalid="true" @endif>@if(!$hasEditValidation) @error('hours_paid')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <div class="wide form-actions"><button class="primary-button payment-primary-button"><i class="fa-solid fa-plus"></i> Enregistrer le paiement</button></div>
    </form>
    <p class="hint">Répartition automatique : le montant rembourse d’abord les plus anciennes dettes mensuelles et un éventuel surplus reste en avance/trop-perçu. Si vous saisissez les heures payées, elles sont déduites telles quelles du solde d’heures, indépendamment du montant. Sans durée saisie, les heures restent estimées à partir du montant.</p>
</section>
<section class="panel balance-panel payment-balance">
    <h2>Heures supplémentaires restantes à payer</h2>
    <p class="muted">Montant net restant à payer</p><div class="big-due">{{ Money::formatCents($balance['remaining']) }}</div><p class="muted">≈ {{ Time::formatDuration($balance['remaining_minutes_indicative']) }} d’heures supplémentaires restantes à payer (conversion indicative)</p>
    <div class="balance-line"><span>Montant total des heures supplémentaires</span><strong>{{ Money::formatCents($balance['generated']) }}</strong></div>
    <div class="balance-line"><span>Montant déjà payé</span><strong>{{ Money::formatCents($balance['paid']) }}</strong></div>
    @if($balance['credit'] > 0)<div class="credit">Avance / trop-perçu : {{ Money::formatCents($balance['credit']) }}</div>@endif
    @if($balance['unallocated_paid_minutes'] > 0)<div class="credit">Heures payées en avance : {{ Time::formatDuration($balance['unallocated_paid_minutes']) }}</div>@endif
</section>
</div>
<section class="panel spacer-top payment-history-panel">
    <div class="panel-heading"><div><h2>Historique des paiements d’heures supplémentaires</h2><p>Supprimer un paiement recalcule automatiquement tous les soldes d’heures supplémentaires.</p></div></div>
    <div class="payment-list">
    @forelse($payments as $payment)
        <article class="payment-row">
            <div class="payment-date"><strong>{{ $payment->payment_date->format('d/m/Y') }}</strong>@if($payment->payment_date->isFuture())<span class="future-payment">Futur</span><small class="payment-future-note">Sera affecté à partir du {{ $payment->payment_date->format('d/m/Y') }}</small>@endif</div>
            <div class="payment-amount">{{ Money::formatCents($payment->amount_cents) }}<small>Heures sup payées : @if($paymentHours[$payment->id]['minutes'] === null)non affectées pour le moment @else{{ $paymentHours[$payment->id]['indicative'] ? '≈ ' : '' }}{{ Time::formatDuration($paymentHours[$payment->id]['minutes']) }}@endif</small></div>
            <div class="payment-actions">
                <button type="button" class="payment-action-button payment-edit-button" data-payment-edit data-payment-id="{{ $payment->id }}" data-payment-date="{{ $payment->payment_date->format('Y-m-d') }}" data-payment-amount="{{ Money::formatInput($payment->amount_cents) }}" data-payment-hours="{{ $payment->hours_paid_minutes !== null ? Time::formatDuration($payment->hours_paid_minutes) : '' }}" data-payment-update-url="{{ route('payments.update', $payment) }}"><i class="fa-solid fa-pen"></i> Modifier</button>
                <form method="post" action="{{ route('payments.destroy', $payment) }}" data-confirm data-confirm-title="Supprimer ce paiement ?" data-confirm-message="Le paiement sera supprimé et les soldes d’heures supplémentaires seront recalculés." data-confirm-action="Supprimer" data-confirm-danger="1">@csrf @method('DELETE')<button class="payment-action-button payment-delete-button"><i class="fa-regular fa-trash-can"></i> Supprimer</button></form>
            </div>
        </article>
    @empty
        <p class="muted">Aucun paiement d’heures supplémentaires enregistré.</p>
    @endforelse
    </div>
    @if($payments->hasPages())<nav class="pagination" aria-label="Pagination des paiements">@if($payments->previousPageUrl())<a class="primary-button secondary-button compact-button" href="{{ $payments->previousPageUrl() }}"><i class="fa-solid fa-chevron-left"></i> Précédent</a>@endif<span>Page {{ $payments->currentPage() }} / {{ $payments->lastPage() }}</span>@if($payments->nextPageUrl())<a class="primary-button secondary-button compact-button" href="{{ $payments->nextPageUrl() }}">Suivant <i class="fa-solid fa-chevron-right"></i></a>@endif</nav>@endif
</section>

<dialog id="payment-edit-dialog" class="payment-edit-dialog" data-reopen-id="{{ $editValidationId ?: '' }}" aria-labelledby="payment-edit-title">
    <form method="post" action="#" id="payment-edit-form">
        @csrf
        @method('PATCH')
        <input type="hidden" name="_payment_edit" id="payment-edit-id" value="{{ $editValidationId ?: '' }}">
        <div class="payment-dialog-head">
            <div><h2 id="payment-edit-title">Modifier le paiement</h2><p>Modifiez les informations du paiement sans quitter la page.</p></div>
            <button type="button" class="payment-dialog-close" data-payment-dialog-close aria-label="Fermer"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="payment-dialog-fields">
            <label>Date du paiement<input type="date" id="payment-edit-date" name="payment_date" value="{{ $hasEditValidation ? old('payment_date') : '' }}" required @if($hasEditValidation && $errors->has('payment_date')) aria-invalid="true" @endif>@if($hasEditValidation) @error('payment_date')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
            <label>Montant reçu (€)<input id="payment-edit-amount" name="amount" inputmode="decimal" value="{{ $hasEditValidation ? old('amount') : '' }}" placeholder="250,00" required @if($hasEditValidation && $errors->has('amount')) aria-invalid="true" @endif>@if($hasEditValidation) @error('amount')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
            <label>Heures supplémentaires payées <small>(facultatif, saisie libre)</small><input id="payment-edit-hours" name="hours_paid" inputmode="numeric" data-time-normalize value="{{ $hasEditValidation ? old('hours_paid') : '' }}" placeholder="20:00" @if($hasEditValidation && $errors->has('hours_paid')) aria-invalid="true" @endif>@if($hasEditValidation) @error('hours_paid')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        </div>
        <div class="payment-dialog-actions">
            <button type="button" class="payment-dialog-button payment-dialog-cancel" data-payment-dialog-close>Annuler</button>
            <button class="payment-dialog-button payment-dialog-save"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
        </div>
    </form>
</dialog>
@endsection

<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="/vendor/fontawesome/css/fontawesome.min.css">
    <link rel="stylesheet" href="/vendor/fontawesome/css/solid.min.css">
    <link rel="stylesheet" href="/vendor/fontawesome/css/regular.min.css">
    <link rel="stylesheet" href="/app.css">
</head>
<body>
@php
    $selectedMonth = request()->route('month');
    $dashboardUrl = $selectedMonth
        ? route('month.show', ['month' => $selectedMonth])
        : route('month.current');
@endphp
<div class="app-shell" id="app-shell">
<aside class="sidebar" aria-label="Navigation principale">
    <div class="sidebar-head"><button type="button" class="bare-icon sidebar-toggle" aria-label="Réduire le menu" aria-expanded="true"><i class="fa-solid fa-bars"></i></button></div>
    <nav class="sidebar-nav">
        <a class="{{ request()->routeIs('month.*') ? 'active' : '' }}" href="{{ $dashboardUrl }}" @if(request()->routeIs('month.*')) aria-current="page" @endif><i class="fa-solid fa-table-columns"></i><span>Tableau de bord</span></a>
        <a class="{{ request()->routeIs('payments.*') ? 'active' : '' }}" href="{{ route('payments.index') }}" @if(request()->routeIs('payments.*')) aria-current="page" @endif><i class="fa-solid fa-money-check-dollar"></i><span>Heures supplémentaires</span></a>
        <a class="{{ request()->routeIs('year.*') ? 'active' : '' }}" href="{{ route('year.show', ['year' => now()->year]) }}" @if(request()->routeIs('year.*')) aria-current="page" @endif><i class="fa-solid fa-chart-column"></i><span>Rapports</span></a>
        <a class="{{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.index') }}" @if(request()->routeIs('settings.*')) aria-current="page" @endif><i class="fa-solid fa-gear"></i><span>Paramètres</span></a>
    </nav>
    <div class="sidebar-bottom"><button type="button" class="sidebar-toggle" aria-expanded="true"><i class="fa-solid fa-angles-left"></i><span>Réduire</span></button></div>
</aside>
<div class="app-main">
<header class="topbar">
    <div class="mobile-top-menu"><button type="button" class="bare-icon mobile-menu-toggle" aria-label="Ouvrir le menu" aria-expanded="false"><i class="fa-solid fa-bars"></i></button></div>
    <div class="topbar-title">Suivi Heures &amp; Salaire</div>
    <div class="topbar-actions"><a class="mobile-calendar" href="{{ $dashboardUrl }}" aria-label="Calendrier"><i class="fa-regular fa-calendar-days"></i></a></div>
</header>
<main class="content">
    @if (session('status'))<div class="flash success">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
    @yield('content')
</main>
</div>
<nav class="mobile-nav" aria-label="Navigation mobile">
    <a class="{{ request()->routeIs('month.*') ? 'active' : '' }}" href="{{ $dashboardUrl }}" @if(request()->routeIs('month.*')) aria-current="page" @endif><i class="fa-solid fa-house"></i><span>Tableau</span></a>
    <a class="{{ request()->routeIs('payments.*') ? 'active' : '' }}" href="{{ route('payments.index') }}" @if(request()->routeIs('payments.*')) aria-current="page" @endif><i class="fa-solid fa-money-check-dollar"></i><span>Heures sup</span></a>
    <a class="{{ request()->routeIs('year.*') ? 'active' : '' }}" href="{{ route('year.show', ['year' => now()->year]) }}" @if(request()->routeIs('year.*')) aria-current="page" @endif><i class="fa-solid fa-chart-column"></i><span>Rapports</span></a>
    <a class="{{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.index') }}" @if(request()->routeIs('settings.*')) aria-current="page" @endif><i class="fa-solid fa-gear"></i><span>Paramètres</span></a>
</nav>
</div>
<dialog id="confirm-dialog" class="confirm-dialog" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-message">
    <div class="confirm-dialog-body">
        <div class="confirm-dialog-icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></div>
        <div class="confirm-dialog-copy"><h2 id="confirm-dialog-title">Confirmer l’action</h2><p id="confirm-dialog-message"></p></div>
    </div>
    <div class="confirm-dialog-actions">
        <button type="button" class="confirm-dialog-cancel" id="confirm-dialog-cancel">Annuler</button>
        <button type="button" class="confirm-dialog-submit" id="confirm-dialog-submit">Confirmer</button>
    </div>
</dialog>
<script src="/app.js" defer></script>
@stack('scripts')
</body>
</html>

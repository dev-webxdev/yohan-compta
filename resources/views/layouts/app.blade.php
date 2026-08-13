<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
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
    <div class="sidebar-head"><button type="button" class="bare-icon sidebar-toggle" aria-label="Réduire le menu" aria-expanded="true">☰</button></div>
    <nav class="sidebar-nav">
        <a class="{{ request()->routeIs('month.*') ? 'active' : '' }}" href="{{ $dashboardUrl }}"><i>▣</i><span>Tableau de bord</span></a>
        <a class="{{ request()->routeIs('payments.*') ? 'active' : '' }}" href="{{ route('payments.index') }}"><i>▭</i><span>Paiements</span></a>
        <a class="{{ request()->routeIs('year.*') ? 'active' : '' }}" href="{{ route('year.show', ['year' => now()->year]) }}"><i>▥</i><span>Rapports</span></a>
        <a class="{{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.index') }}"><i>⚙</i><span>Paramètres</span></a>
    </nav>
    <div class="sidebar-bottom"><button type="button" class="sidebar-toggle" aria-expanded="true"><i>«</i><span>Réduire</span></button></div>
</aside>
<div class="app-main">
<header class="topbar">
    <div class="mobile-top-menu"><button type="button" class="bare-icon mobile-menu-toggle" aria-label="Ouvrir le menu" aria-expanded="false">☰</button></div>
    <div class="topbar-title">Suivi Heures &amp; Salaire</div>
    <div class="topbar-actions"><a class="mobile-calendar" href="{{ $dashboardUrl }}">▦</a></div>
</header>
<main class="content">
    @if (session('status'))<div class="flash success">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
    @yield('content')
</main>
</div>
<nav class="mobile-nav" aria-label="Navigation mobile">
    <a class="{{ request()->routeIs('month.*') ? 'active' : '' }}" href="{{ $dashboardUrl }}"><i>⌂</i><span>Tableau</span></a>
    <a class="{{ request()->routeIs('payments.*') ? 'active' : '' }}" href="{{ route('payments.index') }}"><i>▭</i><span>Paiements</span></a>
    <a class="{{ request()->routeIs('year.*') ? 'active' : '' }}" href="{{ route('year.show', ['year' => now()->year]) }}"><i>▥</i><span>Rapports</span></a>
    <a class="{{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.index') }}"><i>⚙</i><span>Paramètres</span></a>
</nav>
</div>
<script src="/app.js" defer></script>
@stack('scripts')
</body>
</html>

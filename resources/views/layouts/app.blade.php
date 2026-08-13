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
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">⏱ <span>Suivi Heures<br>& Salaire</span></div>
        <nav>
            <a class="{{ request()->routeIs('month.*') ? 'active' : '' }}" href="{{ route('month.current') }}">▦ <span>Tableau de bord</span></a>
            <a class="{{ request()->routeIs('year.*') ? 'active' : '' }}" href="{{ route('year.show', ['year' => now()->year]) }}">▥ <span>Année</span></a>
            <a class="{{ request()->routeIs('payments.*') ? 'active' : '' }}" href="{{ route('payments.index') }}">€ <span>Paiements</span></a>
            <a class="{{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.index') }}">⚙ <span>Paramètres</span></a>
        </nav>
        <div class="sidebar-foot">SQLite · local</div>
    </aside>

    <main class="content">
        <header class="topbar">
            <div>
                <div class="eyebrow">Application personnelle</div>
                <h1>@yield('title', 'Suivi Heures & Salaire')</h1>
            </div>
            <a class="ghost-button" href="{{ route('month.show', ['month' => now()->format('Y-m')]) }}">Aujourd’hui</a>
        </header>

        @if (session('status'))
            <div class="flash success">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="flash error">{{ $errors->first() }}</div>
        @endif

        @yield('content')
    </main>
</div>
<script src="/app.js" defer></script>
@stack('scripts')
</body>
</html>

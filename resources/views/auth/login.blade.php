<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Connexion — {{ config('app.name') }}</title>
    <link rel="stylesheet" href="/vendor/fontawesome/css/fontawesome.min.css">
    <link rel="stylesheet" href="/vendor/fontawesome/css/solid.min.css">
    <link rel="stylesheet" href="/app.css">
    <link rel="stylesheet" href="/auth.css">
</head>
<body class="auth-page">
<main class="auth-shell">
    <section class="auth-card" aria-labelledby="login-title">
        <div class="auth-icon" aria-hidden="true"><i class="fa-solid fa-lock"></i></div>
        <h1 id="login-title">Suivi Heures &amp; Salaire</h1>
        <p class="auth-intro">Connectez-vous pour accéder à vos données.</p>

        @if (session('status'))
            <div class="flash success" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="flash error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="post" action="{{ route('login.store') }}" class="auth-form">
            @csrf
            <label for="login">
                <span>Nom d’utilisateur ou adresse e-mail</span>
                <input
                    id="login"
                    name="login"
                    type="text"
                    value="{{ old('login') }}"
                    autocomplete="username"
                    maxlength="255"
                    required
                    autofocus
                    @error('login') aria-invalid="true" @enderror
                >
            </label>

            <label for="password">
                <span>Mot de passe</span>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    maxlength="4096"
                    required
                    @error('password') aria-invalid="true" @enderror
                >
            </label>

            <button type="submit" class="primary-button auth-submit">
                <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>
                Se connecter
            </button>
        </form>
    </section>
</main>
</body>
</html>

<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AuthController
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('auth.authenticated') === true) {
            return redirect()->route('month.current');
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
        ]);

        $login = trim($data['login']);
        $throttleKey = Str::lower($login).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = max(1, RateLimiter::availableIn($throttleKey));

            throw ValidationException::withMessages([
                'login' => 'Trop de tentatives. Réessayez dans '.$seconds.' secondes.',
            ]);
        }

        $username = trim((string) config('access.username'));
        $email = Str::lower(trim((string) config('access.email')));
        $passwordHash = (string) config('access.password_hash');

        $normalizedLogin = Str::lower($login);
        $identityMatches = ($username !== '' && hash_equals($username, $login))
            || ($email !== '' && hash_equals($email, $normalizedLogin));

        $passwordMatches = $passwordHash !== '' && Hash::check($data['password'], $passwordHash);

        if (!$identityMatches || !$passwordMatches) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'login' => 'Identifiants incorrects.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->put('auth.authenticated', true);
        $request->session()->regenerate();

        return redirect()->intended(route('month.current'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Vous êtes déconnecté.');
    }
}

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
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
        ]);

        $email = Str::lower(trim($data['email']));
        $throttleKey = $email.'|'.$request->ip();
        $ipThrottleKey = 'login-ip|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5) || RateLimiter::tooManyAttempts($ipThrottleKey, 20)) {
            $seconds = max(1, RateLimiter::availableIn($throttleKey), RateLimiter::availableIn($ipThrottleKey));

            throw ValidationException::withMessages([
                'email' => 'Trop de tentatives. Réessayez dans '.$seconds.' secondes.',
            ]);
        }

        $configuredEmail = Str::lower(trim((string) config('access.email')));
        $passwordHash = (string) config('access.password_hash');

        $identityMatches = $configuredEmail !== '' && hash_equals($configuredEmail, $email);

        $passwordMatches = $passwordHash !== '' && Hash::check($data['password'], $passwordHash);

        if (!$identityMatches || !$passwordMatches) {
            RateLimiter::hit($throttleKey, 60);
            RateLimiter::hit($ipThrottleKey, 60);

            throw ValidationException::withMessages([
                'email' => 'Identifiants incorrects.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        RateLimiter::clear($ipThrottleKey);
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

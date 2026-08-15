<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

Artisan::command('auth:password-hash', function (): int {
    $password = $this->secret('Mot de passe (12 caractères minimum)');
    $confirmation = $this->secret('Confirmez le mot de passe');

    if (!is_string($password) || strlen($password) < 12) {
        $this->error('Le mot de passe doit contenir au moins 12 caractères.');

        return 1;
    }

    if (!hash_equals($password, is_string($confirmation) ? $confirmation : '')) {
        $this->error('Les mots de passe ne correspondent pas.');

        return 1;
    }

    $this->newLine();
    $this->line("AUTH_PASSWORD_HASH='".Hash::make($password)."'");

    return 0;
})->purpose('Générer le hash du mot de passe de connexion');

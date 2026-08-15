<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    protected bool $authenticatedByDefault = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'access.username' => 'yohan',
            'access.email' => 'yohan@example.com',
            'access.password_hash' => Hash::make('correct-horse-battery'),
        ]);
    }

    public function test_guests_cannot_access_pages_or_json_endpoints(): void
    {
        $this->get('/mois')->assertRedirect('/connexion');

        $this->putJson('/jours/2026-08-15', [
            'start_time' => '07:45',
            'driving' => '01:00',
            'warehouse' => '',
            'meal_mode' => 'auto',
        ])->assertUnauthorized();
    }

    public function test_login_accepts_username_or_email_and_logout_closes_session(): void
    {
        $this->post('/connexion', [
            'login' => 'yohan',
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/mois');

        $this->get('/mois')->assertOk();

        $this->post('/deconnexion')->assertRedirect('/connexion');
        $this->get('/mois')->assertRedirect('/connexion');

        $this->post('/connexion', [
            'login' => 'YOHAN@EXAMPLE.COM',
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/mois');

        $this->get('/mois')->assertOk();
    }

    public function test_invalid_credentials_return_a_clear_generic_error(): void
    {
        $this->from('/connexion')->post('/connexion', [
            'login' => 'yohan',
            'password' => 'mauvais-mot-de-passe',
        ])
            ->assertRedirect('/connexion')
            ->assertSessionHasErrors(['login' => 'Identifiants incorrects.']);

        $this->get('/mois')->assertRedirect('/connexion');
    }

    public function test_repeated_failures_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/connexion', [
                'login' => 'yohan',
                'password' => 'mauvais-mot-de-passe',
            ])->assertSessionHasErrors('login');
        }

        $response = $this->post('/connexion', [
            'login' => 'yohan',
            'password' => 'mauvais-mot-de-passe',
        ])->assertSessionHasErrors('login');

        self::assertStringContainsString(
            'Trop de tentatives.',
            $response->getSession()->get('errors')->first('login'),
        );
    }

    public function test_password_configuration_uses_a_one_way_hash(): void
    {
        $hash = (string) config('access.password_hash');

        self::assertNotSame('correct-horse-battery', $hash);
        self::assertTrue(Hash::check('correct-horse-battery', $hash));
        self::assertTrue((bool) config('session.encrypt'));
        self::assertTrue((bool) config('session.http_only'));
    }
}

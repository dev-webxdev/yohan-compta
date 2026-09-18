<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $authenticatedByDefault = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'access.email' => 'yohan@example.com',
            'access.password_hash' => Hash::make('correct-horse-battery'),
        ]);
    }

    public function test_login_page_displays_expected_fields(): void
    {
        $this->get('/connexion')
            ->assertOk()
            ->assertSee('Adresse e-mail')
            ->assertSee('Mot de passe')
            ->assertSee('Se connecter');
    }

    public function test_guests_cannot_access_pages_or_json_endpoints(): void
    {
        $this->get('/mois')->assertRedirect('/connexion');
        $this->get('/bibliotheque')->assertRedirect('/connexion');

        $this->putJson('/jours/2026-08-15', [
            'start_time' => '07:45',
            'driving' => '01:00',
            'warehouse' => '',
            'meal_mode' => 'auto',
        ])->assertUnauthorized();
    }

    public function test_login_accepts_email_and_logout_closes_session(): void
    {
        $this->post('/connexion', [
            'email' => 'YOHAN@EXAMPLE.COM',
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/mois');

        $this->get('/mois')->assertOk();

        $this->post('/deconnexion')->assertRedirect('/connexion');
        $this->get('/mois')->assertRedirect('/connexion');

        $this->post('/connexion', [
            'email' => 'yohan@example.com',
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/mois');

        $this->get('/mois')->assertOk();
    }

    public function test_login_always_redirects_to_current_month_even_with_a_stale_intended_url(): void
    {
        $this->withSession(['url.intended' => url('/connexion')])
            ->post('/connexion', [
                'email' => 'yohan@example.com',
                'password' => 'correct-horse-battery',
            ])
            ->assertRedirect('/mois');
    }

    public function test_invalid_credentials_return_a_clear_generic_error(): void
    {
        $this->from('/connexion')->post('/connexion', [
            'email' => 'yohan@example.com',
            'password' => 'mauvais-mot-de-passe',
        ])
            ->assertRedirect('/connexion')
            ->assertSessionHasErrors(['email' => 'Identifiants incorrects.']);

        $this->from('/connexion')->post('/connexion', [
            'email' => 'yohan',
            'password' => 'correct-horse-battery',
        ])
            ->assertRedirect('/connexion')
            ->assertSessionHasErrors('email');

        $this->get('/mois')->assertRedirect('/connexion');
    }

    public function test_invalid_configured_password_hash_never_causes_a_500(): void
    {
        config(['access.password_hash' => 'not-a-valid-bcrypt-hash']);

        $this->from('/connexion')->post('/connexion', [
            'email' => 'yohan@example.com',
            'password' => 'correct-horse-battery',
        ])
            ->assertRedirect('/connexion')
            ->assertSessionHasErrors(['email' => 'Identifiants incorrects.']);
    }

    public function test_repeated_failures_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/connexion', [
                'email' => 'yohan@example.com',
                'password' => 'mauvais-mot-de-passe',
            ])->assertSessionHasErrors('email');
        }

        $this->post('/connexion', [
            'email' => 'yohan@example.com',
            'password' => 'mauvais-mot-de-passe',
        ])->assertSessionHasErrors('email');

        $this->get('/connexion')
            ->assertOk()
            ->assertSee('Trop de tentatives.');
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

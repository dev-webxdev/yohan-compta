<?php

namespace Tests\Feature;

use App\Models\SettingPeriod;
use App\Models\WorkDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Issue80SettingsEffectiveDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_start_change_updates_existing_days_from_effective_date_without_overwriting_custom_times(): void
    {
        WorkDay::query()->create([
            'date' => '2026-08-23',
            'start_time_minutes' => 465,
            'driving_minutes' => 60,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);
        WorkDay::query()->create([
            'date' => '2026-08-24',
            'start_time_minutes' => 465,
            'driving_minutes' => 60,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);
        WorkDay::query()->create([
            'date' => '2026-08-25',
            'start_time_minutes' => 420,
            'driving_minutes' => 60,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);

        SettingPeriod::query()->create([
            'effective_from' => '2026-09-01',
            'default_start_time_minutes' => 480,
            'hourly_net_rate_cents' => 974,
            'weekly_threshold_minutes' => 2100,
            'meal_allowance_cents' => 1600,
            'meal_allowance_time_minutes' => 855,
        ]);
        WorkDay::query()->create([
            'date' => '2026-09-02',
            'start_time_minutes' => 465,
            'driving_minutes' => 60,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);

        $this->post('/parametres', [
            'effective_from' => '2026-08-24',
            'default_start_time' => '08:30',
            'hourly_net_rate' => '9,74',
            'weekly_threshold' => '35:00',
            'meal_allowance' => '16',
            'meal_allowance_time' => '14:15',
        ])->assertRedirect('/parametres');

        self::assertSame(465, WorkDay::query()->whereDate('date', '2026-08-23')->value('start_time_minutes'));
        self::assertSame(510, WorkDay::query()->whereDate('date', '2026-08-24')->value('start_time_minutes'));
        self::assertSame(420, WorkDay::query()->whereDate('date', '2026-08-25')->value('start_time_minutes'));
        self::assertSame(465, WorkDay::query()->whereDate('date', '2026-09-02')->value('start_time_minutes'));
        self::assertSame(480, SettingPeriod::query()->whereDate('effective_from', '2026-09-01')->value('default_start_time_minutes'));

        $content = $this->get('/mois/2026-08')->assertOk()->getContent();
        self::assertMatchesRegularExpression('/data-date="2026-08-23".*?name="start_time" value="07:45"/s', $content);
        self::assertMatchesRegularExpression('/data-date="2026-08-24".*?name="start_time" value="08:30"/s', $content);
        self::assertMatchesRegularExpression('/data-date="2026-08-25".*?name="start_time" value="07:00"/s', $content);
    }
}

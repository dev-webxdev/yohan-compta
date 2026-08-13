<?php

namespace Tests\Unit;

use App\Support\Time;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimeTest extends TestCase
{
    public function test_duration_round_trip(): void
    {
        self::assertSame(450, Time::parseDuration('07:30'));
        self::assertSame('155:45', Time::formatDuration(9345));
    }

    #[DataProvider('invalidTimes')]
    public function test_invalid_values_are_rejected(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Time::parseDuration($value);
    }

    public static function invalidTimes(): array
    {
        return [['14:75'], ['abc'], ['1.5']];
    }
}

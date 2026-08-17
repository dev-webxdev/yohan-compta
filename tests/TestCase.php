<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected bool $authenticatedByDefault = true;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 12:00:00');

        if ($this->authenticatedByDefault) {
            $this->withSession(['auth.authenticated' => true]);
        }
    }

    /** @param array<string,mixed> $data @param array<string,string> $headers */
    public function putJson($uri, array $data = [], array $headers = [], $options = 0): TestResponse
    {
        if (preg_match('#^/jours/\d{4}-\d{2}-\d{2}$#', (string) $uri)) {
            $data += ['unlocked' => true];
        }

        return parent::putJson($uri, $data, $headers, $options);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}

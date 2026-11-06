<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Settings intentionally use the named Redis cache in production.
        // Isolate that store for tests; direct Redis node/traffic calls are mocked
        // explicitly in the tests that verify their messages.
        config()->set('cache.stores.redis', ['driver' => 'array']);
        app('cache')->forgetDriver('redis');
        app()->forgetInstance(\App\Support\Setting::class);
    }
}

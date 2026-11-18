<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (! $app->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || filled(config('database.connections.sqlite.url'))) {
            throw new RuntimeException('Tests must use the isolated SQLite in-memory database; got '.json_encode([$app->environment(), config('database.default'), config('database.connections.sqlite.database')]));
        }

        return $app;
    }
}

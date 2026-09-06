<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $connection = (string) getenv('DB_CONNECTION');
        $database = (string) getenv('DB_DATABASE');

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException('Tests require SQLite :memory:. Refusing to touch any other database.');
        }

        parent::setUp();

        $effective = config('database.connections.'.config('database.default'));
        if (($effective['driver'] ?? null) !== 'sqlite' || ($effective['database'] ?? null) !== ':memory:') {
            throw new \RuntimeException('Effective test connection must be SQLite :memory:.');
        }
    }
}

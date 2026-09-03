<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $connection = (string) getenv('DB_CONNECTION');
        $database = (string) getenv('DB_DATABASE');

        if ($connection !== 'mysql' || ! str_ends_with($database, '_testing')) {
            throw new \RuntimeException('Tests require an isolated MySQL database whose name ends with _testing. Refusing to touch any other database.');
        }

        parent::setUp();
    }
}

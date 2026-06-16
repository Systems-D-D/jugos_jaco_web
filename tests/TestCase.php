<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->guardAgainstProductionDatabase();

        parent::setUp();
    }

    /**
     * Abort test execution if the environment is not pointing to the
     * SQLite in-memory database used for testing. This prevents tests
     * that use RefreshDatabase from wiping the development database.
     */
    protected function guardAgainstProductionDatabase(): void
    {
        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? env('APP_ENV');
        $dbConnection = $_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? env('DB_CONNECTION');
        $dbDatabase = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? env('DB_DATABASE');

        if ($appEnv !== 'testing' || $dbConnection !== 'sqlite' || $dbDatabase !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Tests must run against SQLite in-memory database. '.
                'Current environment: APP_ENV=%s, DB_CONNECTION=%s, DB_DATABASE=%s. '.
                'Check your .env.testing file and run tests with "php artisan test" or "./vendor/bin/pest" with phpunit.xml loaded.',
                var_export($appEnv, true),
                var_export($dbConnection, true),
                var_export($dbDatabase, true)
            ));
        }
    }
}

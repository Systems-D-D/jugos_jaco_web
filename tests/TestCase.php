<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->guardAgainstProductionEnv();

        parent::setUp();
    }

    /**
     * Refresh the application instance, then verify the *resolved* database
     * config before any trait (e.g. RefreshDatabase) gets a chance to run
     * "migrate:fresh". The base setUp() calls refreshApplication() before
     * setUpTraits(), so this is the last safe point with a booted application.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $this->guardAgainstProductionDatabase();
    }

    /**
     * Fast, app-independent guard: abort if the environment variables do not
     * point to the SQLite in-memory database. Runs before the application is
     * booted, so it can only inspect raw env values (not config()).
     */
    protected function guardAgainstProductionEnv(): void
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

    /**
     * Critical guard against a cached config wiping the production database.
     *
     * When the config is cached (bootstrap/cache/config.php), Laravel ignores
     * env() and phpunit's <env> overrides entirely, so guardAgainstProductionEnv()
     * passes while the real connection still points to the production database.
     * This inspects the *resolved* config that migrate:fresh will actually drop.
     */
    protected function guardAgainstProductionDatabase(): void
    {
        $resolvedConnection = config('database.default');
        $resolvedDatabase = config('database.connections.'.$resolvedConnection.'.database');

        if ($resolvedConnection !== 'sqlite' || $resolvedDatabase !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Refusing to run tests: resolved database config does not point to '.
                'SQLite in-memory. config(database.default)=%s, database=%s. '.
                'The config is likely cached: run "php artisan config:clear" before testing.',
                var_export($resolvedConnection, true),
                var_export($resolvedDatabase, true)
            ));
        }
    }
}

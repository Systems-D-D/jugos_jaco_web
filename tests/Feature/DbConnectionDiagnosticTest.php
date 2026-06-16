<?php

it('prints the active database configuration during testing', function () {
    $this->assertTrue(true);

    dump([
        'config_APP_ENV' => config('app.env'),
        'config_DB_CONNECTION' => config('database.default'),
        'config_DB_DATABASE' => config('database.connections.' . config('database.default') . '.database'),
        'env_APP_ENV' => $_ENV['APP_ENV'] ?? 'not set',
        'env_DB_CONNECTION' => $_ENV['DB_CONNECTION'] ?? 'not set',
        'env_DB_DATABASE' => $_ENV['DB_DATABASE'] ?? 'not set',
        'server_APP_ENV' => $_SERVER['APP_ENV'] ?? 'not set',
        'server_DB_CONNECTION' => $_SERVER['DB_CONNECTION'] ?? 'not set',
        'server_DB_DATABASE' => $_SERVER['DB_DATABASE'] ?? 'not set',
        'getenv_APP_ENV' => getenv('APP_ENV'),
        'getenv_DB_CONNECTION' => getenv('DB_CONNECTION'),
        'getenv_DB_DATABASE' => getenv('DB_DATABASE'),
    ]);
});

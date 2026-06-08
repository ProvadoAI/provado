<?php

namespace Mquevedob\Provado;

use Illuminate\Support\ServiceProvider;

class ProvadoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/provado.php', 'provado');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/provado.php' => config_path('provado.php'),
        ], 'provado-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}

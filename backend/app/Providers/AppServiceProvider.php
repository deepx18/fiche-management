<?php

namespace App\Providers;

use App\Services\Google\GoogleSheetsClient;
use App\Services\Google\SheetsReader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // La lecture Google Sheets passe par une interface : la production
        // utilise le client officiel (API v4, lecture seule) tandis que les
        // tests injectent un lecteur simulé, sans identifiants ni réseau.
        $this->app->bind(SheetsReader::class, GoogleSheetsClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

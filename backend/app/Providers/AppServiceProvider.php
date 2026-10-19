<?php

namespace App\Providers;

use App\Services\Google\GoogleSheetsClient;
use App\Services\Google\SheetsReader;
use App\Services\Telephony\CallProviderRegistry;
use App\Services\Telephony\Issabel\IssabelCallProvider;
use App\Services\Telephony\Issabel\IssabelCdrReader;
use App\Services\Telephony\Issabel\IssabelCdrRepository;
use App\Services\Telephony\Kavkom\KavkomCallProvider;
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

        // Phase 2 : la lecture CDR Issabel est une frontière testable ; en
        // production elle interroge une connexion MySQL dédiée en lecture
        // seule, jamais la base applicative.
        $this->app->bind(IssabelCdrReader::class, IssabelCdrRepository::class);

        $this->app->singleton(CallProviderRegistry::class, function ($app): CallProviderRegistry {
            return new CallProviderRegistry([
                $app->make(KavkomCallProvider::class),
                $app->make(IssabelCallProvider::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

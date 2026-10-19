<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CallRecordingController;
use App\Http\Controllers\CallSearchController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContactSearchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DuplicateController;
use App\Http\Controllers\ExtractionController;
use App\Http\Controllers\ImportedFileController;
use App\Http\Controllers\ImportHistoryController;
use App\Http\Controllers\KavkomWebhookController;
use App\Http\Controllers\ProductionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API du gestionnaire de fiches contact
|--------------------------------------------------------------------------
| Authentification par tokens Sanctum (desktops séparés). Tout le module,
| à l'exception de login, est réservé aux administrateurs actifs
| (middleware EnsureAdmin, qui applique aussi la note : rôle = admin).
*/

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:30,1')->name('login');

// Webhook Kavkom : pas de session Sanctum, authentification par secret
// partagé dans un en-tête configuré côté Kavkom (voir config/telephony.php).
Route::post('/webhooks/kavkom/cdr', KavkomWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.kavkom.cdr');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/user/password', [AuthController::class, 'changePassword']);

    Route::middleware('admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'stats']);

        // Fiches importées.
        Route::get('/files', [ImportedFileController::class, 'index']);
        Route::post('/files/import', [ImportedFileController::class, 'store']);
        Route::get('/files/{file}', [ImportedFileController::class, 'show'])->whereNumber('file');
        Route::get('/files/{file}/contacts', [ImportedFileController::class, 'contacts'])->whereNumber('file');
        Route::get('/files/{file}/export', [ImportedFileController::class, 'exportAll'])->whereNumber('file');
        Route::get('/files/{file}/cleaned-export', [ImportedFileController::class, 'exportCleaned'])->whereNumber('file');
        Route::get('/files/{file}/download', [ImportedFileController::class, 'download'])->whereNumber('file');
        Route::delete('/files/{file}', [ImportedFileController::class, 'destroy'])->whereNumber('file');

        // Doublons (scan lecture seule) et extraction.
        Route::get('/files/{file}/duplicates', [DuplicateController::class, 'index'])->whereNumber('file');
        Route::post('/files/{file}/duplicates/extract', [DuplicateController::class, 'extract'])->whereNumber('file');

        Route::get('/duplicate-extractions', [ExtractionController::class, 'index']);
        Route::get('/duplicate-extractions/{extraction}', [ExtractionController::class, 'show'])->whereNumber('extraction');
        Route::get('/duplicate-extractions/{extraction}/export', [ExtractionController::class, 'export'])->whereNumber('extraction');
        Route::post('/duplicate-extractions/{extraction}/restore', [ExtractionController::class, 'restore'])->whereNumber('extraction');

        // Contacts (toutes fiches) + export global.
        Route::get('/contacts', [ContactController::class, 'index']);
        Route::get('/contacts/export', [ContactController::class, 'export']);

        // Recherche globale de contacts (toutes fiches) + export filtré.
        Route::get('/contact-search', [ContactSearchController::class, 'index']);
        Route::get('/contact-search/export', [ContactSearchController::class, 'export']);

        // Production (Phase 1) : table synchronisée depuis Google Sheets,
        // lancement de la synchronisation et numéros clients (saisie locale).
        Route::get('/production', [ProductionController::class, 'index']);
        Route::get('/production/syncs', [ProductionController::class, 'syncs']);
        Route::post('/production/sync', [ProductionController::class, 'sync']);
        Route::patch('/production/{record}/phone', [ProductionController::class, 'updatePhone'])->whereNumber('record');

        // Phase 2 : recherche d'appels asynchrone, statut par fournisseur,
        // appels stockés et téléchargement privé des enregistrements.
        Route::post('/production/calls/search', [CallSearchController::class, 'store'])->middleware('throttle:30,1');
        Route::get('/production/calls/searches/{search}', [CallSearchController::class, 'show'])->whereNumber('search');
        Route::get('/production/calls/searches/{search}/calls', [CallSearchController::class, 'calls'])->whereNumber('search');
        Route::get('/production/{record}/calls', [CallSearchController::class, 'recordCalls'])->whereNumber('record');
        Route::post('/calls/{call}/recording/retrieve', [CallSearchController::class, 'retrieveRecording'])->whereNumber('call')->middleware('throttle:30,1');
        Route::get('/calls/{call}/recording', [CallRecordingController::class, 'show'])->whereNumber('call');

        // Historique d'import.
        Route::get('/import-history', [ImportHistoryController::class, 'index']);
        Route::get('/import-history/{history}', [ImportHistoryController::class, 'show'])->whereNumber('history');

        // Administrateurs.
        Route::get('/admins', [AdminController::class, 'index']);
        Route::post('/admins', [AdminController::class, 'store']);
        Route::patch('/admins/{admin}', [AdminController::class, 'update'])->whereNumber('admin');
        Route::delete('/admins/{admin}', [AdminController::class, 'destroy'])->whereNumber('admin');
    });
});

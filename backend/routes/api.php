<?php

use App\Http\Controllers\IncidentController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\ResponderController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['auth:sanctum', 'ability:read', 'throttle:api']]);
Broadcast::channel('operations', fn ($user) => $user->tokenCan('read'));

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::middleware('ability:read')->group(function () {
        Route::get('zones', [OperationsController::class, 'zones']);
        Route::get('events', [OperationsController::class, 'events']);
        Route::get('incidents', [OperationsController::class, 'incidents']);
        Route::get('responders', [ResponderController::class, 'index']);
        Route::get('integrations', [OperationsController::class, 'integrations']);
    });
    Route::middleware('ability:operate')->group(function () {
        Route::post('zones', [OperationsController::class, 'createZone']);
        Route::post('responders', [ResponderController::class, 'store']);
        Route::post('responders/{responder}/refresh', [ResponderController::class, 'refresh'])->middleware('throttle:network');
        Route::post('incidents/{incident}/recommend', [IncidentController::class, 'recommend']);
        Route::post('incidents/{incident}/approve', [IncidentController::class, 'approve']);
        Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge']);
        Route::post('incidents/{incident}/resolve', [IncidentController::class, 'resolve']);
    });
    Route::middleware('ability:ingest')->group(function () {
        Route::post('demo/zones/{zone}/observations', [OperationsController::class, 'observe']);
        Route::post('demo/responders/{responder}/signals', [ResponderController::class, 'demoSignals']);
    });
});

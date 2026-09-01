<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MissionController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ResponderController;
use App\Http\Controllers\VenueEventController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['auth:sanctum', 'ability:read', 'throttle:api']]);
Broadcast::channel('operations.{organizationId}', fn ($user, int $organizationId) => $user->tokenCan('read') && $user->organization_id === $organizationId);

Route::prefix('v1/auth')->middleware('throttle:api')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('invitations/accept', [AuthController::class, 'acceptInvitation']);
});

Route::prefix('v1')->middleware(['auth:sanctum', 'active.organization', 'throttle:api'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('organization/dashboard', [OrganizationController::class, 'dashboard']);
    Route::get('organization/events', [VenueEventController::class, 'index']);
    Route::middleware('ability:manage')->group(function () {
        Route::get('organization/members', [OrganizationController::class, 'members']);
        Route::post('organization/events', [VenueEventController::class, 'store']);
        Route::post('organization/invitations', [InvitationController::class, 'store']);
    });
    Route::middleware('ability:read')->group(function () {
        Route::get('zones', [OperationsController::class, 'zones']);
        Route::get('events', [OperationsController::class, 'events']);
        Route::get('incidents', [OperationsController::class, 'incidents']);
        Route::get('responders', [ResponderController::class, 'index']);
        Route::get('integrations', [OperationsController::class, 'integrations']);
    });
    Route::middleware('ability:operate')->group(function () {
        Route::post('demo/setup', [DemoController::class, 'setup']);
        Route::post('demo/zones/{zone}/scenario', [DemoController::class, 'scenario']);
        Route::post('demo/network/refresh', [DemoController::class, 'refresh'])->middleware('throttle:network');
        Route::post('demo/zones/{zone}/population/refresh', [DemoController::class, 'population'])->middleware('throttle:network');
        Route::post('zones', [OperationsController::class, 'createZone']);
        Route::post('responders', [ResponderController::class, 'store']);
        Route::post('responders/{responder}/refresh', [ResponderController::class, 'refresh'])->middleware('throttle:network');
        Route::post('incidents/{incident}/recommend', [IncidentController::class, 'recommend']);
        Route::post('incidents/{incident}/approve', [IncidentController::class, 'approve']);
        Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge']);
        Route::post('incidents/{incident}/resolve', [IncidentController::class, 'resolve']);
    });
    Route::middleware('ability:respond')->group(function () {
        Route::get('missions', [MissionController::class, 'index']);
        Route::post('missions/{incident}/acknowledge', [MissionController::class, 'acknowledge']);
    });
    Route::middleware('ability:ingest')->group(function () {
        Route::post('demo/zones/{zone}/observations', [OperationsController::class, 'observe']);
        Route::post('demo/responders/{responder}/signals', [ResponderController::class, 'demoSignals']);
    });
});

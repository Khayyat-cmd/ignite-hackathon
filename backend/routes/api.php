<?php

use App\Http\Controllers\IncidentController;
use App\Http\Controllers\MissionCommunicationController;
use App\Http\Controllers\MissionController;
use App\Http\Controllers\OperatorCopilotController;
use App\Http\Controllers\SimulationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/demo')->middleware('throttle:api')->group(function () {
    Route::get('simulations', [SimulationController::class, 'index']);
    Route::get('simulations/{run}', [SimulationController::class, 'show']);
    Route::post('simulations/{run}/location-retrieval/v0/retrieve', [SimulationController::class, 'retrieve']);
    Route::post('simulations', [SimulationController::class, 'store']);
    Route::post('simulations/{run}/control', [SimulationController::class, 'control']);
    Route::post('simulations/{run}/copilot', [OperatorCopilotController::class, 'store']);
    Route::post('incidents/{incident}/recommend', [IncidentController::class, 'recommend']);
    Route::post('incidents/{incident}/advice', [IncidentController::class, 'advise']);
    Route::post('incidents/{incident}/approve', [IncidentController::class, 'approve']);
    Route::post('incidents/{incident}/resolve', [IncidentController::class, 'resolve']);
    Route::get('responders', [MissionController::class, 'responders']);
    Route::get('missions', [MissionController::class, 'index']);
    Route::post('missions/{incident}/acknowledge', [MissionController::class, 'acknowledge']);
    Route::get('missions/{incident}/messages', [MissionCommunicationController::class, 'index']);
    Route::post('missions/{incident}/messages', [MissionCommunicationController::class, 'store']);
});

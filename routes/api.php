<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CycleController;
use App\Http\Controllers\Api\GroupController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['ok' => true, 'app' => 'ajo-api']);

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/groups', [GroupController::class, 'index']);
    Route::get('/groups/{id}', [GroupController::class, 'show']);
    Route::put('/groups/{id}', [GroupController::class, 'update']);
    Route::delete('/groups/{id}', [GroupController::class, 'destroy']);
    Route::get('/groups/{id}/schedule', [GroupController::class, 'schedule']);

    Route::put('/groups/{id}/cycles/{cycle}/payments/{member}', [CycleController::class, 'markPaid'])->whereNumber('cycle');
    Route::delete('/groups/{id}/cycles/{cycle}/payments/{member}', [CycleController::class, 'unmarkPaid'])->whereNumber('cycle');
    Route::put('/groups/{id}/cycles/{cycle}/payout', [CycleController::class, 'markCollected'])->whereNumber('cycle');
    Route::delete('/groups/{id}/cycles/{cycle}/payout', [CycleController::class, 'unmarkCollected'])->whereNumber('cycle');
});

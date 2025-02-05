<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\OperatorController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\MonitoringController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Authentication routes
Route::post('/auth/login', 'AuthController@login');
Route::post('/auth/refresh', 'AuthController@refresh');

// Protected routes
Route::middleware('auth:api')->group(function () {
    // Message endpoints
    Route::prefix('messages')->group(function () {
        Route::post('/', [MessageController::class, 'send']);
        Route::post('/bulk', [MessageController::class, 'bulk']);
        Route::get('/{messageId}', [MessageController::class, 'status']);
        Route::delete('/{messageId}', [MessageController::class, 'cancel']);
    });

    // Operator management
    Route::prefix('operators')->group(function () {
        Route::get('/', [OperatorController::class, 'index']);
        Route::post('/', [OperatorController::class, 'store']);
        Route::get('/{id}', [OperatorController::class, 'show']);
        Route::put('/{id}', [OperatorController::class, 'update']);
        Route::delete('/{id}', [OperatorController::class, 'destroy']);
        Route::get('/{id}/stats', [OperatorController::class, 'stats']);
        Route::post('/{id}/test', [OperatorController::class, 'test']);
    });

    // Routing management
    Route::prefix('routes')->group(function () {
        Route::get('/', [RouteController::class, 'index']);
        Route::post('/', [RouteController::class, 'store']);
        Route::get('/{id}', [RouteController::class, 'show']);
        Route::put('/{id}', [RouteController::class, 'update']);
        Route::delete('/{id}', [RouteController::class, 'destroy']);
        Route::post('/bulk-update', [RouteController::class, 'bulkUpdate']);
    });

    // Monitoring endpoints
    Route::prefix('monitoring')->group(function () {
        Route::get('/dashboard', [MonitoringController::class, 'dashboard']);
        Route::get('/stats', [MonitoringController::class, 'stats']);
        Route::get('/alerts', [MonitoringController::class, 'alerts']);
        Route::get('/logs', [MonitoringController::class, 'logs']);
        Route::get('/performance', [MonitoringController::class, 'performance']);
    });

    // User management
    Route::prefix('users')->group(function () {
        Route::get('/', 'UserController@index');
        Route::post('/', 'UserController@store');
        Route::get('/{id}', 'UserController@show');
        Route::put('/{id}', 'UserController@update');
        Route::delete('/{id}', 'UserController@destroy');
        Route::post('/{id}/api-key', 'UserController@regenerateApiKey');
    });
}); 
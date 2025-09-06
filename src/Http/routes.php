<?php

use Illuminate\Support\Facades\Route;
use LaravelMint\Http\Controllers\MintApiController;

Route::prefix('api/mint')
    ->name('mint.api.')
    ->middleware(['api'])
    ->group(function () {
        // Health check (no auth required)
        Route::get('/health', [MintApiController::class, 'health'])->name('health')->withoutMiddleware(['throttle']);
        
        // Protected routes
        Route::middleware(['throttle:60,1'])->group(function () {
            // Generation
            Route::post('/generate', [MintApiController::class, 'generate'])->name('generate');
            Route::get('/jobs/{jobId}/status', [MintApiController::class, 'status'])->name('status');
            
            // Import/Export
            Route::post('/import', [MintApiController::class, 'import'])->name('import');
            Route::post('/export', [MintApiController::class, 'export'])->name('export');
            
            // Information
            Route::get('/models', [MintApiController::class, 'models'])->name('models');
            Route::get('/patterns', [MintApiController::class, 'patterns'])->name('patterns');
            Route::get('/scenarios', [MintApiController::class, 'scenarios'])->name('scenarios');
            Route::get('/statistics', [MintApiController::class, 'statistics'])->name('statistics');
            
            // Scenarios
            Route::post('/scenarios/run', [MintApiController::class, 'runScenario'])->name('scenarios.run');
        });
    });
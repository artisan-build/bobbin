<?php

declare(strict_types=1);

use App\Http\Controllers\CaptureController;
use App\Http\Controllers\CapturedRequestController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\EndpointController;
use App\Http\Controllers\EndpointSettingsController;
use App\Http\Controllers\ReplayController;
use Illuminate\Support\Facades\Route;

Route::any('/hooks/{publicId}', CaptureController::class)->name('hooks.capture');

Route::middleware('bfc.auth')->group(function (): void {
    Route::resource('endpoints', EndpointController::class)
        ->only(['index', 'create', 'store', 'show']);
    Route::get('/endpoints/{endpoint}/settings', [EndpointSettingsController::class, 'edit'])
        ->name('endpoint-settings.edit');
    Route::put('/endpoints/{endpoint}/settings', [EndpointSettingsController::class, 'update'])
        ->name('endpoint-settings.update');
    Route::put('/endpoints/{endpoint}/destination', [DestinationController::class, 'update'])
        ->name('destinations.update');
    Route::get('/endpoints/{endpoint}/captures', [CapturedRequestController::class, 'index'])
        ->name('captures.index');
    Route::get('/endpoints/{endpoint}/captures/{capture}', [CapturedRequestController::class, 'show'])
        ->name('captures.show');
    Route::post('/endpoints/{endpoint}/captures/{capture}/replay', ReplayController::class)
        ->name('captures.replay');
});

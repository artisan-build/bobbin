<?php

declare(strict_types=1);

use App\Http\Controllers\CaptureController;
use App\Http\Controllers\CapturedRequestController;
use App\Http\Controllers\EndpointController;
use Illuminate\Support\Facades\Route;

Route::any('/hooks/{publicId}', CaptureController::class)->name('hooks.capture');

Route::middleware('bfc.auth')->group(function (): void {
    Route::redirect('/', '/endpoints');
    Route::resource('endpoints', EndpointController::class)
        ->only(['index', 'create', 'store', 'show']);
    Route::get('/endpoints/{endpoint}/captures', [CapturedRequestController::class, 'index'])
        ->name('captures.index');
    Route::get('/endpoints/{endpoint}/captures/{capture}', [CapturedRequestController::class, 'show'])
        ->name('captures.show');
});

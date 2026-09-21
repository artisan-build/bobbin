<?php

declare(strict_types=1);

use App\Http\Controllers\EndpointController;
use Illuminate\Support\Facades\Route;

Route::middleware('bfc.auth')->group(function (): void {
    Route::redirect('/', '/endpoints');
    Route::resource('endpoints', EndpointController::class)
        ->only(['index', 'create', 'store', 'show']);
});

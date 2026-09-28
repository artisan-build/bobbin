<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

final class DashboardController
{
    public function __invoke(): RedirectResponse
    {
        return to_route('endpoints.index');
    }
}

<?php

use App\Console\Commands\RedispatchMcpReplayRequests;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('captures:prune')->daily();
Schedule::command(RedispatchMcpReplayRequests::class)->everyMinute()->withoutOverlapping();

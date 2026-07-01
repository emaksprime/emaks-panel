<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('panel:archive-logs')
    ->monthlyOn(1, '03:10')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping();

Schedule::command('technical-service:reconcile-iyzico-payments --limit=50 --older-than-minutes=2 --max-attempts=5')
    ->everyFiveMinutes()
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping();

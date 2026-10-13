<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// LGPD: descarta comprovantes vencidos pela política de retenção, todo dia de madrugada.
Schedule::command('reembolso:purge-receipts')->dailyAt('03:15')->withoutOverlapping()->onOneServer();

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| The automated arrears ladder (13 A4)
|--------------------------------------------------------------------------
|
| Every morning at nine, Jamaica time — office hours, so a reminder arrives
| when somebody at the estate can take the call it prompts. A household the
| committee has flagged for hardship or dispute is skipped, as is one keeping
| to an agreed plan; see `Collections::runAutomated()`.
|
| Nothing here runs unless the scheduler does: `php artisan schedule:work` in
| development, and a scheduled task calling `php artisan schedule:run` every
| minute in production — `docs/DEPLOY.md`.
|
*/
Schedule::command('dunning:run')
    ->dailyAt('09:00')
    ->timezone('America/Jamaica')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Alertness checks (13 D2)
|--------------------------------------------------------------------------
|
| Every five minutes: issue the checks guards on duty are due — at an interval
| that varies per guard and per hour, so none can be anticipated — and record
| as missed the ones not answered within two minutes. The Guard App learns of a
| pending check from `POST /presence/activity` and `GET /sync/pull`.
|
*/
Schedule::command('alertness:run')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

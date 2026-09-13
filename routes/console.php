<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| 🔴 DEPLOY PREREQUISITE: nothing here runs without a `php artisan schedule:work`
| service on Railway — a THIRD service off the same image, alongside web and
| queue-worker. No domain and no healthcheck path (it listens on no port, so a
| healthcheck would crash-loop it). That service now EXISTS in production, named
| "Schedular".
|
| ⚠️ It needs the Tamara credentials, not just the database ones. The command
| below does not merely read local rows: reconcileLapsed() asks Tamara about each
| aged hold, and the call is deliberately un-caught (Tamara's answer, never our
| clock, decides a hold is dead). So a missing TAMARA_API_TOKEN on the scheduler
| throws and takes the ALERTING down with it, since reconciliation runs first.
| Verified 2026-09-02: the scheduler service was missing TAMARA_API_TOKEN. It is
| latent only because production currently has zero orders to reconcile.
|
*/

// Warn staff before a Tamara authorisation lapses. Hourly, because the warning
// window is measured in hours and a daily run could miss it entirely.
// `withoutOverlapping` guards a run that outlives its slot; `onOneServer` is
// deliberately NOT used — it needs a shared lock store and there is exactly one
// scheduler container.
Schedule::command('payments:alert-expiring')
    ->hourly()
    ->withoutOverlapping();

// Time-boxed products (store-event offers) leave the storefront at their
// `available_until`. Every minute because the promise to the client is "gone at
// midnight", not "gone within the hour"; the query is one indexed-cheap SELECT.
Schedule::command('catalog:hide-expired')
    ->everyMinute()
    ->withoutOverlapping();

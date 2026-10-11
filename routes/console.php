<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Retire lapsed job postings.
 *
 * Hourly rather than daily: a request's deadline is a time of day, not a date,
 * and a job that lapsed at nine in the morning should stop being quotable then
 * — not at midnight. `withoutOverlapping` keeps a long sweep from stacking up
 * behind itself on a busy instance.
 */
Schedule::command('service-requests:expire')
    ->hourly()
    ->withoutOverlapping();

/*
 * Retire lapsed price offers.
 *
 * The same reasoning as the request sweep above, and the same cadence: a
 * quotation's deadline is a time of day, and an offer that lapsed this morning
 * should stop looking takeable now rather than at midnight.
 */
Schedule::command('quotations:expire')
    ->hourly()
    ->withoutOverlapping();

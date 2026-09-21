<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Message attachment files that no row points at.
 *
 * Nightly and unattended, because the two ways they arise are both silent: a
 * send that died between writing the files and committing the rows, and a
 * database-level cascade (users -> messages -> message_attachments), which
 * fires no Eloquent events, so nothing in the application ever hears that the
 * rows went away. Without this on a timer the files accumulate with nothing
 * left to find them by.
 *
 * The command's own --hours default spares anything recent, so a send in
 * flight while this runs is never touched.
 */
Schedule::command('messages:prune-orphan-attachments')
    ->dailyAt('03:30')
    ->withoutOverlapping();

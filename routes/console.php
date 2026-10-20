<?php

use App\Console\Commands\RunMessageJobs;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| The Plesk cron calls `php artisan schedule:run` every five minutes
| (a cron entry like: "every 5 minutes, cd /var/www/vision, php artisan
| schedule:run"). The scheduled command expires the message tasks whose send
| window has passed.
|
*/

Schedule::command(RunMessageJobs::class)->everyFiveMinutes()->withoutOverlapping();

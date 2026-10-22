<?php

use Illuminate\Support\Facades\Schedule;

// Jalankan lewat cron: * * * * * php artisan schedule:run (lihat docs/DEPLOY-aapanel.md)
Schedule::command('madingo:cleanup-attachments')->dailyAt('02:30');
Schedule::command('madingo:cleanup-guests')->dailyAt('02:45');

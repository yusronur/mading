<?php

use Illuminate\Support\Facades\Schedule;

// Jalankan lewat cron: * * * * * php artisan schedule:run (lihat docs/DEPLOY-aapanel.md)
Schedule::command('madingo:cleanup-attachments')->dailyAt('02:30');
Schedule::command('madingo:cleanup-guests')->dailyAt('02:45');

// Notifikasi (Tahap 19): jam mengikuti config/notify.php dan zona waktu aplikasi
Schedule::command('madingo:notify closed')->everyFiveMinutes();
Schedule::command('madingo:notify release')->dailyAt('06:00');
Schedule::command('madingo:notify due')->dailyAt(sprintf('%02d:00', config('notify.reminder_hour')));
Schedule::command('madingo:notify digest')->dailyAt(sprintf('%02d:00', config('notify.digest_hour')));
Schedule::command('madingo:notify prune')->dailyAt('03:15');

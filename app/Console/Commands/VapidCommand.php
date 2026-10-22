<?php

namespace App\Console\Commands;

use App\Modules\Notify\WebPush;
use Illuminate\Console\Command;

class VapidCommand extends Command
{
    protected $signature = 'madingo:vapid';

    protected $description = 'Buat kunci VAPID untuk Web Push (tempel ke .env)';

    public function handle(): int
    {
        if (config('notify.vapid.public') && config('notify.vapid.private')) {
            $this->warn('Kunci VAPID sudah ada di .env. Mengganti kunci akan memutus semua langganan push yang ada.');
            if (! $this->confirm('Tetap buat kunci baru?')) {
                return self::SUCCESS;
            }
        }
        $k = WebPush::generateKeys();
        $this->line('Tambahkan ke .env lalu jalankan php artisan config:cache');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$k['public']);
        $this->line('VAPID_PRIVATE_KEY='.$k['private']);
        $this->line('VAPID_SUBJECT=mailto:smpn1wajak@gmail.com');

        return self::SUCCESS;
    }
}

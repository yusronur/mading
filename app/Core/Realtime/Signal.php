<?php

namespace App\Core\Realtime;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Kirim siaran dengan pemutus sirkuit: bila Reverb tidak terjangkau, siaran
 * dilewati 30 detik agar permintaan pengguna tidak ikut menunggu. Klien selalu
 * punya pengecekan berkala sebagai cadangan.
 */
class Signal
{
    public static function send(object $event): void
    {
        if (config('broadcasting.default') !== 'reverb' || Cache::get('madingo:realtime-down')) {
            return;
        }

        try {
            broadcast($event);
        } catch (Throwable $e) {
            Cache::put('madingo:realtime-down', true, 30);
            report($e);
        }
    }
}

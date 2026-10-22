<?php

namespace App\Modules\Board;

/**
 * Posisi pecahan untuk urutan manual: memindah satu kartu hanya mengubah
 * satu baris (nilai tengah antara dua tetangga).
 */
class Positions
{
    public static function between(?float $before, ?float $after): float
    {
        return match (true) {
            $before === null && $after === null => 0.0,
            $before === null => $after - 1,
            $after === null => $before + 1,
            default => ($before + $after) / 2,
        };
    }
}

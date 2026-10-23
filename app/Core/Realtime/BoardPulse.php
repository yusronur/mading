<?php

namespace App\Core\Realtime;

use App\Events\BoardUpdated;
use App\Core\Tenancy\TenantContext;
use App\Models\Board;
use App\Models\School;
use App\Modules\Board\BoardPresenter;
use Throwable;

/**
 * Tahap 20: sinyal realtime "isi papan berubah". Semua perubahan kartu, kolom,
 * dan pengaturan dalam satu permintaan digabung menjadi SATU siaran per papan
 * yang dikirim setelah respons (bukan per baris), lengkap dengan penanda versi
 * supaya klien yang sudah mutakhir tidak memuat ulang.
 */
class BoardPulse
{
    /** @var array<string, string> boardId => schoolId */
    private static array $pending = [];

    private static bool $hooked = false;

    public static function mark(?string $schoolId, ?string $boardId): void
    {
        if (! $schoolId || ! $boardId || config('broadcasting.default') !== 'reverb') {
            return;
        }
        self::$pending[$boardId] = $schoolId;

        // Pekerja antrean/perintah berjalan lama tidak punya akhir permintaan: kirim langsung
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            self::flush();

            return;
        }
        if (! self::$hooked) {
            self::$hooked = true;
            app()->terminating(fn () => self::flush());
        }
    }

    public static function flush(): void
    {
        $pending = self::$pending;
        self::$pending = [];
        self::$hooked = false;

        foreach ($pending as $boardId => $schoolId) {
            $version = null;
            try {
                // Setelah respons, konteks sekolah (RLS) bisa sudah dilepas: jalankan ulang di sekolahnya
                $school = School::find($schoolId);
                $version = $school ? app(TenantContext::class)->run($school, function () use ($boardId) {
                    $board = Board::find($boardId);

                    return $board ? app(BoardPresenter::class)->version($board) : null;
                }) : null;
            } catch (Throwable) {
                // tanpa versi pun klien tetap memuat ulang
            }
            Signal::send(new BoardUpdated($schoolId, $boardId, 'posts', $version));
        }
    }

    /** Untuk tes. */
    public static function pending(): array
    {
        return self::$pending;
    }
}

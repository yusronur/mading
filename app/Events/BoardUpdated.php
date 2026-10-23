<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Sinyal "ada perubahan" pada satu papan: kind=activity (aktivitas live) atau posts (kartu/kolom/pengaturan, Tahap 20).
 * Isinya hanya jenis perubahan; klien mengambil data lewat HTTP yang sudah diotorisasi.
 */
class BoardUpdated implements ShouldBroadcastNow
{
    public function __construct(public string $schoolId, public string $boardId, public string $kind, public ?string $version = null) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::channel($this->schoolId, $this->boardId));
    }

    public static function channel(string $schoolId, string $boardId): string
    {
        return "school.{$schoolId}.board.{$boardId}";
    }

    /** Hanya pengguna sekolah yang sama dan boleh melihat papan (dipakai routes/channels.php). */
    public static function authorize(\App\Models\User $user, string $schoolId, string $boardId): bool
    {
        if ($user->school_id !== $schoolId) {
            return false;
        }
        $board = \App\Models\Board::find($boardId);

        return $board !== null && $user->can('view', $board);
    }

    public function broadcastAs(): string
    {
        return 'board.updated';
    }

    public function broadcastWith(): array
    {
        return array_filter(['kind' => $this->kind, 'v' => $this->version]);
    }
}

<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Sinyal "ada perubahan" pada satu papan (aktivitas live sekarang, kartu di tahap 20).
 * Isinya hanya jenis perubahan; klien mengambil data lewat HTTP yang sudah diotorisasi.
 */
class BoardUpdated implements ShouldBroadcastNow
{
    public function __construct(public string $schoolId, public string $boardId, public string $kind) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::channel($this->schoolId, $this->boardId));
    }

    public static function channel(string $schoolId, string $boardId): string
    {
        return "school.{$schoolId}.board.{$boardId}";
    }

    public function broadcastAs(): string
    {
        return 'board.updated';
    }

    public function broadcastWith(): array
    {
        return ['kind' => $this->kind];
    }
}

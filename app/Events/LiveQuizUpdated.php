<?php

namespace App\Events;

use App\Models\QuizSession;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Sinyal "ada perubahan" pada kuis live. Isinya sengaja minim (tanpa soal atau
 * kunci); klien mengambil status lengkap lewat HTTP yang sudah diotorisasi.
 *
 * kind: state (fase berubah) | players (pemain bergabung) | progress (jumlah yang sudah menjawab)
 */
class LiveQuizUpdated implements ShouldBroadcastNow
{
    public function __construct(public QuizSession $session, public string $kind, public array $extra = []) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::channel($this->session));
    }

    public static function channel(QuizSession $s): string
    {
        return "school.{$s->school_id}.live.{$s->id}";
    }

    public function broadcastAs(): string
    {
        return 'live.updated';
    }

    public function broadcastWith(): array
    {
        return ['kind' => $this->kind, 'v' => $this->session->live['version'] ?? 0] + $this->extra;
    }
}

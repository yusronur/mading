<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Sinyal "ada notifikasi baru" untuk satu pengguna. Tanpa isi; lonceng mengambil datanya lewat HTTP. */
class NotificationsChanged implements ShouldBroadcastNow
{
    public function __construct(public string $schoolId, public string $userId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::channel($this->schoolId, $this->userId));
    }

    public static function channel(string $schoolId, string $userId): string
    {
        return "school.{$schoolId}.user.{$userId}";
    }

    /** Hanya pemilik notifikasi yang boleh mendengarkan kanalnya. */
    public static function authorize(\App\Models\User $user, string $schoolId, string $userId): bool
    {
        return $user->school_id === $schoolId && $user->id === $userId;
    }

    public function broadcastAs(): string
    {
        return 'notify.changed';
    }

    public function broadcastWith(): array
    {
        return ['kind' => 'notify'];
    }
}

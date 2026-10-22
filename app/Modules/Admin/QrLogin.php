<?php

namespace App\Modules\Admin;

use App\Models\User;
use Illuminate\Support\Str;

/** Token login QR siswa (AUTH-03). Satu token aktif per siswa. */
class QrLogin
{
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function issue(User $user): string
    {
        $token = Str::random(40);

        $user->forceFill([
            'qr_token' => $token,
            'qr_token_hash' => self::hash($token),
            'qr_token_created_at' => now(),
        ])->save();

        return $token;
    }

    public function revoke(User $user): void
    {
        $user->forceFill(['qr_token' => null, 'qr_token_hash' => null, 'qr_token_created_at' => null])->save();
    }

    public function find(string $token): ?User
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }

        return User::where('qr_token_hash', self::hash($token))
            ->where('role', User::ROLE_STUDENT)
            ->where('status', 'active')
            ->first();
    }

    public static function url(string $token): string
    {
        return route('login.qr', $token);
    }
}

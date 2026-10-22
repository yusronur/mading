<?php

namespace App\Modules\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Sandi awal (wajib diganti saat login pertama).
 *
 * Sandi awal disimpan terenkripsi di kolom `initial_password` selama
 * `must_change_password` masih true, supaya kartu akun bisa dicetak ulang.
 * PasswordController mengosongkannya begitu pengguna mengganti sandi.
 */
class InitialPasswords
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function generate(int $length = 6): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }

    public function lengthFor(User $user): int
    {
        return $user->isStudent() ? 6 : 8;
    }

    /**
     * Hash dengan biaya bcrypt lebih ringan agar ratusan akun tetap cepat.
     * Di-hash ulang dengan biaya standar saat pengguna mengganti sandi.
     */
    public function hash(string $password): string
    {
        $rounds = min(8, (int) config('hashing.bcrypt.rounds', 12));

        return Hash::driver('bcrypt')->make($password, ['rounds' => $rounds]);
    }

    /** Buat sandi awal baru untuk satu pengguna dan kembalikan teks aslinya. */
    public function issue(User $user): string
    {
        $password = $this->generate($this->lengthFor($user));

        $user->forceFill([
            'password' => $this->hash($password),
            'initial_password' => $password,
            'must_change_password' => true,
            'remember_token' => null,
        ])->save();

        return $password;
    }

    /**
     * @param  iterable<User>  $users
     */
    public function issueMany(iterable $users): int
    {
        @set_time_limit(600);
        $count = 0;

        foreach ($users as $user) {
            $this->issue($user);
            $count++;
        }

        return $count;
    }

    /** Dipanggil saat pengguna berhasil mengganti sandi sendiri. */
    public static function forget(User $user): void
    {
        $user->forceFill(['initial_password' => null]);
    }
}

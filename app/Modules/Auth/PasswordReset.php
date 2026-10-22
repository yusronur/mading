<?php

namespace App\Modules\Auth;

use App\Mail\ResetPasswordMail;
use App\Models\AuditLog;
use App\Models\SchoolSetting;
use App\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Reset sandi lewat email. Token acak 48 karakter, hanya hash-nya yang disimpan,
 * berlaku 60 menit dan sekali pakai. Pemakai tidak pernah diberi tahu apakah
 * akun/email ada (tidak membocorkan daftar akun).
 */
class PasswordReset
{
    public const MINUTES = 60;

    /** Maksimal permintaan per akun per jam (melindungi kotak masuk pemilik akun dari banjir email). */
    public const PER_HOUR = 3;

    public static function mailConfigured(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array', null], true);
    }

    public function request(string $username, Request $request): void
    {
        $user = User::query()
            ->whereRaw('lower(username) = ?', [mb_strtolower(trim($username))])
            ->where('status', 'active')
            ->whereIn('role', [User::ROLE_STUDENT, User::ROLE_TEACHER, User::ROLE_ADMIN])
            ->whereNotNull('email')->where('email', '!=', '')
            ->first();

        if (! $user) {
            return;
        }

        $recent = DB::table('password_resets')->where('user_id', $user->id)->where('created_at', '>=', now()->subHour())->count();
        if ($recent >= self::PER_HOUR) {
            return;
        }

        $token = Str::random(48);
        DB::table('password_resets')->insert([
            'id' => (string) Str::uuid7(),
            'school_id' => $user->school_id,
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::MINUTES),
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);

        $school = app(TenantContext::class)->school();
        try {
            Mail::to($user->email)->send(new ResetPasswordMail(
                name: $user->name,
                username: $user->username,
                url: route('password.reset', $token),
                appName: SchoolSetting::first()?->app_display_name ?? 'Mading',
                schoolName: $school?->name ?? 'Sekolah',
                minutes: self::MINUTES,
            ));
            AuditLog::create(['actor_id' => null, 'action' => 'auth.reset_requested', 'target_type' => 'User', 'target_id' => $user->id, 'meta' => ['username' => $user->username], 'ip' => $request->ip()]);
        } catch (\Throwable $e) {
            report($e); // SMTP bermasalah: dicatat di log, pengguna tetap melihat pesan umum
        }
    }

    public function find(string $token): ?object
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            return null;
        }

        return DB::table('password_resets')->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')->where('expires_at', '>', now())->first();
    }

    /** @return bool false bila token tidak valid lagi */
    public function complete(string $token, string $password): bool
    {
        return DB::transaction(function () use ($token, $password) {
            $row = $this->find($token);
            if (! $row) {
                return false;
            }
            // Tandai dipakai lebih dulu: dua klik bersamaan tidak lolos keduanya
            $claimed = DB::table('password_resets')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now()]);
            if (! $claimed) {
                return false;
            }

            $user = User::where('status', 'active')->find($row->user_id);
            if (! $user) {
                return false;
            }

            $user->forceFill([
                'password' => $password,
                'must_change_password' => false,
                'initial_password' => null,
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete(); // keluarkan semua perangkat
            DB::table('password_resets')->where('user_id', $user->id)->delete();
            AuditLog::create(['actor_id' => null, 'action' => 'auth.password_reset_done', 'target_type' => 'User', 'target_id' => $user->id, 'meta' => ['username' => $user->username], 'ip' => request()->ip()]);

            return true;
        });
    }
}

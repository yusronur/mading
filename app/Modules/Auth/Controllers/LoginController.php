<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', ['reset_enabled' => \App\Modules\Auth\PasswordReset::mailConfigured()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string', 'max:100'],
            'remember' => ['boolean'],
        ], [], ['username' => 'NISN / username', 'password' => 'kata sandi']);

        $username = trim($data['username']);
        $key = 'login:'.Str::lower($username).'|'.$request->ip();
        $max = (int) config('madingo.auth.max_attempts', 5);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'username' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
            ]);
        }

        Auth::guard('web')->setRememberDuration((int) config('madingo.auth.remember_days', 30) * 1440);

        $ok = Auth::attempt(
            ['username' => $username, 'password' => $data['password'], 'status' => 'active'],
            (bool) ($data['remember'] ?? false)
        );

        if (! $ok) {
            RateLimiter::hit($key, (int) config('madingo.auth.decay_seconds', 60));

            if (RateLimiter::tooManyAttempts($key, $max)) {
                AuditLog::create([
                    'action' => 'auth.lockout',
                    'meta' => ['username' => $username],
                    'ip' => $request->ip(),
                ]);
            }

            throw ValidationException::withMessages([
                'username' => 'NISN/username atau kata sandi salah.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        if ($user->must_change_password) {
            return redirect()->route('password.change');
        }

        return redirect()->intended(route($user->dashboardRoute()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

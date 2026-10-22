<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Admin\QrLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Masuk dengan memindai kartu QR (AUTH-03). Siswa tidak perlu mengetik
 * NISN dan sandi; sesi diingat di HP selama masa "ingat saya" (AUTH-06).
 */
class QrLoginController extends Controller
{
    public function __invoke(Request $request, string $token, QrLogin $qr): RedirectResponse
    {
        $user = $qr->find($token);

        if (! $user) {
            return redirect()->route('login')->with('error', 'Kode QR tidak berlaku. Mungkin sudah diganti; minta kartu baru ke wali kelas.');
        }

        if (Auth::check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }

        Auth::guard('web')->setRememberDuration((int) config('madingo.auth.remember_days', 30) * 1440);
        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->put('login_via_qr', true);

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        AuditLog::record('auth.qr_login', $user, ['username' => $user->username]);

        return redirect()->route($user->dashboardRoute());
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Akun yang dinonaktifkan admin langsung keluar dari sesi yang sedang berjalan. */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status !== 'active') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->with('error', 'Akun Anda tidak aktif. Hubungi admin sekolah.');
        }

        // Penanda "sedang online" untuk notifikasi (cukup ditulis tiap ~45 detik)
        if ($user && $request->hasSession() && now()->timestamp - (int) $request->session()->get('_seen', 0) > 45) {
            $request->session()->put('_seen', now()->timestamp);
            \App\Modules\Notify\Notify::markOnline($user->id);
        }

        return $next($request);
    }
}

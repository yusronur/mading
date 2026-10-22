<?php

namespace App\Http\Middleware;

use App\Models\QuizSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tamu kuis hanya boleh memakai halaman mengerjakan kuis. Semua rute lain
 * (papan, profil, admin, bank soal, ...) ditolak, walau tamu menebak alamatnya.
 */
class ConfineGuests
{
    private const ALLOWED = [
        'take.show', 'take.start', 'take.attempt', 'take.answer', 'take.focus', 'take.submit', 'take.result',
        'attachments.show', 'login.qr', 'live.play', 'live.state', 'live.answer', 'home', 'logout', 'guest.join', 'guest.store',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isGuest() && ! $request->routeIs(self::ALLOWED) && ! $request->is('broadcasting/auth')) {
            abort_if($request->expectsJson(), 403, 'Tamu hanya bisa mengerjakan kuis.');

            return redirect()->route('home');
        }

        return $next($request);
    }
}

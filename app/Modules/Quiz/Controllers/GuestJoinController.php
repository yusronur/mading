<?php

namespace App\Modules\Quiz\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\QuizSession;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Masuk sebagai tamu ke kuis publik dengan kode + nama (seperti Kahoot).
 *
 * Keamanan: tamu hanya terbuat bila guru mencentang "izinkan tamu" pada tugas
 * publik. Akunnya sementara (peran `guest`, sandi acak yang tidak diketahui
 * siapa pun), terkunci ke SATU kuis, dan oleh middleware ConfineGuests hanya
 * boleh membuka halaman mengerjakan kuis itu. Jumlah tamu per kuis dibatasi.
 */
class GuestJoinController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $code = strtoupper((string) $request->query('kode', ''));

        // Siswa/guru yang sudah masuk memakai jalur kode biasa
        if ($user && ! $user->isGuest()) {
            return preg_match('/^[A-Z0-9]{6}$/', $code) ? redirect()->route('boards.join-link', $code) : redirect()->route('home');
        }

        return Inertia::render('Auth/GuestJoin', ['code' => preg_match('/^[A-Z0-9]{6}$/', $code) ? $code : '']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:6', 'regex:/^[A-Za-z0-9]+$/'],
            'name' => ['required', 'string', 'min:2', 'max:40'],
        ], ['name.min' => 'Nama minimal 2 huruf.', 'name.max' => 'Nama maksimal 40 huruf.'], ['code' => 'kode kuis', 'name' => 'nama']);

        $name = $this->cleanName($data['name']);
        if (mb_strlen($name) < 2) {
            return back()->withErrors(['name' => 'Tulis namamu dengan huruf.'])->withInput();
        }

        $session = QuizSession::where('code', strtoupper($data['code']))->whereNull('class_id')->first();
        if (! $session || ! ($session->settings['allow_guests'] ?? false) || $session->isClosed()) {
            return back()->withErrors(['code' => 'Kode tidak ditemukan, kuis sudah ditutup, atau kuis ini tidak menerima tamu.'])->withInput();
        }

        $current = $request->user();
        if ($current?->isGuest()) {
            if (($current->profile['guest_session'] ?? null) === $session->id) {
                return redirect()->route('take.show', $session); // lanjutkan, tidak membuat tamu baru
            }
            $this->logout($request);
        }

        $max = (int) config('madingo.quiz.max_guests', 300);
        $taken = User::where('role', User::ROLE_GUEST)->whereRaw("profile->>'guest_session' = ?", [$session->id]);
        if ((clone $taken)->count() >= $max) {
            return back()->withErrors(['code' => 'Kuis ini sudah penuh. Hubungi gurumu.'])->withInput();
        }

        // Nama kembar pada kuis yang sama diberi nomor agar guru bisa membedakan
        $same = (clone $taken)->whereRaw('lower(name) = ? or lower(name) like ?', [mb_strtolower($name), mb_strtolower($name).' (%)'])->count();
        $final = $same ? $name.' ('.($same + 1).')' : $name;

        $guest = DB::transaction(fn () => User::create([
            'username' => 'tamu-'.Str::lower(Str::random(10)),
            'name' => $final,
            'password' => Str::random(48), // tidak pernah dibagikan: tamu tidak bisa masuk lewat formulir sandi
            'role' => User::ROLE_GUEST,
            'status' => 'active',
            'must_change_password' => false,
            'profile' => ['guest_session' => $session->id],
        ]));

        Auth::guard('web')->setRememberDuration(1440); // 1 hari: muat ulang/tutup tab tidak kehilangan pekerjaan
        Auth::login($guest, true);
        $request->session()->regenerate();
        AuditLog::record('quiz.guest_joined', $guest, ['session' => $session->title]);

        return redirect()->route('take.show', $session);
    }

    private function cleanName(string $name): string
    {
        $name = strip_tags($name);
        $name = preg_replace('/[\p{C}<>]+/u', '', $name) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    }

    private function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}

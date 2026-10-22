<?php

namespace App\Modules\Notify\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Models\UserPreference;
use App\Modules\Notify\Notify;
use App\Modules\Notify\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Lonceng, halaman notifikasi, dan pengaturan push per pengguna. */
class NotificationController extends Controller
{
    public function __construct(private Notify $notify, private WebPush $push) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $off = UserPreference::where('user_id', $user->id)->value('notify_off') ?? [];

        return Inertia::render('Notify/Index', [
            'items' => $this->notify->recent($user, 30),
            'unread' => $this->notify->unread($user),
            'push' => [
                'available' => $this->notify->pushAvailable(),
                'key' => $this->push->publicKey(),
                'devices' => PushSubscription::where('user_id', $user->id)->count(),
                'kinds' => collect(Notify::togglableFor($user))->map(fn ($k) => $k + ['on' => ! in_array($k['key'], $off, true)])->all(),
                'daily_cap' => config('notify.daily_cap'),
                'quiet' => sprintf('%02d.00–%02d.00', config('notify.quiet_from'), config('notify.quiet_until')),
            ],
        ]);
    }

    /** Ringkasan untuk lonceng; juga dipakai "muat lebih banyak". */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['before' => ['nullable', 'date'], 'limit' => ['nullable', 'integer', 'between:1,50']]);

        return response()->json([
            'unread' => $this->notify->unread($user),
            'items' => $this->notify->recent($user, $data['limit'] ?? 12, $data['before'] ?? null),
        ])->header('Cache-Control', 'no-store');
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $this->notify->markRead($request->user(), $id);

        return response()->json(['unread' => $this->notify->unread($request->user())]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->notify->markRead($request->user());

        return response()->json(['unread' => 0]);
    }

    /** Jenis yang dimatikan pengguna (hanya jenis yang memang bisa diatur untuk perannya). */
    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowed = array_column(Notify::togglableFor($user), 'key');
        $data = $request->validate(['off' => ['present', 'array'], 'off.*' => ['string']]);
        $off = array_values(array_intersect($data['off'], $allowed));

        UserPreference::updateOrCreate(['user_id' => $user->id], ['notify_off' => $off]);

        return response()->json(['off' => $off]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        abort_unless($this->notify->pushAvailable(), 404);
        $d = $request->validate([
            'endpoint' => ['required', 'string', 'max:700'],
            'keys.p256dh' => ['required', 'string', 'max:100'],
            'keys.auth' => ['required', 'string', 'max:40'],
        ]);
        abort_unless(WebPush::allowedEndpoint($d['endpoint']), 422, 'Layanan push peramban ini tidak didukung.');
        abort_unless(strlen(WebPush::unb64($d['keys']['p256dh'])) === 65 && strlen(WebPush::unb64($d['keys']['auth'])) >= 16, 422, 'Kunci langganan tidak valid.');

        // Satu perangkat satu pemilik: bila HP dipakai bergantian, langganan pindah ke akun yang sedang masuk
        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $d['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $d['endpoint'],
                'p256dh' => $d['keys']['p256dh'],
                'auth' => $d['keys']['auth'],
                'agent' => mb_substr((string) $request->userAgent(), 0, 160),
            ],
        );

        return response()->json(['devices' => PushSubscription::where('user_id', $request->user()->id)->count()]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $d = $request->validate(['endpoint' => ['required', 'string', 'max:700']]);
        PushSubscription::where('user_id', $request->user()->id)->where('endpoint_hash', hash('sha256', $d['endpoint']))->delete();

        return response()->json(['devices' => PushSubscription::where('user_id', $request->user()->id)->count()]);
    }

    /** Tombol "Kirim notifikasi uji" untuk memastikan perangkat ini menerima push. */
    public function test(Request $request): JsonResponse
    {
        abort_unless($this->notify->pushAvailable(), 404);
        $n = $this->notify->send($request->user(), 'manual', 'Notifikasi uji', 'Kalau pesan ini muncul, push di perangkatmu sudah aktif. 🎉', route('notifications.index', [], false), null, true);

        return response()->json(['pushed' => $n?->push_state === 'sent']);
    }
}

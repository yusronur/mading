<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppNotification;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PushBroadcast;
use App\Models\PushSubscription;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizSession;
use App\Models\SchoolClass;
use App\Models\User;
use App\Models\Board;
use App\Modules\Notify\Notify;
use App\Modules\Notify\Triggers;
use App\Modules\Notify\WebPush;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tahap 19: lonceng, push anti-banjir, pesan manual admin, dan pemicu. */
class NotifyTest extends TestCase
{
    private object $school;

    private User $admin;

    private User $teacher;

    private User $ani;

    private User $budi;

    private User $outsider;

    private SchoolClass $class;

    private Quiz $quiz;

    protected function setUp(): void
    {
        parent::setUp();
        $keys = WebPush::generateKeys();
        config(['notify.vapid.public' => $keys['public'], 'notify.vapid.private' => $keys['private'], 'notify.vapid.subject' => 'mailto:a@b.test',
            'notify.push_hosts' => ['push.test'], 'notify.quiet_from' => 21, 'notify.quiet_until' => 6, 'notify.daily_cap' => 5]);
        $this->travelTo(now()->setTime(10, 0));
        Http::fake(['push.test/*' => Http::response('', 201)]);

        $this->school = $this->makeSchool();
        $this->admin = $this->inSchool($this->school, fn () => User::where('role', User::ROLE_ADMIN)->firstOrFail());
        $this->teacher = $this->makeUser($this->school, User::ROLE_TEACHER, ['name' => 'Bu Guru']);
        $this->ani = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Ani']);
        $this->budi = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Budi']);
        $this->outsider = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Luar']);

        $this->inSchool($this->school, function () {
            $this->class = SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '7A', 'grade' => 7]);
            foreach ([$this->ani, $this->budi] as $u) {
                DB::table('class_members')->insert(['class_id' => $this->class->id, 'user_id' => $u->id, 'school_id' => $u->school_id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $bank = QuestionBank::create(['owner_id' => $this->teacher->id, 'name' => 'IPA']);
            $bank->questions()->create(['author_id' => $this->teacher->id, 'type' => 'tf', 'prompt' => '<p>Benar?</p>', 'data' => ['answer' => true], 'points' => 1]);
        });
        $this->actingAs($this->teacher)->post(route('quizzes.store'), ['title' => 'Ulangan Bab 1'])->assertRedirect();
        $this->quiz = $this->inSchool($this->school, fn () => Quiz::firstOrFail());
        $q = $this->inSchool($this->school, fn () => \App\Models\Question::firstOrFail());
        $this->actingAs($this->teacher)->put(route('quizzes.update', $this->quiz), [
            'title' => 'Ulangan Bab 1',
            'settings' => ['shuffle_questions' => false, 'shuffle_options' => false, 'duration_minutes' => 30, 'attempts' => 1, 'scoring' => 'best', 'review' => 'after_submit', 'show_score' => true, 'kkm' => 75],
            'questions' => [['id' => $q->id, 'points' => null]],
        ])->assertSessionHasNoErrors();
    }

    private function subscribe(User $u, string $host = 'a'): PushSubscription
    {
        $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($ua)['ec'];
        $pub = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        return $this->inSchool($this->school, fn () => PushSubscription::create([
            'user_id' => $u->id, 'endpoint' => "https://push.test/{$u->id}/$host", 'endpoint_hash' => hash('sha256', "https://push.test/{$u->id}/$host"),
            'p256dh' => WebPush::b64($pub), 'auth' => WebPush::b64(random_bytes(16)),
        ]));
    }

    private function notify(): Notify
    {
        return app(Notify::class);
    }

    private function fakePush(int $status = 201): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['push.test/*' => Http::response('', $status)]);
    }

    private function pushCount(): int
    {
        return Http::recorded()->count();
    }

    private function inbox(User $u)
    {
        return $this->inSchool($this->school, fn () => AppNotification::where('user_id', $u->id)->orderBy('created_at')->get());
    }

    private function assign(array $extra = []): QuizSession
    {
        $this->actingAs($this->teacher)->post(route('assignments.store', $this->quiz), $extra + [
            'class_ids' => [$this->class->id], 'duration_minutes' => 30, 'closes_at' => now()->addDay()->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();

        return $this->inSchool($this->school, fn () => QuizSession::latest()->firstOrFail());
    }

    public function test_push_hosts_allowlist(): void
    {
        config(['notify.push_hosts' => ['fcm.googleapis.com', '*.push.apple.com']]);
        $this->assertTrue(WebPush::allowedEndpoint('https://fcm.googleapis.com/fcm/send/x'));
        $this->assertTrue(WebPush::allowedEndpoint('https://web.push.apple.com/abc'));
        foreach (['http://fcm.googleapis.com/x', 'https://evil.example/x', 'https://127.0.0.1/x', 'https://fcm.googleapis.com.evil.test/x', 'https://u@fcm.googleapis.com/x', 'https://fcm.googleapis.com:8443/x'] as $bad) {
            $this->assertFalse(WebPush::allowedEndpoint($bad), $bad);
        }
    }

    public function test_assigning_a_quiz_notifies_class_students_only_and_pushes(): void
    {
        $this->subscribe($this->ani);
        $s = $this->assign();

        $this->assertCount(1, $this->inbox($this->ani));
        $n = $this->inbox($this->ani)[0];
        $this->assertSame('quiz_assigned', $n->kind);
        $this->assertStringContainsString('Ulangan Bab 1', $n->title);
        $this->assertSame('sent', $n->push_state);
        $this->assertCount(1, $this->inbox($this->budi));
        $this->assertSame('off', $this->inbox($this->budi)[0]->push_state, 'tanpa langganan: hanya lonceng');
        $this->assertCount(0, $this->inbox($this->outsider));
        $this->assertCount(0, $this->inbox($this->teacher));
        $this->assertSame(1, $this->pushCount());

        // Tugas yang sama tidak diberi tahu dua kali
        app(Triggers::class)->quizAssigned([$s]);
        $this->assertCount(1, $this->inbox($this->ani));
    }

    public function test_two_assignments_at_once_become_one_message(): void
    {
        $other = $this->inSchool($this->school, fn () => SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '7B', 'grade' => 7]));
        $this->inSchool($this->school, fn () => DB::table('class_members')->insert(['class_id' => $other->id, 'user_id' => $this->ani->id, 'school_id' => $this->school->id, 'created_at' => now(), 'updated_at' => now()]));
        $this->actingAs($this->teacher)->post(route('assignments.store', $this->quiz), [
            'class_ids' => [$this->class->id, $other->id], 'duration_minutes' => 30, 'closes_at' => now()->addDay()->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();

        $mine = $this->inbox($this->ani);
        $this->assertCount(1, $mine);
        $this->assertSame('2 kuis baru untukmu', $mine[0]->title);
    }

    public function test_daily_cap_quiet_hours_and_release(): void
    {
        $this->subscribe($this->ani);
        $n = $this->notify();
        $u = $this->ani;

        $this->inSchool($this->school, function () use ($n, $u) {
            for ($i = 1; $i <= 6; $i++) {
                $n->send($u, 'grade_ready', "Nilai $i", null, '/', "k$i");
            }
        });
        $states = $this->inbox($this->ani)->pluck('push_state')->all();
        $this->assertSame(['sent', 'sent', 'sent', 'sent', 'sent', 'capped'], $states, 'maksimal 5 push per hari');
        $this->assertSame(5, $this->pushCount());

        // Pesan manual admin tidak terkena batas harian
        $this->inSchool($this->school, fn () => $n->send($u, 'manual', 'Penting', null, '/', null, true));
        $this->assertSame(6, $this->pushCount());

        // Jam tenang: ditahan lalu dilepas pagi sebagai satu ringkasan
        $this->travelTo(now()->addDay()->setTime(22, 30));
        $this->fakePush();
        $this->inSchool($this->school, function () use ($n, $u) {
            $n->send($u, 'grade_ready', 'Malam 1', null, '/', 'm1');
            $n->send($u, 'quiz_assigned', 'Malam 2', null, '/', 'm2');
        });
        $this->assertSame(0, $this->pushCount());
        $this->assertSame(['held', 'held'], $this->inbox($this->ani)->slice(-2)->pluck('push_state')->values()->all());

        $this->inSchool($this->school, fn () => $this->assertSame(0, $n->releaseHeld(), 'masih jam tenang'));
        $this->travelTo(now()->addDay()->setTime(6, 5));
        $this->inSchool($this->school, fn () => $this->assertSame(1, $n->releaseHeld()));
        $this->assertSame(1, $this->pushCount(), 'dua yang ditahan keluar sebagai satu ringkasan');
    }

    public function test_held_notifications_are_released_in_the_morning_as_one_push(): void
    {
        $this->subscribe($this->ani);
        $n = $this->notify();
        $u = $this->ani;
        $this->travelTo(now()->setTime(22, 0));
        $this->inSchool($this->school, function () use ($n, $u) {
            $n->send($u, 'grade_ready', 'Malam 1', null, '/', 'm1');
            $n->send($u, 'quiz_assigned', 'Malam 2', null, '/', 'm2');
        });
        $this->assertSame(0, $this->pushCount());

        $this->travelTo(now()->addDay()->setTime(6, 0));
        $this->inSchool($this->school, fn () => $this->assertSame(1, $n->releaseHeld()));
        $this->assertSame(1, $this->pushCount(), 'dua notifikasi, satu push');
        $this->assertSame(['sent', 'merged'], $this->inbox($this->ani)->pluck('push_state')->all());
        $this->assertSame(1, $this->inSchool($this->school, fn () => $n->sentToday($u)), 'ringkasan dihitung satu push');
    }

    public function test_user_can_turn_kinds_off_and_cannot_touch_fixed_ones(): void
    {
        $this->subscribe($this->ani);
        $this->actingAs($this->ani)->putJson(route('notifications.preferences'), ['off' => ['grade_ready', 'manual', 'digest', 'bogus']])->assertOk()->assertJson(['off' => ['grade_ready']]);
        $n = $this->notify();
        $u = $this->ani;
        $this->inSchool($this->school, function () use ($n, $u) {
            $n->send($u, 'grade_ready', 'Nilai', null, '/', 'x1');
            $n->send($u, 'quiz_assigned', 'Kuis', null, '/', 'x2');
            $n->send($u, 'manual', 'Dari admin', null, '/', null, true);
        });
        $this->assertSame(['off', 'sent', 'sent'], $this->inbox($this->ani)->pluck('push_state')->all());

        $page = $this->actingAs($this->ani)->get(route('notifications.index'))->assertOk();
        $kinds = collect($page->viewData('page')['props']['push']['kinds']);
        $this->assertSame(['quiz_assigned', 'quiz_due', 'grade_ready', 'live_started'], $kinds->pluck('key')->all());
        $this->assertFalse($kinds->firstWhere('key', 'grade_ready')['on']);
    }

    public function test_live_quiz_push_is_skipped_for_students_already_online(): void
    {
        $this->subscribe($this->ani);
        $this->subscribe($this->budi);
        Cache::put("presence:{$this->ani->id}", 1, 120);

        $this->actingAs($this->teacher)->post(route('live.store', $this->quiz), [
            'seconds' => 20, 'teams' => 0, 'allow_guests' => false, 'leaderboard' => true, 'shuffle_questions' => false, 'notify_class_ids' => [$this->class->id],
        ])->assertRedirect();

        $this->assertSame('online', $this->inbox($this->ani)[0]->push_state);
        $this->assertSame('sent', $this->inbox($this->budi)[0]->push_state);
        $this->assertCount(1, $this->inbox($this->ani), 'tetap masuk lonceng');
        $this->assertSame(1, $this->pushCount());

        $this->actingAs($this->teacher)->post(route('live.store', $this->quiz), [
            'seconds' => 20, 'teams' => 0, 'notify_class_ids' => ['not-a-uuid'],
        ])->assertSessionHasErrors('notify_class_ids.0');
    }

    public function test_bell_endpoints_are_private_and_mark_read(): void
    {
        $this->assign();
        $page = $this->actingAs($this->ani)->get(route('student.dashboard'))->assertOk();
        $this->assertSame(1, $page->viewData('page')['props']['notify']['unread']);

        $sum = $this->actingAs($this->ani)->getJson(route('notifications.summary'))->assertOk()->json();
        $this->assertSame(1, $sum['unread']);
        $id = $sum['items'][0]['id'];

        // Orang lain tidak bisa menandai milik Ani
        $this->actingAs($this->budi)->postJson(route('notifications.read', $id))->assertOk();
        $this->assertSame(1, $this->actingAs($this->ani)->getJson(route('notifications.summary'))->json('unread'));

        $this->actingAs($this->ani)->postJson(route('notifications.read', $id))->assertOk()->assertJson(['unread' => 0]);
        $this->actingAs($this->budi)->postJson(route('notifications.read-all'))->assertOk()->assertJson(['unread' => 0]);

        $this->post(route('logout'));
        $this->getJson(route('notifications.summary'))->assertUnauthorized();
    }

    public function test_comment_and_reaction_collapse_into_one_bell_item_without_push(): void
    {
        $this->subscribe($this->ani);
        $board = $this->inSchool($this->school, fn () => Board::create(['owner_id' => $this->teacher->id, 'title' => 'Diskusi', 'visibility' => 'school']));
        $post = $this->inSchool($this->school, fn () => Post::create(['board_id' => $board->id, 'author_id' => $this->ani->id, 'title' => 'Kartu Ani', 'body' => 'isi', 'status' => 'published']));

        foreach (['Bagus', 'Mantap'] as $text) {
            $this->inSchool($this->school, fn () => Comment::create(['post_id' => $post->id, 'author_id' => $this->budi->id, 'body' => $text, 'status' => 'published']));
        }
        $this->inSchool($this->school, fn () => Comment::create(['post_id' => $post->id, 'author_id' => $this->ani->id, 'body' => 'terima kasih', 'status' => 'published']));

        $items = $this->inbox($this->ani)->where('kind', 'comment');
        $this->assertCount(1, $items);
        $this->assertSame(2, $items->first()->count);
        $this->assertStringContainsString('2 komentar baru', $items->first()->title);
        $this->assertSame('none', $items->first()->push_state);
        $this->assertSame(0, $this->pushCount(), 'komentar tidak mengirim push');
    }

    public function test_submission_bell_for_teacher_and_closing_jobs(): void
    {
        $this->subscribe($this->ani);
        $s = $this->assign(['closes_at' => now()->addHours(2)->format('Y-m-d H:i')]);

        $this->actingAs($this->ani)->post(route('take.start', $s))->assertRedirect();
        $a = $this->inSchool($this->school, fn () => QuizAttempt::where('user_id', $this->ani->id)->firstOrFail());
        $this->actingAs($this->ani)->post(route('take.submit', $a))->assertRedirect();

        $bell = $this->inbox($this->teacher)->where('kind', 'submitted');
        $this->assertCount(1, $bell);
        $this->assertSame('none', $bell->first()->push_state);

        // Setelah tenggat: nilai ke yang mengumpulkan, rekap ke guru; dijalankan dua kali tidak menggandakan
        $this->travelTo(now()->addHours(3));
        $this->inSchool($this->school, fn () => app(Triggers::class)->closed());
        $this->inSchool($this->school, fn () => app(Triggers::class)->closed());
        $this->assertSame(1, $this->inbox($this->ani)->where('kind', 'grade_ready')->count());
        $this->assertSame(0, $this->inbox($this->budi)->where('kind', 'grade_ready')->count(), 'yang tidak mengumpulkan tidak dapat nilai');
        $over = $this->inbox($this->teacher)->where('kind', 'overdue');
        $this->assertCount(1, $over);
        $this->assertStringContainsString('1 siswa belum mengumpulkan', $over->first()->title);
    }

    public function test_due_tomorrow_reminder_skips_students_who_already_submitted(): void
    {
        $s = $this->assign(['closes_at' => now()->addDay()->setTime(14, 0)->format('Y-m-d H:i')]);
        $this->actingAs($this->ani)->post(route('take.start', $s))->assertRedirect();
        $a = $this->inSchool($this->school, fn () => QuizAttempt::where('user_id', $this->ani->id)->firstOrFail());
        $this->actingAs($this->ani)->post(route('take.submit', $a))->assertRedirect();

        $this->inSchool($this->school, fn () => $this->assertSame(1, app(Triggers::class)->dueTomorrow()));
        $this->inSchool($this->school, fn () => app(Triggers::class)->dueTomorrow());
        $this->assertSame(0, $this->inbox($this->ani)->where('kind', 'quiz_due')->count());
        $this->assertSame(1, $this->inbox($this->budi)->where('kind', 'quiz_due')->count(), 'sekali sehari walau dijalankan ulang');
    }

    public function test_teacher_digest_once_per_day(): void
    {
        $s = $this->assign();
        $this->actingAs($this->ani)->post(route('take.start', $s))->assertRedirect();
        $a = $this->inSchool($this->school, fn () => QuizAttempt::where('user_id', $this->ani->id)->firstOrFail());
        $this->actingAs($this->ani)->post(route('take.submit', $a))->assertRedirect();

        $this->inSchool($this->school, fn () => app(Triggers::class)->teacherDigest());
        $this->inSchool($this->school, fn () => app(Triggers::class)->teacherDigest());
        $this->assertSame(1, $this->inbox($this->teacher)->where('kind', 'digest')->count());
    }

    public function test_subscription_validation_and_device_handover(): void
    {
        $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($ua)['ec'];
        $keys = ['p256dh' => WebPush::b64("\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)), 'auth' => WebPush::b64(random_bytes(16))];

        $this->actingAs($this->ani)->postJson(route('push.subscribe'), ['endpoint' => 'https://evil.example/x', 'keys' => $keys])->assertStatus(422);
        $this->actingAs($this->ani)->postJson(route('push.subscribe'), ['endpoint' => 'https://push.test/dev1', 'keys' => ['p256dh' => 'abc', 'auth' => 'abc']])->assertStatus(422);

        $this->actingAs($this->ani)->postJson(route('push.subscribe'), ['endpoint' => 'https://push.test/dev1', 'keys' => $keys])->assertOk()->assertJson(['devices' => 1]);
        // HP yang sama dipakai Budi: langganan pindah, Ani tidak lagi menerima di HP itu
        $this->actingAs($this->budi)->postJson(route('push.subscribe'), ['endpoint' => 'https://push.test/dev1', 'keys' => $keys])->assertOk()->assertJson(['devices' => 1]);
        $this->assertSame(0, $this->inSchool($this->school, fn () => PushSubscription::where('user_id', $this->ani->id)->count()));

        $this->actingAs($this->budi)->deleteJson(route('push.unsubscribe'), ['endpoint' => 'https://push.test/dev1'])->assertOk()->assertJson(['devices' => 0]);
    }

    public function test_dead_subscriptions_are_removed_after_410(): void
    {
        $sub = $this->subscribe($this->ani);
        $this->fakePush(410);
        $this->assign();
        $this->assertSame(0, $this->inSchool($this->school, fn () => PushSubscription::whereKey($sub->id)->count()));
        $this->assertCount(1, $this->inbox($this->ani), 'lonceng tetap ada');
    }

    public function test_push_is_encrypted_and_carries_vapid_header(): void
    {
        $this->subscribe($this->ani);
        $this->assign();
        Http::assertSent(function ($req) {
            return str_starts_with($req->url(), 'https://push.test/')
                && $req->header('Content-Encoding')[0] === 'aes128gcm'
                && str_starts_with($req->header('Authorization')[0], 'vapid t=')
                && ! str_contains($req->body(), 'Kuis baru');
        });
    }

    public function test_school_can_turn_push_off_but_bell_keeps_working(): void
    {
        $this->subscribe($this->ani);
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->settingsPayload(['push_enabled' => false]))->assertSessionHasNoErrors();
        $this->assign();
        $this->assertSame('off', $this->inbox($this->ani)[0]->push_state);
        $this->assertSame(0, $this->pushCount());
    }

    private function settingsPayload(array $over = []): array
    {
        $page = $this->actingAs($this->admin)->get(route('admin.settings.edit'))->viewData('page')['props']['settings'];

        return $over + $page + ['default_theme_id' => null];
    }

    public function test_admin_broadcast_audiences_and_authorization(): void
    {
        foreach ([$this->ani, $this->budi, $this->teacher, $this->outsider] as $u) {
            $this->subscribe($u);
        }
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $send = fn (array $d) => $this->actingAs($this->admin)->postJson(route('admin.push.store'), $d + ['title' => 'Libur besok', 'body' => 'Sekolah libur.']);

        $send(['audience' => 'user', 'target' => $this->ani->id])->assertOk()->assertJson(['recipients' => 1, 'with_push' => 1]);
        $send(['audience' => 'teachers'])->assertOk()->assertJson(['recipients' => 1]);
        $send(['audience' => 'students'])->assertOk()->assertJson(['recipients' => 3, 'with_push' => 3]);
        $send(['audience' => 'class', 'target' => $this->class->id])->assertOk()->assertJson(['recipients' => 2]);
        $send(['audience' => 'all'])->assertOk()->assertJson(['recipients' => 4]);

        // Admin tidak dikirimi pesan massal, pengguna nonaktif dilewati
        $this->assertSame(0, $this->inbox($this->admin)->count());
        $this->inSchool($this->school, fn () => $this->budi->update(['status' => 'inactive']));
        $send(['audience' => 'class', 'target' => $this->class->id])->assertOk()->assertJson(['recipients' => 1]);

        $this->assertSame(6, $this->inSchool($this->school, fn () => PushBroadcast::count()));
        $this->assertSame('manual', $this->inbox($this->ani)[0]->kind);

        // Validasi dan pembatasan
        $send(['audience' => 'user'])->assertStatus(422);
        $send(['audience' => 'class', 'target' => '00000000-0000-4000-8000-000000000000'])->assertStatus(422);
        $send(['audience' => 'all', 'url' => 'https://evil.example'])->assertStatus(422);
        $send(['audience' => 'all', 'title' => ''])->assertStatus(422);
        $send(['audience' => 'students', 'url' => '/siswa?x=1'])->assertOk();

        foreach ([$this->teacher, $this->ani] as $nonAdmin) {
            $this->actingAs($nonAdmin)->postJson(route('admin.push.store'), ['audience' => 'all', 'title' => 'x'])->assertForbidden();
            $this->actingAs($nonAdmin)->get(route('admin.push.index'))->assertForbidden();
        }
    }

    public function test_admin_preview_and_search_and_page(): void
    {
        $this->subscribe($this->ani);
        $this->actingAs($this->admin)->postJson(route('admin.push.preview'), ['audience' => 'students'])->assertOk()->assertJson(['recipients' => 3, 'with_push' => 1]);
        $this->actingAs($this->admin)->getJson(route('admin.push.search', ['role' => 'student', 'q' => 'Ani']))->assertOk()->assertJsonPath('0.name', 'Ani');
        $this->actingAs($this->admin)->getJson(route('admin.push.search', ['role' => 'admin', 'q' => 'Ani']))->assertStatus(422);
        $this->actingAs($this->admin)->get(route('admin.push.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Push')->where('reach.students.total', 3)->where('reach.students.with_push', 1)->where('available', true));
    }

    public function test_tenant_isolation_for_notifications(): void
    {
        $this->assign();
        $other = $this->makeSchool('sekolah-lain');
        $x = $this->makeUser($other, User::ROLE_STUDENT);
        $this->subscribe($this->ani);
        $this->assertSame(0, $this->inSchool($other, fn () => AppNotification::count() + PushSubscription::count()));
        $this->actingAs($x)->getJson(route('notifications.summary'))->assertOk()->assertJson(['unread' => 0, 'items' => []]);
    }

    public function test_manifest_and_service_worker_are_public(): void
    {
        $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('display', 'standalone')->assertJsonPath('start_url', '/');
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('icons/icon-192.png'));
        $this->assertFileExists(public_path('icons/icon-512.png'));
    }

    public function test_command_runs_per_school_and_vapid_command_prints_keys(): void
    {
        $this->artisan('madingo:notify closed')->assertSuccessful();
        $this->artisan('madingo:notify bogus')->assertFailed();
        config(['notify.vapid.public' => null, 'notify.vapid.private' => null]);
        $this->artisan('madingo:vapid')->expectsOutputToContain('VAPID_PUBLIC_KEY=')->assertSuccessful();
    }

    public function test_new_notification_signals_only_the_owners_bell_channel(): void
    {
        config(['broadcasting.default' => 'reverb']);
        \Illuminate\Support\Facades\Event::fake([\App\Events\NotificationsChanged::class]);
        $this->assign();

        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\NotificationsChanged::class, fn ($e) => $e->broadcastOn()->name === "private-school.{$this->school->id}.user.{$this->ani->id}"
            && $e->broadcastWith() === ['kind' => 'notify']);
        \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\NotificationsChanged::class, fn ($e) => $e->userId === $this->outsider->id);

        // Props bersama memberi nama kanal milik pengguna itu sendiri
        $page = $this->actingAs($this->ani)->get(route('student.dashboard'));
        $this->assertSame("school.{$this->school->id}.user.{$this->ani->id}", $page->viewData('page')['props']['notify']['channel']);
    }

    public function test_bell_channel_authorization_is_owner_only(): void
    {
        $sid = $this->school->id;
        $this->assertTrue(\App\Events\NotificationsChanged::authorize($this->ani, $sid, $this->ani->id));
        $this->assertFalse(\App\Events\NotificationsChanged::authorize($this->budi, $sid, $this->ani->id));
        $this->assertFalse(\App\Events\NotificationsChanged::authorize($this->teacher, $sid, $this->ani->id));
        $this->assertFalse(\App\Events\NotificationsChanged::authorize($this->ani, 'other-school', $this->ani->id));

        // Kanal terdaftar di Broadcast
        $this->assertStringContainsString('NotificationsChanged::authorize', file_get_contents(base_path('routes/channels.php')));
    }
}

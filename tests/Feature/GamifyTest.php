<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\Post;
use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\UserLevel;
use App\Models\XpEvent;
use App\Modules\Gamify\Gamify;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tahap 15: XP, level, lencana, streak, peringkat kelas (GAM-01..04, 06, 07). */
class GamifyTest extends TestCase
{
    private object $school;

    private User $teacher;

    private User $ani;

    private User $budi;

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();
        Gamify::flush();
        $this->school = $this->makeSchool();
        $this->teacher = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->ani = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Ani']);
        $this->budi = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Budi']);
        $this->board = $this->inSchool($this->school, fn () => Board::create(['owner_id' => $this->teacher->id, 'title' => 'Diskusi', 'visibility' => 'school']));
    }

    private function xp(User $u): int
    {
        return (int) $this->inSchool($this->school, fn () => UserLevel::where('user_id', $u->id)->value('xp'));
    }

    private function g(callable $fn)
    {
        return $this->inSchool($this->school, fn () => $fn(app(Gamify::class)));
    }

    public function test_level_formula(): void
    {
        $g = app(Gamify::class);
        $this->assertSame(1, $g->level(0)['level']);
        $this->assertSame(1, $g->level(59)['level']);
        $this->assertSame(2, $g->level(60)['level']);
        $this->assertSame(3, $g->level(240)['level']);
        $this->assertSame(240, $g->level(60)['next_at']);
    }

    public function test_post_and_meaningful_comment_give_xp_once(): void
    {
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['body' => '<p>Ideku</p>']);
        $post = $this->inSchool($this->school, fn () => Post::firstOrFail());
        $this->assertSame(10, $this->xp($this->ani));
        $this->assertTrue($this->inSchool($this->school, fn () => UserBadge::where('user_id', $this->ani->id)->where('badge', 'first_post')->exists()));

        // Komentar singkat atau di kartu sendiri: tidak ada XP
        $this->actingAs($this->ani)->postJson(route('comments.store', [$this->board, $post]), ['body' => 'Komentar panjang di kartu sendiri'])->assertCreated();
        $this->actingAs($this->budi)->postJson(route('comments.store', [$this->board, $post]), ['body' => 'ok'])->assertCreated();
        $this->assertSame(10, $this->xp($this->ani));
        $this->assertSame(0, $this->xp($this->budi));

        $this->actingAs($this->budi)->postJson(route('comments.store', [$this->board, $post]), ['body' => 'Idemu menarik sekali, setuju!'])->assertCreated();
        $this->assertSame(3, $this->xp($this->budi));

        // Reaksi: sekali per kartu walau suka-batal-suka
        foreach ([1, null, 1] as $v) {
            $this->actingAs($this->budi)->post(route('posts.react', [$this->board, $post]), ['value' => $v]);
        }
        $this->assertSame(4, $this->xp($this->budi));

        // Guru tidak mendapat XP
        $this->actingAs($this->teacher)->postJson(route('comments.store', [$this->board, $post]), ['body' => 'Komentar guru yang cukup panjang']);
        $this->assertSame(0, $this->inSchool($this->school, fn () => XpEvent::where('user_id', $this->teacher->id)->count()));
    }

    public function test_daily_cap_and_category_cap(): void
    {
        $this->g(function (Gamify $g) {
            $got = 0;
            for ($i = 0; $i < 10; $i++) {
                $got += $g->award($this->ani, 'post', 'post', "p$i");
            }
            $this->assertSame(40, $got); // batas kartu per hari

            for ($i = 0; $i < 20; $i++) {
                $g->award($this->ani, 'quiz', 'attempt', "q$i", 30);
            }
        });
        $this->assertSame(150, $this->xp($this->ani)); // batas harian total

        // Hari berikutnya batas direset
        $this->travel(1)->days();
        $this->assertSame(10, $this->g(fn (Gamify $g) => $g->award($this->ani, 'post', 'post', 'baru')));
        $this->travelBack();
    }

    public function test_streak_tolerates_weekend_but_breaks_on_missed_school_day(): void
    {
        $friday = now()->next('Friday')->setTime(9, 0);
        $this->travelTo($friday);
        $this->g(fn (Gamify $g) => $g->award($this->ani, 'post', 'post', 'a'));

        $this->travelTo($friday->copy()->addDays(3)); // Senin
        $this->g(fn (Gamify $g) => $g->award($this->ani, 'post', 'post', 'b'));
        $this->assertSame(2, $this->inSchool($this->school, fn () => UserLevel::find($this->ani->id)->streak));

        $this->travelTo($friday->copy()->addDays(5)); // Rabu: Selasa terlewat
        $this->g(fn (Gamify $g) => $g->award($this->ani, 'post', 'post', 'c'));
        $level = $this->inSchool($this->school, fn () => UserLevel::find($this->ani->id));
        $this->assertSame(1, $level->streak);
        $this->assertSame(2, $level->best_streak);
        $this->travelBack();
    }

    public function test_notices_are_delivered_and_dismissed(): void
    {
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['body' => '<p>Ideku</p>']);
        $props = $this->actingAs($this->ani)->get(route('student.dashboard'))->original->getData()['page']['props'];
        $types = collect($props['gamify']['notices'])->pluck('type')->all();
        $this->assertContains('xp', $types);
        $this->assertContains('badge', $types);
        $this->assertSame(10, $props['gamify']['xp']);

        $ids = collect($props['gamify']['notices'])->pluck('id')->all();
        $this->actingAs($this->ani)->postJson(route('gamify.seen'), ['ids' => $ids])->assertOk();
        $props = $this->actingAs($this->ani)->get(route('student.dashboard'))->original->getData()['page']['props'];
        $this->assertSame([], $props['gamify']['notices']);

        // Guru tidak punya data gamifikasi
        $this->actingAs($this->teacher)->get(route('teacher.dashboard'))->assertInertia(fn ($p) => $p->where('gamify', null));
        $this->actingAs($this->teacher)->get(route('gamify.me'))->assertForbidden();
    }

    public function test_school_can_turn_gamification_off(): void
    {
        $this->inSchool($this->school, fn () => SchoolSetting::firstOrCreate(['school_id' => $this->school->id])->update(['settings' => ['gamify_enabled' => false]]));
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['body' => '<p>Ideku</p>']);
        $this->assertSame(0, $this->xp($this->ani));
        $this->actingAs($this->ani)->get(route('gamify.me'))->assertNotFound();
        $this->actingAs($this->ani)->get(route('student.dashboard'))->assertInertia(fn ($p) => $p->where('gamify', null));
    }

    public function test_class_leaderboard_scopes_toggle_and_dense_ranks(): void
    {
        [$class, $other] = $this->inSchool($this->school, function () {
            $year = AcademicYear::active();
            $mk = fn ($n) => SchoolClass::create(['academic_year_id' => $year->id, 'name' => $n, 'grade' => 7]);
            [$a, $b] = [$mk('7A'), $mk('7B')];
            $now = now();
            foreach ([[$a, $this->ani], [$a, $this->budi], [$b, $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Cici'])]] as [$c, $u]) {
                DB::table('class_members')->insert(['class_id' => $c->id, 'user_id' => $u->id, 'school_id' => $this->school->id, 'created_at' => $now, 'updated_at' => $now]);
            }

            return [$a, $b];
        });
        $this->g(function (Gamify $g) {
            $g->award($this->ani, 'quiz', 'attempt', 'x', 20);
            $g->award($this->budi, 'quiz', 'attempt', 'y', 20);
        });

        $page = fn (User $as) => $this->actingAs($as)->get(route('gamify.leaderboard'))->original->getData()['page']['props'];
        $p = $page($this->ani);
        $this->assertCount(2, $p['board']['rows']); // Cici di kelas lain tidak terlihat
        $this->assertSame([1, 1], array_column($p['board']['rows'], 'rank')); // seri = peringkat sama

        // Guru menyembunyikan peringkat: siswa tidak melihat data, guru tetap
        $this->actingAs($this->teacher)->post(route('gamify.toggle', $class))->assertForbidden(); // guru tidak boleh
        $admin = $this->makeUser($this->school, User::ROLE_ADMIN);
        $this->actingAs($admin)->post(route('gamify.toggle', $class))->assertRedirect();
        $p = $page($this->ani);
        $this->assertTrue($p['hidden']);
        $this->assertNull($p['board']);
        $this->assertNotNull($this->actingAs($this->teacher)->get(route('gamify.leaderboard', ['kelas' => $class->id]))->original->getData()['page']['props']['board']);

        // Siswa tidak bisa mengubah pengaturan atau mengintip kelas lain
        $this->actingAs($this->ani)->post(route('gamify.toggle', $class))->assertForbidden();
        $this->assertSame($class->id, $this->actingAs($this->ani)->get(route('gamify.leaderboard', ['kelas' => $other->id]))->original->getData()['page']['props']['class_id']);
    }

    public function test_teacher_filter_counts_only_xp_from_own_boards_and_quizzes(): void
    {
        $class = $this->inSchool($this->school, function () {
            $year = AcademicYear::active();
            $c = SchoolClass::create(['academic_year_id' => $year->id, 'name' => '7A', 'grade' => 7]);
            DB::table('class_members')->insert(['class_id' => $c->id, 'user_id' => $this->ani->id, 'school_id' => $this->school->id, 'created_at' => now(), 'updated_at' => now()]);

            return $c;
        });
        $this->g(function (Gamify $g) {
            $g->award($this->ani, 'post', 'post', (string) \Illuminate\Support\Str::uuid(), 10); // sumber tak dikenal → bukan milik guru
        });
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['body' => '<p>Ideku di papan guru</p>']);
        $this->board->forceFill(['owner_id' => $this->teacher->id])->save();

        $props = fn ($q) => $this->actingAs($this->teacher)->get(route('gamify.leaderboard', ['kelas' => $class->id, 'periode' => 'total'] + $q))->original->getData()['page']['props'];
        $all = $props([])['board']['rows'][0]['xp'];
        $mine = $props(['sumber' => 'saya']);
        $this->assertTrue($mine['mine']);
        $this->assertGreaterThan(0, $mine['board']['rows'][0]['xp']);
        $this->assertLessThan($all, $mine['board']['rows'][0]['xp']);
        // guru lain tidak punya papan/kuis ini → 0
        $other = $this->makeUser($this->school, User::ROLE_TEACHER);
        $r = $this->actingAs($other)->get(route('gamify.leaderboard', ['kelas' => $class->id, 'periode' => 'total', 'sumber' => 'saya']))->original->getData()['page']['props'];
        $this->assertSame(0, $r['board']['rows'][0]['xp']);
    }

    public function test_cosmetics_unlock_by_xp_and_themes_follow(): void
    {
        $theme = fn (string $key) => \App\Models\Theme::whereNull('school_id')->where('key', $key)->firstOrFail();
        $prefs = ['color_mode' => 'system', 'reduce_motion' => false, 'font_scale' => 100];

        // XP 0: hanya hadiah gratis
        $this->actingAs($this->ani)->put(route('profile.preferences'), $prefs + ['avatar' => 'robot'])->assertSessionHasErrors('avatar');
        $this->actingAs($this->ani)->put(route('profile.preferences'), $prefs + ['frame' => 'emas'])->assertSessionHasErrors('frame');
        $this->actingAs($this->ani)->put(route('profile.preferences'), $prefs + ['theme_id' => $theme('retro')->id])->assertSessionHasErrors('theme_id');
        $this->actingAs($this->ani)->put(route('profile.preferences'), $prefs + ['avatar' => 'rubah', 'frame' => 'polos'])->assertSessionHasNoErrors();

        $this->g(fn (Gamify $g) => $g->award($this->ani, 'quiz', 'attempt', 'big', 30));
        $this->travel(1)->days();
        $this->g(fn (Gamify $g) => $this->assertSame(30, $g->unlockXp($this->ani)));
        $this->g(fn (Gamify $g) => collect(range(1, 12))->each(function ($i) use ($g) {
            $this->travel($i)->days();
            $g->award($this->ani, 'quiz', 'attempt', "d$i", 30);
        }));
        $this->travelBack();
        $this->assertSame(390, $this->xp($this->ani));

        // 390 XP: bingkai perak dan tema retro (300) terbuka; emas dan robot belum
        $this->actingAs($this->ani)->put(route('profile.preferences'), $prefs + ['avatar' => 'gurita', 'frame' => 'perak', 'theme_id' => $theme('retro')->id])->assertSessionHasNoErrors();
        $this->actingAs($this->ani)->put(route('profile.preferences'), $prefs + ['avatar' => 'robot'])->assertSessionHasErrors('avatar');
        $this->actingAs($this->ani)->put(route('profile.preferences'), $prefs + ['theme_id' => $theme('alam')->id])->assertSessionHasErrors('theme_id');

        $user = $this->actingAs($this->ani)->get(route('student.dashboard'))->original->getData()['page']['props']['auth']['user'];
        $this->assertSame('🐙', $user['avatar']);
        $this->assertNotNull($user['frame']);

        // Guru: semua terbuka
        $this->actingAs($this->teacher)->put(route('profile.preferences'), $prefs + ['avatar' => 'singa', 'frame' => 'api', 'theme_id' => $theme('alam')->id])->assertSessionHasNoErrors();
    }
}

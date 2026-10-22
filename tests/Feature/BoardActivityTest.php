<?php

namespace Tests\Feature;

use App\Events\BoardUpdated;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Tahap 14: aktivitas live di papan (LIV-01..04) dan mode proyektor (BRD-20). */
class BoardActivityTest extends TestCase
{
    private object $school;

    private User $teacher;

    private User $ani;

    private User $budi;

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->makeSchool();
        $this->teacher = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->ani = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Ani']);
        $this->budi = $this->makeUser($this->school, User::ROLE_STUDENT, ['name' => 'Budi']);
        $this->board = $this->inSchool($this->school, fn () => Board::create(['owner_id' => $this->teacher->id, 'title' => 'Diskusi', 'visibility' => 'school']));
    }

    private function start(array $data)
    {
        return $this->actingAs($this->teacher)->postJson(route('activities.store', $this->board), $data);
    }

    private function current(User $as): ?array
    {
        return $this->actingAs($as)->getJson(route('activities.show', $this->board))->assertOk()->json('activity');
    }

    private function respond(User $as, string $id, mixed $value)
    {
        return $this->actingAs($as)->postJson(route('activities.respond', [$this->board, $id]), ['value' => $value]);
    }

    private function act(string $id, string $action, array $extra = [])
    {
        return $this->actingAs($this->teacher)->postJson(route('activities.act', [$this->board, $id]), ['action' => $action] + $extra);
    }

    public function test_poll_vote_change_and_results(): void
    {
        $this->assertNull($this->current($this->ani));
        $a = $this->start(['type' => 'poll', 'question' => 'Pilih satu', 'options' => ['Kucing', 'Anjing', ''], 'results' => 'live'])->assertOk()->json('activity');
        $this->assertSame(['Kucing', 'Anjing'], array_column($a['config']['options'], 'text'));

        $this->respond($this->ani, $a['id'], 'o1')->assertOk();
        $this->respond($this->budi, $a['id'], 'o1')->assertOk();
        $this->respond($this->budi, $a['id'], 'o2')->assertOk();            // boleh ganti pilihan
        $this->respond($this->ani, $a['id'], 'o9')->assertStatus(422);      // pilihan tidak ada

        $seen = $this->current($this->ani);
        $this->assertSame([1, 1], $seen['results']);
        $this->assertSame(['o1'], $seen['mine']);
        $this->assertFalse($seen['can_manage']);

        // Tutup: tidak bisa memilih lagi
        $this->act($a['id'], 'close')->assertOk()->assertJsonPath('activity.status', 'closed');
        $this->respond($this->ani, $a['id'], 'o2')->assertStatus(422);
        $this->assertSame([1, 1], $this->current($this->budi)['results']);
    }

    public function test_poll_results_can_stay_hidden_until_closed(): void
    {
        $a = $this->start(['type' => 'poll', 'question' => 'Rahasia?', 'options' => ['Ya', 'Tidak'], 'results' => 'closed'])->json('activity');
        $this->respond($this->ani, $a['id'], 'o1')->assertOk();

        $this->assertNull($this->current($this->ani)['results']);
        $this->assertSame([1, 0], $this->current($this->teacher)['results']);
        $this->act($a['id'], 'close');
        $this->assertSame([1, 0], $this->current($this->ani)['results']);
    }

    public function test_word_cloud_limits_and_filtering(): void
    {
        $a = $this->start(['type' => 'cloud', 'prompt' => 'Satu kata tentang IPA', 'max_words' => 2])->json('activity');

        $this->respond($this->ani, $a['id'], ['  Energi ', 'energi', 'Atom'])->assertOk();
        $this->respond($this->budi, $a['id'], ['ENERGI'])->assertOk();
        $this->respond($this->ani, $a['id'], ['Bumi'])->assertStatus(422);          // lebih dari 2 kata
        $this->respond($this->budi, $a['id'], ['anjing'])->assertStatus(422);       // kata kasar ditolak

        $words = collect($this->current($this->ani)['words'])->pluck('count', 'text')->all();
        $this->assertSame(['energi' => 2, 'atom' => 1], $words);

        // Guru bisa menghapus kata dari awan
        $this->act($a['id'], 'remove_word', ['text' => 'Atom'])->assertOk();
        $this->assertSame(['energi'], array_column($this->current($this->ani)['words'], 'text'));
    }

    public function test_wheel_is_drawn_on_server_without_repeats(): void
    {
        $a = $this->start(['type' => 'wheel', 'source' => 'list', 'names' => "Ani\nBudi\nCitra", 'no_repeat' => true])->assertOk()->json('activity');
        $this->assertSame(['Ani', 'Budi', 'Citra'], $a['config']['names']);

        $this->actingAs($this->ani)->postJson(route('activities.act', [$this->board, $a['id']]), ['action' => 'spin'])->assertForbidden();

        $winners = [];
        for ($i = 0; $i < 2; $i++) {
            $s = $this->act($a['id'], 'spin')->assertOk()->json('activity.state.spin');
            $winners[] = $s['index'];
        }
        $this->assertCount(2, array_unique($winners), 'tanpa pengulangan');
        // Tinggal satu nama: otomatis terpilih, tidak perlu diputar
        $this->act($a['id'], 'spin')->assertStatus(422);

        $this->act($a['id'], 'reset_wheel')->assertOk();
        $this->act($a['id'], 'spin')->assertOk();
    }

    public function test_wheel_from_class_roster(): void
    {
        $class = $this->inSchool($this->school, function () {
            $c = SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '7A', 'grade' => 7]);
            foreach ([$this->ani, $this->budi] as $u) {
                DB::table('class_members')->insert(['class_id' => $c->id, 'user_id' => $u->id, 'school_id' => $u->school_id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->board->update(['visibility' => 'class', 'class_id' => $c->id]);

            return $c;
        });

        $a = $this->start(['type' => 'wheel', 'source' => 'class'])->assertOk()->json('activity');
        $this->assertSame(['Ani', 'Budi'], $a['config']['names']);
        $this->assertNotNull($class);

        // Papan tanpa rombel tidak bisa memakai daftar rombel
        $other = $this->inSchool($this->school, fn () => Board::create(['owner_id' => $this->teacher->id, 'title' => 'Lain', 'visibility' => 'school']));
        $this->actingAs($this->teacher)->postJson(route('activities.store', $other), ['type' => 'wheel', 'source' => 'class'])->assertStatus(422);
    }

    public function test_timer_and_replacing_activity(): void
    {
        $t = $this->start(['type' => 'timer', 'seconds' => 120, 'title' => 'Diskusi'])->assertOk()->json('activity');
        $this->assertSame('timer', $t['type']);
        $left = strtotime($t['state']['ends_at']) - strtotime($t['server_now']);
        $this->assertEqualsWithDelta(120, $left, 2);

        $this->act($t['id'], 'restart', ['seconds' => 30])->assertOk();
        $this->assertEqualsWithDelta(30, strtotime($this->current($this->ani)['state']['ends_at']) - time(), 3);

        // Jeda menyimpan sisa waktu; lanjut memulai lagi dari sisa itu
        $this->act($t['id'], 'resume')->assertStatus(422);
        $this->act($t['id'], 'pause')->assertOk();
        $paused = $this->current($this->ani)['state'];
        $this->assertEqualsWithDelta(30000, $paused['paused_left'], 3000);
        $this->act($t['id'], 'pause')->assertStatus(422);
        $this->travel(20)->seconds();
        $this->act($t['id'], 'resume')->assertOk();
        $after = $this->current($this->ani)['state'];
        $this->assertArrayNotHasKey('paused_left', $after);
        $this->assertEqualsWithDelta(30, strtotime($after['ends_at']) - now()->timestamp, 3); // 20 detik jeda tidak mengurangi sisa
        $this->travelBack();

        // Aktivitas baru menggantikan yang lama
        $p = $this->start(['type' => 'poll', 'question' => 'Q', 'options' => ['a', 'b']])->json('activity');
        $this->assertSame($p['id'], $this->current($this->ani)['id']);
        $this->assertSame('ended', DB::table('board_activities')->where('id', $t['id'])->value('status') ?? 'ended');

        $this->act($p['id'], 'end');
        $this->assertNull($this->current($this->ani));
    }

    public function test_access_rules_and_validation(): void
    {
        // Siswa tidak boleh memulai aktivitas
        $this->actingAs($this->ani)->postJson(route('activities.store', $this->board), ['type' => 'timer', 'seconds' => 60])->assertForbidden();
        // Validasi
        $this->start(['type' => 'poll', 'question' => 'Q', 'options' => ['hanya satu']])->assertStatus(422);
        $this->start(['type' => 'timer', 'seconds' => 1])->assertStatus(422);
        $this->start(['type' => 'bebas'])->assertStatus(422);

        // Papan pribadi: siswa tidak melihat aktivitasnya
        $this->inSchool($this->school, fn () => $this->board->update(['visibility' => 'private']));
        $this->actingAs($this->ani)->getJson(route('activities.show', $this->board))->assertForbidden();

        // Sekolah lain tidak bisa mengakses
        $other = $this->makeSchool('sekolah-lain');
        $outsider = $this->makeUser($other, User::ROLE_TEACHER);
        $this->actingAs($outsider)->getJson(route("activities.show", $this->board))->assertForbidden();
    }

    public function test_board_page_and_projector_include_activity_and_signal_is_minimal(): void
    {
        $a = $this->start(['type' => 'poll', 'question' => 'Rahasia hasil', 'options' => ['x', 'y']])->json('activity');
        foreach (['boards.show', 'boards.projector'] as $route) {
            $this->actingAs($this->ani)->get(route($route, $this->board))->assertOk()
                ->assertInertia(fn ($p) => $p->where('activity.id', $a['id'])->where('activity.type', 'poll')->has('activity_channel'));
        }
        $this->actingAs($this->teacher)->get(route('boards.projector', $this->board))->assertOk()->assertInertia(fn ($p) => $p->component('Boards/Projector')->where('can.manage', true));

        config(['broadcasting.default' => 'reverb']);
        Event::fake([BoardUpdated::class]);
        $this->respond($this->ani, $a['id'], 'o1')->assertOk();
        Event::assertDispatched(BoardUpdated::class, fn (BoardUpdated $e) => $e->broadcastOn()->name === "private-school.{$this->school->id}.board.{$this->board->id}"
            && $e->broadcastWith() === ['kind' => 'activity']);
    }
}

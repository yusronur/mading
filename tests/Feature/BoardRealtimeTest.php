<?php

namespace Tests\Feature;

use App\Core\Realtime\BoardPulse;
use App\Events\BoardUpdated;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\Post;
use App\Models\SchoolClass;
use App\Models\User;
use App\Modules\Board\BoardPresenter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Tahap 20: sinyal realtime kartu lewat Reverb, satu siaran per papan per permintaan. */
class BoardRealtimeTest extends TestCase
{
    private object $school;

    private User $teacher;

    private User $ani;

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'reverb']);
        Cache::forget('madingo:realtime-down');
        Event::fake([BoardUpdated::class, \App\Events\NotificationsChanged::class]);
        $this->school = $this->makeSchool();
        $this->teacher = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->ani = $this->makeUser($this->school, User::ROLE_STUDENT);
        $this->board = $this->inSchool($this->school, function () {
            $c = SchoolClass::create(['academic_year_id' => AcademicYear::active()->id, 'name' => '7A', 'grade' => 7]);
            DB::table('class_members')->insert(['class_id' => $c->id, 'user_id' => $this->ani->id, 'school_id' => $this->school->id, 'created_at' => now(), 'updated_at' => now()]);

            return Board::create(['owner_id' => $this->teacher->id, 'title' => 'Realtime', 'visibility' => 'class', 'class_id' => $c->id]);
        });
    }

    private function signals(): \Illuminate\Support\Collection
    {
        return Event::dispatched(BoardUpdated::class)->map(fn ($e) => $e[0])->filter(fn ($e) => $e->kind === 'posts')->values();
    }

    public function test_new_card_sends_one_signal_with_current_version(): void
    {
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => 'Halo', 'checklist' => [['text' => 'a']]])->assertSessionHasNoErrors();
        $s = $this->signals();
        $this->assertCount(1, $s, 'kartu + sentuhan papan digabung jadi satu siaran');
        $this->assertSame($this->board->id, $s[0]->boardId);
        $this->assertSame("school.{$this->school->id}.board.{$this->board->id}", $s[0]->broadcastOn()->name === null ? '' : str_replace('private-', '', $s[0]->broadcastOn()->name));
        $version = $this->inSchool($this->school, fn () => app(BoardPresenter::class)->version($this->board->fresh()));
        $this->assertSame(['kind' => 'posts', 'v' => $version], $s[0]->broadcastWith());
        $this->assertSame([], BoardPulse::pending());
    }

    public function test_reaction_comment_move_and_delete_signal(): void
    {
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => 'Satu']);
        $post = $this->inSchool($this->school, fn () => Post::firstOrFail());
        $before = $this->signals()->count();

        $this->actingAs($this->teacher)->post(route('posts.pin', [$this->board, $post]));
        $this->actingAs($this->ani)->postJson(route('posts.react', [$this->board, $post]), ['value' => 1]);
        $this->actingAs($this->teacher)->delete(route('posts.destroy', [$this->board, $post]));

        $this->assertGreaterThanOrEqual($before + 3, $this->signals()->count());
    }

    public function test_no_signal_when_broadcasting_off_or_reverb_down(): void
    {
        config(['broadcasting.default' => 'null']);
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => 'Senyap']);
        $this->assertCount(0, $this->signals());

        config(['broadcasting.default' => 'reverb']);
        Cache::put('madingo:realtime-down', true, 30); // pemutus sirkuit terbuka
        $this->actingAs($this->ani)->post(route('posts.store', $this->board), ['title' => 'Masih jalan'])->assertSessionHasNoErrors();
        $this->assertCount(0, $this->signals());
    }

    public function test_board_channel_authorization(): void
    {
        $outsider = $this->makeUser($this->school, User::ROLE_STUDENT);
        $other = $this->makeSchool('sekolah-lain');
        $foreign = $this->makeUser($other, User::ROLE_TEACHER);
        $cb = fn (User $u) => $this->inSchool($this->school, fn () => BoardUpdated::authorize($u, $this->school->id, $this->board->id));
        $this->assertTrue($cb($this->ani));
        $this->assertTrue($cb($this->teacher));
        $this->assertFalse($cb($outsider));
        $this->assertFalse($cb($foreign));
    }

    public function test_loadtest_seed_and_clean(): void
    {
        $file = storage_path('app/loadtest.json');
        $backup = is_file($file) ? file_get_contents($file) : null;
        $this->beforeApplicationDestroyed(fn () => $backup !== null && file_put_contents($file, $backup));
        $this->artisan('madingo:loadtest', ['action' => 'seed', '--school' => 'sekolah-uji', '--students' => 5, '--password' => 'UjiBeban123'])->assertSuccessful();
        $json = json_decode(file_get_contents(storage_path('app/loadtest.json')), true);
        $this->assertSame(5, $json['students']);
        $count = fn () => $this->inSchool($this->school, fn () => User::where('username', 'like', 'uji-beban-%')->count());
        $this->assertSame(6, $count()); // 5 siswa + 1 guru
        $this->inSchool($this->school, fn () => $this->assertSame(10, \App\Models\Quiz::findOrFail($json['quiz_id'])->questions()->count()));

        // Siswa uji bisa masuk dan membuka papannya
        $this->post(route('login.store'), ['username' => 'uji-beban-0001', 'password' => 'UjiBeban123'])->assertRedirect();
        $this->get(route('boards.show', $json['board_id']))->assertOk();

        $this->artisan('madingo:loadtest', ['action' => 'clean', '--school' => 'sekolah-uji'])->assertSuccessful();
        $this->assertSame(0, $count());
        $this->assertFileDoesNotExist(storage_path('app/loadtest.json'));
    }
}

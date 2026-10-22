<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\User;
use Tests\TestCase;

/** Revisi 0.1.1: bank soal publik, salin, urutan, cetak. */
class BankSharingTest extends TestCase
{
    private object $school;

    private User $owner;

    private User $other;

    private QuestionBank $bank;

    private array $qs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->makeSchool();
        $this->owner = $this->makeUser($this->school, User::ROLE_TEACHER, ['name' => 'Bu Sari']);
        $this->other = $this->makeUser($this->school, User::ROLE_TEACHER, ['name' => 'Pak Dedi']);

        $this->inSchool($this->school, function () {
            $this->bank = QuestionBank::create(['owner_id' => $this->owner->id, 'name' => 'IPA 7', 'subject' => 'IPA', 'grade' => 7]);
            foreach (['Satu', 'Dua', 'Tiga'] as $i => $t) {
                $this->qs[] = $this->bank->questions()->create([
                    'author_id' => $this->owner->id, 'type' => 'mc', 'prompt' => "<p>{$t}</p>", 'position' => $i + 1,
                    'data' => ['options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']], 'answer' => 'a'],
                ]);
            }
        });
    }

    private function publish(bool $public = true): void
    {
        $this->actingAs($this->owner)->put(route('banks.update', $this->bank), ['name' => 'IPA 7', 'subject' => 'IPA', 'grade' => 7, 'is_public' => $public])->assertSessionHasNoErrors();
    }

    public function test_banks_are_private_by_default_and_public_ones_are_read_only_for_others(): void
    {
        $this->actingAs($this->other)->get(route('banks.index'))->assertInertia(fn ($p) => $p->has('banks', 0)->has('shared', 0));
        $this->actingAs($this->other)->get(route('banks.print', $this->bank))->assertForbidden();
        $this->actingAs($this->other)->getJson(route('quizzes.picker', $this->bank))->assertForbidden();

        $this->publish();
        $page = $this->actingAs($this->other)->get(route('banks.index', ['bank' => $this->bank->id]));
        $page->assertInertia(fn ($p) => $p->has('banks', 0)->has('shared', 1)->where('shared.0.owner', 'Bu Sari')->where('bankId', $this->bank->id)->has('questions', 3));
        $this->actingAs($this->other)->getJson(route('quizzes.picker', $this->bank))->assertOk()->assertJsonCount(3);
        $this->actingAs($this->other)->get(route('banks.print', $this->bank))->assertOk();

        // Hanya baca: tidak bisa mengubah, menghapus, menambah, atau mengurutkan
        $this->actingAs($this->other)->put(route('banks.update', $this->bank), ['name' => 'Diubah'])->assertForbidden();
        $this->actingAs($this->other)->delete(route('banks.destroy', $this->bank))->assertForbidden();
        $this->actingAs($this->other)->put(route('questions.update', $this->qs[0]), ['type' => 'tf', 'prompt' => 'x', 'difficulty' => 1, 'points' => 1, 'spec' => ['correct' => true]])->assertForbidden();
        $this->actingAs($this->other)->postJson(route('questions.reorder', $this->bank), ['ids' => [$this->qs[2]->id, $this->qs[0]->id]])->assertForbidden();

        // Dicabut kembali ke pribadi
        $this->publish(false);
        $this->actingAs($this->other)->getJson(route('quizzes.picker', $this->bank))->assertForbidden();
    }

    public function test_other_teacher_can_use_public_questions_in_own_quiz_but_not_private_ones(): void
    {
        $this->publish();
        $this->actingAs($this->other)->post(route('quizzes.store'), ['title' => 'Kuis Dedi'])->assertRedirect();
        $quiz = $this->inSchool($this->school, fn () => Quiz::where('owner_id', $this->other->id)->firstOrFail());
        $save = fn (array $ids) => $this->actingAs($this->other)->put(route('quizzes.update', $quiz), [
            'title' => 'Kuis Dedi', 'settings' => ['duration_minutes' => 30, 'attempts' => 1, 'scoring' => 'best', 'review' => 'after_submit', 'kkm' => 75],
            'questions' => array_map(fn ($id) => ['id' => $id], $ids),
        ])->assertSessionHasNoErrors();

        $save([$this->qs[0]->id, $this->qs[1]->id]);
        $this->assertSame(2, $this->inSchool($this->school, fn () => $quiz->questions()->count()));

        $this->publish(false);
        $save([$this->qs[0]->id]);
        $this->assertSame(0, $this->inSchool($this->school, fn () => $quiz->questions()->count()), 'soal bank pribadi guru lain tidak bisa dipakai');
    }

    public function test_copy_public_bank_makes_a_private_independent_copy(): void
    {
        $this->actingAs($this->other)->post(route('banks.copy', $this->bank))->assertForbidden();

        $this->publish();
        $this->actingAs($this->other)->post(route('banks.copy', $this->bank))->assertRedirect();
        $copy = $this->inSchool($this->school, fn () => QuestionBank::where('owner_id', $this->other->id)->firstOrFail());
        $this->assertFalse($copy->is_public, 'salinan selalu pribadi');
        $this->assertSame(['Satu', 'Dua', 'Tiga'], $this->inSchool($this->school, fn () => $copy->questions()->orderBy('position')->get()->map(fn ($q) => strip_tags($q->prompt))->all()));
        $this->assertSame($this->other->id, $this->inSchool($this->school, fn () => $copy->questions()->first()->author_id));

        // Mengubah salinan tidak menyentuh bank asli
        $this->actingAs($this->other)->delete(route('questions.destroy', $this->inSchool($this->school, fn () => $copy->questions()->first())))->assertRedirect();
        $this->assertSame(3, $this->inSchool($this->school, fn () => $this->bank->questions()->count()));
    }

    public function test_reorder_sort_modes_and_print_page(): void
    {
        [$a, $b, $c] = $this->qs;
        $this->actingAs($this->owner)->postJson(route('questions.reorder', $this->bank), ['ids' => [$c->id, $a->id, $b->id]])->assertOk();

        $order = fn (string $sort) => $this->actingAs($this->owner)->get(route('banks.index', ['bank' => $this->bank->id, 'sort' => $sort]))
            ->viewData('page')['props']['questions'];
        $titles = fn ($qs) => array_map(fn ($q) => strip_tags($q['prompt']), $qs);

        $this->assertSame(['Tiga', 'Satu', 'Dua'], $titles($order('manual')));
        $this->assertSame(['Tiga', 'Dua', 'Satu'], $titles($order('newest')), 'terbaru dibuat tampil dulu');
        $this->assertSame(['Satu', 'Dua', 'Tiga'], $titles($order('oldest')), 'terlama dibuat tampil dulu');
        $this->assertSame(['Tiga', 'Satu', 'Dua'], $titles($order('manual')));

        $this->actingAs($this->owner)->get(route('banks.print', $this->bank))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Quiz/BankPrint')->has('questions', 3)->where('bank.name', 'IPA 7'));
        $student = $this->makeUser($this->school, User::ROLE_STUDENT);
        $this->actingAs($student)->get(route('banks.print', $this->bank))->assertForbidden();
    }

    public function test_match_question_print_payload_has_consistent_key_letters(): void
    {
        $q = $this->inSchool($this->school, fn () => $this->bank->questions()->create([
            'author_id' => $this->owner->id, 'type' => 'match', 'prompt' => '<p>Jodohkan</p>', 'position' => 9,
            'data' => \App\Modules\Quiz\QuestionData::normalize('match', ['pairs' => [['left' => 'A1', 'right' => 'B1'], ['left' => 'A2', 'right' => 'B2'], ['left' => 'A3', 'right' => 'B3']]]),
        ]));
        $row = collect($this->actingAs($this->owner)->get(route('banks.print', $this->bank))->viewData('page')['props']['questions'])->firstWhere('id', $q->id);

        foreach ($row['lefts'] as $i => $left) {
            $letter = $row['match_key'][$i];
            $rightText = $row['rights'][ord($letter) - 65]['text'];
            $this->assertSame('B'.substr($left['text'], 1), $rightText, 'huruf kunci menunjuk kartu kanan yang benar');
        }
    }
}

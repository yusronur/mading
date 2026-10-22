<?php

namespace Tests\Unit;

use App\Models\Question;
use App\Modules\Quiz\QuestionData;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuestionDataTest extends TestCase
{
    private function q(string $type, array $data): Question
    {
        return new Question(['type' => $type, 'data' => $data]);
    }

    public function test_normalize_and_grade_each_type(): void
    {
        $mc = QuestionData::normalize('mc', ['options' => ['Merah', '', 'Biru', 'Hijau'], 'correct' => [1]]);
        $this->assertSame(['a', 'b', 'c'], array_column($mc['options'], 'id'), 'opsi kosong dibuang');
        $this->assertSame('b', $mc['answer']);
        $this->assertTrue(QuestionData::grade($this->q('mc', $mc), 'b')['correct']);
        $this->assertFalse(QuestionData::grade($this->q('mc', $mc), 'a')['correct']);

        $mcm = QuestionData::normalize('mcm', ['options' => ['A', 'B', 'C'], 'correct' => ['C', 'a']]);
        $this->assertSame(['a', 'c'], $mcm['answers']);
        $this->assertTrue(QuestionData::grade($this->q('mcm', $mcm), ['c', 'a'])['correct']);
        $this->assertFalse(QuestionData::grade($this->q('mcm', $mcm), ['a'])['correct'], 'semua-atau-tidak');

        $tf = QuestionData::normalize('tf', ['correct' => 'Salah']);
        $this->assertFalse($tf['answer']);
        $this->assertTrue(QuestionData::grade($this->q('tf', $tf), 'false')['correct']);

        $short = QuestionData::normalize('short', ['answers' => ['Foto Sintesis', ' fotosintesis ', '3,5']]);
        $q = $this->q('short', $short);
        foreach (['FOTOSINTESIS', 'foto   sintesis.', '3.5', '3,5'] as $ok) {
            $this->assertTrue(QuestionData::grade($q, $ok)['correct'], $ok);
        }
        $this->assertFalse(QuestionData::grade($q, 'respirasi')['correct']);
    }

    public function test_student_view_never_contains_key(): void
    {
        $mc = $this->q('mc', QuestionData::normalize('mc', ['options' => ['x', 'y'], 'correct' => [0]]));
        $this->assertArrayNotHasKey('answer', QuestionData::forStudent($mc));
        $this->assertStringNotContainsString('answer', json_encode(QuestionData::forStudent($mc, true, 42)));
        $short = $this->q('short', ['answers' => ['rahasia']]);
        $this->assertStringNotContainsString('rahasia', json_encode(QuestionData::forStudent($short)));
    }

    public function test_invalid_inputs_are_rejected(): void
    {
        foreach ([
            ['mc', ['options' => ['satu'], 'correct' => [0]]],
            ['mc', ['options' => ['a', 'b'], 'correct' => [0, 1]]],
            ['mc', ['options' => ['a', 'b'], 'correct' => [5]]],
            ['mcm', ['options' => ['a', 'b'], 'correct' => []]],
            ['tf', ['correct' => 'mungkin']],
            ['short', ['answers' => ['', ' ']]],
            ['essay', []],
        ] as [$type, $in]) {
            try {
                QuestionData::normalize($type, $in);
                $this->fail("{$type} seharusnya ditolak");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_match_type_grading_is_partial_and_does_not_leak_pairs(): void
    {
        $d = QuestionData::normalize('match', [
            'pairs' => [['left' => 'Jakarta', 'right' => 'Indonesia'], ['left' => 'Tokyo', 'right' => 'Jepang'], ['left' => 'Seoul', 'right' => 'Korea']],
            'extras' => ['Thailand', ''],
        ]);
        $this->assertCount(3, $d['pairs']);
        $this->assertCount(4, $d['rights'], 'tiga jawaban benar + satu pengecoh');

        $q = $this->q('match', $d);
        $right = fn (string $text) => collect($d['rights'])->firstWhere('text', $text)['id'];
        [$p1, $p2, $p3] = array_column($d['pairs'], 'id');

        $full = [$p1 => $right('Indonesia'), $p2 => $right('Jepang'), $p3 => $right('Korea')];
        $this->assertTrue(QuestionData::grade($q, $full)['correct']);

        $partial = [$p1 => $right('Indonesia'), $p2 => $right('Korea'), $p3 => $right('Thailand')];
        $res = QuestionData::grade($q, $partial);
        $this->assertFalse($res['correct']);
        $this->assertEqualsWithDelta(1 / 3, $res['ratio'], 0.001, 'nilai sebagian');
        $this->assertSame(0.0, QuestionData::grade($q, [])['ratio']);
        $this->assertSame(0.0, QuestionData::grade($q, 'bukan-array')['ratio']);

        // Versi siswa: tidak ada kunci, id kanan tidak berurutan dengan id kiri
        $student = QuestionData::forStudent($q, false, 123);
        $this->assertSame(['lefts', 'rights'], array_keys($student));
        $this->assertStringNotContainsString('right_id', json_encode($student));
        foreach ($student['rights'] as $r) {
            $this->assertSame(['id', 'text'], array_keys($r));
        }
        // Bentuk editor bisa dinormalisasi ulang tanpa perubahan arti
        $again = QuestionData::normalize('match', QuestionData::forEditor($q));
        $this->assertSame(array_column($d['pairs'], 'left'), array_column($again['pairs'], 'left'));

        // Teks kanan kembar dianggap setara
        $dup = QuestionData::normalize('match', ['pairs' => [['left' => 'Ayam', 'right' => 'Hewan'], ['left' => 'Kucing', 'right' => 'Hewan'], ['left' => 'Mawar', 'right' => 'Tumbuhan']]]);
        $qd = $this->q('match', $dup);
        $hewan = collect($dup['pairs'])->map(fn ($p) => $p['right_id'])->all();
        $swapped = [$dup['pairs'][0]['id'] => $hewan[1], $dup['pairs'][1]['id'] => $hewan[0], $dup['pairs'][2]['id'] => $dup['pairs'][2]['right_id']];
        $this->assertTrue(QuestionData::grade($qd, $swapped)['correct']);
    }

    public function test_match_rejects_incomplete_pairs(): void
    {
        foreach ([
            ['pairs' => [['left' => 'A', 'right' => '1']]],
            ['pairs' => [['left' => 'A', 'right' => '1'], ['left' => 'B', 'right' => '']]],
            ['pairs' => array_fill(0, 9, ['left' => 'x', 'right' => 'y'])],
        ] as $in) {
            try {
                QuestionData::normalize('match', $in);
                $this->fail('seharusnya ditolak');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

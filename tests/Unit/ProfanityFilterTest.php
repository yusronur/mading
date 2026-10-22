<?php

namespace Tests\Unit;

use App\Modules\Board\ProfanityFilter;
use PHPUnit\Framework\TestCase;

class ProfanityFilterTest extends TestCase
{
    private function f(array $extra = [], array $allowed = []): ProfanityFilter
    {
        return new ProfanityFilter($extra, $allowed);
    }

    public function test_detects_plain_slang_and_disguised_words(): void
    {
        $f = $this->f();
        foreach (['dasar Goblok', 'anjiiiing lu', 'a.n.j.i.n.g', 'b4ngs4t', 'kntl', 'ajg bgt', 'g o b l o k', 'T0L0L'] as $text) {
            $this->assertNotNull($f->find($text), $text);
        }
    }

    public function test_does_not_flag_normal_school_words(): void
    {
        $f = $this->f();
        foreach (['Fotosintesis butuh cahaya matahari', 'Tugas kelompok bab 3', 'Kucing dan anjing adalah mamalia peliharaan'] as $text) {
            // "anjing" bawaan termasuk umpatan; kalimat biologi tetap bisa diizinkan admin
            if (str_contains($text, 'anjing')) {
                $this->assertNull($this->f([], ['anjing'])->find($text), $text);
            } else {
                $this->assertNull($f->find($text), $text);
            }
        }
        $this->assertNull($f->find('Mamanya membawa kue tart'));
        $this->assertNull($f->find('bangkai hewan diurai bakteri'));
    }

    public function test_school_can_add_words(): void
    {
        $this->assertSame('cupu', $this->f(['Cupu'])->find('kamu cupu banget'));
    }
}

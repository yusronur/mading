<?php

namespace Tests\Unit;

use App\Modules\Import\ImportParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ImportParserTest extends TestCase
{
    private function parse(array $data, string $type = 'students'): array
    {
        return (new ImportParser)->parseRows($data, $type)['rows'];
    }

    public function test_finds_header_below_title_rows_like_dapodik(): void
    {
        $rows = $this->parse([
            ['Daftar Peserta Didik'],
            ['SMP Negeri Contoh'],
            [],
            ['No', 'Nama', 'NISN', 'JK', 'Rombel Saat Ini'],
            [1, 'Ahmad Fauzi', '0123456789', 'L', '7 A'],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('valid', $rows[0]['status']);
        $this->assertSame('7A', $rows[0]['class']);
        $this->assertSame(5, $rows[0]['line']);
    }

    public function test_pads_nisn_that_lost_leading_zero(): void
    {
        $rows = $this->parse([['nisn', 'nama', 'rombel'], [123456789, 'Budi', '8B']]);

        $this->assertSame('0123456789', $rows[0]['username']);
        $this->assertSame('valid', $rows[0]['status']);
    }

    public function test_reports_row_errors_and_duplicates(): void
    {
        $rows = $this->parse([
            ['nisn', 'nama', 'jk', 'rombel', 'email'],
            ['0123456789', 'Citra', 'X', '7A', ''],
            ['01234', '', 'P', '', 'bukan-email'],
            ['0011223344', 'Dian', 'P', '7A', ''],
            ['0011223344', 'Dian Lagi', 'P', '7A', ''],
        ]);

        $this->assertContains('JK harus L atau P.', $rows[0]['errors']);
        $this->assertContains('Nama kosong.', $rows[1]['errors']);
        $this->assertContains('Rombel kosong.', $rows[1]['errors']);
        $this->assertContains('Format email tidak valid.', $rows[1]['errors']);
        $this->assertSame('valid', $rows[2]['status']);
        $this->assertSame('error', $rows[3]['status']);
        $this->assertStringContainsString('Duplikat', $rows[3]['errors'][0]);
    }

    public function test_teacher_subjects_are_split(): void
    {
        $rows = $this->parse([
            ['NIP atau Username', 'Nama', 'Mapel', 'Wali Kelas'],
            ['198501012010011001', 'Bu Rina', 'IPA; Informatika', '7a'],
        ], 'teachers');

        $this->assertSame(['IPA', 'Informatika'], $rows[0]['subjects']);
        $this->assertSame('7A', $rows[0]['homeroom']);
    }

    public function test_missing_required_columns_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->parse([['nama', 'kelas'], ['A', '7A']]);
    }
}

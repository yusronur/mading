<?php

namespace App\Modules\Quiz;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Templat impor soal Excel dan Word (QZ-07). */
class QuestionTemplates
{
    public const EXAMPLES = [
        ['PG', 'Bagian sel tumbuhan yang berfungsi sebagai tempat fotosintesis adalah ...', 'Mitokondria', 'Kloroplas', 'Vakuola', 'Ribosom', '', 'B', 'Kloroplas mengandung klorofil yang menangkap cahaya.', 'Sel', 'mudah', 1],
        ['PGK', 'Manakah yang termasuk hewan vertebrata? (pilih semua yang benar)', 'Ikan', 'Cacing', 'Katak', 'Ubur-ubur', 'Burung', 'A,C,E', 'Vertebrata punya tulang belakang.', 'Klasifikasi', 'sedang', 2],
        ['BS', 'Matahari terbit dari arah barat.', '', '', '', '', '', 'S', 'Matahari terbit dari timur.', 'Bumi', 'mudah', 1],
        ['IS', 'Hasil dari $3^2 + 4^2$ adalah ...', '', '', '', '', '', '25 | dua puluh lima', 'Rumus matematika ditulis di antara dua tanda dolar.', 'Bilangan', 'sedang', 1],
    ];

    public function xlsx(): string
    {
        $book = new Spreadsheet;
        $s = $book->getActiveSheet()->setTitle('Soal');
        $s->fromArray(['Tipe', 'Soal', 'A', 'B', 'C', 'D', 'E', 'Kunci', 'Pembahasan', 'Topik', 'Kesulitan', 'Poin'], null, 'A1');
        $s->fromArray(self::EXAMPLES, null, 'A2');
        $s->getStyle('A1:L1')->getFont()->setBold(true);
        $s->getStyle('A1:L1')->getFill()->setFillType('solid')->getStartColor()->setRGB('FDE68A');
        $s->getColumnDimension('B')->setWidth(60);
        foreach (['C', 'D', 'E', 'F', 'G'] as $c) {
            $s->getColumnDimension($c)->setWidth(16);
        }
        foreach (['H', 'I', 'J', 'K'] as $c) {
            $s->getColumnDimension($c)->setWidth(18);
        }
        $s->getStyle('B:B')->getAlignment()->setWrapText(true);
        $s->freezePane('A2');

        $h = $book->createSheet()->setTitle('Petunjuk');
        $lines = [
            ['Petunjuk impor soal'],
            ['Isi lembar "Soal" mulai baris 2. Hapus contoh sebelum mengimpor. Satu baris = satu soal.'],
            ['Tipe: PG (pilihan ganda), PGK (pilihan ganda kompleks), BS (benar/salah), IS (isian singkat). Boleh dikosongkan; ditebak dari kunci.'],
            ['Pilihan A-E hanya untuk PG dan PGK (minimal 2, maksimal 6 dengan kolom F).'],
            ['Kunci: PG satu huruf (B); PGK beberapa huruf dipisah koma (A,C,E); BS: B atau S; IS: jawaban, pisahkan beberapa jawaban benar dengan | .'],
            ['Isian singkat tidak membedakan huruf besar/kecil dan spasi berlebih.'],
            ['Rumus matematika: tulis di antara tanda $, misalnya $x^2 + \\frac{1}{2}$.'],
            ['Kesulitan: mudah, sedang, atau sulit. Poin bawaan 1.'],
            ['Gambar, audio, dan video ditambahkan lewat editor soal setelah impor, atau gunakan templat Word (gambar ikut terbaca).'],
        ];
        $h->fromArray($lines, null, 'A1');
        $h->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $h->getColumnDimension('A')->setWidth(120);
        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->save('php://output');

        return ob_get_clean();
    }

    public function docx(): string
    {
        return SimpleDocx::build([
            ['bold' => 'TEMPLAT SOAL MADINGO'],
            'Tulis soal dengan format di bawah. Nomor soal "1." dan pilihan "A." boleh diketik manual atau memakai penomoran otomatis Word.',
            'Baris Kunci wajib. Baris Pembahasan, Topik, Kesulitan, Tipe, dan Poin boleh dihapus. Gambar yang ditempel di bawah teks soal ikut terimpor.',
            'Rumus: tulis di antara tanda $, misalnya $\frac{a}{b}$. Hapus petunjuk ini sebelum mengimpor (opsional).',
            '',
            '1. Bagian sel tumbuhan yang berfungsi sebagai tempat fotosintesis adalah ...',
            'A. Mitokondria',
            'B. Kloroplas',
            'C. Vakuola',
            'D. Ribosom',
            'Kunci: B',
            'Pembahasan: Kloroplas mengandung klorofil yang menangkap cahaya.',
            'Topik: Sel',
            'Kesulitan: mudah',
            '',
            '2. Manakah yang termasuk hewan vertebrata? (pilih semua yang benar)',
            'A. Ikan',
            'B. Cacing',
            'C. Katak',
            'D. Burung',
            'Kunci: A, C, D',
            'Kesulitan: sedang',
            '',
            '3. Matahari terbit dari arah barat.',
            'Kunci: Salah',
            '',
            '4. Hasil dari $3^2 + 4^2$ adalah ...',
            'Kunci: 25 | dua puluh lima',
        ]);
    }
}

<?php

namespace App\Modules\Import;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Templat impor dan daftar akun dalam format .xlsx. */
class SpreadsheetExporter
{
    public const TEMPLATES = [
        'students' => [
            'headers' => ['nisn', 'nama', 'jk', 'rombel', 'tanggal_lahir', 'email'],
            'examples' => [
                ['0123456789', 'Ahmad Fauzi', 'L', '7A', '2013-05-17', ''],
                ['0123456790', 'Siti Aminah', 'P', '7B', '2013-08-02', ''],
            ],
            'notes' => 'Kolom wajib: nisn (10 digit), nama, rombel. Rombel yang belum ada dibuat otomatis pada tahun ajaran aktif. Ekspor Dapodik juga bisa langsung diunggah.',
        ],
        'teachers' => [
            'headers' => ['nip_atau_username', 'nama', 'jk', 'email', 'mapel', 'wali_kelas'],
            'examples' => [
                ['198501012010011001', 'Budi Santoso, S.Pd.', 'L', '', 'Matematika', '7A'],
                ['bu.rina', 'Rina Wulandari, S.Pd.', 'P', 'rina@contoh.sch.id', 'IPA; Informatika', ''],
            ],
            'notes' => 'Kolom wajib: nip_atau_username dan nama. Pisahkan beberapa mapel dengan titik koma. wali_kelas diisi nama rombel yang sudah ada.',
        ],
    ];

    public function template(string $type): StreamedResponse
    {
        $tpl = self::TEMPLATES[$type];
        $headers = $tpl['headers'];
        $rows = $tpl['examples'];

        return $this->download(
            "templat-impor-{$type}.xlsx",
            $headers,
            $rows,
            $tpl['notes']
        );
    }

    public function credentials(array $credentials, string $filename): StreamedResponse
    {
        $rows = array_map(fn ($c) => [$c['username'], $c['name'], $c['class'], $c['password']], $credentials);

        return $this->download(
            $filename,
            ['username', 'nama', 'rombel', 'sandi_awal'],
            $rows,
            'Sandi awal wajib diganti saat login pertama. Simpan berkas ini dengan aman dan hapus setelah dibagikan.'
        );
    }

    /** Membuat berkas .xlsx di disk (dipakai pengujian dan data uji). */
    public function writeFile(string $path, array $headers, array $rows): void
    {
        $writer = new Xlsx($this->build($headers, $rows, null));
        $writer->save($path);
    }

    private function download(string $filename, array $headers, array $rows, ?string $note): StreamedResponse
    {
        $spreadsheet = $this->build($headers, $rows, $note);

        return new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function build(array $headers, array $rows, ?string $note): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');

        foreach ($headers as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }

        $last = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9E3FF');

        foreach ($rows as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                // Simpan sebagai teks agar angka nol di depan (NISN, NIP) tidak hilang
                $sheet->setCellValueExplicit([$c + 1, $r + 2], (string) $value, DataType::TYPE_STRING);
            }
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        if ($note) {
            $info = $spreadsheet->createSheet();
            $info->setTitle('Petunjuk');
            $info->setCellValue('A1', $note);
            $info->getColumnDimension('A')->setWidth(120);
            $info->getStyle('A1')->getAlignment()->setWrapText(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }
}

<?php

namespace App\Console\Commands;

use App\Modules\Import\SpreadsheetExporter;
use Illuminate\Console\Command;

/** Membuat berkas Excel siswa/guru fiktif untuk menguji impor (data uji, bukan data asli). */
class MakeSampleImportCommand extends Command
{
    protected $signature = 'madingo:sample-import
        {type=students : students atau teachers}
        {--rows=900 : Jumlah baris}
        {--path= : Lokasi berkas (bawaan: storage/app/data-uji-<type>.xlsx)}';

    protected $description = 'Membuat berkas Excel data uji untuk impor';

    private const FIRST = ['Adi', 'Bayu', 'Citra', 'Dian', 'Eko', 'Fajar', 'Gita', 'Hendra', 'Indah', 'Joko', 'Kartika', 'Lukman', 'Maya', 'Nanda', 'Oki', 'Putri', 'Rizki', 'Sari', 'Tono', 'Umi', 'Vina', 'Wahyu', 'Yuni', 'Zaki'];

    private const LAST = ['Pratama', 'Saputra', 'Lestari', 'Wijaya', 'Kusuma', 'Hidayat', 'Rahmawati', 'Nugroho', 'Permata', 'Santoso', 'Utami', 'Firmansyah'];

    public function handle(SpreadsheetExporter $exporter): int
    {
        $type = $this->argument('type');
        $count = max(1, (int) $this->option('rows'));
        $path = $this->option('path') ?: storage_path("app/data-uji-{$type}.xlsx");

        if (! in_array($type, ['students', 'teachers'], true)) {
            $this->error('Jenis harus students atau teachers.');

            return self::FAILURE;
        }

        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $name = self::FIRST[$i % count(self::FIRST)].' '.self::LAST[intdiv($i, count(self::FIRST)) % count(self::LAST)];

            if ($type === 'students') {
                // 27 rombel: 7A-7I, 8A-8I, 9A-9I (sesuai Blueprint Bab 10.4)
                $grade = 7 + intdiv(($i - 1) % 27, 9);
                $letter = chr(65 + (($i - 1) % 9));
                $rows[] = [sprintf('88%08d', $i), $name, $i % 2 ? 'L' : 'P', $grade.$letter, '2013-01-15', ''];
            } else {
                $rows[] = [sprintf('guru.uji%03d', $i), $name.', S.Pd.', $i % 2 ? 'L' : 'P', '', 'Mapel '.$i, ''];
            }
        }

        $exporter->writeFile($path, SpreadsheetExporter::TEMPLATES[$type]['headers'], $rows);
        $this->components->info("Berkas data uji dibuat: {$path} ({$count} baris)");

        return self::SUCCESS;
    }
}

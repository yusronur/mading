<?php

namespace App\Modules\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Membaca berkas Excel/CSV guru atau siswa (ADM-01, ADM-02).
 *
 * - Baris judul dicari otomatis di 15 baris pertama (ekspor Dapodik
 *   biasanya punya beberapa baris judul sebelum header).
 * - Nama kolom dikenali dari beberapa alias (lihat COLUMNS).
 * - Setiap baris diberi status: valid / error, beserta pesan.
 * - Tidak menyentuh database; penandaan "baru" atau "diperbarui"
 *   dilakukan oleh ImportService.
 */
class ImportParser
{
    public const COLUMNS = [
        'students' => [
            'username' => ['nisn'],
            'name' => ['nama', 'nama_lengkap', 'nama_siswa', 'nama_peserta_didik', 'nama_pd'],
            'class' => ['rombel', 'kelas', 'rombongan_belajar', 'rombel_saat_ini', 'nama_rombel'],
            'gender' => ['jk', 'jenis_kelamin', 'l/p', 'lp', 'l_p', 'gender'],
            'birth_date' => ['tanggal_lahir', 'tgl_lahir', 'tanggal_lahir_(yyyy-mm-dd)'],
            'email' => ['email', 'e-mail', 'surel'],
        ],
        'teachers' => [
            'username' => ['nip_atau_username', 'username', 'nip', 'nuptk', 'nik_guru'],
            'name' => ['nama', 'nama_lengkap', 'nama_guru', 'nama_ptk'],
            'email' => ['email', 'e-mail', 'surel'],
            'gender' => ['jk', 'jenis_kelamin', 'l/p', 'lp', 'l_p', 'gender'],
            'subjects' => ['mapel', 'mata_pelajaran', 'mapel_diampu'],
            'homeroom' => ['wali_kelas', 'wali_rombel', 'walas'],
        ],
    ];

    public const REQUIRED = [
        'students' => ['username', 'name', 'class'],
        'teachers' => ['username', 'name'],
    ];

    public const LABELS = [
        'username' => 'NISN / Username',
        'name' => 'Nama',
        'class' => 'Rombel',
        'gender' => 'JK',
        'birth_date' => 'Tanggal lahir',
        'email' => 'Email',
        'subjects' => 'Mapel',
        'homeroom' => 'Wali kelas',
    ];

    /**
     * @return array{rows: array<int, array>, columns: array<string,int>, header_row: int}
     */
    public function parseFile(string $path, string $type, int $maxRows = 3000): array
    {
        if (! isset(self::COLUMNS[$type])) {
            throw new RuntimeException('Jenis impor tidak dikenal.');
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getActiveSheet();
        } catch (\Throwable $e) {
            throw new RuntimeException('Berkas tidak dapat dibaca. Pastikan formatnya .xlsx, .xls, atau .csv.');
        }

        $data = $sheet->toArray(null, false, false, false);

        return $this->parseRows($data, $type, $maxRows);
    }

    public function parseRows(array $data, string $type, int $maxRows = 3000): array
    {
        [$headerIndex, $columns] = $this->findHeader($data, $type);

        if ($headerIndex === null) {
            $need = implode(', ', array_map(fn ($k) => self::LABELS[$k], self::REQUIRED[$type]));
            throw new RuntimeException("Baris judul kolom tidak ditemukan. Kolom wajib: {$need}.");
        }

        $rows = [];
        $seen = [];
        $lineCount = 0;

        for ($i = $headerIndex + 1; $i < count($data); $i++) {
            $raw = $data[$i];

            if ($this->isEmptyRow($raw)) {
                continue;
            }

            if (++$lineCount > $maxRows) {
                throw new RuntimeException("Berkas melebihi batas {$maxRows} baris. Pecah menjadi beberapa berkas.");
            }

            $row = ['line' => $i + 1, 'errors' => []];

            foreach ($columns as $field => $col) {
                $row[$field] = $this->cell($raw[$col] ?? null, $field);
            }

            $row = $type === 'students' ? $this->validateStudent($row) : $this->validateTeacher($row);

            if ($row['username'] !== '' && empty($row['errors'])) {
                $key = strtolower($row['username']);
                if (isset($seen[$key])) {
                    $row['errors'][] = 'Duplikat di berkas (sama dengan baris '.$seen[$key].').';
                } else {
                    $seen[$key] = $row['line'];
                }
            }

            $row['status'] = empty($row['errors']) ? 'valid' : 'error';
            $rows[] = $row;
        }

        return ['rows' => $rows, 'columns' => $columns, 'header_row' => $headerIndex + 1];
    }

    private function findHeader(array $data, string $type): array
    {
        $limit = min(15, count($data));

        for ($i = 0; $i < $limit; $i++) {
            $columns = [];

            foreach ((array) $data[$i] as $col => $value) {
                $normalized = $this->normalizeHeader((string) $value);

                foreach (self::COLUMNS[$type] as $field => $aliases) {
                    if (! isset($columns[$field]) && in_array($normalized, $aliases, true)) {
                        $columns[$field] = $col;
                    }
                }
            }

            $hasRequired = count(array_intersect(self::REQUIRED[$type], array_keys($columns))) === count(self::REQUIRED[$type]);

            if ($hasRequired) {
                return [$i, $columns];
            }
        }

        return [null, []];
    }

    private function normalizeHeader(string $value): string
    {
        $value = strtolower(trim(preg_replace('/\s+/', ' ', $value)));

        return str_replace([' ', '.'], ['_', ''], $value);
    }

    private function cell(mixed $value, string $field): string
    {
        if ($value === null) {
            return '';
        }

        if ($field === 'birth_date' && is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        if (is_float($value) && floor($value) === $value) {
            $value = number_format($value, 0, '', '');
        }

        return trim(preg_replace('/\s+/', ' ', (string) $value));
    }

    private function validateStudent(array $row): array
    {
        $nisn = preg_replace('/\D/', '', $row['username'] ?? '');

        // Excel sering membuang angka nol di depan NISN (0123456789 -> 123456789).
        // Hanya dipulihkan bila yang hilang 1-2 digit; angka yang jauh lebih
        // pendek dianggap salah ketik.
        if (strlen($nisn) >= 8 && strlen($nisn) < 10 && ctype_digit($row['username'] ?? '')) {
            $nisn = str_pad($nisn, 10, '0', STR_PAD_LEFT);
        }

        $row['username'] = $nisn;

        if ($nisn === '') {
            $row['errors'][] = 'NISN kosong.';
        } elseif (strlen($nisn) !== 10) {
            $row['errors'][] = 'NISN harus 10 digit.';
        }

        $row = $this->validateCommon($row);

        $row['class'] = strtoupper(str_replace(' ', '', $row['class'] ?? ''));
        if ($row['class'] === '') {
            $row['errors'][] = 'Rombel kosong.';
        } elseif (strlen($row['class']) > 30) {
            $row['errors'][] = 'Nama rombel terlalu panjang.';
        }

        $birth = $row['birth_date'] ?? '';
        if ($birth !== '') {
            $parsed = $this->parseDate($birth);
            if ($parsed === null) {
                $row['errors'][] = 'Tanggal lahir tidak dikenali (pakai YYYY-MM-DD).';
            } else {
                $row['birth_date'] = $parsed;
            }
        }

        return $row;
    }

    private function validateTeacher(array $row): array
    {
        $row['username'] = str_replace(' ', '', $row['username'] ?? '');

        if ($row['username'] === '') {
            $row['errors'][] = 'NIP/username kosong.';
        } elseif (! preg_match('/^[A-Za-z0-9._@\-]{3,60}$/', $row['username'])) {
            $row['errors'][] = 'Username hanya boleh huruf, angka, titik, @, garis (3-60 karakter).';
        }

        $row = $this->validateCommon($row);

        if (isset($row['homeroom'])) {
            $row['homeroom'] = strtoupper(str_replace(' ', '', $row['homeroom']));
        }

        if (isset($row['subjects'])) {
            $row['subjects'] = array_values(array_filter(array_map('trim', preg_split('/[;,]/', $row['subjects']))));
        }

        return $row;
    }

    private function validateCommon(array $row): array
    {
        if (($row['name'] ?? '') === '') {
            $row['errors'][] = 'Nama kosong.';
        } elseif (mb_strlen($row['name']) > 150) {
            $row['errors'][] = 'Nama lebih dari 150 karakter.';
        }

        if (isset($row['gender']) && $row['gender'] !== '') {
            $g = strtoupper(substr($row['gender'], 0, 1));
            $map = ['L' => 'L', 'P' => 'P', 'M' => 'L', 'F' => 'P', 'W' => 'P'];
            if (isset($map[$g])) {
                $row['gender'] = $map[$g];
            } else {
                $row['errors'][] = 'JK harus L atau P.';
            }
        }

        if (isset($row['email']) && $row['email'] !== '' && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $row['errors'][] = 'Format email tidak valid.';
        }

        return $row;
    }

    private function parseDate(string $value): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'd.m.Y', 'Y/m/d'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $value);
            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function isEmptyRow(mixed $row): bool
    {
        foreach ((array) $row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}

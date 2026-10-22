<?php

namespace App\Modules\Import;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\ImportJob;
use App\Models\SchoolClass;
use App\Models\User;
use App\Modules\Admin\InitialPasswords;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Alur impor: unggah -> pratinjau (ImportJob status "preview") -> konfirmasi
 * -> proses (job antrean) -> laporan.
 *
 * Impor ulang memperbarui data berdasarkan NISN/username, tidak menggandakan.
 * Sandi awal acak hanya dibuat untuk akun baru dan disimpan terenkripsi agar
 * admin bisa mengunduh daftar akun sekali.
 */
class ImportService
{
    public function __construct(private ImportParser $parser, private InitialPasswords $passwords) {}

    public function createPreview(string $path, string $originalName, string $type, ?User $by): ImportJob
    {
        if ($type === 'students' && ! AcademicYear::active()) {
            throw new RuntimeException('Belum ada tahun ajaran aktif. Buat dan aktifkan tahun ajaran terlebih dahulu.');
        }

        $parsed = $this->parser->parseFile($path, $type, (int) config('madingo.import.max_rows', 3000));
        $rows = $this->annotate($parsed['rows'], $type);

        return ImportJob::create([
            'user_id' => $by?->id,
            'type' => $type,
            'status' => 'preview',
            'original_name' => $originalName,
            'total_rows' => count($rows),
            'valid_rows' => count(array_filter($rows, fn ($r) => $r['status'] !== 'error')),
            'rows' => $rows,
        ]);
    }

    /** Tandai baris valid sebagai "baru" atau "diperbarui". */
    public function annotate(array $rows, string $type): array
    {
        $role = $type === 'students' ? User::ROLE_STUDENT : User::ROLE_TEACHER;
        $usernames = array_values(array_filter(array_column($rows, 'username')));

        $existing = [];
        foreach (array_chunk($usernames, 1000) as $chunk) {
            User::withTrashed()->whereIn('username', $chunk)->get(['username', 'role'])
                ->each(function ($u) use (&$existing) {
                    $existing[strtolower($u->username)] = $u->role;
                });
        }

        $classNames = [];
        if ($type === 'teachers' && ($year = AcademicYear::active())) {
            $classNames = SchoolClass::where('academic_year_id', $year->id)->pluck('name')->all();
        }

        foreach ($rows as &$row) {
            if ($row['status'] === 'error') {
                continue;
            }

            $key = strtolower($row['username']);

            if (isset($existing[$key]) && $existing[$key] !== $role) {
                $row['errors'][] = 'Username sudah dipakai akun '.(User::ROLE_LABELS[$existing[$key]] ?? $existing[$key]).'.';
                $row['status'] = 'error';

                continue;
            }

            if ($type === 'teachers' && ! empty($row['homeroom']) && ! in_array($row['homeroom'], $classNames, true)) {
                $row['warnings'][] = "Rombel {$row['homeroom']} belum ada; wali kelas dilewati.";
            }

            $row['status'] = isset($existing[$key]) ? 'update' : 'new';
        }

        return $rows;
    }

    public function process(ImportJob $import): ImportJob
    {
        if ($import->status !== 'preview' && $import->status !== 'processing') {
            throw new RuntimeException('Impor ini sudah diproses.');
        }

        @set_time_limit(600);
        $import->update(['status' => 'processing']);

        try {
            $result = $import->type === 'students'
                ? $this->processStudents($import->rows ?? [])
                : $this->processTeachers($import->rows ?? []);

            $import->update([
                'status' => 'done',
                'created_count' => $result['created'],
                'updated_count' => $result['updated'],
                'skipped_count' => $result['skipped'],
                'credentials' => $result['credentials'],
                'finished_at' => now(),
            ]);

            AuditLog::create([
                'actor_id' => $import->user_id,
                'action' => 'import.'.$import->type,
                'target_type' => 'ImportJob',
                'target_id' => $import->id,
                'meta' => [
                    'file' => $import->original_name,
                    'created' => $result['created'],
                    'updated' => $result['updated'],
                    'skipped' => $result['skipped'],
                ],
            ]);
        } catch (\Throwable $e) {
            $import->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'finished_at' => now()]);
            throw $e;
        }

        return $import->fresh();
    }

    private function processStudents(array $rows): array
    {
        $year = AcademicYear::active() ?? throw new RuntimeException('Tidak ada tahun ajaran aktif.');
        $created = $updated = $skipped = 0;
        $credentials = [];

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::transaction(function () use ($chunk, $year, &$created, &$updated, &$skipped, &$credentials) {
                $classes = SchoolClass::where('academic_year_id', $year->id)->get()->keyBy('name');
                $users = User::withTrashed()
                    ->whereIn('username', array_column($chunk, 'username'))
                    ->get()->keyBy(fn ($u) => strtolower($u->username));

                foreach ($chunk as $row) {
                    if (! in_array($row['status'], ['new', 'update'], true)) {
                        $skipped++;

                        continue;
                    }

                    $class = $classes->get($row['class']);
                    if (! $class) {
                        $class = SchoolClass::create([
                            'academic_year_id' => $year->id,
                            'name' => $row['class'],
                            'grade' => SchoolClass::guessGrade($row['class']),
                        ]);
                        $classes->put($class->name, $class);
                    }

                    $attributes = array_filter([
                        'name' => $row['name'],
                        'gender' => $row['gender'] ?? null,
                        'birth_date' => $row['birth_date'] ?? null,
                        'email' => $row['email'] ?? null,
                    ], fn ($v) => $v !== null && $v !== '');

                    $user = $users->get(strtolower($row['username']));

                    if ($user) {
                        if ($user->trashed()) {
                            $user->restore();
                        }
                        $user->fill($attributes + ['status' => 'active'])->save();
                        $updated++;
                    } else {
                        $password = $this->randomPassword();
                        $user = new User($attributes + [
                            'username' => $row['username'],
                            'role' => User::ROLE_STUDENT,
                            'status' => 'active',
                            'must_change_password' => true,
                        ]);
                        $user->password = $this->passwords->hash($password);
                        $user->initial_password = $password;
                        $user->save();
                        $created++;
                        $credentials[] = [
                            'username' => $user->username,
                            'name' => $user->name,
                            'class' => $class->name,
                            'password' => $password,
                        ];
                    }

                    $this->moveToClass($user, $class, $year);
                }
            });
        }

        return compact('created', 'updated', 'skipped', 'credentials');
    }

    private function processTeachers(array $rows): array
    {
        $year = AcademicYear::active();
        $classes = $year ? SchoolClass::where('academic_year_id', $year->id)->get()->keyBy('name') : collect();
        $created = $updated = $skipped = 0;
        $credentials = [];

        DB::transaction(function () use ($rows, $classes, &$created, &$updated, &$skipped, &$credentials) {
            foreach ($rows as $row) {
                if (! in_array($row['status'], ['new', 'update'], true)) {
                    $skipped++;

                    continue;
                }

                $user = User::withTrashed()->where('username', $row['username'])->first();
                $profile = ['subjects' => $row['subjects'] ?? []];
                $attributes = array_filter([
                    'name' => $row['name'],
                    'gender' => $row['gender'] ?? null,
                    'email' => $row['email'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');

                if ($user) {
                    if ($user->trashed()) {
                        $user->restore();
                    }
                    $merged = array_merge($user->profile ?? [], array_filter($profile));
                    $user->fill($attributes + ['status' => 'active', 'profile' => $merged])->save();
                    $updated++;
                } else {
                    $password = $this->randomPassword(8);
                    $user = new User($attributes + [
                        'username' => $row['username'],
                        'role' => User::ROLE_TEACHER,
                        'status' => 'active',
                        'profile' => $profile,
                        'must_change_password' => true,
                    ]);
                    $user->password = $this->passwords->hash($password);
                    $user->initial_password = $password;
                    $user->save();
                    $created++;
                    $credentials[] = ['username' => $user->username, 'name' => $user->name, 'class' => '', 'password' => $password];
                }

                if (! empty($row['homeroom']) && ($class = $classes->get($row['homeroom']))) {
                    $class->update(['homeroom_teacher_id' => $user->id]);
                }
            }
        });

        return compact('created', 'updated', 'skipped', 'credentials');
    }

    /** Siswa hanya punya satu rombel per tahun ajaran. */
    private function moveToClass(User $user, SchoolClass $class, AcademicYear $year): void
    {
        $current = DB::table('class_members')
            ->join('classes', 'classes.id', '=', 'class_members.class_id')
            ->where('class_members.user_id', $user->id)
            ->where('classes.academic_year_id', $year->id)
            ->pluck('class_members.class_id')
            ->all();

        if ($current === [$class->id]) {
            return;
        }

        if ($current) {
            DB::table('class_members')->where('user_id', $user->id)->whereIn('class_id', $current)->delete();
        }

        DB::table('class_members')->insert([
            'class_id' => $class->id,
            'user_id' => $user->id,
            'school_id' => $user->school_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function randomPassword(int $length = 6): string
    {
        return $this->passwords->generate($length);
    }
}

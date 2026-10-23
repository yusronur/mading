<?php

namespace App\Modules\Recap;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Halaman dan ekspor rekap nilai per rombel (Tahap 18). */
class RecapController extends Controller
{
    public function __construct(private Recap $recap) {}

    public function index(Request $request): Response
    {
        [$classes, $class, $all] = $this->context($request);

        return Inertia::render('Teacher/Recap', [
            'classes' => $classes->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'class_id' => $class?->id,
            'all' => $all,
            'is_admin' => $request->user()->isAdmin(),
            'recap' => $class ? $this->recap->build($class, $request->user(), $all) : null,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [, $class, $all] = $this->context($request);
        abort_unless($class, 404);
        $r = $this->recap->build($class, $request->user(), $all);
        $tz = config('app.timezone');

        $book = new Spreadsheet;
        $s = $book->getActiveSheet()->setTitle('Rekap');
        $s->setCellValue('A1', 'Rekap nilai rombel '.$class->name);
        $s->setCellValue('A2', ($all ? 'Semua guru' : 'Guru: '.$request->user()->name).' · dicetak '.now()->timezone($tz)->format('d-m-Y H:i'));
        $s->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $kind = ['quiz' => 'Kuis', 'live' => 'Live', 'board' => 'Papan'];
        $head = ['No', 'NISN', 'Nama'];
        foreach ($r['columns'] as $c) {
            $head[] = $kind[$c['kind']].': '.$c['title'].($c['date'] ? ' ('.Carbon::parse($c['date'])->timezone($tz)->format('d/m').')' : '');
        }
        $head[] = 'Rata-rata';
        $s->fromArray($head, null, 'A4');
        $last = Coordinate::stringFromColumnIndex(count($head));
        $s->getStyle("A4:{$last}4")->getFont()->setBold(true);
        $s->getStyle("A4:{$last}4")->getAlignment()->setWrapText(true);

        $row = 5;
        foreach ($r['rows'] as $i => $x) {
            $line = [$i + 1, null, $x['name']];
            foreach ($r['columns'] as $c) {
                $line[] = $x['cells'][$c['key']];
            }
            $line[] = $x['average'];
            $s->fromArray($line, null, 'A'.$row, true);
            $s->setCellValueExplicit('B'.$row, (string) $x['username'], DataType::TYPE_STRING);
            $row++;
        }
        $avg = ['', '', 'Rata-rata kelas'];
        foreach ($r['columns'] as $c) {
            $avg[] = $r['column_average'][$c['key']];
        }
        $s->fromArray($avg, null, 'A'.$row, true);
        $s->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);

        $s->getColumnDimension('A')->setWidth(5);
        $s->getColumnDimension('B')->setWidth(14);
        $s->getColumnDimension('C')->setWidth(30);
        for ($i = 4; $i <= count($head); $i++) {
            $s->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(14);
        }
        $s->getRowDimension(4)->setRowHeight(60);
        $s->freezePane('D5');

        $name = 'rekap-nilai-'.Str::slug($class->name).'-'.now()->format('Ymd').'.xlsx';

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: ?SchoolClass, 2: bool} */
    private function context(Request $request): array
    {
        $user = $request->user();
        abort_unless($user->hasRole(User::ROLE_TEACHER, User::ROLE_ADMIN), 403);
        $year = AcademicYear::active();
        $classes = $year ? SchoolClass::where('academic_year_id', $year->id)->orderBy('grade')->orderBy('name')->get() : collect();
        $class = $classes->firstWhere('id', $request->query('kelas')) ?? $classes->first();
        // Admin bisa melihat nilai dari semua guru; guru hanya miliknya
        $all = $user->isAdmin() && $request->query('guru') !== 'saya';

        return [$classes, $class, $all];
    }
}

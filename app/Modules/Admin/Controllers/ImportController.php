<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ImportJob;
use App\Modules\Import\ImportParser;
use App\Modules\Import\ImportService;
use App\Modules\Import\Jobs\ProcessImport;
use App\Modules\Import\SpreadsheetExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Import/Index', [
            'imports' => ImportJob::with('user:id,name')->latest()->limit(20)
                ->get(['id', 'user_id', 'type', 'status', 'original_name', 'total_rows', 'created_count', 'updated_count', 'skipped_count', 'created_at']),
            'hasActiveYear' => AcademicYear::active() !== null,
            'notes' => array_map(fn ($t) => $t['notes'], SpreadsheetExporter::TEMPLATES),
        ]);
    }

    public function store(Request $request, ImportService $service): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:students,teachers'],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:'.config('madingo.import.max_file_kb', 5120)],
        ], [], ['type' => 'jenis data', 'file' => 'berkas']);

        $file = $data['file'];

        try {
            $import = $service->createPreview($file->getRealPath(), $file->getClientOriginalName(), $data['type'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.imports.show', $import);
    }

    public function show(Request $request, ImportJob $import): Response
    {
        $rows = collect($import->rows ?? []);
        $onlyProblems = $request->boolean('masalah');

        $visible = $rows
            ->when($onlyProblems, fn ($c) => $c->filter(fn ($r) => $r['status'] === 'error' || ! empty($r['warnings'])))
            ->take(300)
            ->values();

        return Inertia::render('Admin/Import/Show', [
            'import' => [
                'id' => $import->id,
                'type' => $import->type,
                'status' => $import->status,
                'original_name' => $import->original_name,
                'total_rows' => $import->total_rows,
                'valid_rows' => $import->valid_rows,
                'created_count' => $import->created_count,
                'updated_count' => $import->updated_count,
                'skipped_count' => $import->skipped_count,
                'error_message' => $import->error_message,
                'has_credentials' => ! empty($import->credentials),
                'finished_at' => $import->finished_at?->toIso8601String(),
            ],
            'summary' => [
                'new' => $rows->where('status', 'new')->count(),
                'update' => $rows->where('status', 'update')->count(),
                'error' => $rows->where('status', 'error')->count(),
                'warnings' => $rows->filter(fn ($r) => ! empty($r['warnings']))->count(),
            ],
            'rows' => $visible,
            'columns' => array_keys(ImportParser::COLUMNS[$import->type]),
            'labels' => ImportParser::LABELS,
            'onlyProblems' => $onlyProblems,
            'truncated' => $rows->count() > $visible->count() && ! $onlyProblems,
        ]);
    }

    public function process(ImportJob $import): RedirectResponse
    {
        if ($import->status !== 'preview') {
            return back()->with('error', 'Impor ini sudah diproses.');
        }

        if ($import->valid_rows === 0) {
            return back()->with('error', 'Tidak ada baris valid untuk diproses.');
        }

        try {
            ProcessImport::dispatch($import->id);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Impor gagal: '.$e->getMessage());
        }

        $import->refresh();

        return back()->with('success', $import->status === 'done'
            ? "Impor selesai: {$import->created_count} akun baru, {$import->updated_count} diperbarui."
            : 'Impor sedang diproses di antrean. Muat ulang halaman ini beberapa saat lagi.');
    }

    public function destroy(ImportJob $import): RedirectResponse
    {
        if ($import->status === 'preview') {
            $import->delete();

            return redirect()->route('admin.imports.index')->with('success', 'Pratinjau impor dibatalkan.');
        }

        // Hapus sandi awal tersimpan setelah dibagikan
        $import->update(['credentials' => null]);

        return back()->with('success', 'Daftar sandi awal dihapus dari server.');
    }

    public function credentials(ImportJob $import, SpreadsheetExporter $exporter): StreamedResponse|RedirectResponse
    {
        if (empty($import->credentials)) {
            return back()->with('error', 'Daftar sandi awal tidak tersedia.');
        }

        return $exporter->credentials($import->credentials, 'akun-'.$import->type.'-'.$import->created_at->format('Ymd-His').'.xlsx');
    }

    public function template(string $type, SpreadsheetExporter $exporter): StreamedResponse
    {
        abort_unless(isset(SpreadsheetExporter::TEMPLATES[$type]), 404);

        return $exporter->template($type);
    }
}

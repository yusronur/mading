<?php

namespace App\Modules\Admin\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AcademicYearController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/AcademicYears', [
            'years' => AcademicYear::withCount('classes')->orderByDesc('starts_on')->get(),
            'suggestion' => AcademicYear::suggestName(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'regex:/^\d{4}\/\d{4}$/', Rule::unique('academic_years')->where('school_id', app(TenantContext::class)->id())],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'active_semester' => ['required', 'in:1,2'],
            'activate' => ['boolean'],
        ], ['name.regex' => 'Format tahun ajaran: 2026/2027.'], ['name' => 'tahun ajaran']);

        $year = AcademicYear::create($data + ['is_active' => false]);

        if ($request->boolean('activate') || ! AcademicYear::where('is_active', true)->exists()) {
            $this->makeActive($year);
        }

        AuditLog::record('academic_year.created', $year, ['name' => $year->name]);

        return back()->with('success', "Tahun ajaran {$year->name} dibuat.");
    }

    public function update(Request $request, AcademicYear $year): RedirectResponse
    {
        $data = $request->validate(['active_semester' => ['required', 'in:1,2']]);
        $year->update($data);

        return back()->with('success', 'Semester aktif diperbarui.');
    }

    public function activate(AcademicYear $year): RedirectResponse
    {
        $this->makeActive($year);
        AuditLog::record('academic_year.activated', $year, ['name' => $year->name]);

        return back()->with('success', "Tahun ajaran {$year->name} sekarang aktif.");
    }

    private function makeActive(AcademicYear $year): void
    {
        DB::transaction(function () use ($year) {
            AcademicYear::where('id', '!=', $year->id)->update(['is_active' => false]);
            $year->update(['is_active' => true]);
        });
    }
}

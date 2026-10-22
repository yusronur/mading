<?php

namespace App\Modules\Admin\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClassController extends Controller
{
    public function index(): Response
    {
        $year = AcademicYear::active();

        return Inertia::render('Admin/Classes', [
            'year' => $year?->only(['id', 'name']),
            'classes' => $year
                ? SchoolClass::where('academic_year_id', $year->id)
                    ->withCount('members')
                    ->with('homeroomTeacher:id,name')
                    ->orderBy('grade')->orderBy('name')
                    ->get()
                : [],
            'teachers' => User::where('role', User::ROLE_TEACHER)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, TenantContext $tenant): RedirectResponse
    {
        $year = AcademicYear::active();
        abort_unless($year, 422, 'Belum ada tahun ajaran aktif.');

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:30',
                Rule::unique('classes')->where('school_id', $tenant->id())->where('academic_year_id', $year->id),
            ],
            'grade' => ['nullable', 'integer', 'between:1,12'],
            'homeroom_teacher_id' => ['nullable', 'uuid', Rule::exists('users', 'id')->where('school_id', $tenant->id())->where('role', User::ROLE_TEACHER)],
        ], [], ['name' => 'nama rombel']);

        $name = strtoupper(str_replace(' ', '', $data['name']));

        SchoolClass::create([
            'academic_year_id' => $year->id,
            'name' => $name,
            'grade' => $data['grade'] ?? SchoolClass::guessGrade($name),
            'homeroom_teacher_id' => $data['homeroom_teacher_id'] ?? null,
        ]);

        return back()->with('success', "Rombel {$name} dibuat.");
    }

    public function update(Request $request, SchoolClass $class, TenantContext $tenant): RedirectResponse
    {
        $data = $request->validate([
            'homeroom_teacher_id' => ['nullable', 'uuid', Rule::exists('users', 'id')->where('school_id', $tenant->id())->where('role', User::ROLE_TEACHER)],
            'grade' => ['nullable', 'integer', 'between:1,12'],
        ]);

        $class->update($data);

        return back()->with('success', "Rombel {$class->name} diperbarui.");
    }
}

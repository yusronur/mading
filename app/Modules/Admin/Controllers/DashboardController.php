<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ImportJob;
use App\Models\SchoolClass;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $year = AcademicYear::active();

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'teachers' => User::where('role', User::ROLE_TEACHER)->where('status', 'active')->count(),
                'students' => User::where('role', User::ROLE_STUDENT)->where('status', 'active')->count(),
                'classes' => $year ? SchoolClass::where('academic_year_id', $year->id)->count() : 0,
                'boards' => Board::count(),
            ],
            'year' => $year?->only(['id', 'name', 'active_semester']),
            'recentImports' => ImportJob::latest()->limit(5)->get(['id', 'type', 'status', 'original_name', 'created_count', 'updated_count', 'created_at']),
        ]);
    }
}

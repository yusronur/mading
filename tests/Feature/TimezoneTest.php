<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Jam yang disimpan Eloquent harus sama dengan jam database, apa pun zona server. */
class TimezoneTest extends TestCase
{
    public function test_eloquent_timestamps_match_database_clock(): void
    {
        $school = $this->makeSchool();
        $log = $this->inSchool($school, fn () => AuditLog::record('test.clock'));

        $drift = $this->inSchool($school, fn () => (float) DB::selectOne(
            'select abs(extract(epoch from (now() - created_at))) as d from audit_logs where id = ?', [$log->id]
        )->d);

        $this->assertLessThan(60, $drift, 'created_at bergeser dari jam database');
        $this->assertEqualsWithDelta(now()->getTimestamp(), $this->inSchool($school, fn () => $log->fresh())->created_at->getTimestamp(), 60);
    }
}

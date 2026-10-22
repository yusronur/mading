<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Models\QuizSession;
use App\Models\School;
use App\Modules\Quiz\LiveQuiz;
use Illuminate\Console\Command;

/** Tutup kuis live yang ditinggalkan guru agar hasilnya masuk laporan dan siswa tidak terjebak. */
class CloseStaleLiveCommand extends Command
{
    protected $signature = 'madingo:close-stale-live';

    protected $description = 'Tutup kuis live yang ditinggalkan guru';

    public function handle(TenantContext $tenant, LiveQuiz $live): int
    {
        $n = 0;
        foreach (School::all() as $school) {
            $tenant->run($school, function () use ($live, &$n) {
                QuizSession::where('mode', 'live')->whereNull('closed_at')
                    ->where('updated_at', '<', now()->subHours(LiveQuiz::STALE_HOURS))->get()
                    ->each(function (QuizSession $s) use ($live, &$n) {
                        $n += $live->finishIfStale($s) ? 1 : 0;
                    });
            });
        }
        $this->info("$n kuis live ditutup.");

        return self::SUCCESS;
    }
}

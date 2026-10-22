<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Modules\Notify\Triggers;
use Illuminate\Console\Command;

/** Pekerjaan notifikasi terjadwal per sekolah: due (H-1), closed (nilai), digest (guru), release (jam tenang), prune. */
class NotifyCommand extends Command
{
    protected $signature = 'madingo:notify {job : due|closed|digest|release|prune}';

    protected $description = 'Jalankan pekerjaan notifikasi terjadwal';

    public function handle(Triggers $triggers): int
    {
        $job = $this->argument('job');
        if (! in_array($job, ['due', 'closed', 'digest', 'release', 'prune'], true)) {
            $this->error('Pekerjaan tidak dikenal.');

            return self::INVALID;
        }

        $total = 0;
        foreach (School::all() as $school) {
            try {
                $total += $triggers->runFor($school, $job);
            } catch (\Throwable $e) {
                report($e);
                $this->warn("{$school->slug}: {$e->getMessage()}");
            }
        }
        $this->info("$job: $total");

        return self::SUCCESS;
    }
}

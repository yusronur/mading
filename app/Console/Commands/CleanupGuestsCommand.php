<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Models\QuizAttempt;
use App\Models\QuizSession;
use App\Models\School;
use App\Models\User;
use Illuminate\Console\Command;

/** Hapus akun tamu kuis yang tidak pernah mengerjakan, atau yang kuisnya sudah dihapus. */
class CleanupGuestsCommand extends Command
{
    protected $signature = 'madingo:cleanup-guests {--hours=24}';

    protected $description = 'Hapus akun tamu kuis yang tidak terpakai';

    public function handle(TenantContext $tenant): int
    {
        $total = 0;

        foreach (School::all() as $school) {
            $tenant->run($school, function () use (&$total) {
                $liveSessions = QuizSession::pluck('id')->all();
                User::where('role', User::ROLE_GUEST)->orderBy('id')->chunkById(200, function ($chunk) use (&$total, $liveSessions) {
                    foreach ($chunk as $guest) {
                        $sid = $guest->profile['guest_session'] ?? null;
                        $orphan = ! $sid || ! in_array($sid, $liveSessions, true);
                        $idle = $guest->created_at->lt(now()->subHours((int) $this->option('hours')))
                            && ! QuizAttempt::where('user_id', $guest->id)->exists();
                        if ($orphan || $idle) {
                            $guest->forceDelete();
                            $total++;
                        }
                    }
                });
            });
        }

        $this->info("{$total} akun tamu dihapus.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Models\Attachment;
use App\Models\School;
use Illuminate\Console\Command;

/** Hapus lampiran yang diunggah tapi tidak jadi ditempel (lebih dari 1 hari). */
class CleanupAttachmentsCommand extends Command
{
    protected $signature = 'madingo:cleanup-attachments {--hours=24}';

    protected $description = 'Hapus lampiran yatim (tidak terpakai di kartu mana pun)';

    public function handle(TenantContext $tenant): int
    {
        $total = 0;

        foreach (School::all() as $school) {
            $tenant->run($school, function () use (&$total) {
                Attachment::whereNull('attachable_id')
                    ->where('created_at', '<', now()->subHours((int) $this->option('hours')))
                    ->orderBy('id')
                    ->chunkById(200, function ($chunk) use (&$total) {
                        $chunk->each->purge();
                        $total += $chunk->count();
                    });
            });
        }

        $this->info("{$total} lampiran yatim dihapus.");

        return self::SUCCESS;
    }
}

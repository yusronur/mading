<?php

namespace App\Modules\Import\Jobs;

use App\Core\Tenancy\TenantAwareJob;
use App\Models\ImportJob;
use App\Modules\Import\ImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ProcessImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $importId)
    {
        $this->rememberTenant();
    }

    public function handle(ImportService $service): void
    {
        $this->inTenant(function () use ($service) {
            $import = ImportJob::findOrFail($this->importId);
            $service->process($import);
        });
    }
}

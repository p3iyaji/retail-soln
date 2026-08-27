<?php

namespace Modules\Purchasing\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Purchasing\Models\SupplierImport;
use Modules\Purchasing\Services\SupplierCsvService;

class ProcessSupplierImportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $importId) {}

    public function handle(SupplierCsvService $csv): void
    {
        $import = SupplierImport::query()->find($this->importId);
        if ($import === null) {
            return;
        }

        $csv->processImport($import);
    }
}

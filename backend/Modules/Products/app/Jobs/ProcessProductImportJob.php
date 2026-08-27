<?php

namespace Modules\Products\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Products\Models\ProductImport;
use Modules\Products\Services\ProductCsvService;

class ProcessProductImportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $importId) {}

    public function handle(ProductCsvService $csv): void
    {
        $import = ProductImport::query()->find($this->importId);
        if ($import === null) {
            return;
        }

        try {
            $csv->processImport($import);
        } catch (\Throwable $e) {
            $import->status = 'failed';
            $import->errors = array_merge($import->errors ?? [], [[
                'row' => 0,
                'field' => 'import',
                'message' => $e->getMessage(),
            ]]);
            $import->finished_at = now();
            $import->save();

            throw $e;
        }
    }
}

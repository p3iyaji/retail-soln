<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\Sale;

class SendSaleReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $saleId) {}

    public function handle(): void
    {
        $sale = Sale::query()
            ->with(['items.productVariant', 'payments', 'store', 'customer'])
            ->find($this->saleId);

        if ($sale === null) {
            return;
        }

        Log::info('Sale receipt queued', [
            'saleId' => $sale->id,
            'total' => (string) $sale->total,
            'storeId' => $sale->store_id,
        ]);
    }
}

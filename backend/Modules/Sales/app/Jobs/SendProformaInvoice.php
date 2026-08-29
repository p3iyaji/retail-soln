<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\ProformaInvoice;

class SendProformaInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $proformaInvoiceId) {}

    public function handle(): void
    {
        $proforma = ProformaInvoice::query()
            ->with(['items.productVariant', 'customer', 'store'])
            ->find($this->proformaInvoiceId);

        if ($proforma === null) {
            return;
        }

        Log::info('Proforma invoice delivery queued', [
            'proformaId' => $proforma->id,
            'number' => $proforma->number,
            'customerEmail' => $proforma->customer?->email,
            'total' => (string) $proforma->total,
        ]);
    }
}

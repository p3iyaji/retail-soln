<?php

namespace Modules\Reporting\Data;

/**
 * Filter set shared by the JSON inventory report and CSV export so they
 * cannot describe different populations.
 */
class InventoryStockReportFilters
{
    public function __construct(
        public readonly string $period,
        public readonly string $anchorDate,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            period: (string) $validated['period'],
            anchorDate: (string) $validated['anchorDate'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'anchorDate' => $this->anchorDate,
        ];
    }
}

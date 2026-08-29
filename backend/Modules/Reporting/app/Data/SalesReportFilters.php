<?php

namespace Modules\Reporting\Data;

/**
 * Filter set shared by the JSON summary, the paginated rows, and the CSV export
 * so an export can never describe a different population than the screen it came from.
 */
class SalesReportFilters
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $productVariantId = null,
        public readonly ?int $categoryId = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?int $cashierId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            from: (string) $validated['from'],
            to: (string) $validated['to'],
            productVariantId: isset($validated['productVariantId']) ? (int) $validated['productVariantId'] : null,
            categoryId: isset($validated['categoryId']) ? (int) $validated['categoryId'] : null,
            paymentMethod: isset($validated['paymentMethod']) ? (string) $validated['paymentMethod'] : null,
            cashierId: isset($validated['cashierId']) ? (int) $validated['cashierId'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'productVariantId' => $this->productVariantId,
            'categoryId' => $this->categoryId,
            'paymentMethod' => $this->paymentMethod,
            'cashierId' => $this->cashierId,
        ];
    }
}

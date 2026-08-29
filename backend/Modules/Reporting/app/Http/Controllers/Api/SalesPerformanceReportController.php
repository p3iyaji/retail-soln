<?php

namespace Modules\Reporting\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;
use Modules\Reporting\Data\SalesReportFilters;
use Modules\Reporting\Http\Concerns\ResolvesReportScope;
use Modules\Reporting\Services\SalesPerformanceReportService;
use Modules\Sales\Enums\PaymentMethod;
use Modules\Sales\Models\Sale;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesPerformanceReportController
{
    use ResolvesReportScope;

    public function show(
        Request $request,
        OrganizationService $organizations,
        SalesPerformanceReportService $reports,
    ): JsonResponse {
        $user = $this->reportUser($request);
        $organization = $organizations->current();
        $store = $this->resolveStore($request, $user, $organization);

        $validated = $this->validateFilters($request);
        $filters = SalesReportFilters::fromValidated($validated);
        $timezone = $reports->resolveTimezone($organization);

        $summary = $reports->summary($organization, $store, $filters);
        $perPage = max(1, min(100, (int) ($validated['per_page'] ?? 50)));
        $page = $reports->paginateSales($organization, $store, $filters, $perPage);

        return response()->json([
            'data' => [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                ],
                'range' => [
                    'from' => $filters->from,
                    'to' => $filters->to,
                    'timezone' => $timezone,
                ],
                'currency' => $organization->default_currency,
                'filters' => $filters->toArray(),
                'summary' => [
                    'salesTotal' => $summary['salesTotal'],
                    'salesCount' => $summary['salesCount'],
                    'averageOrderValue' => $summary['averageOrderValue'],
                ],
                'cashiers' => $summary['cashiers'],
                'topProducts' => $reports->topProducts($organization, $store, $filters),
                'rows' => collect($page->items())
                    ->map(fn (Sale $sale) => $reports->saleRow($sale, $timezone))
                    ->all(),
            ],
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function export(
        Request $request,
        OrganizationService $organizations,
        SalesPerformanceReportService $reports,
    ): StreamedResponse {
        $user = $this->reportUser($request);
        $organization = $organizations->current();
        $store = $this->resolveStore($request, $user, $organization);

        $filters = SalesReportFilters::fromValidated($this->validateFilters($request));
        $content = $reports->exportCsv(
            $organization,
            $store,
            $filters,
            $reports->resolveTimezone($organization),
        );

        $filename = sprintf('sales-report-%d-%s-to-%s.csv', $store->id, $filters->from, $filters->to);

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'productVariantId' => ['nullable', 'integer', 'min:1'],
            'categoryId' => ['nullable', 'integer', 'min:1'],
            'paymentMethod' => ['nullable', Rule::in(PaymentMethod::values())],
            'cashierId' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}

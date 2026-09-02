<?php

namespace Modules\Reporting\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Organization\Models\Organization;
use Modules\Organization\Services\OrganizationService;
use Modules\Reporting\Data\InventoryStockReportFilters;
use Modules\Reporting\Http\Concerns\ResolvesReportScope;
use Modules\Reporting\Services\InventoryStockReportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryStockReportController
{
    use ResolvesReportScope;

    public function show(
        Request $request,
        OrganizationService $organizations,
        InventoryStockReportService $reports,
    ): JsonResponse {
        $user = $this->reportUser($request);
        $organization = $organizations->current();
        $store = $this->resolveStore($request, $user, $organization);

        $validated = $this->validateFilters($request, $organization);
        $filters = InventoryStockReportFilters::fromValidated($validated);
        $perPage = max(1, min(100, (int) ($validated['per_page'] ?? 50)));
        $page = max(1, (int) ($validated['page'] ?? 1));

        $built = $reports->buildReport($organization, $store, $filters, $page, $perPage);

        return response()->json([
            'data' => [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                ],
                'period' => [
                    'preset' => $filters->period,
                    'anchorDate' => $filters->anchorDate,
                    'start' => $built['summary']['periodStart'],
                    'end' => $built['summary']['periodEnd'],
                    'timezone' => $built['summary']['timezone'],
                ],
                'filters' => $filters->toArray(),
                'summary' => [
                    'positionSkuCount' => $built['summary']['positionSkuCount'],
                    'onHandTotal' => $built['summary']['onHandTotal'],
                    'movements' => $built['summary']['movements'],
                ],
                'rows' => $built['rows'],
            ],
            'meta' => $built['meta'],
        ]);
    }

    public function export(
        Request $request,
        OrganizationService $organizations,
        InventoryStockReportService $reports,
    ): StreamedResponse {
        $user = $this->reportUser($request);
        $organization = $organizations->current();
        $store = $this->resolveStore($request, $user, $organization);

        $filters = InventoryStockReportFilters::fromValidated(
            $this->validateFilters($request, $organization),
        );
        $content = $reports->exportCsv($organization, $store, $filters);

        $filename = sprintf(
            'inventory-stock-%d-%s-%s.csv',
            $store->id,
            $filters->period,
            $filters->anchorDate,
        );

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request, Organization $organization): array
    {
        $timezone = app(InventoryStockReportService::class)->resolveTimezone($organization);
        $defaultAnchor = CarbonImmutable::now($timezone)->toDateString();

        $validated = $request->validate([
            'period' => ['required', Rule::in(['day', 'week', 'month', 'year'])],
            'anchorDate' => ['nullable', 'date_format:Y-m-d', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $validated['anchorDate'] = $validated['anchorDate'] ?? $defaultAnchor;

        return $validated;
    }
}

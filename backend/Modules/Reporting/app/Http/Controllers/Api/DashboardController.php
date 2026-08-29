<?php

namespace Modules\Reporting\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Reporting\Http\Concerns\ResolvesReportScope;
use Modules\Reporting\Services\DashboardSummaryService;

class DashboardController
{
    use ResolvesReportScope;

    public function show(
        Request $request,
        OrganizationService $organizations,
        DashboardSummaryService $summaries,
    ): JsonResponse {
        $user = $this->reportUser($request);
        $organization = $organizations->current();
        $store = $this->resolveStore($request, $user, $organization);

        return response()->json([
            'data' => $summaries->forStore($organization, $store, $user),
        ]);
    }
}

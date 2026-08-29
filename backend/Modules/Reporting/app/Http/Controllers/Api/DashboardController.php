<?php

namespace Modules\Reporting\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Reporting\Services\DashboardSummaryService;

class DashboardController
{
    public function show(
        Request $request,
        OrganizationService $organizations,
        DashboardSummaryService $summaries,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || ! $user->hasPermission('reports.view')) {
            abort(403, 'This action is unauthorized.');
        }

        $storeId = $this->resolveStoreId($request, $user);
        $organization = $organizations->current();

        $store = Store::query()
            ->where('organization_id', $organization->id)
            ->whereKey($storeId)
            ->first();

        if ($store === null) {
            abort(422, 'Store not found.');
        }

        return response()->json([
            'data' => $summaries->forStore($organization, $store, $user),
        ]);
    }

    private function resolveStoreId(Request $request, User $user): int
    {
        $header = $request->header('X-Store-Id');
        if ($header === null || $header === '' || ! ctype_digit((string) $header)) {
            abort(422, 'X-Store-Id header is required.');
        }

        $storeId = (int) $header;
        if (! $user->canAccessStore($storeId)) {
            abort(403, 'This action is unauthorized.');
        }

        return $storeId;
    }
}

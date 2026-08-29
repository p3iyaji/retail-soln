<?php

namespace Modules\Reporting\Http\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;

/**
 * Every reporting endpoint is gated the same way: staff token, `reports.view`,
 * and an `X-Store-Id` the caller is actually assigned to (AD-4).
 */
trait ResolvesReportScope
{
    private function reportUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->hasPermission('reports.view')) {
            abort(403, 'This action is unauthorized.');
        }

        return $user;
    }

    private function resolveStore(Request $request, User $user, Organization $organization): Store
    {
        $store = Store::query()
            ->where('organization_id', $organization->id)
            ->whereKey($this->resolveStoreId($request, $user))
            ->first();

        if ($store === null) {
            abort(422, 'Store not found.');
        }

        return $store;
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

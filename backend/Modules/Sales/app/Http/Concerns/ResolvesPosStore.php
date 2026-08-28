<?php

namespace Modules\Sales\Http\Concerns;

use Illuminate\Http\Request;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;

trait ResolvesPosStore
{
    protected function resolvePosStoreId(Request $request): int
    {
        $header = $request->header('X-Store-Id');
        if ($header === null || $header === '' || ! ctype_digit((string) $header)) {
            abort(422, 'X-Store-Id header is required.');
        }

        $storeId = (int) $header;
        $user = $request->user();
        if ($user === null || ! $user->canAccessStore($storeId)) {
            abort(403, 'This action is unauthorized.');
        }

        $organizationId = app(OrganizationService::class)->current()->id;
        $exists = Store::query()
            ->where('organization_id', $organizationId)
            ->whereKey($storeId)
            ->exists();

        if (! $exists) {
            abort(422, 'Store not found.');
        }

        return $storeId;
    }
}

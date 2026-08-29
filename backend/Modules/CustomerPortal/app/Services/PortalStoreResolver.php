<?php

namespace Modules\CustomerPortal\Services;

use Illuminate\Validation\ValidationException;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;

class PortalStoreResolver
{
    /**
     * Resolve the store used for catalogue price and stock signals.
     *
     * Explicit storeId must belong to the portal org and be active (else 422).
     * When omitted: config default_store_id, else first active org store, else null.
     */
    public function resolve(Organization $organization, ?int $storeId = null): ?Store
    {
        if ($storeId !== null) {
            $store = Store::query()
                ->where('organization_id', $organization->id)
                ->where('id', $storeId)
                ->where('is_active', true)
                ->first();

            if ($store === null) {
                throw ValidationException::withMessages([
                    'storeId' => ['The selected store is invalid for this organization.'],
                ]);
            }

            return $store;
        }

        $configuredId = config('customer_portal.default_store_id');
        if ($configuredId !== null && $configuredId !== '') {
            $store = Store::query()
                ->where('organization_id', $organization->id)
                ->where('id', (int) $configuredId)
                ->where('is_active', true)
                ->first();

            if ($store !== null) {
                return $store;
            }
        }

        return Store::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}

<?php

namespace Modules\CustomerPortal\Services;

use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;

class PortalSupportInfoService
{
    public function __construct(
        private readonly PortalOrganizationResolver $organizations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $organization = $this->organizations->resolve();

        $stores = Store::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country']);

        return [
            'organizationName' => $organization->name,
            'supportEmail' => config('customer_portal.support_email'),
            'supportPhone' => config('customer_portal.support_phone'),
            'businessHours' => config('customer_portal.business_hours'),
            'stores' => $stores->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'code' => $store->code,
                'address' => $this->formatStoreAddress($store),
            ])->values()->all(),
        ];
    }

    private function formatStoreAddress(Store $store): ?string
    {
        $parts = array_filter([
            $store->address_line1,
            $store->address_line2,
            $store->city,
            $store->state,
            $store->postal_code,
            $store->country,
        ], fn (?string $part) => $part !== null && trim($part) !== '');

        if ($parts === []) {
            return null;
        }

        return implode(', ', $parts);
    }
}

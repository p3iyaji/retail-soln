<?php

namespace Modules\CustomerPortal\Services;

use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalContactInquiry;

class PortalContactService
{
    public function __construct(
        private readonly PortalOrganizationResolver $organizations,
    ) {}

    /**
     * @param  array{name: string, email: string, phone?: string|null, subject: string, message: string}  $data
     */
    public function submit(?CustomerAccount $account, array $data): PortalContactInquiry
    {
        $organization = $this->organizations->resolve();

        return PortalContactInquiry::query()->create([
            'organization_id' => $organization->id,
            'customer_account_id' => $account?->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'status' => 'new',
        ]);
    }
}

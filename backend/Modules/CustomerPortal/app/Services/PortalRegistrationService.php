<?php

namespace Modules\CustomerPortal\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\Customers\Enums\CustomerType;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerService;
use Modules\Organization\Models\Organization;

class PortalRegistrationService
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly PortalOrganizationResolver $organizations,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, phone?: ?string}  $data
     * @return array{account: CustomerAccount, verification_token: string}
     */
    public function register(array $data): array
    {
        $organization = $this->organizations->resolve();
        $email = Str::lower(trim($data['email']));

        if (
            CustomerAccount::query()
                ->where('organization_id', $organization->id)
                ->where('email', $email)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'email' => ['This email is already registered.'],
            ]);
        }

        try {
            return DB::transaction(function () use ($organization, $email, $data) {
                $customer = $this->resolveOrCreateCustomer($organization, $email, $data);

                if (CustomerAccount::query()->where('customer_id', $customer->id)->exists()) {
                    throw ValidationException::withMessages([
                        'email' => ['This customer account is already linked to portal credentials.'],
                    ]);
                }

                $plainToken = Str::random(64);
                $minutes = (int) config('customer_portal.verification_expires_minutes', 60);

                $account = CustomerAccount::query()->create([
                    'organization_id' => $organization->id,
                    'customer_id' => $customer->id,
                    'email' => $email,
                    'password' => $data['password'],
                    'email_verified_at' => null,
                    'email_verification_token' => hash('sha256', $plainToken),
                    'email_verification_expires_at' => now()->addMinutes($minutes),
                ]);

                return [
                    'account' => $account->fresh(['customer.addresses']),
                    'verification_token' => $plainToken,
                ];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw ValidationException::withMessages([
                    'email' => ['This email is already registered.'],
                ]);
            }

            throw $e;
        }
    }

    /**
     * @param  array{name: string, email: string, password: string, phone?: ?string}  $data
     */
    private function resolveOrCreateCustomer(Organization $organization, string $email, array $data): Customer
    {
        $matches = Customer::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)
            ->get();

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'email' => ['Multiple customer records match this email. Contact support.'],
            ]);
        }

        if ($matches->count() === 1) {
            $existing = $matches->first();

            if ($existing->is_walk_in) {
                throw ValidationException::withMessages([
                    'email' => ['Cannot register the walk-in customer.'],
                ]);
            }

            // Link only — do not overwrite CRM name/phone/type.
            return $existing->loadMissing(['addresses', 'group']);
        }

        return $this->customers->create($organization->id, [
            'name' => $data['name'],
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'type' => CustomerType::Registered->value,
            'is_active' => true,
        ]);
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';
        $driverCode = (string) ($e->errorInfo[1] ?? '');
        $message = $e->getMessage();

        return $sqlState === '23000'
            || $driverCode === '19'
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
    }
}

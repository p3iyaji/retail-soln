<?php

namespace Modules\CustomerPortal\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\Customers\Models\Customer;
use Modules\Customers\Models\CustomerAddress;
use Modules\Customers\Services\CustomerService;

class PortalAccountService
{
    public function __construct(
        private readonly CustomerService $customers,
    ) {}

    /**
     * @param  array{name?: string, phone?: ?string}  $data
     */
    public function updateProfile(CustomerAccount $account, array $data): CustomerAccount
    {
        $customer = $account->customer;
        if ($customer === null) {
            throw ValidationException::withMessages([
                'customer' => ['Customer profile is missing.'],
            ]);
        }

        $payload = [];
        if (array_key_exists('name', $data)) {
            $payload['name'] = $data['name'];
        }
        if (array_key_exists('phone', $data)) {
            $payload['phone'] = $data['phone'];
        }

        if ($payload !== []) {
            $this->customers->update($customer, $account->organization_id, $payload);
        }

        return $account->fresh(['customer.addresses']);
    }

    /**
     * @param  array{
     *   type?: string,
     *   line1: string,
     *   line2?: ?string,
     *   city?: ?string,
     *   region?: ?string,
     *   postal_code?: ?string,
     *   country?: ?string,
     *   is_default?: bool,
     * }  $data
     */
    public function createAddress(CustomerAccount $account, array $data): CustomerAddress
    {
        $customer = $this->requireCustomer($account);

        return DB::transaction(function () use ($account, $customer, $data) {
            $isDefault = (bool) ($data['is_default'] ?? false);
            if ($isDefault) {
                $this->clearDefaultAddresses($customer);
            } elseif (! $customer->addresses()->where('is_default', true)->exists()) {
                $isDefault = true;
            }

            return CustomerAddress::query()->create([
                'organization_id' => $account->organization_id,
                'customer_id' => $customer->id,
                'type' => $data['type'] ?? 'shipping',
                'line1' => $data['line1'],
                'line2' => $data['line2'] ?? null,
                'city' => $data['city'] ?? null,
                'region' => $data['region'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'country' => $data['country'] ?? null,
                'is_default' => $isDefault,
            ]);
        });
    }

    /**
     * @param  array{
     *   type?: string,
     *   line1?: string,
     *   line2?: ?string,
     *   city?: ?string,
     *   region?: ?string,
     *   postal_code?: ?string,
     *   country?: ?string,
     *   is_default?: bool,
     * }  $data
     */
    public function updateAddress(CustomerAccount $account, CustomerAddress $address, array $data): CustomerAddress
    {
        $this->assertOwnsAddress($account, $address);
        $customer = $this->requireCustomer($account);

        return DB::transaction(function () use ($customer, $address, $data) {
            if (array_key_exists('type', $data)) {
                $address->type = $data['type'];
            }
            if (array_key_exists('line1', $data)) {
                $address->line1 = $data['line1'];
            }
            if (array_key_exists('line2', $data)) {
                $address->line2 = $data['line2'];
            }
            if (array_key_exists('city', $data)) {
                $address->city = $data['city'];
            }
            if (array_key_exists('region', $data)) {
                $address->region = $data['region'];
            }
            if (array_key_exists('postal_code', $data)) {
                $address->postal_code = $data['postal_code'];
            }
            if (array_key_exists('country', $data)) {
                $address->country = $data['country'];
            }

            if (array_key_exists('is_default', $data)) {
                if ($data['is_default']) {
                    $this->clearDefaultAddresses($customer);
                    $address->is_default = true;
                } elseif ($address->is_default) {
                    $this->promoteAnotherDefault($customer, $address);
                    $address->is_default = false;
                }
            }

            $address->save();

            return $address->fresh();
        });
    }

    public function deleteAddress(CustomerAccount $account, CustomerAddress $address): void
    {
        $this->assertOwnsAddress($account, $address);
        $customer = $this->requireCustomer($account);

        DB::transaction(function () use ($customer, $address) {
            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                $next = $customer->addresses()->orderBy('id')->first();
                if ($next !== null) {
                    $next->is_default = true;
                    $next->save();
                }
            }
        });
    }

    private function requireCustomer(CustomerAccount $account): Customer
    {
        $customer = $account->customer;
        if ($customer === null) {
            throw ValidationException::withMessages([
                'customer' => ['Customer profile is missing.'],
            ]);
        }

        return $customer;
    }

    private function assertOwnsAddress(CustomerAccount $account, CustomerAddress $address): void
    {
        if ((int) $address->customer_id !== (int) $account->customer_id) {
            abort(404);
        }
    }

    private function clearDefaultAddresses(Customer $customer): void
    {
        $customer->addresses()->where('is_default', true)->update(['is_default' => false]);
    }

    private function promoteAnotherDefault(Customer $customer, CustomerAddress $current): void
    {
        $other = $customer->addresses()
            ->where('id', '!=', $current->id)
            ->orderBy('id')
            ->first();

        if ($other === null) {
            throw ValidationException::withMessages([
                'isDefault' => ['At least one address must remain the default.'],
            ]);
        }

        $other->is_default = true;
        $other->save();
    }
}

<?php

namespace Modules\Customers\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Customers\Enums\CustomerType;
use Modules\Customers\Models\Customer;
use Modules\Customers\Models\CustomerGroup;
use RuntimeException;

class CustomerService
{
    public function ensureWalkIn(int $organizationId): Customer
    {
        return DB::transaction(function () use ($organizationId) {
            $existing = Customer::query()
                ->where('organization_id', $organizationId)
                ->where('is_walk_in', true)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing->loadMissing(['addresses', 'group']);
            }

            try {
                $customer = new Customer;
                $customer->organization_id = $organizationId;
                $customer->customer_group_id = null;
                $customer->type = CustomerType::WalkIn;
                $customer->name = 'Walk-in';
                $customer->phone = null;
                $customer->email = null;
                $customer->account_number = 'WALK-IN';
                $customer->is_walk_in = true;
                $customer->credit_limit = '0.0000';
                $customer->balance = '0.0000';
                $customer->loyalty_ref = null;
                $customer->notes = null;
                $customer->is_active = true;
                $customer->save();

                return $customer->fresh(['addresses', 'group']);
            } catch (QueryException $e) {
                $existing = Customer::query()
                    ->where('organization_id', $organizationId)
                    ->where('is_walk_in', true)
                    ->first();

                if ($existing !== null) {
                    return $existing->loadMissing(['addresses', 'group']);
                }

                throw $e;
            }
        });
    }

    /**
     * @param  array{
     *   name: string,
     *   type?: string,
     *   phone?: ?string,
     *   email?: ?string,
     *   account_number?: ?string,
     *   customer_group_id?: ?int,
     *   credit_limit?: string,
     *   balance?: string,
     *   loyalty_ref?: ?string,
     *   notes?: ?string,
     *   is_active?: bool,
     *   addresses?: list<array<string, mixed>>,
     * }  $data
     */
    public function create(int $organizationId, array $data): Customer
    {
        $this->ensureWalkIn($organizationId);

        return DB::transaction(function () use ($organizationId, $data) {
            $type = $data['type'] ?? CustomerType::Registered->value;
            if ($type === CustomerType::WalkIn->value) {
                throw new RuntimeException('Cannot create additional Walk-in customers.');
            }

            $customer = Customer::query()->create([
                'organization_id' => $organizationId,
                'customer_group_id' => $data['customer_group_id'] ?? null,
                'type' => $type,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'account_number' => $data['account_number'] ?? ('TMP-'.uniqid()),
                'credit_limit' => $data['credit_limit'] ?? '0.0000',
                'balance' => $data['balance'] ?? '0.0000',
                'loyalty_ref' => $data['loyalty_ref'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (empty($data['account_number'])) {
                $customer->account_number = 'C-'.$customer->id;
                $customer->save();
            }

            $this->syncAddresses($customer, $organizationId, $data);

            return $customer->fresh(['addresses', 'group']);
        });
    }

    /**
     * @param  array{
     *   name?: string,
     *   type?: string,
     *   phone?: ?string,
     *   email?: ?string,
     *   account_number?: ?string,
     *   customer_group_id?: ?int,
     *   credit_limit?: string,
     *   balance?: string,
     *   loyalty_ref?: ?string,
     *   notes?: ?string,
     *   is_active?: bool,
     *   addresses?: list<array<string, mixed>>,
     * }  $data
     */
    public function update(Customer $customer, int $organizationId, array $data): Customer
    {
        if ($customer->is_walk_in) {
            if (array_key_exists('name', $data) && $data['name'] !== $customer->name) {
                throw new RuntimeException('Cannot rename the Walk-in customer.');
            }
            if (array_key_exists('type', $data) && $data['type'] !== CustomerType::WalkIn->value) {
                throw new RuntimeException('Cannot change Walk-in customer type.');
            }
            if (array_key_exists('is_active', $data) && $data['is_active'] === false) {
                throw new RuntimeException('Cannot deactivate the Walk-in customer.');
            }
            if (array_key_exists('account_number', $data) && $data['account_number'] !== $customer->account_number) {
                throw new RuntimeException('Cannot change Walk-in account number.');
            }
        }

        if (($data['type'] ?? null) === CustomerType::WalkIn->value && ! $customer->is_walk_in) {
            throw new RuntimeException('Cannot convert a customer to Walk-in.');
        }

        return DB::transaction(function () use ($customer, $organizationId, $data) {
            foreach ([
                'name', 'type', 'phone', 'email', 'account_number', 'customer_group_id',
                'credit_limit', 'balance', 'loyalty_ref', 'notes', 'is_active',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $customer->{$field} = $data[$field];
                }
            }
            $customer->save();

            if (array_key_exists('addresses', $data)) {
                $this->syncAddresses($customer, $organizationId, $data);
            }

            return $customer->fresh(['addresses', 'group']);
        });
    }

    public function delete(Customer $customer): void
    {
        if ($customer->is_walk_in) {
            throw new RuntimeException('Cannot delete the Walk-in customer.');
        }

        $customer->delete();
    }

    /**
     * @param  array{name: string, code?: ?string}  $data
     */
    public function createGroup(int $organizationId, array $data): CustomerGroup
    {
        return CustomerGroup::query()->create([
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
        ]);
    }

    /**
     * @param  array{name?: string, code?: ?string}  $data
     */
    public function updateGroup(CustomerGroup $group, array $data): CustomerGroup
    {
        if (array_key_exists('name', $data)) {
            $group->name = $data['name'];
        }
        if (array_key_exists('code', $data)) {
            $group->code = $data['code'];
        }
        $group->save();

        return $group->fresh();
    }

    public function deleteGroup(CustomerGroup $group): void
    {
        Customer::query()
            ->where('customer_group_id', $group->id)
            ->update(['customer_group_id' => null]);

        $group->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncAddresses(Customer $customer, int $organizationId, array $data): void
    {
        if (! array_key_exists('addresses', $data)) {
            return;
        }

        $customer->addresses()->delete();
        $defaultAssigned = false;
        foreach ($data['addresses'] ?? [] as $address) {
            $isDefault = (bool) ($address['is_default'] ?? false) && ! $defaultAssigned;
            if ($isDefault) {
                $defaultAssigned = true;
            }
            $customer->addresses()->create([
                'organization_id' => $organizationId,
                'type' => $address['type'] ?? 'other',
                'line1' => $address['line1'],
                'line2' => $address['line2'] ?? null,
                'city' => $address['city'] ?? null,
                'region' => $address['region'] ?? null,
                'postal_code' => $address['postal_code'] ?? null,
                'country' => $address['country'] ?? null,
                'is_default' => $isDefault,
            ]);
        }
    }
}

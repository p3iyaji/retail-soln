<?php

namespace Modules\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;
use Modules\Purchasing\Models\Supplier;
use RuntimeException;

class SupplierService
{
    /**
     * @param  array{
     *   name: string,
     *   code?: ?string,
     *   is_active?: bool,
     *   contacts?: list<array<string, mixed>>,
     *   addresses?: list<array<string, mixed>>,
     *   product_mappings?: list<array<string, mixed>>,
     * }  $data
     */
    public function create(int $organizationId, array $data): Supplier
    {
        return DB::transaction(function () use ($organizationId, $data) {
            $supplier = Supplier::query()->create([
                'organization_id' => $organizationId,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->syncNested($supplier, $organizationId, $data);

            return $supplier->fresh(['contacts', 'addresses', 'productMappings.product']);
        });
    }

    /**
     * @param  array{
     *   name?: string,
     *   code?: ?string,
     *   is_active?: bool,
     *   contacts?: list<array<string, mixed>>,
     *   addresses?: list<array<string, mixed>>,
     *   product_mappings?: list<array<string, mixed>>,
     * }  $data
     */
    public function update(Supplier $supplier, int $organizationId, array $data): Supplier
    {
        return DB::transaction(function () use ($supplier, $organizationId, $data) {
            if (array_key_exists('name', $data)) {
                $supplier->name = $data['name'];
            }
            if (array_key_exists('code', $data)) {
                $supplier->code = $data['code'];
            }
            if (array_key_exists('is_active', $data)) {
                $supplier->is_active = $data['is_active'];
            }
            $supplier->save();

            if (array_key_exists('contacts', $data)
                || array_key_exists('addresses', $data)
                || array_key_exists('product_mappings', $data)) {
                $this->syncNested($supplier, $organizationId, $data);
            }

            return $supplier->fresh(['contacts', 'addresses', 'productMappings.product']);
        });
    }

    /**
     * Prefer soft deactivate when products or mappings reference the supplier.
     *
     * @return array{supplier: Supplier, deactivated: bool}
     */
    public function deleteOrDeactivate(Supplier $supplier): array
    {
        $hasProducts = $supplier->products()->exists();
        $hasMappings = $supplier->productMappings()->exists();

        if ($hasProducts || $hasMappings) {
            $supplier->is_active = false;
            $supplier->save();

            return [
                'supplier' => $supplier->fresh(['contacts', 'addresses', 'productMappings.product']),
                'deactivated' => true,
            ];
        }

        $supplier->delete();

        return ['supplier' => $supplier, 'deactivated' => false];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncNested(Supplier $supplier, int $organizationId, array $data): void
    {
        if (array_key_exists('contacts', $data)) {
            $supplier->contacts()->delete();
            $primaryAssigned = false;
            foreach ($data['contacts'] ?? [] as $contact) {
                $isPrimary = (bool) ($contact['is_primary'] ?? false) && ! $primaryAssigned;
                if ($isPrimary) {
                    $primaryAssigned = true;
                }
                $supplier->contacts()->create([
                    'organization_id' => $organizationId,
                    'name' => $contact['name'],
                    'email' => $contact['email'] ?? null,
                    'phone' => $contact['phone'] ?? null,
                    'title' => $contact['title'] ?? null,
                    'is_primary' => $isPrimary,
                ]);
            }
        }

        if (array_key_exists('addresses', $data)) {
            $supplier->addresses()->delete();
            $defaultAssigned = false;
            foreach ($data['addresses'] ?? [] as $address) {
                $isDefault = (bool) ($address['is_default'] ?? false) && ! $defaultAssigned;
                if ($isDefault) {
                    $defaultAssigned = true;
                }
                $supplier->addresses()->create([
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

        if (array_key_exists('product_mappings', $data)) {
            $supplier->productMappings()->delete();
            $seenProductIds = [];
            foreach ($data['product_mappings'] ?? [] as $mapping) {
                $productId = $this->resolveProductId($organizationId, $mapping);
                if (isset($seenProductIds[$productId])) {
                    throw new RuntimeException('Duplicate product mapping.');
                }
                $seenProductIds[$productId] = true;
                $supplier->productMappings()->create([
                    'organization_id' => $organizationId,
                    'product_id' => $productId,
                    'supplier_sku' => $mapping['supplier_sku'] ?? null,
                    'cost' => $mapping['cost'] ?? null,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $mapping
     */
    private function resolveProductId(int $organizationId, array $mapping): int
    {
        if (! empty($mapping['product_id'])) {
            $exists = Product::query()
                ->where('organization_id', $organizationId)
                ->where('id', $mapping['product_id'])
                ->exists();
            if (! $exists) {
                throw new RuntimeException('Unknown product for mapping.');
            }

            return (int) $mapping['product_id'];
        }

        if (! empty($mapping['product_sku'])) {
            $product = Product::query()
                ->where('organization_id', $organizationId)
                ->where('sku', $mapping['product_sku'])
                ->first();
            if ($product === null) {
                throw new RuntimeException('Unknown product SKU for mapping.');
            }

            return $product->id;
        }

        throw new RuntimeException('Product mapping requires productId or productSku.');
    }
}

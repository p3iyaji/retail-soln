<?php

namespace Modules\Sync\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Modules\Sync\Models\Device;
use RuntimeException;

class DeviceService
{
    /**
     * @return LengthAwarePaginator<int, Device>
     */
    public function list(int $organizationId, int $storeId, int $perPage = 50): LengthAwarePaginator
    {
        return Device::query()
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function register(
        int $organizationId,
        int $storeId,
        string $uuid,
        string $name,
        bool $enabled = true,
    ): Device {
        $uuid = strtolower($uuid);

        try {
            return Device::query()->create([
                'organization_id' => $organizationId,
                'store_id' => $storeId,
                'uuid' => $uuid,
                'name' => $name,
                'enabled' => $enabled,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueDeviceViolation($e)) {
                throw new RuntimeException('A device with this UUID is already registered.');
            }

            throw $e;
        }
    }

    /**
     * @param  array{name?: string, enabled?: bool}  $attributes
     */
    public function update(Device $device, array $attributes): Device
    {
        $fill = [];
        if (array_key_exists('name', $attributes)) {
            $fill['name'] = $attributes['name'];
        }
        if (array_key_exists('enabled', $attributes)) {
            $fill['enabled'] = (bool) $attributes['enabled'];
        }

        if ($fill !== []) {
            $device->forceFill($fill)->save();
        }

        return $device->refresh();
    }

    private function isUniqueDeviceViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'devices_organization_id_uuid')
            || (str_contains($message, 'unique') && str_contains($message, 'uuid'));
    }
}

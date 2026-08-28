<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Str;
use Modules\Sales\Enums\TerminalStatus;
use Modules\Sales\Models\Terminal;

class TerminalService
{
    public function resolveOrCreate(int $organizationId, int $storeId, string $name): Terminal
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new \RuntimeException('Terminal name is required.');
        }

        if (strlen($trimmed) > 64) {
            throw new \RuntimeException('Terminal name must be at most 64 characters.');
        }

        $existing = Terminal::query()
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
            ->where('name', $trimmed)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Terminal::query()->create([
            'organization_id' => $organizationId,
            'store_id' => $storeId,
            'uuid' => (string) Str::uuid(),
            'name' => $trimmed,
            'status' => TerminalStatus::Active,
        ]);
    }
}

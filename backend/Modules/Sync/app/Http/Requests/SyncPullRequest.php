<?php

namespace Modules\Sync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;

class SyncPullRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user?->hasPermission('sales.create') !== true) {
            return false;
        }

        $storeId = $this->resolveStoreId();
        if ($storeId === null) {
            return false;
        }

        return $user->canAccessStore($storeId);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'deviceUuid' => ['sometimes', 'nullable', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function storeId(): int
    {
        $storeId = $this->resolveStoreId();
        if ($storeId === null) {
            abort(422, 'X-Store-Id header is required.');
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

    public function cursor(): ?string
    {
        $cursor = $this->query('cursor');
        if ($cursor === null || $cursor === '') {
            return null;
        }

        return (string) $cursor;
    }

    public function deviceUuid(): string
    {
        $fromQuery = $this->query('deviceUuid');
        if (is_string($fromQuery) && $fromQuery !== '') {
            $this->assertUuid($fromQuery);

            return $fromQuery;
        }

        $fromHeader = $this->header('X-Device-Uuid');
        if (is_string($fromHeader) && $fromHeader !== '') {
            $this->assertUuid($fromHeader);

            return $fromHeader;
        }

        abort(422, 'deviceUuid query parameter or X-Device-Uuid header is required.');
    }

    private function assertUuid(string $value): void
    {
        if (! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value)) {
            abort(422, 'deviceUuid must be a valid UUID.');
        }
    }

    public function limit(): ?int
    {
        if (! $this->has('limit')) {
            return null;
        }

        return (int) $this->query('limit');
    }

    private function resolveStoreId(): ?int
    {
        $header = $this->header('X-Store-Id');
        if ($header === null || $header === '' || ! ctype_digit((string) $header)) {
            return null;
        }

        return (int) $header;
    }
}

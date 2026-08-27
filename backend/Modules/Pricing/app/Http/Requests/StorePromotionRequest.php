<?php

namespace Modules\Pricing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;
use Modules\Pricing\Enums\PromotionRuleType;

class StorePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('promotions.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;
        $types = array_column(PromotionRuleType::cases(), 'value');
        $decimal = ['regex:/^\d+(\.\d{1,4})?$/'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'storeId' => [
                'nullable',
                'integer',
                Rule::exists('stores', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date', 'after_or_equal:startsAt'],
            'isActive' => ['sometimes', 'boolean'],
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.type' => ['required', 'string', Rule::in($types)],
            'rules.*.value' => ['required_unless:rules.*.type,bogo', ...$decimal],
            'rules.*.productVariantId' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'rules.*.buyQty' => ['required_if:rules.*.type,bogo', ...$decimal],
            'rules.*.getQty' => ['required_if:rules.*.type,bogo', ...$decimal],
            'rules.*.getDiscountPercent' => ['required_if:rules.*.type,bogo', ...$decimal],
        ];
    }

    /**
     * @return array{
     *   name: string,
     *   store_id: int|null,
     *   starts_at: string|null,
     *   ends_at: string|null,
     *   is_active: bool,
     *   rules: list<array<string, mixed>>
     * }
     */
    public function attributesPayload(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'store_id' => $this->filled('storeId') ? $this->integer('storeId') : null,
            'starts_at' => $this->filled('startsAt') ? $this->string('startsAt')->toString() : null,
            'ends_at' => $this->filled('endsAt') ? $this->string('endsAt')->toString() : null,
            'is_active' => $this->boolean('isActive', true),
            'rules' => collect($this->input('rules', []))->map(fn (array $r) => [
                'type' => $r['type'],
                'value' => $r['value'] ?? '0',
                'product_variant_id' => $r['productVariantId'] ?? null,
                'buy_qty' => $r['buyQty'] ?? null,
                'get_qty' => $r['getQty'] ?? null,
                'get_discount_percent' => $r['getDiscountPercent'] ?? null,
            ])->all(),
        ];
    }
}

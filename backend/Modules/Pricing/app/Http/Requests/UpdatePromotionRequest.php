<?php

namespace Modules\Pricing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Organization\Services\OrganizationService;
use Modules\Pricing\Enums\PromotionRuleType;
use Modules\Pricing\Models\Promotion;

class UpdatePromotionRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'storeId' => [
                'nullable',
                'integer',
                Rule::exists('stores', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date'],
            'isActive' => ['sometimes', 'boolean'],
            'rules' => ['sometimes', 'array', 'min:1'],
            'rules.*.type' => ['required_with:rules', 'string', Rule::in($types)],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->exists('endsAt') || ! $this->filled('endsAt')) {
                return;
            }

            /** @var Promotion|null $promotion */
            $promotion = $this->route('promotion');
            $startsAt = $this->filled('startsAt')
                ? $this->string('startsAt')->toString()
                : $promotion?->starts_at?->toIso8601String();

            if ($startsAt === null || $startsAt === '') {
                return;
            }

            $endsAt = $this->string('endsAt')->toString();
            if (strtotime($endsAt) < strtotime($startsAt)) {
                $validator->errors()->add('endsAt', 'The ends at must be a date after or equal to starts at.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesPayload(): array
    {
        $data = [];
        if ($this->has('name')) {
            $data['name'] = $this->string('name')->toString();
        }
        if ($this->exists('storeId')) {
            $data['store_id'] = $this->filled('storeId') ? $this->integer('storeId') : null;
        }
        if ($this->exists('startsAt')) {
            $data['starts_at'] = $this->filled('startsAt') ? $this->string('startsAt')->toString() : null;
        }
        if ($this->exists('endsAt')) {
            $data['ends_at'] = $this->filled('endsAt') ? $this->string('endsAt')->toString() : null;
        }
        if ($this->has('isActive')) {
            $data['is_active'] = $this->boolean('isActive');
        }
        if ($this->has('rules')) {
            $data['rules'] = collect($this->input('rules', []))->map(fn (array $r) => [
                'type' => $r['type'],
                'value' => $r['value'] ?? '0',
                'product_variant_id' => $r['productVariantId'] ?? null,
                'buy_qty' => $r['buyQty'] ?? null,
                'get_qty' => $r['getQty'] ?? null,
                'get_discount_percent' => $r['getDiscountPercent'] ?? null,
            ])->all();
        }

        return $data;
    }
}

<?php

namespace Modules\Products\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Http\Resources\BrandResource;
use Modules\Products\Http\Resources\CategoryResource;
use Modules\Products\Http\Resources\UnitResource;
use Modules\Products\Models\Brand;
use Modules\Products\Models\Category;
use Modules\Products\Models\Unit;

class CatalogController
{
    public function categories(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);

        $items = Category::query()
            ->where('organization_id', $organizations->current()->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => CategoryResource::collection($items),
        ]);
    }

    public function storeCategory(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertManage($request);
        $organizationId = $organizations->current()->id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('categories', 'slug')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'parentId' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $category = Category::query()->create([
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'parent_id' => $data['parentId'] ?? null,
            'is_active' => $data['isActive'] ?? true,
        ]);

        return response()->json(['data' => new CategoryResource($category)], 201);
    }

    public function brands(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);

        $items = Brand::query()
            ->where('organization_id', $organizations->current()->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => BrandResource::collection($items),
        ]);
    }

    public function storeBrand(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertManage($request);
        $organizationId = $organizations->current()->id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('brands', 'slug')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $brand = Brand::query()->create([
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'is_active' => $data['isActive'] ?? true,
        ]);

        return response()->json(['data' => new BrandResource($brand)], 201);
    }

    public function units(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);

        $items = Unit::query()
            ->where('organization_id', $organizations->current()->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => UnitResource::collection($items),
        ]);
    }

    public function storeUnit(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertManage($request);
        $organizationId = $organizations->current()->id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abbreviation' => [
                'required',
                'string',
                'max:20',
                Rule::unique('units', 'abbreviation')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $unit = Unit::query()->create([
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'abbreviation' => $data['abbreviation'],
            'is_active' => $data['isActive'] ?? true,
        ]);

        return response()->json(['data' => new UnitResource($unit)], 201);
    }

    private function assertView(Request $request): void
    {
        $user = $request->user();
        if ($user?->hasPermission('catalog.view') !== true
            && $user?->hasPermission('products.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function assertManage(Request $request): void
    {
        if ($request->user()?->hasPermission('products.manage') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }
}

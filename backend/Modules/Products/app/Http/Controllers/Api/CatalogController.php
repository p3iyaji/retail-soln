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

        $query = Category::query()
            ->where('organization_id', $organizations->current()->id)
            ->orderBy('name');

        if ($search = $request->string('q')->trim()->toString()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('activeOnly')) {
            $query->where('is_active', true);
        }

        return response()->json([
            'data' => CategoryResource::collection($query->get()),
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

    public function showCategory(Request $request, Category $category, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);
        $this->assertOrg($category->organization_id, $organizations);

        return response()->json(['data' => new CategoryResource($category)]);
    }

    public function updateCategory(Request $request, Category $category, OrganizationService $organizations): JsonResponse
    {
        $this->assertManage($request);
        $this->assertOrg($category->organization_id, $organizations);
        $organizationId = $organizations->current()->id;

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('categories', 'slug')
                    ->where(fn ($q) => $q->where('organization_id', $organizationId))
                    ->ignore($category->id),
            ],
            'parentId' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)->where('id', '!=', $category->id)
                ),
            ],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $category->name = $data['name'];
            if (! array_key_exists('slug', $data)) {
                $category->slug = Str::slug($data['name']);
            }
        }
        if (array_key_exists('slug', $data)) {
            $category->slug = $data['slug'] ?: Str::slug($category->name);
        }
        if (array_key_exists('parentId', $data)) {
            $category->parent_id = $data['parentId'];
        }
        if (array_key_exists('isActive', $data)) {
            $category->is_active = $data['isActive'];
        }

        $category->save();

        return response()->json(['data' => new CategoryResource($category->fresh())]);
    }

    public function destroyCategory(Request $request, Category $category, OrganizationService $organizations): JsonResponse
    {
        $this->assertManage($request);
        $this->assertOrg($category->organization_id, $organizations);

        if ($category->products()->exists()) {
            $category->is_active = false;
            $category->save();

            return response()->json([
                'data' => new CategoryResource($category->fresh()),
                'meta' => ['deactivated' => true],
            ]);
        }

        $category->delete();

        return response()->json(null, 204);
    }

    public function brands(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);

        $query = Brand::query()
            ->where('organization_id', $organizations->current()->id)
            ->orderBy('name');

        if ($search = $request->string('q')->trim()->toString()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('activeOnly')) {
            $query->where('is_active', true);
        }

        return response()->json([
            'data' => BrandResource::collection($query->get()),
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

    public function showBrand(Request $request, Brand $brand, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);
        $this->assertOrg($brand->organization_id, $organizations);

        return response()->json(['data' => new BrandResource($brand)]);
    }

    public function updateBrand(Request $request, Brand $brand, OrganizationService $organizations): JsonResponse
    {
        $this->assertManage($request);
        $this->assertOrg($brand->organization_id, $organizations);
        $organizationId = $organizations->current()->id;

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('brands', 'slug')
                    ->where(fn ($q) => $q->where('organization_id', $organizationId))
                    ->ignore($brand->id),
            ],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $brand->name = $data['name'];
            if (! array_key_exists('slug', $data)) {
                $brand->slug = Str::slug($data['name']);
            }
        }
        if (array_key_exists('slug', $data)) {
            $brand->slug = $data['slug'] ?: Str::slug($brand->name);
        }
        if (array_key_exists('isActive', $data)) {
            $brand->is_active = $data['isActive'];
        }

        $brand->save();

        return response()->json(['data' => new BrandResource($brand->fresh())]);
    }

    public function destroyBrand(Request $request, Brand $brand, OrganizationService $organizations): JsonResponse
    {
        $this->assertManage($request);
        $this->assertOrg($brand->organization_id, $organizations);

        if ($brand->products()->exists()) {
            $brand->is_active = false;
            $brand->save();

            return response()->json([
                'data' => new BrandResource($brand->fresh()),
                'meta' => ['deactivated' => true],
            ]);
        }

        $brand->delete();

        return response()->json(null, 204);
    }

    public function units(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);

        $query = Unit::query()
            ->where('organization_id', $organizations->current()->id)
            ->orderBy('name');

        if ($search = $request->string('q')->trim()->toString()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('abbreviation', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('activeOnly')) {
            $query->where('is_active', true);
        }

        return response()->json([
            'data' => UnitResource::collection($query->get()),
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

    public function showUnit(Request $request, Unit $unit, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);
        $this->assertOrg($unit->organization_id, $organizations);

        return response()->json(['data' => new UnitResource($unit)]);
    }

    public function updateUnit(Request $request, Unit $unit, OrganizationService $organizations): JsonResponse
    {
        $this->assertManage($request);
        $this->assertOrg($unit->organization_id, $organizations);
        $organizationId = $organizations->current()->id;

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'abbreviation' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('units', 'abbreviation')
                    ->where(fn ($q) => $q->where('organization_id', $organizationId))
                    ->ignore($unit->id),
            ],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $unit->name = $data['name'];
        }
        if (array_key_exists('abbreviation', $data)) {
            $unit->abbreviation = $data['abbreviation'];
        }
        if (array_key_exists('isActive', $data)) {
            $unit->is_active = $data['isActive'];
        }

        $unit->save();

        return response()->json(['data' => new UnitResource($unit->fresh())]);
    }

    public function destroyUnit(Request $request, Unit $unit, OrganizationService $organizations): JsonResponse
    {
        $this->assertManage($request);
        $this->assertOrg($unit->organization_id, $organizations);

        if ($unit->products()->exists()) {
            $unit->is_active = false;
            $unit->save();

            return response()->json([
                'data' => new UnitResource($unit->fresh()),
                'meta' => ['deactivated' => true],
            ]);
        }

        $unit->delete();

        return response()->json(null, 204);
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

    private function assertOrg(int $organizationId, OrganizationService $organizations): void
    {
        if ($organizationId !== $organizations->current()->id) {
            abort(404);
        }
    }
}

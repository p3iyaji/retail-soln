<?php

namespace Modules\Products\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Http\Requests\StoreProductRequest;
use Modules\Products\Http\Requests\UpdateProductRequest;
use Modules\Products\Http\Resources\ProductResource;
use Modules\Products\Models\Product;

class ProductController
{
    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->authorize($request, 'viewAny', Product::class);

        $organization = $organizations->current();
        $query = Product::query()
            ->with(['category', 'brand', 'unit', 'supplier', 'images'])
            ->where('organization_id', $organization->id)
            ->orderBy('name');

        if ($search = $request->string('q')->trim()->toString()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->filled('categoryId')) {
            $query->where('category_id', $request->integer('categoryId'));
        }

        $products = $query->paginate(perPage: (int) $request->integer('per_page', 15));

        return response()->json([
            'data' => ProductResource::collection($products->items()),
            'meta' => [
                'currentPage' => $products->currentPage(),
                'lastPage' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function store(
        StoreProductRequest $request,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->authorize($request, 'create', Product::class);

        $organization = $organizations->current();

        $product = DB::transaction(function () use ($request, $organization) {
            $product = Product::query()->create([
                ...$request->productAttributes(),
                'organization_id' => $organization->id,
            ]);

            foreach ($request->imageAttributes() as $image) {
                $product->images()->create($image);
            }

            return $product->load(['category', 'brand', 'unit', 'supplier', 'images']);
        });

        return response()->json([
            'data' => new ProductResource($product),
        ], 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorize($request, 'view', $product);
        $this->ensureCurrentOrg($product);

        $product->load(['category', 'brand', 'unit', 'supplier', 'images']);

        return response()->json([
            'data' => new ProductResource($product),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->authorize($request, 'update', $product);
        $this->ensureCurrentOrg($product);

        $product = DB::transaction(function () use ($request, $product) {
            $product->fill($request->productAttributes());
            $product->save();

            if ($request->hasImages()) {
                $product->images()->delete();
                foreach ($request->imageAttributes() as $image) {
                    $product->images()->create($image);
                }
            }

            return $product->fresh()->load(['category', 'brand', 'unit', 'supplier', 'images']);
        });

        return response()->json([
            'data' => new ProductResource($product),
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorize($request, 'delete', $product);
        $this->ensureCurrentOrg($product);

        $product->delete();

        return response()->json([
            'message' => 'Product deleted.',
        ]);
    }

    private function ensureCurrentOrg(Product $product): void
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        if ($product->organization_id !== $organizationId) {
            abort(404);
        }
    }

    private function authorize(Request $request, string $ability, mixed $model): void
    {
        if ($request->user()?->can($ability, $model) !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }
}

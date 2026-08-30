<?php

namespace Modules\Products\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Services\CatalogImageStorageService;

class ProductImageUploadController
{
    public function store(
        Request $request,
        OrganizationService $organizations,
        CatalogImageStorageService $storage,
    ): JsonResponse {
        if ($request->user()?->hasPermission('products.manage') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $validated = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
        ]);

        $stored = $storage->store(
            $validated['image'],
            $organizations->current()->id,
        );

        return response()->json(['data' => $stored], 201);
    }
}

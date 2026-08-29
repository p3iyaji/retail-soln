<?php

namespace Modules\Products\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Http\Resources\ProductImportResource;
use Modules\Products\Http\Resources\ProductResource;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImport;
use Modules\Products\Services\ProductCsvService;
use Modules\Products\Services\ProductSearchService;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductCatalogToolsController
{
    public function search(
        Request $request,
        OrganizationService $organizations,
        ProductSearchService $search,
    ): JsonResponse {
        if ($request->user()?->can('viewAny', Product::class) !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'categoryId' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $started = hrtime(true);
        $results = $search->search(
            $organizations->current()->id,
            $data['q'] ?? '',
            isset($data['categoryId']) ? (int) $data['categoryId'] : null,
            isset($data['limit']) ? (int) $data['limit'] : 25,
        );
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        return response()->json([
            'data' => ProductResource::collection($results),
            'meta' => [
                'count' => $results->count(),
                'elapsedMs' => round($elapsedMs, 2),
            ],
        ]);
    }

    public function export(
        Request $request,
        OrganizationService $organizations,
        ProductCsvService $csv,
    ): StreamedResponse {
        if ($request->user()?->can('viewAny', Product::class) !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $content = $csv->exportCsv($organizations->current()->id);

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, 'products-export.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function previewImport(
        Request $request,
        OrganizationService $organizations,
        ProductCsvService $csv,
    ): JsonResponse {
        if ($request->user()?->hasPermission('products.import') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
        ]);

        try {
            $preview = $csv->preview($request->file('file'), $organizations->current()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'totalRows' => $preview['totalRows'],
                'validCount' => $preview['validCount'],
                'errorCount' => count($preview['errors']),
                'errors' => array_slice($preview['errors'], 0, 100),
                'sampleRows' => array_slice($preview['rows'], 0, 5),
            ],
        ]);
    }

    public function import(
        Request $request,
        OrganizationService $organizations,
        ProductCsvService $csv,
    ): JsonResponse {
        if ($request->user()?->hasPermission('products.import') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
        ]);

        try {
            $import = $csv->startImport(
                $request->file('file'),
                $organizations->current()->id,
                $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new ProductImportResource($import),
        ], $import->async ? 202 : 201);
    }

    public function showImport(
        Request $request,
        ProductImport $import,
        OrganizationService $organizations,
    ): JsonResponse {
        if ($request->user()?->hasPermission('products.import') !== true
            && $request->user()?->hasPermission('products.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        if ($import->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        return response()->json([
            'data' => new ProductImportResource($import),
        ]);
    }
}

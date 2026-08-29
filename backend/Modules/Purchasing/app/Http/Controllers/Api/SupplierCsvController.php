<?php

namespace Modules\Purchasing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Purchasing\Http\Resources\SupplierImportResource;
use Modules\Purchasing\Models\SupplierImport;
use Modules\Purchasing\Services\SupplierCsvService;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierCsvController
{
    public function export(
        Request $request,
        OrganizationService $organizations,
        SupplierCsvService $csv,
    ): StreamedResponse {
        $this->assertManage($request);

        $content = $csv->exportCsv($organizations->current()->id);

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, 'suppliers-export.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function previewImport(
        Request $request,
        OrganizationService $organizations,
        SupplierCsvService $csv,
    ): JsonResponse {
        $this->assertManage($request);

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
        SupplierCsvService $csv,
    ): JsonResponse {
        $this->assertManage($request);

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
            'data' => new SupplierImportResource($import),
        ], $import->async ? 202 : 201);
    }

    public function showImport(
        Request $request,
        SupplierImport $import,
        OrganizationService $organizations,
    ): JsonResponse {
        $user = $request->user();
        if ($user?->hasPermission('suppliers.manage') !== true
            && $user?->hasPermission('suppliers.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        if ($import->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        return response()->json([
            'data' => new SupplierImportResource($import),
        ]);
    }

    private function assertManage(Request $request): void
    {
        if ($request->user()?->hasPermission('suppliers.manage') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }
}

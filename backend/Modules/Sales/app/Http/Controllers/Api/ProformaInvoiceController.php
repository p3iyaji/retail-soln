<?php

namespace Modules\Sales\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Http\Requests\ConvertProformaInvoiceRequest;
use Modules\Sales\Http\Requests\StoreProformaInvoiceRequest;
use Modules\Sales\Http\Requests\UpdateProformaInvoiceRequest;
use Modules\Sales\Http\Resources\InvoiceResource;
use Modules\Sales\Http\Resources\ProformaInvoiceResource;
use Modules\Sales\Http\Resources\SaleResource;
use Modules\Sales\Models\ProformaInvoice;
use Modules\Sales\Services\IdempotencyConflictException;
use Modules\Sales\Services\ProformaConversionService;
use Modules\Sales\Services\ProformaInvoiceService;
use RuntimeException;

class ProformaInvoiceController
{
    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        if ($request->user()?->hasPermission('invoices.proforma.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $organizationId = $organizations->current()->id;

        $query = ProformaInvoice::query()
            ->where('organization_id', $organizationId)
            ->with(['customer', 'store', 'items.productVariant'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('storeId')) {
            $query->where('store_id', (int) $request->integer('storeId'));
        }

        if ($request->filled('customerId')) {
            $query->where('customer_id', (int) $request->integer('customerId'));
        }

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $paginator = $query->paginate(perPage: $perPage);

        return response()->json([
            'data' => ProformaInvoiceResource::collection($paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'perPage' => $paginator->perPage(),
            ],
        ]);
    }

    public function store(
        StoreProformaInvoiceRequest $request,
        OrganizationService $organizations,
        ProformaInvoiceService $service,
    ): JsonResponse {
        try {
            $proforma = $service->create(
                $organizations->current()->id,
                $request->user()->id,
                $request->proformaAttributes(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new ProformaInvoiceResource($proforma)], 201);
    }

    public function show(
        Request $request,
        ProformaInvoice $proformaInvoice,
        OrganizationService $organizations,
        ProformaInvoiceService $service,
    ): JsonResponse {
        if ($request->user()?->hasPermission('invoices.proforma.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        if ($proformaInvoice->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        $service->refreshExpiry($proformaInvoice);
        $proformaInvoice->load(['customer', 'store', 'items.productVariant', 'creator', 'issuer']);

        return response()->json(['data' => new ProformaInvoiceResource($proformaInvoice)]);
    }

    public function update(
        UpdateProformaInvoiceRequest $request,
        ProformaInvoice $proformaInvoice,
        OrganizationService $organizations,
        ProformaInvoiceService $service,
    ): JsonResponse {
        if ($proformaInvoice->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        try {
            $proforma = $service->update($proformaInvoice, $request->proformaAttributes());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new ProformaInvoiceResource($proforma)]);
    }

    public function issue(
        Request $request,
        ProformaInvoice $proformaInvoice,
        OrganizationService $organizations,
        ProformaInvoiceService $service,
    ): JsonResponse {
        if ($request->user()?->hasPermission('invoices.proforma.issue') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        if ($proformaInvoice->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        try {
            $proforma = $service->issue($proformaInvoice, $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new ProformaInvoiceResource($proforma)]);
    }

    public function cancel(
        Request $request,
        ProformaInvoice $proformaInvoice,
        OrganizationService $organizations,
        ProformaInvoiceService $service,
    ): JsonResponse {
        if ($request->user()?->hasPermission('invoices.proforma.cancel') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        if ($proformaInvoice->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        try {
            $proforma = $service->cancel($proformaInvoice);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new ProformaInvoiceResource($proforma)]);
    }

    public function convert(
        ConvertProformaInvoiceRequest $request,
        ProformaInvoice $proformaInvoice,
        OrganizationService $organizations,
        ProformaConversionService $conversion,
    ): JsonResponse {
        if ($proformaInvoice->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        $attrs = $request->convertAttributes();
        $honorPrices = ($attrs['honorPrices'] ?? false)
            && $request->user()?->hasPermission('invoices.proforma.honor-prices') === true;

        try {
            $result = $conversion->convert(
                $proformaInvoice,
                $request->user()->id,
                $attrs,
                $honorPrices,
            );
        } catch (IdempotencyConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'proforma' => new ProformaInvoiceResource($result['proforma']),
                'sale' => $result['sale'] === null ? null : new SaleResource($result['sale']),
                'invoice' => $result['invoice'] === null ? null : new InvoiceResource($result['invoice']),
            ],
        ]);
    }

    public function pdf(
        Request $request,
        ProformaInvoice $proformaInvoice,
        OrganizationService $organizations,
        ProformaInvoiceService $service,
    ): Response {
        if ($request->user()?->hasPermission('invoices.proforma.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        if ($proformaInvoice->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        $service->refreshExpiry($proformaInvoice);
        $proformaInvoice->load(['customer', 'store.organization', 'items.productVariant']);

        if (! in_array($proformaInvoice->status->value, ['issued', 'accepted', 'converted'], true)) {
            abort(422, 'PDF is available after the proforma is issued.');
        }

        $filename = ($proformaInvoice->number ?? 'proforma-'.$proformaInvoice->id).'.html';

        return response()->view('sales::proforma-document', [
            'proforma' => $proformaInvoice,
        ])->withHeaders([
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$filename.'"',
        ]);
    }
}

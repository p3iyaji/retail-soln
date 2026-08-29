<?php

namespace Modules\CustomerPortal\Services;

use App\Services\Payments\PaymentIntentResult;
use App\Services\Payments\PaymentRedirectUrl;
use App\Services\Payments\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Models\PortalOrderItem;
use Modules\Customers\Models\CustomerAddress;
use Modules\CustomerPortal\Support\PortalOrderStatuses;
use Modules\Inventory\Services\StockReservationService;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Products\Models\ProductVariant;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PortalCheckoutService
{
    /** @var list<string> */
    private const PAYMENT_STATUSES = ['awaiting_payment', 'paid', 'failed'];

    public const RESERVATION_REFERENCE_TYPE = 'portal_order';

    public function __construct(
        private readonly PortalCartService $cart,
        private readonly PortalStoreResolver $stores,
        private readonly PortalOrganizationResolver $organizations,
        private readonly PaymentService $payments,
        private readonly PaymentRedirectUrl $redirectUrls,
        private readonly StockReservationService $reservations,
        private readonly PortalOrderNotifier $orderNotifications,
    ) {}

    /**
     * @param  array{
     *   fulfilmentMode: string,
     *   storeId?: ?int,
     *   addressId?: ?int,
     *   contactPhone?: ?string,
     * }  $input
     * @return array{lines: list<array<string, mixed>>, totals: array<string, string>, fulfilment: array<string, mixed>, store: array{id: int, name: string}}
     */
    public function preview(CustomerAccount $account, array $input): array
    {
        $organization = $this->organizations->resolve();
        $this->assertAccountOrg($account, $organization);

        $cartPayload = $this->cart->getCart($account, $input['storeId'] ?? null);
        if ($cartPayload['lines'] === []) {
            throw ValidationException::withMessages([
                'cart' => ['Cart is empty.'],
            ]);
        }

        $resolved = $this->resolveFulfilment($account, $organization, $input);
        $priced = $this->repriceLines(
            $organization,
            $resolved['store'],
            $cartPayload['lines'],
        );
        $totals = $this->computeTotals($priced['lines'], $resolved['deliveryFee']);

        return [
            'lines' => $priced['lines'],
            'totals' => $totals,
            'fulfilment' => [
                'mode' => $resolved['mode'],
                'addressId' => $resolved['address']?->id,
                'contactPhone' => $resolved['contactPhone'],
            ],
            'store' => [
                'id' => $resolved['store']->id,
                'name' => $resolved['store']->name,
            ],
        ];
    }

    /**
     * @param  array{
     *   fulfilmentMode: string,
     *   storeId?: ?int,
     *   addressId?: ?int,
     *   contactPhone?: ?string,
     *   paymentProvider: string,
     *   successUrl?: ?string,
     *   cancelUrl?: ?string,
     * }  $input
     * @return array{order: PortalOrder, payment: PaymentIntentResult, created: bool}
     */
    public function placeOrder(CustomerAccount $account, array $input, string $idempotencyKey): array
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw ValidationException::withMessages([
                'idempotencyKey' => ['Idempotency-Key must be between 1 and 64 characters.'],
            ]);
        }

        $organization = $this->organizations->resolve();
        $this->assertAccountOrg($account, $organization);

        $fingerprint = [
            'fulfilmentMode' => $input['fulfilmentMode'],
            'storeId' => $input['storeId'] ?? null,
            'addressId' => $input['addressId'] ?? null,
            'contactPhone' => $this->normalizePhone($input['contactPhone'] ?? null),
            'paymentProvider' => strtolower($input['paymentProvider']),
        ];

        $existing = PortalOrder::query()
            ->where('organization_id', $organization->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            $this->assertIdempotentFingerprint($existing, $fingerprint);

            return [
                'order' => $existing->load(['items', 'store']),
                'payment' => $this->paymentResultForOrder($existing, replay: true),
                'created' => false,
            ];
        }

        try {
            return DB::transaction(function () use ($account, $organization, $input, $idempotencyKey, $fingerprint) {
                $lockedExisting = PortalOrder::query()
                    ->where('organization_id', $organization->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($lockedExisting !== null) {
                    $this->assertIdempotentFingerprint($lockedExisting, $fingerprint);

                    return [
                        'order' => $lockedExisting->load(['items', 'store']),
                        'payment' => $this->paymentResultForOrder($lockedExisting, replay: true),
                        'created' => false,
                    ];
                }

                $items = $this->cart->lockedItems($account);
                if ($items->isEmpty()) {
                    throw ValidationException::withMessages([
                        'cart' => ['Cart is empty.'],
                    ]);
                }

                $resolved = $this->resolveFulfilment($account, $organization, $input);
                $store = $resolved['store'];

                $lines = [];
                foreach ($items as $item) {
                    $variant = $item->variant;
                    if (
                        $variant === null
                        || ! $variant->is_active
                        || $variant->product === null
                        || ! $variant->product->is_active
                    ) {
                        throw ValidationException::withMessages([
                            'cart' => ['Cart contains an unavailable product or variant.'],
                        ]);
                    }

                    $priced = $this->cart->priceLine(
                        $organization->id,
                        $variant->id,
                        $store->id,
                        (string) $item->quantity,
                    );

                    $lines[] = [
                        'variant' => $variant,
                        'qty' => $priced['qty'],
                        'priced' => $priced,
                    ];
                }

                $linePayloads = array_map(function (array $row) {
                    /** @var ProductVariant $variant */
                    $variant = $row['variant'];
                    $priced = $row['priced'];
                    $qty = $priced['qty'];
                    $unitNet = bccomp($qty, '0', 4) > 0 ? bcdiv($priced['netPrice'], $qty, 4) : '0.0000';
                    $unitGross = bccomp($qty, '0', 4) > 0 ? bcdiv($priced['grossPrice'], $qty, 4) : '0.0000';

                    return [
                        'variantId' => $variant->id,
                        'productName' => $variant->product?->name ?? $variant->name,
                        'variantName' => $variant->name,
                        'sku' => $variant->sku,
                        'qty' => $qty,
                        'unitListPrice' => $priced['listPrice'],
                        'unitNetPrice' => $unitNet,
                        'unitGrossPrice' => $unitGross,
                        'lineTax' => $priced['tax']['amount'],
                        'lineTotal' => $priced['lineTotal'],
                        'lineNet' => $priced['netPrice'],
                        'lineDiscount' => $priced['discountAmount'],
                    ];
                }, $lines);

                $totals = $this->computeTotals($linePayloads, $resolved['deliveryFee']);
                $provider = strtolower($input['paymentProvider']);
                $uuid = Uuid::uuid4()->toString();
                $orderNumber = $this->nextOrderNumber($organization->id);
                $paymentReference = 'po_'.Str::lower(Str::replace('-', '', $uuid));

                try {
                    $order = PortalOrder::query()->create([
                        'organization_id' => $organization->id,
                        'customer_account_id' => $account->id,
                        'customer_id' => $account->customer_id,
                        'uuid' => $uuid,
                        'order_number' => $orderNumber,
                        'status' => 'pending',
                        'payment_status' => 'awaiting_payment',
                        'fulfilment_mode' => $resolved['mode'],
                        'store_id' => $store->id,
                        'customer_address_id' => $resolved['address']?->id,
                        'contact_phone' => $resolved['contactPhone'],
                        'subtotal' => $totals['subtotal'],
                        'discount_total' => $totals['discountTotal'],
                        'tax_total' => $totals['taxTotal'],
                        'delivery_fee' => $totals['deliveryFee'],
                        'total' => $totals['grandTotal'],
                        'payment_provider' => $provider,
                        'payment_reference' => $paymentReference,
                        'idempotency_key' => $idempotencyKey,
                        'request_fingerprint' => $fingerprint,
                    ]);
                } catch (QueryException $e) {
                    if ($this->isUniqueIdempotencyViolation($e)) {
                        $existingOrder = PortalOrder::query()
                            ->where('organization_id', $organization->id)
                            ->where('idempotency_key', $idempotencyKey)
                            ->firstOrFail();
                        $this->assertIdempotentFingerprint($existingOrder, $fingerprint);

                        return [
                            'order' => $existingOrder->load(['items', 'store']),
                            'payment' => $this->paymentResultForOrder($existingOrder, replay: true),
                            'created' => false,
                        ];
                    }

                    throw $e;
                }

                foreach ($linePayloads as $line) {
                    PortalOrderItem::query()->create([
                        'portal_order_id' => $order->id,
                        'product_variant_id' => $line['variantId'],
                        'product_name' => $line['productName'],
                        'variant_name' => $line['variantName'],
                        'sku' => $line['sku'],
                        'quantity' => $line['qty'],
                        'unit_list_price' => $line['unitListPrice'],
                        'unit_net_price' => $line['unitNetPrice'],
                        'unit_gross_price' => $line['unitGrossPrice'],
                        'line_tax' => $line['lineTax'],
                        'line_total' => $line['lineTotal'],
                    ]);
                }

                $this->reserveOrderStock($order->fresh(['items']));

                $urls = $this->redirectUrls->resolve(
                    $input['successUrl'] ?? null,
                    $input['cancelUrl'] ?? null,
                    $order->id,
                    $paymentReference,
                );

                $intent = $this->payments->createIntent([
                    'provider' => $provider,
                    'reference' => $paymentReference,
                    'amount' => $totals['grandTotal'],
                    'email' => $account->email,
                    'description' => 'Portal order '.$orderNumber,
                    'metadata' => [
                        'orderId' => $order->id,
                        'orderNumber' => $orderNumber,
                    ],
                    'successUrl' => $urls['successUrl'],
                    'cancelUrl' => $urls['cancelUrl'],
                ]);

                $order->payment_reference = $intent->reference;
                $order->payment_redirect_url = $intent->redirectUrl;
                $order->save();

                $this->cart->clear($account);

                return [
                    'order' => $order->fresh(['items', 'store']),
                    'payment' => $intent,
                    'created' => true,
                ];
            });
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Insufficient available stock')) {
                throw ValidationException::withMessages([
                    'stock' => [$e->getMessage()],
                ]);
            }

            throw $e;
        }
    }

    public function confirmManualPayment(CustomerAccount $account, int $orderId): PortalOrder
    {
        $order = PortalOrder::query()
            ->where('customer_account_id', $account->id)
            ->where('id', $orderId)
            ->firstOrFail();

        if ($order->payment_provider !== 'manual') {
            throw ValidationException::withMessages([
                'payment' => ['Only manual payments can be confirmed this way.'],
            ]);
        }

        return $this->markPaymentStatus($order, 'paid');
    }

    /**
     * Customer self-cancel for unpaid orders; releases reserved stock.
     *
     * Order `status` becomes cancelled (primary). Payment enum stays
     * awaiting_payment|failed|paid — no new payment_status without migration.
     */
    public function cancelAwaitingPayment(CustomerAccount $account, int $orderId): PortalOrder
    {
        return DB::transaction(function () use ($account, $orderId) {
            /** @var PortalOrder $locked */
            $locked = PortalOrder::query()
                ->where('customer_account_id', $account->id)
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === PortalOrderStatuses::CANCELLED) {
                return $locked->load(['items', 'store']);
            }

            if (! $this->isUnpaidPendingHold($locked)) {
                throw ValidationException::withMessages([
                    'order' => ['Only unpaid pending orders can be cancelled.'],
                ]);
            }

            $locked->loadMissing('items');
            if ($locked->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'order' => ['Cannot cancel an order with no line items to release.'],
                ]);
            }

            $locked->status = PortalOrderStatuses::CANCELLED;
            $locked->save();

            $this->releaseOrderStock($locked->fresh(['items']));

            return $locked->fresh(['items', 'store']);
        });
    }

    /**
     * Cancel unpaid awaiting_payment / failed orders past reservation TTL and release stock.
     *
     * @return array{expired: int, failures: int}
     */
    public function expireUnpaidReservations(?int $ttlMinutes = null): array
    {
        $ttl = $ttlMinutes ?? (int) config('customer_portal.reservation_ttl_minutes', 60);
        if ($ttl < 1) {
            $ttl = 60;
        }

        $cutoff = now()->subMinutes($ttl);
        $expired = 0;
        $failures = 0;

        PortalOrder::query()
            ->whereIn('payment_status', ['awaiting_payment', 'failed'])
            ->where('status', PortalOrderStatuses::PENDING)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(50, function ($orders) use (&$expired, &$failures, $cutoff): void {
                foreach ($orders as $order) {
                    try {
                        DB::transaction(function () use ($order, &$expired, $cutoff): void {
                            /** @var PortalOrder $locked */
                            $locked = PortalOrder::query()->whereKey($order->id)->lockForUpdate()->first();
                            if ($locked === null) {
                                return;
                            }

                            if (! $this->isUnpaidPendingHold($locked)) {
                                return;
                            }

                            if ($locked->created_at !== null && $locked->created_at->gt($cutoff)) {
                                return;
                            }

                            $locked->loadMissing('items');
                            if ($locked->items->isEmpty()) {
                                throw new RuntimeException(
                                    'Cannot expire portal order #'.$locked->id.': no line items to release.',
                                );
                            }

                            $locked->status = PortalOrderStatuses::CANCELLED;
                            $locked->save();
                            $this->releaseOrderStock($locked->fresh(['items']));
                            $expired++;
                        });
                    } catch (\Throwable $e) {
                        $failures++;
                        Log::warning('portal.expire_unpaid_reservation_failed', [
                            'portal_order_id' => $order->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return ['expired' => $expired, 'failures' => $failures];
    }

    /**
     * Apply a webhook payment status for a known reference.
     *
     * @throws HttpException 409 when provider mismatches the order
     */
    public function applyPaymentReferenceStatus(string $reference, string $status, string $provider): ?PortalOrder
    {
        if (! in_array($status, self::PAYMENT_STATUSES, true)) {
            throw new HttpException(400, 'Unsupported payment status: '.$status);
        }

        $order = PortalOrder::query()
            ->where('payment_reference', $reference)
            ->first();

        if ($order === null) {
            return null;
        }

        if (strtolower((string) $order->payment_provider) !== strtolower($provider)) {
            throw new HttpException(409, 'Payment provider does not match this order.');
        }

        return $this->markPaymentStatus($order, $status);
    }

    public function markPaymentStatus(PortalOrder $order, string $status): PortalOrder
    {
        if (! in_array($status, self::PAYMENT_STATUSES, true)) {
            throw new HttpException(400, 'Unsupported payment status: '.$status);
        }

        $transitioned = false;

        $result = DB::transaction(function () use ($order, $status, &$transitioned) {
            /** @var PortalOrder $locked */
            $locked = PortalOrder::query()->where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($locked->payment_status === $status) {
                return $locked->load(['items', 'store']);
            }

            // Paid is terminal for payment; ignore failed (or other) after paid.
            if ($locked->payment_status === 'paid' && $status !== 'paid') {
                return $locked->load(['items', 'store']);
            }

            // Cancelled orders must not be revived by a late paid webhook.
            if ($status === 'paid' && $locked->status === 'cancelled') {
                return $locked->load(['items', 'store']);
            }

            $locked->payment_status = $status;
            if ($status === 'paid') {
                if ($locked->paid_at === null) {
                    $locked->paid_at = now();
                }
                if ($locked->status === 'pending') {
                    $locked->status = 'confirmed';
                }
            }
            $locked->save();
            $transitioned = true;

            return $locked->fresh(['items', 'store']);
        });

        if ($transitioned && $status === 'paid') {
            $this->orderNotifications->paid($result);
        }

        return $result;
    }

    private function paymentResultForOrder(PortalOrder $order, bool $replay = false): PaymentIntentResult
    {
        $provider = (string) $order->payment_provider;
        $redirect = $order->payment_redirect_url;

        if (
            ($redirect === null || $redirect === '')
            && in_array($provider, ['stripe', 'paystack'], true)
            && $order->payment_status === 'awaiting_payment'
        ) {
            $redirect = $this->redirectUrls->defaultSuccessUrl(
                $order->id,
                (string) $order->payment_reference,
            );
        }

        return new PaymentIntentResult(
            provider: $provider,
            reference: (string) $order->payment_reference,
            status: (string) $order->payment_status,
            redirectUrl: $redirect,
            requiresClientAction: $provider !== 'manual' && $order->payment_status === 'awaiting_payment',
            meta: $replay ? ['replay' => true] : [],
        );
    }

    /**
     * @param  array{
     *   fulfilmentMode: string,
     *   storeId?: ?int,
     *   addressId?: ?int,
     *   contactPhone?: ?string,
     * }  $input
     * @return array{mode: string, store: Store, address: ?CustomerAddress, contactPhone: ?string, deliveryFee: string}
     */
    private function resolveFulfilment(CustomerAccount $account, Organization $organization, array $input): array
    {
        $mode = $input['fulfilmentMode'];
        if (! in_array($mode, ['home_delivery', 'click_collect', 'store_pickup'], true)) {
            throw ValidationException::withMessages([
                'fulfilmentMode' => ['Invalid fulfilment mode.'],
            ]);
        }

        $contactPhone = $this->normalizePhone($input['contactPhone'] ?? $account->customer?->phone);

        if ($mode === 'home_delivery') {
            $addressId = $input['addressId'] ?? null;
            if ($addressId === null) {
                throw ValidationException::withMessages([
                    'addressId' => ['A delivery address is required for home delivery.'],
                ]);
            }

            $address = CustomerAddress::query()
                ->where('customer_id', $account->customer_id)
                ->where('id', $addressId)
                ->first();

            if ($address === null) {
                throw ValidationException::withMessages([
                    'addressId' => ['Delivery address not found.'],
                ]);
            }

            $store = $this->stores->resolve($organization, $input['storeId'] ?? null);
            if ($store === null) {
                throw ValidationException::withMessages([
                    'storeId' => ['No fulfilling store is available.'],
                ]);
            }

            $fee = (string) config('customer_portal.delivery_fee', '0.0000');
            if ($fee === '' || ! is_numeric($fee)) {
                $fee = '0.0000';
            }
            if (bccomp($fee, '0', 4) < 0) {
                throw ValidationException::withMessages([
                    'deliveryFee' => ['Delivery fee configuration cannot be negative.'],
                ]);
            }

            return [
                'mode' => $mode,
                'store' => $store,
                'address' => $address,
                'contactPhone' => $contactPhone,
                'deliveryFee' => bcadd($fee, '0', 4),
            ];
        }

        $storeId = $input['storeId'] ?? null;
        if ($storeId === null) {
            throw ValidationException::withMessages([
                'storeId' => ['A store is required for pickup / click & collect.'],
            ]);
        }

        $store = $this->stores->resolve($organization, (int) $storeId);
        if ($store === null) {
            throw ValidationException::withMessages([
                'storeId' => ['The selected store is invalid for this organization.'],
            ]);
        }

        return [
            'mode' => $mode,
            'store' => $store,
            'address' => null,
            'contactPhone' => $contactPhone,
            'deliveryFee' => '0.0000',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{lines: list<array<string, mixed>>}
     */
    private function repriceLines(Organization $organization, Store $store, array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $priced = $this->cart->priceLine(
                $organization->id,
                (int) $line['variantId'],
                $store->id,
                (string) $line['qty'],
            );
            $qty = $priced['qty'];
            $unitNet = bccomp($qty, '0', 4) > 0 ? bcdiv($priced['netPrice'], $qty, 4) : '0.0000';
            $unitGross = bccomp($qty, '0', 4) > 0 ? bcdiv($priced['grossPrice'], $qty, 4) : '0.0000';

            $out[] = [
                'id' => $line['id'] ?? null,
                'variantId' => $line['variantId'],
                'productId' => $line['productId'] ?? null,
                'productName' => $line['productName'],
                'variantName' => $line['variantName'],
                'sku' => $line['sku'],
                'qty' => $qty,
                'unitListPrice' => $priced['listPrice'],
                'unitNetPrice' => $unitNet,
                'unitGrossPrice' => $unitGross,
                'lineTax' => $priced['tax']['amount'],
                'lineDiscount' => $priced['discountAmount'],
                'lineTotal' => $priced['lineTotal'],
                'lineNet' => $priced['netPrice'],
            ];
        }

        return ['lines' => $out];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{subtotal: string, discountTotal: string, taxTotal: string, deliveryFee: string, grandTotal: string}
     */
    private function computeTotals(array $lines, string $deliveryFee): array
    {
        $subtotal = '0.0000';
        $taxTotal = '0.0000';
        $discountTotal = '0.0000';

        foreach ($lines as $line) {
            $lineNet = isset($line['lineNet'])
                ? (string) $line['lineNet']
                : bcmul((string) $line['unitNetPrice'], (string) $line['qty'], 4);
            $subtotal = bcadd($subtotal, $lineNet, 4);
            $taxTotal = bcadd($taxTotal, (string) $line['lineTax'], 4);
            if (isset($line['lineDiscount'])) {
                $discountTotal = bcadd($discountTotal, (string) $line['lineDiscount'], 4);
            }
        }

        $itemsGross = bcadd($subtotal, $taxTotal, 4);
        $grand = bcadd($itemsGross, $deliveryFee, 4);

        return [
            'subtotal' => $subtotal,
            'discountTotal' => $discountTotal,
            'taxTotal' => $taxTotal,
            'deliveryFee' => bcadd($deliveryFee, '0', 4),
            'grandTotal' => $grand,
        ];
    }

    /**
     * Dual-post available→reserved for order lines (same txn as place-order).
     */
    private function reserveOrderStock(PortalOrder $order): void
    {
        $order->loadMissing('items');
        $this->reservations->reserve([
            'organizationId' => (int) $order->organization_id,
            'locationId' => (int) $order->store_id,
            'lines' => $order->items->map(fn (PortalOrderItem $item) => [
                'productVariantId' => (int) $item->product_variant_id,
                'quantity' => (string) $item->quantity,
            ])->all(),
            'referenceType' => self::RESERVATION_REFERENCE_TYPE,
            'referenceId' => (int) $order->id,
            'notes' => 'Portal order '.$order->order_number,
        ]);
    }

    /**
     * Dual-post reserved→available; idempotent via movement UUIDs.
     */
    private function releaseOrderStock(PortalOrder $order): void
    {
        $order->loadMissing('items');
        if ($order->items->isEmpty()) {
            throw new RuntimeException(
                'Cannot release reservation: order #'.$order->id.' has no line items.',
            );
        }

        $this->reservations->release([
            'organizationId' => (int) $order->organization_id,
            'locationId' => (int) $order->store_id,
            'lines' => $order->items->map(fn (PortalOrderItem $item) => [
                'productVariantId' => (int) $item->product_variant_id,
                'quantity' => (string) $item->quantity,
            ])->all(),
            'referenceType' => self::RESERVATION_REFERENCE_TYPE,
            'referenceId' => (int) $order->id,
            'notes' => 'Release portal order '.$order->order_number,
        ]);
    }

    /**
     * Unpaid pending hold eligible for cancel / TTL release (not yet paid or fulfilled).
     */
    private function isUnpaidPendingHold(PortalOrder $order): bool
    {
        return in_array((string) $order->payment_status, ['awaiting_payment', 'failed'], true)
            && (string) $order->status === PortalOrderStatuses::PENDING;
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     */
    private function assertIdempotentFingerprint(PortalOrder $order, array $fingerprint): void
    {
        $stored = $order->request_fingerprint ?? [];
        foreach (['fulfilmentMode', 'storeId', 'addressId', 'contactPhone', 'paymentProvider'] as $key) {
            $left = $key === 'contactPhone'
                ? $this->normalizePhone($stored[$key] ?? null)
                : ($stored[$key] ?? null);
            $right = $fingerprint[$key] ?? null;
            if ($left != $right) {
                throw ValidationException::withMessages([
                    'idempotencyKey' => ['Idempotency-Key was already used with a different request body.'],
                ]);
            }
        }
    }

    /**
     * Allocate next order number under the current transaction (caller must hold a lock).
     */
    private function nextOrderNumber(int $organizationId): string
    {
        $last = PortalOrder::query()
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('order_number');

        $seq = 1;
        if (is_string($last) && preg_match('/-(\d+)$/', $last, $matches) === 1) {
            $seq = ((int) $matches[1]) + 1;
        }

        return 'PO-'.str_pad((string) $organizationId, 3, '0', STR_PAD_LEFT).'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    private function normalizePhone(mixed $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $trimmed = trim((string) $phone);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isUniqueIdempotencyViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'idempotency_key')
            || str_contains($message, 'portal_orders_organization_id_idempotency_key');
    }

    private function assertAccountOrg(CustomerAccount $account, Organization $organization): void
    {
        if ((int) $account->organization_id !== (int) $organization->id) {
            abort(403, 'Account does not belong to this portal organization.');
        }
    }
}

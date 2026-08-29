<?php

namespace Modules\CustomerPortal\Services;

use Illuminate\Support\Facades\Log;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\Notifications\Data\NotificationRecipient;
use Modules\Notifications\Services\NotificationService;
use Modules\Notifications\Support\NotificationTypes;
use Throwable;

/**
 * Customer-facing portal order notifications. Every entry point is called
 * after the owning transaction has committed and swallows its own failures:
 * an order is paid, ready, or shipped whether or not the message goes out.
 */
class PortalOrderNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function paid(PortalOrder $order): void
    {
        $this->send(
            $order,
            NotificationTypes::PORTAL_ORDER_PAID,
            'Payment received for order '.$order->order_number,
            'We have received your payment. Your order is confirmed and we will let you know when it is on its way.',
        );
    }

    public function readyForPickup(PortalOrder $order): void
    {
        $this->send(
            $order,
            NotificationTypes::PORTAL_ORDER_READY_FOR_PICKUP,
            'Order '.$order->order_number.' is ready for pickup',
            'Your order is packed and waiting at the store. Bring your order number when you collect it.',
        );
    }

    public function shipped(PortalOrder $order): void
    {
        $order->loadMissing('shipment');
        $tracking = $order->shipment?->tracking_number;
        $carrier = $order->shipment?->carrier_name;

        $this->send(
            $order,
            NotificationTypes::PORTAL_ORDER_SHIPPED,
            'Order '.$order->order_number.' is on its way',
            'Your order has left the store and is out for delivery.',
            array_filter([
                'carrier' => $carrier,
                'tracking' => $tracking,
            ], fn (?string $value) => $value !== null && $value !== ''),
        );
    }

    /**
     * @param  array<string, string>  $extraPayload
     */
    private function send(
        PortalOrder $order,
        string $type,
        string $subject,
        string $body,
        array $extraPayload = [],
    ): void {
        try {
            $order->loadMissing(['account.customer', 'store']);
            $account = $order->account;

            if ($account === null) {
                return;
            }

            $this->notifications->notify(
                type: $type,
                organizationId: (int) $order->organization_id,
                recipients: [NotificationRecipient::customerAccount(
                    (int) $account->id,
                    $account->email,
                    $order->contact_phone,
                    $account->customer?->name,
                )],
                subject: $subject,
                body: $body,
                storeId: (int) $order->store_id,
                payload: array_merge([
                    'orderNumber' => (string) $order->order_number,
                    'status' => (string) $order->status,
                    'total' => (string) $order->total,
                    'store' => (string) ($order->store?->name ?? ''),
                ], $extraPayload),
                relatedType: 'portal_order',
                relatedId: (int) $order->id,
            );
        } catch (Throwable $e) {
            Log::warning('notifications.portal_order_notify_failed', [
                'portalOrderId' => $order->id,
                'type' => $type,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

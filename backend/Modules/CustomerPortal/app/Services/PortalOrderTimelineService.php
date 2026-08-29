<?php

namespace Modules\CustomerPortal\Services;

use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Support\PortalOrderStatuses;

class PortalOrderTimelineService
{
    /**
     * Fulfilment-aware status timeline derived from current status + timestamps (no history table).
     *
     * @return list<array{key: string, label: string, state: string, detail: string|null, at: string|null}>
     */
    public function build(PortalOrder $order): array
    {
        $status = (string) $order->status;
        $steps = $this->stepDefinitions($order);

        if (PortalOrderStatuses::isTerminal($status)) {
            return $this->terminalTimeline($order, $steps, $status);
        }

        $keys = array_column($steps, 'key');
        $currentIndex = array_search($status, $keys, true);
        if ($currentIndex === false) {
            $currentIndex = 0;
        }

        $timeline = [];
        foreach ($steps as $i => $step) {
            $state = match (true) {
                $i < $currentIndex => 'completed',
                $i === $currentIndex => 'current',
                default => 'upcoming',
            };

            $timeline[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'state' => $state,
                'detail' => $this->detailFor($order, $step['key'], $state),
                'at' => $this->timestampFor($order, $step['key'], $state),
            ];
        }

        return $timeline;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function stepDefinitions(PortalOrder $order): array
    {
        $pickup = in_array($order->fulfilment_mode, ['store_pickup', 'click_collect'], true);

        if ($pickup) {
            return [
                ['key' => PortalOrderStatuses::PENDING, 'label' => PortalOrderStatuses::label(PortalOrderStatuses::PENDING)],
                ['key' => PortalOrderStatuses::CONFIRMED, 'label' => PortalOrderStatuses::label(PortalOrderStatuses::CONFIRMED)],
                ['key' => PortalOrderStatuses::PROCESSING, 'label' => PortalOrderStatuses::label(PortalOrderStatuses::PROCESSING)],
                ['key' => PortalOrderStatuses::READY_FOR_PICKUP, 'label' => PortalOrderStatuses::label(PortalOrderStatuses::READY_FOR_PICKUP)],
                ['key' => PortalOrderStatuses::DELIVERED, 'label' => 'Delivered / Collected'],
            ];
        }

        return [
            ['key' => PortalOrderStatuses::PENDING, 'label' => PortalOrderStatuses::label(PortalOrderStatuses::PENDING)],
            ['key' => PortalOrderStatuses::CONFIRMED, 'label' => PortalOrderStatuses::label(PortalOrderStatuses::CONFIRMED)],
            ['key' => PortalOrderStatuses::PROCESSING, 'label' => PortalOrderStatuses::label(PortalOrderStatuses::PROCESSING)],
            ['key' => PortalOrderStatuses::OUT_FOR_DELIVERY, 'label' => PortalOrderStatuses::label(PortalOrderStatuses::OUT_FOR_DELIVERY)],
            ['key' => PortalOrderStatuses::DELIVERED, 'label' => PortalOrderStatuses::label(PortalOrderStatuses::DELIVERED)],
        ];
    }

    /**
     * @param  list<array{key: string, label: string}>  $steps
     * @return list<array{key: string, label: string, state: string, detail: string|null, at: string|null}>
     */
    private function terminalTimeline(PortalOrder $order, array $steps, string $terminal): array
    {
        $completedThrough = match (true) {
            $order->payment_status === 'paid' || $order->paid_at !== null => PortalOrderStatuses::CONFIRMED,
            default => PortalOrderStatuses::PENDING,
        };

        $keys = array_column($steps, 'key');
        $throughIndex = array_search($completedThrough, $keys, true);
        if ($throughIndex === false) {
            $throughIndex = 0;
        }

        $timeline = [];
        foreach ($steps as $i => $step) {
            if ($i > $throughIndex) {
                break;
            }
            $timeline[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'state' => 'completed',
                'detail' => $this->detailFor($order, $step['key'], 'completed'),
                'at' => $this->timestampFor($order, $step['key'], 'completed'),
            ];
        }

        $timeline[] = [
            'key' => $terminal,
            'label' => PortalOrderStatuses::label($terminal),
            'state' => 'current',
            'detail' => null,
            'at' => $order->updated_at?->toIso8601String(),
        ];

        return $timeline;
    }

    private function detailFor(PortalOrder $order, string $key, string $state): ?string
    {
        if ($state === 'upcoming') {
            return null;
        }

        $storeName = $order->store?->name;

        return match ($key) {
            PortalOrderStatuses::PENDING => $order->payment_status === 'paid'
                ? 'Payment received'.($order->payment_provider ? ' via '.ucfirst((string) $order->payment_provider) : '')
                : 'Awaiting payment',
            PortalOrderStatuses::CONFIRMED => $storeName
                ? 'Stock reserved · '.$storeName
                : 'Order confirmed',
            PortalOrderStatuses::PROCESSING => 'Picked & packed',
            PortalOrderStatuses::READY_FOR_PICKUP => 'Collect at store counter · bring this order ID',
            PortalOrderStatuses::OUT_FOR_DELIVERY => 'On the way to your address',
            PortalOrderStatuses::DELIVERED => in_array($order->fulfilment_mode, ['store_pickup', 'click_collect'], true)
                ? 'Collected'
                : 'Delivered',
            default => null,
        };
    }

    private function timestampFor(PortalOrder $order, string $key, string $state): ?string
    {
        if ($state === 'upcoming') {
            return null;
        }

        return match ($key) {
            PortalOrderStatuses::PENDING => $order->created_at?->toIso8601String(),
            PortalOrderStatuses::CONFIRMED => $order->paid_at?->toIso8601String()
                ?? ($order->payment_status === 'paid' ? $order->updated_at?->toIso8601String() : null),
            default => $state === 'current' || $state === 'completed'
                ? ($order->updated_at?->toIso8601String())
                : null,
        };
    }
}

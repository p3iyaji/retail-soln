<?php

namespace Modules\Notifications\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Modules\Notifications\Data\NotificationRecipient;
use Modules\Notifications\Jobs\DeliverNotification;
use Modules\Notifications\Models\Notification;
use Modules\Notifications\Support\NotificationStatuses;
use Throwable;

/**
 * The single entry point triggers use. Creates one pending row per
 * (recipient, channel) and queues delivery after commit — callers on the POS,
 * checkout, and fulfilment paths never wait on a transport (AD-7).
 */
class NotificationService
{
    public function __construct(
        private readonly NotificationChannelRegistry $channels,
        private readonly Dispatcher $bus,
    ) {}

    /**
     * @param  list<NotificationRecipient>  $recipients
     * @param  array<string, mixed>  $payload
     * @param  list<string>|null  $channels  overrides the configured channels for this type
     * @return list<Notification>
     */
    public function notify(
        string $type,
        int $organizationId,
        array $recipients,
        string $subject,
        string $body,
        ?int $storeId = null,
        array $payload = [],
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?array $channels = null,
    ): array {
        $channelKeys = $channels ?? $this->channelsForType($type);
        $created = [];

        foreach ($recipients as $recipient) {
            foreach ($channelKeys as $channelKey) {
                $notification = $this->makeRow(
                    $type,
                    $organizationId,
                    $storeId,
                    $channelKey,
                    $recipient,
                    $subject,
                    $body,
                    $payload,
                    $relatedType,
                    $relatedId,
                );

                if ($notification === null) {
                    continue;
                }

                $created[] = $notification;
            }
        }

        foreach ($created as $notification) {
            $this->queueDelivery($notification);
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeRow(
        string $type,
        int $organizationId,
        ?int $storeId,
        string $channelKey,
        NotificationRecipient $recipient,
        string $subject,
        string $body,
        array $payload,
        ?string $relatedType,
        ?int $relatedId,
    ): ?Notification {
        if (! $this->channels->has($channelKey)) {
            Log::warning('notifications.unknown_channel', ['channel' => $channelKey, 'type' => $type]);

            return null;
        }

        $notification = new Notification([
            'organization_id' => $organizationId,
            'store_id' => $storeId,
            'type' => $type,
            'channel' => $channelKey,
            'status' => NotificationStatuses::PENDING,
            'recipient_type' => $recipient->type,
            'recipient_id' => $recipient->id,
            'recipient_address' => $recipient->addressFor($channelKey),
            'recipient_name' => $recipient->name,
            'subject' => $subject,
            'body' => $body,
            'payload' => $payload === [] ? null : $payload,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
        ]);

        // A recipient without an address for this channel is a silent skip, not a failure.
        if (! $this->channels->get($channelKey)->supports($notification)) {
            return null;
        }

        $notification->save();

        return $notification;
    }

    private function queueDelivery(Notification $notification): void
    {
        try {
            $job = new DeliverNotification($notification->id);

            $queue = config('notifications.queue');
            if (is_string($queue) && $queue !== '') {
                $job->onQueue($queue);
            }

            // Dispatched eagerly (not via PendingDispatch destruct) so a broken
            // queue connection surfaces here and is swallowed below.
            $this->bus->dispatch($job);
        } catch (Throwable $e) {
            // A broken queue connection must not take down the business action.
            Log::error('notifications.dispatch_failed', [
                'notificationId' => $notification->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function channelsForType(string $type): array
    {
        // Type keys contain dots, so the map has to be indexed directly rather
        // than through config() dot notation.
        $map = config('notifications.type_channels', []);
        $configured = is_array($map) ? ($map[$type] ?? null) : null;

        if (is_array($configured)) {
            return array_values(array_filter($configured, 'is_string'));
        }

        $default = config('notifications.default_channels', []);

        return is_array($default) ? array_values(array_filter($default, 'is_string')) : [];
    }
}

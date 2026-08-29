<?php

namespace Modules\Notifications\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notifications\Data\NotificationRecipient;
use Modules\Notifications\Models\Notification;
use Modules\Notifications\Support\NotificationChannels;
use Modules\Organization\Services\OrganizationService;

/**
 * Staff in-app inbox. Scoped to the signed-in user's own rows inside the
 * current organization — there is no cross-user or portal read path here.
 */
class NotificationController
{
    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        $user = $this->authorizedUser($request);
        $organizationId = (int) $organizations->current()->id;

        $query = $this->inboxQuery($organizationId, (int) $user->id);

        $filter = strtolower((string) $request->query('status', 'all'));
        if ($filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($filter === 'read') {
            $query->whereNotNull('read_at');
        }

        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Notification $notification) => $this->toPayload($notification))
                ->values(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'unreadCount' => $this->inboxQuery($organizationId, (int) $user->id)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    public function read(Request $request, int $notification, OrganizationService $organizations): JsonResponse
    {
        $user = $this->authorizedUser($request);
        $organizationId = (int) $organizations->current()->id;

        /** @var Notification|null $model */
        $model = $this->inboxQuery($organizationId, (int) $user->id)
            ->whereKey($notification)
            ->first();

        if ($model === null) {
            abort(404);
        }

        if ($model->read_at === null) {
            $model->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => $this->toPayload($model)]);
    }

    /**
     * @return Builder<Notification>
     */
    private function inboxQuery(int $organizationId, int $userId): Builder
    {
        return Notification::query()
            ->where('organization_id', $organizationId)
            ->where('channel', NotificationChannels::IN_APP)
            ->where('recipient_type', NotificationRecipient::STAFF_USER)
            ->where('recipient_id', $userId);
    }

    private function authorizedUser(Request $request): mixed
    {
        $user = $request->user();
        if ($user?->hasPermission('notifications.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'channel' => $notification->channel,
            'status' => $notification->status,
            'storeId' => $notification->store_id,
            'subject' => $notification->subject,
            'body' => $notification->body,
            'payload' => $notification->payload,
            'relatedType' => $notification->related_type,
            'relatedId' => $notification->related_id,
            'readAt' => $notification->read_at?->toIso8601String(),
            'sentAt' => $notification->sent_at?->toIso8601String(),
            'createdAt' => $notification->created_at?->toIso8601String(),
        ];
    }
}

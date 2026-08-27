<?php

namespace App\Http\Controllers\CreditApplication;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppNotificationResource;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The customer's in-app notification inbox: the persisted rows NotificationService writes.
 * Notifications are per-user; the WebSocket (private-user.{id}) is only the live push — this
 * endpoint is the authoritative list, including anything missed while disconnected.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AppNotification::forNotifiable($request->user())
            ->latest()
            ->limit(max(1, min((int) $request->query('limit', 50), 100)));

        return AppNotificationResource::collection($query->get());
    }

    /** Marks one or all notifications read. Idempotent. */
    public function markAsRead(Request $request): JsonResponse
    {
        $id = $request->query('id');

        $query = AppNotification::forNotifiable($request->user());

        if ($id !== null) {
            $query->where('id', (int) $id);
        } else {
            $query->unread();
        }

        $query->update(['read_at' => now()]);

        return response()->json(['message' => 'Notifications marked as read.']);
    }
}
